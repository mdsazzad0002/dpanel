//! The client against a small ACME server that lives in the test: it checks
//! every signature, nonce and URL the way RFC 8555 says a CA must, reads the
//! HTTP-01 token from the folder the gateway serves or the DNS-01 value from
//! the TXT records a solver wrote, and signs a real certificate for the CSR.

use std::{
    collections::{BTreeMap, HashSet},
    fs,
    net::TcpListener,
    path::{Path, PathBuf},
    sync::{Arc, Mutex},
    time::Duration,
};

use axum::{
    Router,
    body::Bytes,
    extract::State,
    http::{HeaderMap, Method, StatusCode, Uri},
    response::{IntoResponse, Response},
};
use base64::{Engine as _, engine::general_purpose::URL_SAFE_NO_PAD};
use rcgen::{
    BasicConstraints, CertificateParams, CertificateSigningRequestParams, DnType, IsCa, Issuer, KeyPair, SanType,
};
use ring::signature::{ECDSA_P256_SHA256_FIXED, UnparsedPublicKey};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use x509_parser::pem::parse_x509_pem;

use futures_util::future::BoxFuture;

use super::*;
use crate::platform::{Os, PlatformError, Profile};

struct Authorization {
    /// Without `*.`: a wildcard is a flag, as RFC 8555 §7.1.4 says.
    name: String,
    wildcard: bool,
    token: String,
    status: &'static str,
    error: Option<Value>,
}

struct Order {
    authorizations: Vec<Authorization>,
    certificate: Option<String>,
}

/// TXT records by name: the zone the CA reads DNS-01 answers from.
type Zone = Arc<Mutex<BTreeMap<String, Vec<String>>>>;

struct Ca {
    base: String,
    challenge_dir: PathBuf,
    zone: Zone,
    issuer: Issuer<'static, KeyPair>,
    root_pem: String,
    /// How long the next certificate lasts.
    valid_days: i64,
    /// The next validation fails, as when DNS points elsewhere.
    refuse_validation: bool,
    /// The next signed request is told its nonce is stale.
    stale_nonce_once: bool,
    nonces: HashSet<String>,
    counter: u64,
    /// The JWK of each account; its index is the account id.
    accounts: Vec<Value>,
    registrations: u32,
    orders: Vec<Order>,
    revoked: Vec<Vec<u8>>,
}

type Shared = Arc<Mutex<Ca>>;

fn decode(text: &str) -> Vec<u8> {
    URL_SAFE_NO_PAD.decode(text).expect("base64url")
}

/// RFC 7638, written the long way round so it does not share the client's code.
fn thumbprint(jwk: &Value) -> String {
    let members: BTreeMap<&str, &str> =
        ["crv", "kty", "x", "y"].into_iter().map(|name| (name, jwk[name].as_str().unwrap())).collect();
    URL_SAFE_NO_PAD.encode(Sha256::digest(serde_json::to_string(&members).unwrap()))
}

fn problem(status: StatusCode, kind: &str, detail: &str) -> (StatusCode, Value) {
    (status, json!({ "type": format!("urn:ietf:params:acme:error:{kind}"), "detail": detail }))
}

impl Ca {
    fn nonce(&mut self) -> String {
        self.counter += 1;
        let nonce = format!("nonce-{}", self.counter);
        self.nonces.insert(nonce.clone());
        nonce
    }

