//! A certificate the customer brings: task M5. It is checked the way the
//! gateway will load it, kept in a folder no engine writes to, and never
//! renewed or replaced until it is uninstalled or a reinstall is forced.

use std::{
    fs,
    io::BufReader,
    path::{Path, PathBuf},
    sync::Arc,
};

use rustls::sign::CertifiedKey;

use crate::platform::{
    self,
    acme::{CertInfo, custom_certificate_files, uploaded_certificate, write_private, write_public},
};

const MAX_CERTIFICATE_BYTES: usize = 64 * 1024;
const MAX_KEY_BYTES: usize = 16 * 1024;
const MAX_CHAIN: usize = 10;

pub(super) struct Custom {
    pub certificate_path: PathBuf,
    pub private_key_path: PathBuf,
    pub info: CertInfo,
}

/// The uploaded certificate serving `domain`, if there is one: the same
/// choice the gateway makes.
pub(super) fn current(tls_dir: &Path, domain: &str) -> Option<Custom> {
    let (certificate_path, private_key_path) = uploaded_certificate(tls_dir, domain)?;
    let info = CertInfo::read(&certificate_path).filter(|_| private_key_path.is_file())?;
    Some(Custom { certificate_path, private_key_path, info })
}

/// What an upload must pass before anything is written: a chain that starts
/// with a certificate for `domain` that has not expired, and its own key.
pub(super) fn check(domain: &str, certificate: &str, private_key: &str, now: i64) -> Result<CertInfo, String> {
    if certificate.len() > MAX_CERTIFICATE_BYTES || private_key.len() > MAX_KEY_BYTES {
        return Err("The certificate or the key is too large.".into());
    }
    let chain = rustls_pemfile::certs(&mut BufReader::new(certificate.as_bytes()))
        .collect::<Result<Vec<_>, _>>()
        .map_err(|_| "The certificate is not valid PEM.".to_string())?;
    if chain.is_empty() {
        return Err("No certificate found. Paste the PEM text, starting with -----BEGIN CERTIFICATE-----.".into());
    }
    if chain.len() > MAX_CHAIN {
        return Err(format!("The chain has more than {MAX_CHAIN} certificates."));
    }
    let info = CertInfo::from_pem(certificate.as_bytes()).ok_or("The certificate cannot be read.")?;
    if !info.covers(domain) {
        let names = if info.names.is_empty() { "no domain".to_string() } else { info.names.join(", ") };
        return Err(format!(
            "The certificate is for {names}, not for {domain}. The domain's own certificate has to come first, before the CA's."
        ));
    }
    if info.not_after <= now {
        return Err("The certificate has already expired.".into());
    }

    let key = rustls_pemfile::private_key(&mut BufReader::new(private_key.as_bytes()))
        .map_err(|_| "The private key is not valid PEM.".to_string())?
        .ok_or("No private key found. Paste the PEM text of an unencrypted key.")?;
    let signing_key = rustls::crypto::ring::sign::any_supported_type(&key)
        .map_err(|_| "This kind of private key is not supported. Use an RSA, ECDSA or Ed25519 key without a password.".to_string())?;
    CertifiedKey::new(chain, Arc::clone(&signing_key))
        .keys_match()
        .map_err(|_| "The private key does not belong to this certificate.".to_string())?;
    Ok(info)
}

pub(super) fn install(
    tls_dir: &Path,
    domain: &str,
    certificate: &str,
    private_key: &str,
    now: i64,
) -> Result<Custom, String> {
    let info = check(domain, certificate, private_key, now)?;
    let (certificate_path, private_key_path) = custom_certificate_files(tls_dir, domain);
    let folder = certificate_path.parent().unwrap_or(tls_dir);
    platform::files().create_dir_private(folder).map_err(|error| format!("cannot create {}: {error}", folder.display()))?;
    write_private(&private_key_path, private_key.as_bytes())
        .and_then(|()| write_public(&certificate_path, certificate.as_bytes()))
        .map_err(|error| format!("cannot store the certificate: {error}"))?;
    Ok(Custom { certificate_path, private_key_path, info })
}

/// False when there was nothing uploaded for `domain`.
pub(super) fn remove(tls_dir: &Path, domain: &str) -> Result<bool, String> {
    let (certificate_path, private_key_path) = custom_certificate_files(tls_dir, domain);
    let mut removed = false;
    for path in [certificate_path, private_key_path] {
        match fs::remove_file(&path) {
            Ok(()) => removed = true,
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => {}
            Err(error) => return Err(format!("failed to remove {}: {error}", path.display())),
        }
    }
    Ok(removed)
}

