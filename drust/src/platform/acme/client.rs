//! The ACME protocol itself (RFC 8555), with an ES256 account key.
//!
//! Each call builds its own small runtime and blocks on it, so it must run
//! on a blocking thread, never inside an async task.

use std::{
    fs,
    io::Write,
    net::IpAddr,
    path::{Path, PathBuf},
    sync::Mutex,
    time::{Duration, Instant},
};

use base64::{Engine as _, engine::general_purpose::URL_SAFE_NO_PAD};
use rcgen::{CertificateParams, DistinguishedName, KeyPair};
use reqwest::{StatusCode, Url, header};
use ring::{
    rand::SystemRandom,
    signature::{ECDSA_P256_SHA256_FIXED_SIGNING, EcdsaKeyPair, KeyPair as _},
};
use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

use super::{AcmeClient, AcmeSettings, CertInfo, CertRequest, Challenge, IssuedCert, RenewResult, unix_now};
use crate::platform::{
    self, PlatformError,
    fs::{FileMode, LinkPolicy},
};

/// One certificate at a time: two orders must not share a nonce or race to
/// create the account key.
static ONE_AT_A_TIME: Mutex<()> = Mutex::new(());

const BAD_NONCE: &str = "urn:ietf:params:acme:error:badNonce";

pub struct Rfc8555Client {
    settings: AcmeSettings,
}

fn failed(action: &str, detail: impl Into<String>) -> PlatformError {
    PlatformError::Failed { action: action.to_string(), detail: detail.into() }
}

fn base64url(bytes: impl AsRef<[u8]>) -> String {
    URL_SAFE_NO_PAD.encode(bytes)
}

/// The CA is reached over HTTPS. Plain HTTP is only for a test server on
/// this machine, and that holds for every URL the server hands back too.
fn allowed_url(url: &str) -> Result<Url, PlatformError> {
    let parsed = Url::parse(url).map_err(|_| failed("reach the ACME server", format!("Invalid ACME URL: {url}")))?;
    let loopback = parsed.host_str().is_some_and(|host| {
        host == "localhost"
            || host.trim_matches(['[', ']']).parse::<IpAddr>().is_ok_and(|address| address.is_loopback())
    });
    match parsed.scheme() {
        "https" => Ok(parsed),
        "http" if loopback => Ok(parsed),
        _ => Err(failed("reach the ACME server", format!("The ACME server must be reached over HTTPS: {url}"))),
    }
}

/// A name a public CA can validate over HTTP: no wildcard, no address.
fn valid_name(name: &str) -> bool {
    name.len() <= 253
        && name.contains('.')
        && name.split('.').all(|label| {
            !label.is_empty()
                && label.len() <= 63
                && !label.starts_with('-')
                && !label.ends_with('-')
                && label.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'-')
        })
        && name.rsplit('.').next().is_some_and(|top| top.bytes().any(|byte| byte.is_ascii_alphabetic()))
}

fn checked(request: &CertRequest) -> Result<CertRequest, PlatformError> {
    let domains: Vec<String> = request.domains.iter().map(|name| name.trim().to_ascii_lowercase()).collect();
    if domains.is_empty() || domains.len() > 100 {
        return Err(failed("issue certificate", "A certificate needs between 1 and 100 names."));
    }
    if request.challenge == Challenge::Dns01 {
        return Err(failed("issue certificate", "The DNS-01 challenge is not available yet."));
    }
    if let Some(bad) = domains.iter().find(|name| !valid_name(name)) {
        return Err(failed("issue certificate", format!("Not a name HTTP-01 can validate: {bad}")));
    }
    allowed_url(&request.directory)?;
    Ok(CertRequest { domains, challenge: request.challenge, directory: request.directory.trim().to_string() })
}

/// What `issue` wrote down, so `renew_due` can ask for the same again.
#[derive(Debug, Serialize, Deserialize)]
struct StoredOrder {
    domains: Vec<String>,
    directory: String,
    challenge: String,
}

struct AccountKey {
    pair: EcdsaKeyPair,
    rng: SystemRandom,
}

impl AccountKey {
    fn from_pkcs8(pkcs8: &[u8]) -> Result<Self, PlatformError> {
        let rng = SystemRandom::new();
        let pair = EcdsaKeyPair::from_pkcs8(&ECDSA_P256_SHA256_FIXED_SIGNING, pkcs8, &rng)
            .map_err(|error| failed("read ACME account key", format!("The ACME account key is unreadable: {error}")))?;
        Ok(Self { pair, rng })
    }