    /// What a CA checks on every POST. Gives the account and the payload.
    fn verified(&mut self, path: &str, body: &[u8]) -> Result<(usize, Vec<u8>), (StatusCode, Value)> {
        let malformed = |detail: &str| problem(StatusCode::BAD_REQUEST, "malformed", detail);
        let jws: Value = serde_json::from_slice(body).map_err(|_| malformed("body is not JSON"))?;
        let (Some(protected64), Some(payload64), Some(signature64)) =
            (jws["protected"].as_str(), jws["payload"].as_str(), jws["signature"].as_str())
        else {
            return Err(malformed("not a flattened JWS"));
        };
        let protected: Value = serde_json::from_slice(&decode(protected64)).map_err(|_| malformed("protected"))?;
        if protected["alg"] != "ES256" {
            return Err(malformed("alg"));
        }
        if protected["url"].as_str() != Some(&format!("{}{path}", self.base)) {
            return Err(malformed("url does not match the request"));
        }
        let known = protected["nonce"].as_str().is_some_and(|nonce| self.nonces.remove(nonce));
        if !known || std::mem::take(&mut self.stale_nonce_once) {
            return Err(problem(StatusCode::BAD_REQUEST, "badNonce", "stale nonce"));
        }

        let (account, jwk) = match (protected["kid"].as_str(), protected.get("jwk")) {
            (Some(kid), None) => {
                let id: usize = kid
                    .strip_prefix(&format!("{}/acct/", self.base))
                    .and_then(|id| id.parse().ok())
                    .filter(|id| *id < self.accounts.len())
                    .ok_or_else(|| problem(StatusCode::BAD_REQUEST, "accountDoesNotExist", "unknown kid"))?;
                (id, self.accounts[id].clone())
            }
            (None, Some(jwk)) if path == "/new-account" => (usize::MAX, jwk.clone()),
            _ => return Err(malformed("exactly one of kid and jwk, and jwk only to register")),
        };
        let mut point = vec![4u8];
        point.extend(decode(jwk["x"].as_str().unwrap_or_default()));
        point.extend(decode(jwk["y"].as_str().unwrap_or_default()));
        UnparsedPublicKey::new(&ECDSA_P256_SHA256_FIXED, point)
            .verify(format!("{protected64}.{payload64}").as_bytes(), &decode(signature64))
            .map_err(|_| malformed("bad signature"))?;

        if account == usize::MAX {
            let existing = self.accounts.iter().position(|known| *known == jwk);
            let id = existing.unwrap_or_else(|| {
                self.accounts.push(jwk);
                self.registrations += 1;
                self.accounts.len() - 1
            });
            return Ok((id, decode(payload64)));
        }
        Ok((account, decode(payload64)))
    }

    fn order_json(&self, id: usize) -> Value {
        let order = &self.orders[id];
        let status = if order.certificate.is_some() {
            "valid"
        } else if order.authorizations.iter().any(|authorization| authorization.status == "invalid") {
            "invalid"
        } else if order.authorizations.iter().all(|authorization| authorization.status == "valid") {
            "ready"
        } else {
            "pending"
        };
        let mut json = json!({
            "status": status,
            "identifiers": order.authorizations.iter().map(|a| json!({"type": "dns", "value": a.asked()})).collect::<Vec<_>>(),
            "authorizations": (0..order.authorizations.len()).map(|index| format!("{}/authz/{id}/{index}", self.base)).collect::<Vec<_>>(),
            "finalize": format!("{}/finalize/{id}", self.base),
        });
        if order.certificate.is_some() {
            json["certificate"] = json!(format!("{}/cert/{id}", self.base));
        }
        json
    }

    fn authorization_json(&self, order: usize, index: usize) -> Value {
        let authorization = &self.orders[order].authorizations[index];
        let challenge = |kind: &str| {
            let mut challenge = json!({
                "type": kind,
                "url": format!("{}/chall/{order}/{index}/{}", self.base, usize::from(kind == "http-01")),
                "token": authorization.token,
                "status": authorization.status,
            });
            if let Some(error) = authorization.error.as_ref() {
                challenge["error"] = error.clone();
            }
            challenge
        };
        // DNS-01 first: the client has to pick by type, not by position.
        // A wildcard can only be proved in DNS, so it gets nothing else.
        let challenges =
            if authorization.wildcard { vec![challenge("dns-01")] } else { vec![challenge("dns-01"), challenge("http-01")] };
        let mut json = json!({
            "status": authorization.status,
            "identifier": { "type": "dns", "value": authorization.name },
            "challenges": challenges,
        });
        if authorization.wildcard {
            json["wildcard"] = json!(true);
        }
        json
    }

