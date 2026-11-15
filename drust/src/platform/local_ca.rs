//! The local profile's certificate authority (F6, spec §6.12): `ssl/ensure`
//! on a PC issues from here instead of Let's Encrypt, so
//! `https://mysite.test` opens without a warning once the CA is trusted.
//!
//! The CA's key sits in the user's own profile, where anything running as
//! the user can read it. So the CA certificate carries name constraints: it
//! can only vouch for local names (`.test`, `.localhost` and the like), and
//! browsers refuse anything else it signs. A stolen key cannot forge a
//! real site.
//!
//! Trusting it goes to the user's own certificate store, which needs no
//! administrator (Windows asks the user to confirm). Firefox keeps its own
//! store and needs `security.enterprise_roots.enabled`.

use std::{fs, path::PathBuf};

use rcgen::{
    BasicConstraints, CertificateParams, DistinguishedName, DnType, ExtendedKeyUsagePurpose, GeneralSubtree, IsCa,
    Issuer, KeyPair, KeyUsagePurpose, NameConstraints,
};
use time::{Duration, OffsetDateTime};

use super::{
    PlatformError,
    acme::{AcmeClient, CertRequest, Challenge, IssuedCert, RenewResult, write_private, write_public},
    paths::Paths,
};

/// Names the CA may sign: these and everything under them (RFC 5280's
/// dNSName constraint). Reserved for local use, so none is a real site.
pub const LOCAL_SUFFIXES: &[&str] = &["test", "localhost", "local", "internal", "example", "invalid"];

const CA_YEARS: i64 = 10;
/// Under the 398 days browsers allow, so no browser objects to the length.
const LEAF_DAYS: i64 = 397;

fn failed(action: &str, detail: impl ToString) -> PlatformError {
    PlatformError::Failed { action: action.to_string(), detail: detail.to_string() }
}

/// Whether `name` is one the CA may sign.
pub fn is_local_name(name: &str) -> bool {
    let name = name.trim_end_matches('.').to_ascii_lowercase();
    !name.is_empty()
        && !name.starts_with("*.")
        && LOCAL_SUFFIXES.iter().any(|suffix| name == *suffix || name.ends_with(&format!(".{suffix}")))
}

#[derive(Debug, Clone)]
pub struct LocalCa {
    /// `ca.crt` and `ca.key`.
    dir: PathBuf,
    /// Where issued certificates go, as the gateway expects.
    tls_dir: PathBuf,
}

impl LocalCa {
    pub fn new(dir: PathBuf, tls_dir: PathBuf) -> Self {
        Self { dir, tls_dir }
    }

    pub fn for_system(paths: &Paths) -> Self {
        Self::new(paths.local_ca_dir(), paths.tls_dir())
    }

    pub fn certificate_path(&self) -> PathBuf {
        self.dir.join("ca.crt")
    }

    fn key_path(&self) -> PathBuf {
        self.dir.join("ca.key")
    }

    /// The CA's certificate in PEM, made the first time it is needed.
    pub fn ensure(&self) -> Result<String, PlatformError> {
        if let (Ok(certificate), true) = (fs::read_to_string(self.certificate_path()), self.key_path().is_file()) {
            return Ok(certificate);
        }
        fs::create_dir_all(&self.dir).map_err(|error| PlatformError::from_io("create the local CA folder", error))?;
        let key = KeyPair::generate().map_err(|error| failed("make the local CA key", error))?;
        let now = OffsetDateTime::now_utc();
        let mut params = ca_params(&hostname());
        params.not_before = now - Duration::days(1);
        params.not_after = now + Duration::days(365 * CA_YEARS);
        let certificate = params.self_signed(&key).map_err(|error| failed("make the local CA", error))?;
        write_private(&self.key_path(), key.serialize_pem().as_bytes())?;
        write_public(&self.certificate_path(), certificate.pem().as_bytes())?;
        Ok(certificate.pem())
    }

    /// The CA's certificate in DER, for the OS's certificate store.
    pub fn certificate_der(&self) -> Result<Vec<u8>, PlatformError> {
        let pem = self.ensure()?;
        let (_, parsed) =
            x509_parser::pem::parse_x509_pem(pem.as_bytes()).map_err(|error| failed("read the local CA", error))?;
        Ok(parsed.contents)
    }