    /// The stored key for this CA, or a new one.
    fn load_or_create(path: &Path) -> Result<Self, PlatformError> {
        if let Ok(pkcs8) = fs::read(path) {
            return Self::from_pkcs8(&pkcs8);
        }
        let rng = SystemRandom::new();
        let pkcs8 = EcdsaKeyPair::generate_pkcs8(&ECDSA_P256_SHA256_FIXED_SIGNING, &rng)
            .map_err(|_| failed("create ACME account key", "Could not generate an ACME account key."))?;
        write_private(path, pkcs8.as_ref())?;
        Self::from_pkcs8(pkcs8.as_ref())
    }

    fn jwk(&self) -> Value {
        // Uncompressed point: 0x04, then x and y, 32 bytes each.
        let point = self.pair.public_key().as_ref();
        json!({ "crv": "P-256", "kty": "EC", "x": base64url(&point[1..33]), "y": base64url(&point[33..65]) })
    }

    /// RFC 7638: the hash of the key's required members, in name order.
    fn thumbprint(&self) -> String {
        let point = self.pair.public_key().as_ref();
        let canonical = format!(
            r#"{{"crv":"P-256","kty":"EC","x":"{}","y":"{}"}}"#,
            base64url(&point[1..33]),
            base64url(&point[33..65])
        );
        base64url(Sha256::digest(canonical.as_bytes()))
    }

    fn sign(&self, message: &[u8]) -> Result<String, PlatformError> {
        self.pair
            .sign(&self.rng, message)
            .map(|signature| base64url(signature.as_ref()))
            .map_err(|_| failed("sign ACME request", "Could not sign the ACME request."))
    }
}

/// Owner-only, and never half written: a temporary file renamed into place.
pub(crate) fn write_private(path: &Path, bytes: &[u8]) -> Result<(), PlatformError> {
    let temporary = temporary_beside(path);
    let files = platform::files();
    let mut file = files.replace_private(&temporary, LinkPolicy::Refuse)?;
    file.write_all(bytes).and_then(|()| file.sync_all()).map_err(|error| PlatformError::from_io("write file", error))?;
    drop(file);
    files.set_mode(&temporary, FileMode::Private)?;
    fs::rename(&temporary, path).map_err(|error| PlatformError::from_io("write file", error))
}

pub(crate) fn write_public(path: &Path, bytes: &[u8]) -> Result<(), PlatformError> {
    let temporary = temporary_beside(path);
    fs::write(&temporary, bytes)
        .and_then(|()| fs::rename(&temporary, path))
        .map_err(|error| PlatformError::from_io("write file", error))
}

fn temporary_beside(path: &Path) -> PathBuf {
    let mut name = path.file_name().unwrap_or_default().to_os_string();
    name.push(".new");
    path.with_file_name(name)
}

#[derive(Debug, Deserialize)]
#[serde(rename_all = "camelCase")]
struct Directory {
    new_nonce: String,
    new_account: String,
    new_order: String,
    revoke_cert: Option<String>,
}

struct Reply {
    location: Option<String>,
    retry_after: Option<u64>,
    body: Vec<u8>,
}

impl Reply {
    fn json(&self) -> Result<Value, PlatformError> {
        serde_json::from_slice(&self.body)
            .map_err(|_| failed("read ACME reply", "The ACME server sent a reply that is not JSON."))
    }
}

/// An RFC 7807 problem as one line a person can act on.
fn problem_text(status: StatusCode, body: &[u8]) -> String {
    let Ok(problem) = serde_json::from_slice::<Value>(body) else {
        return format!("The ACME server answered {status}.");
    };
    let mut text = problem_line(&problem).unwrap_or_else(|| format!("The ACME server answered {status}."));
    for sub in problem["subproblems"].as_array().into_iter().flatten() {
        if let Some(line) = problem_line(sub) {
            text.push_str("; ");
            text.push_str(&line);
        }
    }
    text.chars().take(2000).collect()
}