    /// Stands in for the CA's own request to the name being proved: HTTP to
    /// the name for `http-01` (kind 1), a TXT lookup for `dns-01` (kind 0).
    fn validate(&mut self, order: usize, index: usize, kind: usize, account: usize) {
        let key_authorization =
            format!("{}.{}", self.orders[order].authorizations[index].token, thumbprint(&self.accounts[account]));
        let name = self.orders[order].authorizations[index].name.clone();
        let refused = std::mem::take(&mut self.refuse_validation);
        let (proved, detail) = if kind == 0 {
            let record = format!("_acme-challenge.{name}");
            let expected = URL_SAFE_NO_PAD.encode(Sha256::digest(key_authorization.as_bytes()));
            let found = self.zone.lock().unwrap().get(&record).is_some_and(|values| values.contains(&expected));
            (found, format!("No TXT record found at {record}"))
        } else {
            let token = &self.orders[order].authorizations[index].token;
            let served = fs::read_to_string(self.challenge_dir.join(token)).ok();
            (
                served.as_deref() == Some(&key_authorization),
                format!("Invalid response from http://{name}/.well-known/acme-challenge/{token}: 404"),
            )
        };
        let authorization = &mut self.orders[order].authorizations[index];
        if !refused && proved {
            authorization.status = "valid";
        } else {
            authorization.status = "invalid";
            authorization.error = Some(json!({ "type": "urn:ietf:params:acme:error:unauthorized", "detail": detail }));
        }
    }

    fn sign(&mut self, order: usize, csr: &[u8]) -> Result<(), (StatusCode, Value)> {
        let bad_csr = |detail: &str| problem(StatusCode::BAD_REQUEST, "badCSR", detail);
        let mut request = CertificateSigningRequestParams::from_der(&csr.to_vec().into())
            .map_err(|error| bad_csr(&error.to_string()))?;
        let mut asked: Vec<String> = request
            .params
            .subject_alt_names
            .iter()
            .filter_map(|name| match name {
                SanType::DnsName(name) => Some(name.as_str().to_string()),
                _ => None,
            })
            .collect();
        let mut ordered: Vec<String> = self.orders[order].authorizations.iter().map(Authorization::asked).collect();
        asked.sort();
        ordered.sort();
        if asked != ordered {
            return Err(bad_csr("the CSR names are not the order's"));
        }
        let now = time::OffsetDateTime::now_utc();
        request.params.not_before = now - time::Duration::hours(1);
        request.params.not_after = now + time::Duration::days(self.valid_days);
        let leaf = request.signed_by(&self.issuer).map_err(|error| bad_csr(&error.to_string()))?;
        self.orders[order].certificate = Some(format!("{}{}", leaf.pem(), self.root_pem));
        Ok(())
    }

    fn answer(&mut self, method: &Method, path: &str, body: &[u8]) -> Result<Response, (StatusCode, Value)> {
        if path == "/directory" {
            return Ok(axum::Json(json!({
                "newNonce": format!("{}/new-nonce", self.base),
                "newAccount": format!("{}/new-account", self.base),
                "newOrder": format!("{}/new-order", self.base),
                "revokeCert": format!("{}/revoke-cert", self.base),
            }))
            .into_response());
        }
        if path == "/new-nonce" {
            return Ok(StatusCode::OK.into_response());
        }
        if method != Method::POST {
            return Err(problem(StatusCode::METHOD_NOT_ALLOWED, "malformed", "POST only"));
        }
        let (account, payload) = self.verified(path, body)?;
        let payload: Value = if payload.is_empty() { Value::Null } else { serde_json::from_slice(&payload).unwrap() };
        let ids: Vec<usize> = path.split('/').skip(2).filter_map(|part| part.parse().ok()).collect();
        let not_found = || problem(StatusCode::NOT_FOUND, "malformed", "no such resource");
        let order_exists = |ca: &Self| ids.first().is_some_and(|id| *id < ca.orders.len());

        match path.split('/').nth(1).unwrap_or_default() {
            "new-account" => {
                assert_eq!(payload["termsOfServiceAgreed"], true);
                let location = [("location", format!("{}/acct/{account}", self.base))];
                Ok((StatusCode::CREATED, location, axum::Json(json!({ "status": "valid" }))).into_response())
            }
            "new-order" => {
                let authorizations = payload["identifiers"]
                    .as_array()
                    .unwrap()
                    .iter()
                    .map(|identifier| {
                        self.counter += 1;
                        let value = identifier["value"].as_str().unwrap();
                        Authorization {
                            name: value.trim_start_matches("*.").to_string(),
                            wildcard: value.starts_with("*."),
                            token: format!("token_{}-A", self.counter),
                            status: "pending",
                            error: None,
                        }
                    })
                    .collect();
                self.orders.push(Order { authorizations, certificate: None });
                let id = self.orders.len() - 1;
                let location = [("location", format!("{}/order/{id}", self.base))];
                Ok((StatusCode::CREATED, location, axum::Json(self.order_json(id))).into_response())
            }
            "order" if order_exists(self) => Ok(axum::Json(self.order_json(ids[0])).into_response()),
            "authz" if order_exists(self) => Ok(axum::Json(self.authorization_json(ids[0], ids[1])).into_response()),
            "chall" if order_exists(self) => {
                assert_eq!(payload, json!({}), "a challenge is answered with an empty object");
                let kind = ids[2];
                if kind == 1 && self.orders[ids[0]].authorizations[ids[1]].wildcard {
                    return Err(not_found());
                }
                self.validate(ids[0], ids[1], kind, account);
                Ok(axum::Json(self.authorization_json(ids[0], ids[1])["challenges"][kind].clone()).into_response())
            }
            "finalize" if order_exists(self) => {
                if self.order_json(ids[0])["status"] != "ready" {
                    return Err(problem(StatusCode::FORBIDDEN, "orderNotReady", "the order is not ready"));
                }
                self.sign(ids[0], &decode(payload["csr"].as_str().unwrap()))?;
                Ok(axum::Json(self.order_json(ids[0])).into_response())
            }
            "cert" if order_exists(self) => {
                let chain = self.orders[ids[0]].certificate.clone().ok_or_else(not_found)?;
                Ok(([("content-type", "application/pem-certificate-chain")], chain).into_response())
            }
            "revoke-cert" => {
                self.revoked.push(decode(payload["certificate"].as_str().unwrap()));
                Ok(StatusCode::OK.into_response())
            }
            _ => Err(not_found()),
        }
    }
}