    fn issuer(&self) -> Result<Issuer<'static, KeyPair>, PlatformError> {
        let pem = self.ensure()?;
        let key = fs::read_to_string(self.key_path()).map_err(|error| PlatformError::from_io("read the local CA key", error))?;
        let key = KeyPair::from_pem(&key).map_err(|error| failed("read the local CA key", error))?;
        Issuer::from_ca_cert_pem(&pem, key).map_err(|error| failed("read the local CA", error))
    }

    /// Signs a certificate for `domains` (the first names the files) into
    /// `tls_dir/<first>.crt` and `.key`: the certificate, then the CA's.
    pub fn issue_for(&self, domains: &[String]) -> Result<IssuedCert, PlatformError> {
        let Some(first) = domains.first() else {
            return Err(failed("issue a local certificate", "no domain"));
        };
        if let Some(outside) = domains.iter().find(|name| !is_local_name(name)) {
            return Err(failed(
                "issue a local certificate",
                format!(
                    "{outside} is not a local name; the local CA only signs names under .{}",
                    LOCAL_SUFFIXES.join(", .")
                ),
            ));
        }
        let issuer = self.issuer()?;
        let key = KeyPair::generate().map_err(|error| failed("make a certificate key", error))?;
        let mut params =
            CertificateParams::new(domains.to_vec()).map_err(|error| failed("issue a local certificate", error))?;
        params.distinguished_name = DistinguishedName::new();
        params.distinguished_name.push(DnType::CommonName, first.clone());
        params.is_ca = IsCa::ExplicitNoCa;
        params.key_usages = vec![KeyUsagePurpose::DigitalSignature, KeyUsagePurpose::KeyEncipherment];
        params.extended_key_usages = vec![ExtendedKeyUsagePurpose::ServerAuth];
        params.use_authority_key_identifier_extension = true;
        let now = OffsetDateTime::now_utc();
        params.not_before = now - Duration::hours(1);
        params.not_after = now + Duration::days(LEAF_DAYS);
        let certificate = params.signed_by(&key, &issuer).map_err(|error| failed("issue a local certificate", error))?;

        fs::create_dir_all(&self.tls_dir).map_err(|error| PlatformError::from_io("create the certificate folder", error))?;
        let certificate_path = self.tls_dir.join(format!("{first}.crt"));
        let private_key_path = self.tls_dir.join(format!("{first}.key"));
        let chain = format!("{}{}", certificate.pem(), self.ensure()?);
        write_private(&private_key_path, key.serialize_pem().as_bytes())?;
        write_public(&certificate_path, chain.as_bytes())?;
        Ok(IssuedCert {
            domain: first.clone(),
            certificate_path,
            private_key_path,
            not_after: params.not_after.unix_timestamp(),
        })
    }
}

fn ca_params(host: &str) -> CertificateParams {
    let mut params = CertificateParams::default();
    params.distinguished_name = DistinguishedName::new();
    params.distinguished_name.push(DnType::CommonName, format!("dPanel Local CA ({host})"));
    params.distinguished_name.push(DnType::OrganizationName, "dPanel local development");
    params.is_ca = IsCa::Ca(BasicConstraints::Constrained(0));
    params.key_usages = vec![KeyUsagePurpose::KeyCertSign, KeyUsagePurpose::CrlSign, KeyUsagePurpose::DigitalSignature];
    params.name_constraints = Some(NameConstraints {
        permitted_subtrees: LOCAL_SUFFIXES.iter().map(|suffix| GeneralSubtree::DnsName((*suffix).into())).collect(),
        excluded_subtrees: Vec::new(),
    });
    params
}

/// Tells one PC's CA from another's in the user's certificate list.
fn hostname() -> String {
    ["COMPUTERNAME", "HOSTNAME"]
        .iter()
        .find_map(|name| std::env::var(name).ok())
        .filter(|name| !name.is_empty())
        .unwrap_or_else(|| "this computer".into())
}

/// Issues and nothing else: renewal happens in `ssl/ensure`, which reissues
/// what is within its renew window, and there is no one to revoke with.
impl AcmeClient for LocalCa {
    fn issue(&self, request: &CertRequest) -> Result<IssuedCert, PlatformError> {
        if request.challenge != Challenge::Http01 || request.domains.iter().any(|name| name.starts_with("*.")) {
            return Err(failed("issue a local certificate", "the local CA does not issue wildcards"));
        }
        self.issue_for(&request.domains)
    }

    fn renew_due(&self) -> Result<Vec<RenewResult>, PlatformError> {
        Ok(Vec::new())
    }

    /// Deletes the certificate; a browser never asks the local CA whether
    /// it still stands.
    fn revoke(&self, domain: &str) -> Result<(), PlatformError> {
        for extension in ["crt", "key"] {
            match fs::remove_file(self.tls_dir.join(format!("{domain}.{extension}"))) {
                Err(error) if error.kind() != std::io::ErrorKind::NotFound => {
                    return Err(PlatformError::from_io("remove the certificate", error));
                }
                _ => {}
            }
        }
        Ok(())
    }
}

/// Puts the CA in the user's trusted roots. Windows shows its own
/// confirmation first; `false` means the user said no.
pub fn trust(ca: &LocalCa) -> Result<bool, PlatformError> {
    let der = ca.certificate_der()?;
    #[cfg(windows)]
    {
        super::windows::cert_store::add_user_root(&der).map_err(|error| failed("trust the local CA", error))
    }
    #[cfg(not(windows))]
    {
        let _ = der;
        Err(PlatformError::Unsupported { capability: super::capabilities::Capability::Ssl })
    }
}

/// Removes it again; `false` when it was not there.
pub fn untrust(ca: &LocalCa) -> Result<bool, PlatformError> {
    let der = ca.certificate_der()?;
    #[cfg(windows)]
    {
        super::windows::cert_store::remove_user_root(&der).map_err(|error| failed("stop trusting the local CA", error))
    }
    #[cfg(not(windows))]
    {
        let _ = der;
        Err(PlatformError::Unsupported { capability: super::capabilities::Capability::Ssl })
    }
}

/// Whether the user's trusted roots hold this CA. `false` before it exists.
pub fn trusted(ca: &LocalCa) -> Result<bool, PlatformError> {
    if !ca.certificate_path().is_file() {
        return Ok(false);
    }
    let der = ca.certificate_der()?;
    #[cfg(windows)]
    {
        super::windows::cert_store::has_user_root(&der).map_err(|error| failed("look up the local CA", error))
    }
    #[cfg(not(windows))]
    {
        let _ = der;
        Err(PlatformError::Unsupported { capability: super::capabilities::Capability::Ssl })
    }
}

#[cfg(test)]
mod tests;