fn problem_line(problem: &Value) -> Option<String> {
    let kind = problem["type"].as_str().map(|kind| kind.rsplit(':').next().unwrap_or(kind));
    let detail = problem["detail"].as_str();
    let name = problem["identifier"]["value"].as_str().map(|name| format!("{name}: ")).unwrap_or_default();
    match (detail, kind) {
        (Some(detail), Some(kind)) => Some(format!("{name}{detail} ({kind})")),
        (Some(detail), None) => Some(format!("{name}{detail}")),
        (None, Some(kind)) => Some(format!("{name}{kind}")),
        (None, None) => None,
    }
}

struct Session {
    http: reqwest::Client,
    directory: Directory,
    key: AccountKey,
    /// The account URL, once the CA knows the key.
    account: Option<String>,
    nonce: Option<String>,
    deadline: Instant,
}

impl Session {
    async fn open(settings: &AcmeSettings, directory_url: &str) -> Result<Self, PlatformError> {
        allowed_url(directory_url)?;
        let mut builder = reqwest::Client::builder()
            .user_agent(concat!("drust/", env!("CARGO_PKG_VERSION")))
            .timeout(Duration::from_secs(30));
        if let Some(bundle) = settings.ca_bundle.as_ref() {
            let pem = fs::read(bundle).map_err(|error| {
                failed("read CA bundle", format!("Cannot read DRUST_ACME_CA_BUNDLE {}: {error}", bundle.display()))
            })?;
            let roots = reqwest::Certificate::from_pem_bundle(&pem)
                .map_err(|error| failed("read CA bundle", format!("DRUST_ACME_CA_BUNDLE is not PEM: {error}")))?;
            for root in roots {
                builder = builder.add_root_certificate(root);
            }
        }
        let http = builder.build().map_err(|error| failed("reach the ACME server", error.to_string()))?;

        let directory: Directory = http
            .get(directory_url)
            .send()
            .await
            .and_then(|response| response.error_for_status())
            .map_err(|error| failed("reach the ACME server", format!("Cannot reach the ACME server: {error}")))?
            .json()
            .await
            .map_err(|_| failed("reach the ACME server", "The ACME directory is not what RFC 8555 describes."))?;

        platform::files().create_dir_private(&settings.state_dir)?;
        let key = AccountKey::load_or_create(&account_key_path(settings, directory_url))?;
        let mut session =
            Self { http, directory, key, account: None, nonce: None, deadline: Instant::now() + settings.timeout };

        // Registering a key the CA already knows returns the same account,
        // so this also finds the account URL after a restart.
        let mut account = json!({ "termsOfServiceAgreed": true });
        if let Some(contact) = settings.contact.as_ref() {
            account["contact"] = json!([format!("mailto:{contact}")]);
        }
        let new_account = session.directory.new_account.clone();
        let reply = session.post(&new_account, Some(&account)).await?;
        session.account = Some(
            reply.location.ok_or_else(|| failed("register ACME account", "The ACME server did not name the account."))?,
        );
        Ok(session)
    }

    async fn fresh_nonce(&mut self) -> Result<String, PlatformError> {
        if let Some(nonce) = self.nonce.take() {
            return Ok(nonce);
        }
        let url = allowed_url(&self.directory.new_nonce)?;
        let response = self
            .http
            .head(url)
            .send()
            .await
            .map_err(|error| failed("reach the ACME server", format!("Cannot reach the ACME server: {error}")))?;
        replay_nonce(response.headers())
            .ok_or_else(|| failed("reach the ACME server", "The ACME server gave no nonce."))
    }

    /// A signed POST. `None` is POST-as-GET: an empty payload that reads.
    async fn post(&mut self, url: &str, payload: Option<&Value>) -> Result<Reply, PlatformError> {
        self.post_accepting(url, payload, None).await
    }

