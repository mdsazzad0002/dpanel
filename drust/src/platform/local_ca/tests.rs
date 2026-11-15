use std::sync::Arc;

use rustls::{
    RootCertStore,
    client::{WebPkiServerVerifier, danger::ServerCertVerifier},
    crypto::ring::default_provider,
    pki_types::{CertificateDer, ServerName, UnixTime},
};

use super::*;

fn ca() -> (tempfile::TempDir, LocalCa) {
    let dir = tempfile::tempdir().unwrap();
    let ca = LocalCa::new(dir.path().join("local-ca"), dir.path().join("tls"));
    (dir, ca)
}

fn ders(pem: &str) -> Vec<CertificateDer<'static>> {
    rustls_pemfile::certs(&mut pem.as_bytes()).map(Result::unwrap).collect()
}

/// What a browser does: the chain must lead to the trusted CA and name
/// `host`, within the CA's name constraints.
fn verify(ca: &LocalCa, chain_pem: &str, host: &str) -> Result<(), rustls::Error> {
    let mut roots = RootCertStore::empty();
    roots.add(CertificateDer::from(ca.certificate_der().unwrap())).unwrap();
    let verifier = WebPkiServerVerifier::builder_with_provider(Arc::new(roots), Arc::new(default_provider()))
        .build()
        .unwrap();
    let chain = ders(chain_pem);
    let name = ServerName::try_from(host.to_string()).unwrap();
    verifier.verify_server_cert(&chain[0], &chain[1..], &name, &[], UnixTime::now()).map(drop)
}

#[test]
fn a_browser_trusting_the_ca_accepts_its_certificates() {
    let (_dir, ca) = ca();
    let issued = ca.issue_for(&["shop.test".into(), "www.shop.test".into()]).unwrap();

    let chain = fs::read_to_string(&issued.certificate_path).unwrap();
    assert_eq!(ders(&chain).len(), 2, "the certificate, then the CA's");
    verify(&ca, &chain, "shop.test").unwrap();
    verify(&ca, &chain, "www.shop.test").unwrap();
    assert!(verify(&ca, &chain, "other.test").is_err());

    let info = crate::platform::acme::CertInfo::read(&issued.certificate_path).unwrap();
    assert!(info.covers("shop.test") && info.covers("www.shop.test"));
    let days = (info.not_after - OffsetDateTime::now_utc().unix_timestamp()) / 86_400;
    assert!((396..=397).contains(&days), "{days} days");
    assert!(issued.private_key_path.is_file());
    assert_eq!(issued.certificate_path, ca.tls_dir.join("shop.test.crt"));
}

#[test]
fn the_ca_is_made_once_and_kept() {
    let (_dir, ca) = ca();
    let first = ca.ensure().unwrap();
    ca.issue_for(&["a.test".into()]).unwrap();
    assert_eq!(ca.ensure().unwrap(), first);
    assert!(ca.key_path().is_file());
}

#[test]
fn drust_refuses_names_outside_the_local_ones() {
    let (_dir, ca) = ca();
    for name in ["google.com", "shop.test.com", "*.shop.test", "testx"] {
        let error = ca.issue_for(&[name.to_string()]).unwrap_err().to_string();
        assert!(error.contains("not a local name"), "{name}: {error}");
    }
    assert!(ca.issue_for(&["shop.test".into(), "bank.com".into()]).is_err());
    assert!(!ca.tls_dir.join("shop.test.crt").exists());
}

/// The point of the name constraints: even with the CA's key in hand, a
/// certificate for a real site does not pass in a browser.
#[test]
fn a_stolen_key_cannot_vouch_for_a_real_site() {
    let (_dir, ca) = ca();
    ca.ensure().unwrap();
    let issuer = ca.issuer().unwrap();
    let key = KeyPair::generate().unwrap();
    let mut params = CertificateParams::new(vec!["google.com".to_string()]).unwrap();
    params.extended_key_usages = vec![ExtendedKeyUsagePurpose::ServerAuth];
    params.use_authority_key_identifier_extension = true;
    let forged = params.signed_by(&key, &issuer).unwrap();
    let chain = format!("{}{}", forged.pem(), ca.ensure().unwrap());

    let error = verify(&ca, &chain, "google.com").unwrap_err();
    assert!(format!("{error:?}").contains("NameConstraint"), "{error:?}");
}

#[test]
fn local_names_are_the_reserved_ones() {
    for name in ["shop.test", "a.b.test", "localhost", "app.localhost", "x.local", "SHOP.TEST", "shop.test."] {
        assert!(is_local_name(name), "{name}");
    }
    for name in ["shop.com", "test.com", "mytest", "*.shop.test", "", "localhost.com"] {
        assert!(!is_local_name(name), "{name}");
    }
}

#[test]
fn the_acme_client_side_issues_and_revokes() {
    let (_dir, ca) = ca();
    let request = CertRequest {
        domains: vec!["shop.test".into()],
        challenge: Challenge::Http01,
        directory: String::new(),
        dns: None,
    };
    let issued = AcmeClient::issue(&ca, &request).unwrap();
    assert!(issued.certificate_path.is_file());
    AcmeClient::revoke(&ca, "shop.test").unwrap();
    assert!(!issued.certificate_path.exists() && !issued.private_key_path.exists());
    AcmeClient::revoke(&ca, "shop.test").unwrap();

    let wildcard = CertRequest { domains: vec!["*.shop.test".into()], challenge: Challenge::Dns01, ..request };
    assert!(AcmeClient::issue(&ca, &wildcard).is_err());
}