async fn serve(State(ca): State<Shared>, method: Method, uri: Uri, _headers: HeaderMap, body: Bytes) -> Response {
    let mut ca = ca.lock().unwrap();
    let mut response = match ca.answer(&method, uri.path(), &body) {
        Ok(response) => response,
        Err((status, problem)) => {
            (status, [("content-type", "application/problem+json")], problem.to_string()).into_response()
        }
    };
    let nonce = ca.nonce();
    response.headers_mut().insert("replay-nonce", nonce.parse().unwrap());
    response
}

impl Authorization {
    /// The name as the order and the CSR carry it.
    fn asked(&self) -> String {
        if self.wildcard { format!("*.{}", self.name) } else { self.name.clone() }
    }
}

/// Writes TXT records into the test CA's zone, and remembers what it did.
#[derive(Default)]
struct FakeDns {
    zone: Zone,
    /// What `publish` and `withdraw` were asked, in order.
    calls: Mutex<Vec<String>>,
    /// `publish` fails, as when the zone is not on this server.
    broken: bool,
}

impl Dns01Solver for FakeDns {
    fn publish<'a>(&'a self, name: &'a str, value: &'a str) -> BoxFuture<'a, Result<(), PlatformError>> {
        Box::pin(async move {
            self.calls.lock().unwrap().push(format!("publish {name}"));
            if self.broken {
                return Err(PlatformError::Failed { action: "publish".into(), detail: "zone not here".into() });
            }
            self.zone.lock().unwrap().entry(name.to_string()).or_default().push(value.to_string());
            Ok(())
        })
    }

    fn withdraw<'a>(&'a self, name: &'a str, value: &'a str) -> BoxFuture<'a, Result<(), PlatformError>> {
        Box::pin(async move {
            self.calls.lock().unwrap().push(format!("withdraw {name}"));
            if let Some(values) = self.zone.lock().unwrap().get_mut(name) {
                values.retain(|known| known != value);
            }
            Ok(())
        })
    }
}

/// A CA on a loopback port, and everything the client needs to use it.
struct Fixture {
    _folder: tempfile::TempDir,
    ca: Shared,
    zone: Zone,
    directory: String,
    settings: AcmeSettings,
}