    async fn post_accepting(
        &mut self,
        url: &str,
        payload: Option<&Value>,
        accept: Option<&str>,
    ) -> Result<Reply, PlatformError> {
        let target = allowed_url(url)?;
        let payload = payload.map(|payload| base64url(payload.to_string())).unwrap_or_default();
        let mut attempt = 0;
        loop {
            attempt += 1;
            let nonce = self.fresh_nonce().await?;
            let mut protected = json!({ "alg": "ES256", "nonce": nonce, "url": url });
            match self.account.as_ref() {
                Some(account) => protected["kid"] = json!(account),
                None => protected["jwk"] = self.key.jwk(),
            }
            let protected = base64url(protected.to_string());
            let signature = self.key.sign(format!("{protected}.{payload}").as_bytes())?;
            let body = json!({ "protected": protected, "payload": payload, "signature": signature });

            let mut request = self
                .http
                .post(target.clone())
                .header(header::CONTENT_TYPE, "application/jose+json")
                .body(body.to_string());
            if let Some(accept) = accept {
                request = request.header(header::ACCEPT, accept);
            }
            let response = request
                .send()
                .await
                .map_err(|error| failed("reach the ACME server", format!("Cannot reach the ACME server: {error}")))?;

            let status = response.status();
            self.nonce = replay_nonce(response.headers());
            let location = header_text(response.headers(), header::LOCATION);
            let retry_after = header_text(response.headers(), header::RETRY_AFTER).and_then(|value| value.parse().ok());
            let body = response
                .bytes()
                .await
                .map_err(|error| failed("read ACME reply", format!("The ACME reply was cut short: {error}")))?
                .to_vec();

            if status.is_success() {
                return Ok(Reply { location, retry_after, body });
            }
            // The CA may drop a nonce at any time and hands a new one back.
            let stale_nonce = serde_json::from_slice::<Value>(&body)
                .is_ok_and(|problem| problem["type"].as_str() == Some(BAD_NONCE));
            if stale_nonce && attempt < 3 {
                continue;
            }
            return Err(failed("ask the ACME server", problem_text(status, &body)));
        }
    }

    /// Reads `url` until its `status` is one of `done`.
    async fn wait_for(&mut self, url: &str, done: &[&str], what: &str) -> Result<Value, PlatformError> {
        loop {
            let reply = self.post(url, None).await?;
            let object = reply.json()?;
            let status = object["status"].as_str().unwrap_or_default();
            if done.contains(&status) {
                return Ok(object);
            }
            if status == "invalid" {
                return Err(failed("issue certificate", invalid_text(&object, what)));
            }
            let pause = Duration::from_secs(reply.retry_after.unwrap_or(1).clamp(1, 10));
            if Instant::now() + pause > self.deadline {
                return Err(failed("issue certificate", format!("The ACME server did not finish {what} in time.")));
            }
            tokio::time::sleep(pause).await;
        }
    }
}

fn header_text(headers: &header::HeaderMap, name: header::HeaderName) -> Option<String> {
    headers.get(name).and_then(|value| value.to_str().ok()).map(str::to_string)
}

fn replay_nonce(headers: &header::HeaderMap) -> Option<String> {
    headers.get("replay-nonce").and_then(|value| value.to_str().ok()).map(str::to_string)
}

/// Why an authorization or an order went `invalid`.
fn invalid_text(object: &Value, what: &str) -> String {
    let challenges = object["challenges"].as_array().into_iter().flatten().map(|challenge| &challenge["error"]);
    let reason = std::iter::once(&object["error"]).chain(challenges).find_map(problem_line);
    match (object["identifier"]["value"].as_str(), reason) {
        (Some(name), Some(reason)) => format!("The CA could not validate {name}: {reason}"),
        (None, Some(reason)) => format!("The CA refused {what}: {reason}"),
        (Some(name), None) => format!("The CA could not validate {name}."),
        (None, None) => format!("The CA refused {what}."),
    }
}

fn account_key_path(settings: &AcmeSettings, directory_url: &str) -> PathBuf {
    let digest = Sha256::digest(directory_url.as_bytes());
    let name: String = digest.iter().take(8).map(|byte| format!("{byte:02x}")).collect();
    settings.state_dir.join(format!("account-{name}.pk8"))
}

/// A token is the CA's, and it becomes a file name.
fn safe_token(token: &str) -> bool {
    !token.is_empty() && token.len() <= 256 && token.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'-' || byte == b'_')
}

/// The HTTP-01 answer on disk; gone again when this is dropped.
struct PresentedToken(PathBuf);

impl PresentedToken {
    fn present(directory: &Path, token: &str, key_authorization: &str) -> Result<Self, PlatformError> {
        if !safe_token(token) {
            return Err(failed("answer the challenge", "The CA sent a token that is not a safe file name."));
        }
        fs::create_dir_all(directory).map_err(|error| PlatformError::from_io("create challenge folder", error))?;
        let path = directory.join(token);
        fs::write(&path, key_authorization).map_err(|error| PlatformError::from_io("write challenge token", error))?;
        Ok(Self(path))
    }
}