#[cfg(test)]
mod tests {
    use rcgen::{CertificateParams, KeyPair, PKCS_ECDSA_P384_SHA384, PKCS_ED25519};

    use super::*;

    const NOW: i64 = 1_800_000_000;
    const DAY: i64 = 86_400;

    fn certificate(key: &KeyPair, names: &[&str], days: i64) -> String {
        let mut params = CertificateParams::new(names.iter().map(|name| name.to_string()).collect::<Vec<_>>()).unwrap();
        params.not_after = time::OffsetDateTime::from_unix_timestamp(NOW + days * DAY).unwrap();
        params.self_signed(key).unwrap().pem()
    }

    #[test]
    fn a_certificate_with_its_own_key_is_accepted_whatever_the_key_type() {
        for key in [
            KeyPair::generate().unwrap(),
            KeyPair::generate_for(&PKCS_ECDSA_P384_SHA384).unwrap(),
            KeyPair::generate_for(&PKCS_ED25519).unwrap(),
        ] {
            let info = check("example.com", &certificate(&key, &["example.com"], 90), &key.serialize_pem(), NOW).unwrap();
            assert_eq!(info.not_after, NOW + 90 * DAY);
        }
    }

    #[test]
    fn a_wildcard_and_a_chain_with_the_ca_after_the_leaf_are_accepted() {
        let key = KeyPair::generate().unwrap();
        let ca = certificate(&KeyPair::generate().unwrap(), &["ca.example"], 900);
        let chain = format!("{}{ca}", certificate(&key, &["*.example.com", "example.com"], 90));

        assert!(check("example.com", &chain, &key.serialize_pem(), NOW).is_ok());
        assert!(check("shop.example.com", &chain, &key.serialize_pem(), NOW).is_ok());
    }

    #[test]
    fn what_cannot_serve_the_domain_is_refused_with_the_reason() {
        let key = KeyPair::generate().unwrap();
        let key_pem = key.serialize_pem();
        let good = certificate(&key, &["example.com"], 90);
        let refused = |certificate: &str, key: &str| check("example.com", certificate, key, NOW).unwrap_err();

        let other_key = KeyPair::generate().unwrap().serialize_pem();
        assert!(refused(&good, &other_key).contains("does not belong"));
        assert!(refused(&certificate(&key, &["other.com", "www.other.com"], 90), &key_pem).contains("is for other.com, www.other.com, not for example.com"));
        assert!(refused(&certificate(&key, &["example.com"], 0), &key_pem).contains("expired"));
        assert!(refused(&certificate(&key, &["example.com"], -5), &key_pem).contains("expired"));
        // The CA's certificate first: the leaf must lead the chain.
        let ca_first = format!("{}{good}", certificate(&KeyPair::generate().unwrap(), &["ca.example"], 900));
        assert!(refused(&ca_first, &key_pem).contains("has to come first"));
        assert!(refused("hello", &key_pem).contains("No certificate found"));
        assert!(refused(&good, "hello").contains("No private key found"));
        assert!(refused(&good, &good).contains("No private key found"));
        assert!(refused(&good.repeat(11), &key_pem).contains("more than 10"));
        assert!(refused(&"x".repeat(MAX_CERTIFICATE_BYTES + 1), &key_pem).contains("too large"));
    }

    #[test]
    fn an_upload_is_stored_apart_and_removed_whole() {
        let folder = tempfile::tempdir().unwrap();
        let key = KeyPair::generate().unwrap();
        let pem = certificate(&key, &["example.com"], 90);
        assert!(current(folder.path(), "example.com").is_none());

        let stored = install(folder.path(), "example.com", &pem, &key.serialize_pem(), NOW).unwrap();

        assert_eq!(stored.certificate_path, folder.path().join("custom").join("example.com.crt"));
        assert_eq!(fs::read_to_string(&stored.certificate_path).unwrap(), pem);
        assert_eq!(fs::read_to_string(&stored.private_key_path).unwrap(), key.serialize_pem());
        if let Some(mode) = platform::files().mode_bits(&fs::metadata(&stored.private_key_path).unwrap()) {
            assert_eq!(mode & 0o777, 0o600);
        }
        assert_eq!(current(folder.path(), "example.com").unwrap().info, stored.info);

        // A refused upload leaves the one in place alone.
        let wrong = KeyPair::generate().unwrap().serialize_pem();
        assert!(install(folder.path(), "example.com", &pem, &wrong, NOW).is_err());
        assert_eq!(fs::read_to_string(&stored.private_key_path).unwrap(), key.serialize_pem());

        assert!(remove(folder.path(), "example.com").unwrap());
        assert!(current(folder.path(), "example.com").is_none());
        assert!(!remove(folder.path(), "example.com").unwrap());
    }
}