impl Fixture {
    fn new() -> Self {
        let folder = tempfile::tempdir().unwrap();
        let settings = AcmeSettings {
            tls_dir: folder.path().join("tls"),
            state_dir: folder.path().join("state"),
            challenge_dir: folder.path().join("challenge"),
            contact: Some("admin@example.com".into()),
            ca_bundle: None,
            renew_before: Duration::from_secs(30 * 86_400),
            timeout: Duration::from_secs(20),
        };

        let key = KeyPair::generate().unwrap();
        let mut root = CertificateParams::new(Vec::<String>::new()).unwrap();
        root.is_ca = IsCa::Ca(BasicConstraints::Unconstrained);
        root.distinguished_name.push(DnType::CommonName, "drust test CA");
        let root_pem = root.self_signed(&key).unwrap().pem();

        let listener = TcpListener::bind("127.0.0.1:0").unwrap();
        listener.set_nonblocking(true).unwrap();
        let base = format!("http://{}", listener.local_addr().unwrap());
        let zone = Zone::default();
        let ca = Arc::new(Mutex::new(Ca {
            base: base.clone(),
            challenge_dir: settings.challenge_dir.clone(),
            zone: zone.clone(),
            issuer: Issuer::new(root, key),
            root_pem,
            valid_days: 90,
            refuse_validation: false,
            stale_nonce_once: false,
            nonces: HashSet::new(),
            counter: 0,
            accounts: Vec::new(),
            registrations: 0,
            orders: Vec::new(),
            revoked: Vec::new(),
        }));
        let app = Router::new().fallback(serve).with_state(ca.clone());
        std::thread::spawn(move || {
            tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(async move {
                let listener = tokio::net::TcpListener::from_std(listener).unwrap();
                axum::serve(listener, app).await.unwrap();
            });
        });

        Self { _folder: folder, ca, zone, directory: format!("{base}/directory"), settings }
    }

    fn client(&self) -> Rfc8555Client {
        Rfc8555Client::new(self.settings.clone())
    }

    fn request(&self, domains: &[&str]) -> CertRequest {
        CertRequest {
            domains: domains.iter().map(|name| name.to_string()).collect(),
            challenge: Challenge::Http01,
            directory: self.directory.clone(),
            dns: None,
        }
    }

    /// The same names over DNS-01, through a solver that writes this CA's zone.
    fn dns_request(&self, domains: &[&str]) -> (CertRequest, Arc<FakeDns>) {
        let dns = Arc::new(FakeDns { zone: self.zone.clone(), ..FakeDns::default() });
        let request = CertRequest { challenge: Challenge::Dns01, dns: Some(dns.clone()), ..self.request(domains) };
        (request, dns)
    }

    fn txt_left(&self) -> usize {
        self.zone.lock().unwrap().values().map(Vec::len).sum()
    }

    fn tokens_left(&self) -> usize {
        fs::read_dir(&self.settings.challenge_dir).map(|entries| entries.count()).unwrap_or(0)
    }
}

/// The public key inside the first certificate of a PEM file.
fn certificate_key(path: &Path) -> Vec<u8> {
    let pem = fs::read(path).unwrap();
    let (_, pem) = parse_x509_pem(&pem).unwrap();
    pem.parse_x509().unwrap().public_key().subject_public_key.data.to_vec()
}

#[test]
fn a_certificate_is_issued_with_its_own_key_and_the_token_is_gone_afterwards() {
    let fixture = Fixture::new();
    fixture.ca.lock().unwrap().stale_nonce_once = true;
    let client = fixture.client();

    let issued = client.issue(&fixture.request(&["Example.com", "www.example.com"])).unwrap();

    assert_eq!(issued.domain, "example.com");
    assert_eq!(issued.certificate_path, fixture.settings.tls_dir.join("example.com.crt"));
    assert_eq!(issued.private_key_path, fixture.settings.tls_dir.join("example.com.key"));
    let info = CertInfo::read(&issued.certificate_path).unwrap();
    assert!(info.covers("example.com") && info.covers("www.example.com"));
    assert!(!info.covers("other.example.com"));
    assert_eq!(info.not_after, issued.not_after);
    assert!(info.outlives(unix_now(), Duration::from_secs(80 * 86_400)));

    // The key on disk is the one the certificate is for, and only root reads it.
    let key = KeyPair::from_pem(&fs::read_to_string(&issued.private_key_path).unwrap()).unwrap();
    assert_eq!(key.public_key_raw(), certificate_key(&issued.certificate_path));
    let metadata = fs::metadata(&issued.private_key_path).unwrap();
    if let Some(mode) = crate::platform::files().mode_bits(&metadata) {
        assert_eq!(mode & 0o777, 0o600);
    }
    // The chain carries the issuer after the leaf.
    assert_eq!(fs::read_to_string(&issued.certificate_path).unwrap().matches("BEGIN CERTIFICATE").count(), 2);

    assert_eq!(fixture.tokens_left(), 0);
    assert!(client.manages("example.com"));
    assert!(!client.manages("www.example.com"));

    // A second certificate reuses the account and gets a new key.
    client.issue(&fixture.request(&["example.com", "www.example.com"])).unwrap();
    assert_eq!(fixture.ca.lock().unwrap().registrations, 1);
    assert_ne!(key.public_key_raw(), certificate_key(&issued.certificate_path));
}