impl Drop for PresentedToken {
    fn drop(&mut self) {
        let _ = fs::remove_file(&self.0);
    }
}

impl Rfc8555Client {
    pub fn new(settings: AcmeSettings) -> Self {
        Self { settings }
    }

    fn certificate_path(&self, domain: &str) -> PathBuf {
        self.settings.tls_dir.join(format!("{domain}.crt"))
    }

    fn key_path(&self, domain: &str) -> PathBuf {
        self.settings.tls_dir.join(format!("{domain}.key"))
    }

    fn order_path(&self, domain: &str) -> PathBuf {
        self.settings.state_dir.join("orders").join(format!("{domain}.json"))
    }

    fn stored_order(&self, domain: &str) -> Option<StoredOrder> {
        serde_json::from_slice(&fs::read(self.order_path(domain)).ok()?).ok()
    }

    /// Whether this client issued the certificate in `tls_dir` for `domain`.
    /// The folder also holds certificates that came from elsewhere.
    pub fn manages(&self, domain: &str) -> bool {
        valid_name(domain) && self.order_path(domain).is_file()
    }

    /// Deletes the certificate, its key and the renewal record. False when
    /// this client never issued one for `domain`.
    pub fn forget(&self, domain: &str) -> Result<bool, PlatformError> {
        if !self.manages(domain) {
            return Ok(false);
        }
        for path in [self.certificate_path(domain), self.key_path(domain), self.order_path(domain)] {
            match fs::remove_file(&path) {
                Ok(()) => {}
                Err(error) if error.kind() == std::io::ErrorKind::NotFound => {}
                Err(error) => return Err(PlatformError::from_io("remove certificate", error)),
            }
        }
        Ok(true)
    }

    fn block_on<T>(&self, future: impl Future<Output = Result<T, PlatformError>>) -> Result<T, PlatformError> {
        let _one = ONE_AT_A_TIME.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        tokio::runtime::Builder::new_current_thread()
            .enable_all()
            .build()
            .map_err(|error| failed("start ACME client", error.to_string()))?
            .block_on(future)
    }

    async fn issue_checked(&self, request: &CertRequest) -> Result<IssuedCert, PlatformError> {
        let mut session = Session::open(&self.settings, &request.directory).await?;

        let identifiers: Vec<Value> =
            request.domains.iter().map(|name| json!({ "type": "dns", "value": name })).collect();
        let new_order = session.directory.new_order.clone();
        let reply = session.post(&new_order, Some(&json!({ "identifiers": identifiers }))).await?;
        let order_url =
            reply.location.clone().ok_or_else(|| failed("issue certificate", "The ACME server did not name the order."))?;
        let order = reply.json()?;

        for authorization_url in order["authorizations"].as_array().into_iter().flatten().filter_map(Value::as_str) {
            self.authorize(&mut session, authorization_url).await?;
        }
        let order = session.wait_for(&order_url, &["ready", "valid"], "the order").await?;

        // A new key for every certificate, as certbot does.
        let key = KeyPair::generate().map_err(|error| failed("issue certificate", error.to_string()))?;
        let order = if order["status"] == "valid" {
            order
        } else {
            let mut params = CertificateParams::new(request.domains.clone())
                .map_err(|error| failed("issue certificate", error.to_string()))?;
            params.distinguished_name = DistinguishedName::new();
            let csr = params.serialize_request(&key).map_err(|error| failed("issue certificate", error.to_string()))?;
            let finalize = order["finalize"]
                .as_str()
                .ok_or_else(|| failed("issue certificate", "The order has no finalize URL."))?
                .to_string();
            session.post(&finalize, Some(&json!({ "csr": base64url(csr.der()) }))).await?;
            session.wait_for(&order_url, &["valid"], "the order").await?
        };

        let certificate_url = order["certificate"]
            .as_str()
            .ok_or_else(|| failed("issue certificate", "The order is valid but has no certificate."))?
            .to_string();
        let chain =
            session.post_accepting(&certificate_url, None, Some("application/pem-certificate-chain")).await?.body;

        let domain = &request.domains[0];
        let info = CertInfo::from_pem(&chain)
            .filter(|info| request.domains.iter().all(|name| info.covers(name)))
            .ok_or_else(|| failed("issue certificate", "The CA sent a certificate that is not for the names asked."))?;

        fs::create_dir_all(&self.settings.tls_dir)
            .map_err(|error| PlatformError::from_io("create certificate folder", error))?;
        let (certificate_path, private_key_path) = (self.certificate_path(domain), self.key_path(domain));
        write_private(&private_key_path, key.serialize_pem().as_bytes())?;
        write_public(&certificate_path, &chain)?;

        let stored = StoredOrder {
            domains: request.domains.clone(),
            directory: request.directory.clone(),
            challenge: request.challenge.as_str().to_string(),
        };
        platform::files().create_dir_private(&self.settings.state_dir.join("orders"))?;
        write_private(&self.order_path(domain), serde_json::to_vec_pretty(&stored).unwrap_or_default().as_slice())?;

        Ok(IssuedCert { domain: domain.clone(), certificate_path, private_key_path, not_after: info.not_after })
    }