#[test]
fn a_refused_validation_names_the_domain_and_leaves_nothing_behind() {
    let fixture = Fixture::new();
    fixture.ca.lock().unwrap().refuse_validation = true;

    let error = fixture.client().issue(&fixture.request(&["example.com"])).unwrap_err().to_string();

    assert!(error.contains("could not validate example.com"), "{error}");
    assert!(error.contains("Invalid response from http://example.com/.well-known/acme-challenge/"), "{error}");
    assert!(error.contains("unauthorized"), "{error}");
    assert!(!fixture.settings.tls_dir.join("example.com.crt").exists());
    assert!(!fixture.settings.tls_dir.join("example.com.key").exists());
    assert_eq!(fixture.tokens_left(), 0);
    assert!(!fixture.client().manages("example.com"));
}

#[test]
fn only_certificates_close_to_expiry_are_renewed() {
    let fixture = Fixture::new();
    let client = fixture.client();
    fixture.ca.lock().unwrap().valid_days = 10;
    client.issue(&fixture.request(&["soon.example.com"])).unwrap();
    fixture.ca.lock().unwrap().valid_days = 90;
    client.issue(&fixture.request(&["later.example.com"])).unwrap();
    let month = Duration::from_secs(30 * 86_400);
    let soon = fixture.settings.tls_dir.join("soon.example.com.crt");
    assert!(!CertInfo::read(&soon).unwrap().outlives(unix_now(), month));

    let results = client.renew_due().unwrap();

    assert_eq!(results, vec![RenewResult { domain: "soon.example.com".into(), renewed: true, error: None }]);
    assert!(CertInfo::read(&soon).unwrap().outlives(unix_now(), month));
    assert_eq!(client.renew_due().unwrap(), vec![]);

    // A certificate that went missing is issued again; a failure is reported, not fatal.
    fs::remove_file(&soon).unwrap();
    fixture.ca.lock().unwrap().refuse_validation = true;
    let results = client.renew_due().unwrap();
    assert_eq!(results.len(), 1);
    assert!(!results[0].renewed);
    assert!(results[0].error.as_deref().unwrap().contains("could not validate soon.example.com"));
}

#[test]
fn revoking_sends_the_certificate_to_the_ca() {
    let fixture = Fixture::new();
    let client = fixture.client();
    let issued = client.issue(&fixture.request(&["example.com"])).unwrap();

    client.revoke("example.com").unwrap();

    let sent = fixture.ca.lock().unwrap().revoked.clone();
    assert_eq!(sent, vec![CertInfo::read(&issued.certificate_path).unwrap().der().to_vec()]);
    assert!(matches!(client.revoke("unknown.example.com"), Err(PlatformError::NotFound { .. })));
    assert!(matches!(client.revoke("../../etc/passwd"), Err(PlatformError::NotFound { .. })));
}

#[test]
fn forgetting_removes_only_what_this_client_issued() {
    let fixture = Fixture::new();
    let client = fixture.client();
    client.issue(&fixture.request(&["example.com"])).unwrap();
    // The panel's own certificate lives in the same folder and came from elsewhere.
    let foreign = fixture.settings.tls_dir.join("panel.example.com.crt");
    fs::write(&foreign, "not ours").unwrap();

    assert!(!client.forget("panel.example.com").unwrap());
    assert!(foreign.exists());

    assert!(client.forget("example.com").unwrap());
    assert!(!fixture.settings.tls_dir.join("example.com.crt").exists());
    assert!(!fixture.settings.tls_dir.join("example.com.key").exists());
    assert!(!client.manages("example.com"));
    assert_eq!(client.renew_due().unwrap(), vec![]);
}

#[test]
fn requests_that_cannot_work_are_refused_before_the_ca_is_asked() {
    let fixture = Fixture::new();
    let client = fixture.client();
    let refused = |request: CertRequest| client.issue(&request).unwrap_err().to_string();

    assert!(refused(fixture.request(&[])).contains("between 1 and 100"));
    assert!(refused(fixture.request(&["*.example.com"])).contains("HTTP-01"));
    assert!(refused(fixture.request(&["203.0.113.7"])).contains("HTTP-01"));
    assert!(refused(fixture.request(&["localhost"])).contains("HTTP-01"));
    assert!(refused(fixture.request(&["bad_name.example.com"])).contains("HTTP-01"));
    assert!(refused(fixture.request(&["../example.com"])).contains("HTTP-01"));
    assert!(refused(CertRequest { challenge: Challenge::Dns01, ..fixture.request(&["example.com"]) }).contains("where the domain's DNS lives"));
    let (wildcard_first, _) = fixture.dns_request(&["*.example.com", "example.com"]);
    assert!(refused(wildcard_first).contains("first name"));
    let (two_stars, _) = fixture.dns_request(&["example.com", "*.*.example.com"]);
    assert!(refused(two_stars).contains("DNS-01"));
    let plain = CertRequest { directory: "http://ca.example.com/directory".into(), ..fixture.request(&["example.com"]) };
    assert!(refused(plain).contains("HTTPS"));

    assert!(fixture.ca.lock().unwrap().accounts.is_empty());
}

#[test]
fn a_wildcard_is_proved_in_dns_and_the_records_are_gone_afterwards() {
    let fixture = Fixture::new();
    let client = fixture.client();
    let (request, dns) = fixture.dns_request(&["example.com", "*.example.com"]);

    let issued = client.issue(&request).unwrap();

    let info = CertInfo::read(&issued.certificate_path).unwrap();
    assert_eq!(issued.certificate_path, fixture.settings.tls_dir.join("example.com.crt"));
    assert!(info.covers("example.com") && info.covers("shop.example.com") && info.covers("www.example.com"));
    assert!(!info.covers("a.b.example.com"));
    // Both names share one TXT name; each value is up only while the CA looks.
    assert_eq!(
        *dns.calls.lock().unwrap(),
        [
            "publish _acme-challenge.example.com",
            "withdraw _acme-challenge.example.com",
            "publish _acme-challenge.example.com",
            "withdraw _acme-challenge.example.com",
        ]
    );
    assert_eq!(fixture.txt_left(), 0);
    assert_eq!(fixture.tokens_left(), 0);
    assert!(client.manages("example.com"));

    // renew_due leaves it to ssl/ensure, which knows where the zone lives.
    fs::remove_file(&issued.certificate_path).unwrap();
    assert_eq!(client.renew_due().unwrap(), vec![]);
}

#[test]
fn a_failed_dns_validation_still_removes_the_record() {
    let fixture = Fixture::new();
    fixture.ca.lock().unwrap().refuse_validation = true;
    let (request, dns) = fixture.dns_request(&["example.com", "*.example.com"]);

    let error = fixture.client().issue(&request).unwrap_err().to_string();

    assert!(error.contains("could not validate example.com"), "{error}");
    assert!(error.contains("No TXT record found at _acme-challenge.example.com"), "{error}");
    assert_eq!(dns.calls.lock().unwrap().last().map(String::as_str), Some("withdraw _acme-challenge.example.com"));
    assert_eq!(fixture.txt_left(), 0);
    assert!(!fixture.settings.tls_dir.join("example.com.crt").exists());

    // A zone that cannot be written stops before the CA is told to look.
    let broken = Arc::new(FakeDns { zone: fixture.zone.clone(), broken: true, ..FakeDns::default() });
    let (request, _) = fixture.dns_request(&["example.org"]);
    let error = fixture.client().issue(&CertRequest { dns: Some(broken), ..request }).unwrap_err().to_string();
    assert!(error.contains("zone not here"), "{error}");
}

#[test]
fn only_a_linux_server_can_choose_between_certbot_and_acme() {
    assert_eq!(SslEngine::choose(Os::Linux, Profile::Server, None), SslEngine::Certbot);
    assert_eq!(SslEngine::choose(Os::Linux, Profile::Server, Some("certbot")), SslEngine::Certbot);
    assert_eq!(SslEngine::choose(Os::Linux, Profile::Server, Some(" ACME ")), SslEngine::Acme);
    for os in [Os::Windows, Os::Macos] {
        assert_eq!(SslEngine::choose(os, Profile::Server, None), SslEngine::Acme);
        assert_eq!(SslEngine::choose(os, Profile::Server, Some("certbot")), SslEngine::Acme);
    }
    for os in [Os::Linux, Os::Windows, Os::Macos] {
        assert_eq!(SslEngine::choose(os, Profile::Local, Some("acme")), SslEngine::LocalCa);
    }
}