    /// Proves one name. The token stays on disk only while the CA looks.
    async fn authorize(&self, session: &mut Session, url: &str) -> Result<(), PlatformError> {
        let authorization = session.post(url, None).await?.json()?;
        if authorization["status"] == "valid" {
            return Ok(());
        }
        let name = authorization["identifier"]["value"].as_str().unwrap_or("the name").to_string();
        let challenge = authorization["challenges"]
            .as_array()
            .into_iter()
            .flatten()
            .find(|challenge| challenge["type"] == "http-01")
            .ok_or_else(|| failed("issue certificate", format!("The CA offers no HTTP-01 challenge for {name}.")))?;
        let (Some(token), Some(challenge_url)) = (challenge["token"].as_str(), challenge["url"].as_str()) else {
            return Err(failed("issue certificate", format!("The CA sent a broken challenge for {name}.")));
        };

        let key_authorization = format!("{token}.{}", session.key.thumbprint());
        let _token = PresentedToken::present(&self.settings.challenge_dir, token, &key_authorization)?;
        if challenge["status"] == "pending" {
            session.post(challenge_url, Some(&json!({}))).await?;
        }
        session.wait_for(url, &["valid"], "the name").await?;
        Ok(())
    }

    fn orders(&self) -> Vec<String> {
        let mut domains: Vec<String> = fs::read_dir(self.settings.state_dir.join("orders"))
            .into_iter()
            .flatten()
            .flatten()
            .filter_map(|entry| entry.file_name().to_str()?.strip_suffix(".json").map(str::to_string))
            .filter(|domain| valid_name(domain))
            .collect();
        domains.sort();
        domains
    }
}

impl AcmeClient for Rfc8555Client {
    fn issue(&self, request: &CertRequest) -> Result<IssuedCert, PlatformError> {
        let request = checked(request)?;
        self.block_on(self.issue_checked(&request))
    }

    fn renew_due(&self) -> Result<Vec<RenewResult>, PlatformError> {
        let now = unix_now();
        let mut results = Vec::new();
        for domain in self.orders() {
            let fresh = CertInfo::read(&self.certificate_path(&domain))
                .is_some_and(|info| info.outlives(now, self.settings.renew_before));
            if fresh {
                continue;
            }
            let Some(stored) = self.stored_order(&domain) else { continue };
            let request = CertRequest { domains: stored.domains, challenge: Challenge::Http01, directory: stored.directory };
            let outcome = self.issue(&request);
            results.push(RenewResult {
                domain,
                renewed: outcome.is_ok(),
                error: outcome.err().map(|error| error.to_string()),
            });
        }
        Ok(results)
    }

    fn revoke(&self, domain: &str) -> Result<(), PlatformError> {
        let domain = domain.trim().to_ascii_lowercase();
        let not_ours = || PlatformError::NotFound { what: format!("No ACME certificate for {domain}.") };
        if !valid_name(&domain) {
            return Err(not_ours());
        }
        let stored = self.stored_order(&domain).ok_or_else(not_ours)?;
        let info = CertInfo::read(&self.certificate_path(&domain)).ok_or_else(not_ours)?;
        self.block_on(async {
            let mut session = Session::open(&self.settings, &stored.directory).await?;
            let revoke = session
                .directory
                .revoke_cert
                .clone()
                .ok_or_else(|| failed("revoke certificate", "This ACME server does not revoke certificates."))?;
            session.post(&revoke, Some(&json!({ "certificate": base64url(info.der()) }))).await?;
            Ok(())
        })
    }
}