#[test]
fn a_wildcard_covers_one_label_and_not_the_bare_name() {
    let info = CertInfo { not_after: 1_000, names: vec!["*.example.com".into(), "example.org".into()], der: Vec::new() };
    assert!(info.covers("www.example.com"));
    assert!(info.covers("WWW.Example.com"));
    assert!(!info.covers("example.com"));
    assert!(!info.covers("a.b.example.com"));
    assert!(info.covers("example.org"));
    assert!(!info.covers("www.example.org"));

    assert!(info.outlives(0, Duration::from_secs(999)));
    assert!(!info.outlives(0, Duration::from_secs(1_000)));
    assert!(!info.outlives(2_000, Duration::ZERO));
}

/// Against a real ACME server, for example Pebble or Let's Encrypt staging:
///
/// ```text
/// DRUST_ACME_TEST_DIRECTORY=https://localhost:14000/dir \
/// DRUST_ACME_TEST_DOMAIN=site.example.test \
/// DRUST_ACME_TEST_HTTP_BIND=127.0.0.1:5002 \
/// DRUST_ACME_TEST_CA_BUNDLE=pebble.minica.pem \
/// cargo test real_acme_server -- --ignored --nocapture
/// ```
///
/// The CA must reach `DRUST_ACME_TEST_HTTP_BIND` as port 80 of the domain.
/// It issues, renews because the certificate is "due", then revokes.
#[test]
#[ignore = "needs an ACME server; see the comment"]
fn a_real_acme_server_issues_renews_and_revokes() {
    let var = |name: &str| std::env::var(name).unwrap_or_else(|_| panic!("{name} is not set"));
    let folder = tempfile::tempdir().unwrap();
    let settings = AcmeSettings {
        tls_dir: folder.path().join("tls"),
        state_dir: folder.path().join("state"),
        challenge_dir: folder.path().join("challenge"),
        contact: None,
        ca_bundle: std::env::var("DRUST_ACME_TEST_CA_BUNDLE").ok().map(PathBuf::from),
        // Longer than any certificate lasts, so the new one is due at once.
        renew_before: Duration::from_secs(3_650 * 86_400),
        timeout: Duration::from_secs(120),
    };

    // Stands in for the gateway: the token folder over plain HTTP.
    let challenge_dir = settings.challenge_dir.clone();
    let listener = TcpListener::bind(var("DRUST_ACME_TEST_HTTP_BIND")).unwrap();
    listener.set_nonblocking(true).unwrap();
    std::thread::spawn(move || {
        let app = Router::new().fallback(move |uri: Uri| {
            let challenge_dir = challenge_dir.clone();
            async move {
                let token = uri.path().strip_prefix("/.well-known/acme-challenge/").unwrap_or("-");
                match fs::read(challenge_dir.join(token.replace(['/', '\\', '.'], "-"))) {
                    Ok(body) => body.into_response(),
                    Err(_) => StatusCode::NOT_FOUND.into_response(),
                }
            }
        });
        tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(async move {
            axum::serve(tokio::net::TcpListener::from_std(listener).unwrap(), app).await.unwrap();
        });
    });

    let domain = var("DRUST_ACME_TEST_DOMAIN");
    let client = Rfc8555Client::new(settings.clone());
    let request = CertRequest {
        domains: vec![domain.clone()],
        challenge: Challenge::Http01,
        directory: var("DRUST_ACME_TEST_DIRECTORY"),
        dns: None,
    };

    let issued = client.issue(&request).unwrap();
    let first = CertInfo::read(&issued.certificate_path).unwrap();
    assert!(first.covers(&domain));
    println!("issued {domain}, not after {}", first.not_after);

    let renewed = client.renew_due().unwrap();
    assert_eq!(renewed, vec![RenewResult { domain: domain.clone(), renewed: true, error: None }]);
    let second = CertInfo::read(&issued.certificate_path).unwrap();
    assert_ne!(first.der(), second.der());
    println!("renewed {domain}, not after {}", second.not_after);

    client.revoke(&domain).unwrap();
    println!("revoked {domain}");
    assert_eq!(fs::read_dir(&settings.challenge_dir).unwrap().count(), 0);
}
