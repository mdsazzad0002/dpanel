//! `AcmeClient`: spec section 6.12. Certificates from an ACME server (Let's
//! Encrypt by default) without certbot, so the same code issues on every OS.
//!
//! Linux keeps certbot unless `DRUST_SSL_ENGINE=acme`. Certificates land in
//! `Paths::tls_dir()` as `<domain>.crt` and `<domain>.key`, where the gateway
//! already looks. The HTTP-01 token goes to one folder the gateway answers
//! from for every hostname, never into a site's own folder. A DNS-01 answer
//! (wildcards, task M4) is a TXT record, written by a `Dns01Solver` the
//! caller hands in, because only the caller knows where the zone lives.

mod client;
#[cfg(test)]
mod tests;

use std::{
    env, fmt, fs,
    path::{Path, PathBuf},
    sync::Arc,
    time::Duration,
};

use futures_util::future::BoxFuture;

use x509_parser::{extensions::GeneralName, pem::parse_x509_pem};

use super::{Os, PlatformError, Profile, paths::Paths};

pub use client::Rfc8555Client;
pub(crate) use client::{write_private, write_public};

pub const LETS_ENCRYPT: &str = "https://acme-v02.api.letsencrypt.org/directory";

/// Where certbot keeps the certificates it issued. Linux only.
pub const CERTBOT_LIVE_DIR: &str = "/etc/letsencrypt/live";

/// What issues this machine's certificates.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum SslEngine {
    Certbot,
    Acme,
    LocalCa,
}

impl SslEngine {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Certbot => "certbot",
            Self::Acme => "acme",
            Self::LocalCa => "local_ca",
        }
    }

    /// `wanted` is `DRUST_SSL_ENGINE`. Only a Linux server has a choice:
    /// certbot unless it asks for `acme`. Other servers have no certbot.
    pub fn choose(os: Os, profile: Profile, wanted: Option<&str>) -> Self {
        match (profile, os) {
            (Profile::Local, _) => Self::LocalCa,
            (Profile::Server, Os::Linux) => {
                if wanted.is_some_and(|value| value.trim().eq_ignore_ascii_case("acme")) {
                    Self::Acme
                } else {
                    Self::Certbot
                }
            }
            (Profile::Server, _) => Self::Acme,
        }
    }
}

/// The engine of this machine. The gateway and the API read the same
/// environment file, so they always agree.
pub fn engine() -> SslEngine {
    let platform = super::current();
    SslEngine::choose(platform.os(), platform.profile(), env::var("DRUST_SSL_ENGINE").ok().as_deref())
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Challenge {
    Http01,
    /// A TXT record at `_acme-challenge.<name>`. Wildcards need it (M4).
    Dns01,
}

impl Challenge {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Http01 => "http-01",
            Self::Dns01 => "dns-01",
        }
    }
}

/// Puts DNS-01 answers where the CA looks: TXT records in the zone.
pub trait Dns01Solver: Send + Sync {
    /// Adds `value` to the TXT records at `name`, keeping any already there,
    /// and returns once the zone's nameservers answer with it.
    fn publish<'a>(&'a self, name: &'a str, value: &'a str) -> BoxFuture<'a, Result<(), PlatformError>>;
    /// Takes `value` away again; other TXT records at `name` stay.
    fn withdraw<'a>(&'a self, name: &'a str, value: &'a str) -> BoxFuture<'a, Result<(), PlatformError>>;
}

#[derive(Clone)]
pub struct CertRequest {
    /// The first name is the certificate's file name, so never a wildcard.
    pub domains: Vec<String>,
    pub challenge: Challenge,
    /// The ACME directory URL.
    pub directory: String,
    /// Where DNS-01 answers go. Needed for `Dns01`, unused for `Http01`.
    pub dns: Option<Arc<dyn Dns01Solver>>,
}

impl fmt::Debug for CertRequest {
    fn fmt(&self, formatter: &mut fmt::Formatter<'_>) -> fmt::Result {
        formatter
            .debug_struct("CertRequest")
            .field("domains", &self.domains)
            .field("challenge", &self.challenge)
            .field("directory", &self.directory)
            .field("dns", &self.dns.is_some())
            .finish()
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct IssuedCert {
    pub domain: String,
    pub certificate_path: PathBuf,
    pub private_key_path: PathBuf,
    /// Seconds since the Unix epoch.
    pub not_after: i64,
}

/// One certificate that was due. `error` is why it could not be renewed.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RenewResult {
    pub domain: String,
    pub renewed: bool,
    pub error: Option<String>,
}

pub trait AcmeClient: Send + Sync {
    fn issue(&self, request: &CertRequest) -> Result<IssuedCert, PlatformError>;
    /// Renews every certificate this client issued that is close to expiry.
    fn renew_due(&self) -> Result<Vec<RenewResult>, PlatformError>;
    fn revoke(&self, domain: &str) -> Result<(), PlatformError>;
}

/// Where the client keeps things. Separate from `Paths` so a test can point
/// it at a temporary folder.
#[derive(Debug, Clone)]
pub struct AcmeSettings {
    pub tls_dir: PathBuf,
    /// Account keys and what was issued. Owner-only.
    pub state_dir: PathBuf,
    /// The gateway answers `/.well-known/acme-challenge/<token>` from here.
    pub challenge_dir: PathBuf,
    /// An address the CA may write to about expiry. Optional.
    pub contact: Option<String>,
    /// Extra roots to trust when talking to the CA: a private CA.
    pub ca_bundle: Option<PathBuf>,
    pub renew_before: Duration,
    /// The longest one certificate may take.
    pub timeout: Duration,
}

impl AcmeSettings {
    pub fn for_system(paths: &Paths, env: impl Fn(&str) -> Option<String>) -> Self {
        let filled = |name: &str| env(name).map(|value| value.trim().to_string()).filter(|value| !value.is_empty());
        Self {
            tls_dir: paths.tls_dir(),
            state_dir: paths.state_dir().join("acme"),
            challenge_dir: challenge_dir_in(paths),
            contact: filled("DRUST_ACME_EMAIL"),
            ca_bundle: filled("DRUST_ACME_CA_BUNDLE").map(PathBuf::from),
            renew_before: Duration::from_secs(30 * 86_400),
            // Waiting for a TXT record to reach the nameservers does not count.
            timeout: Duration::from_secs(180),
        }
    }
}

fn challenge_dir_in(paths: &Paths) -> PathBuf {
    paths.data_dir().join("acme").join(".well-known").join("acme-challenge")
}

/// The one folder HTTP-01 tokens are served from, whatever the hostname.
pub fn challenge_dir() -> PathBuf {
    challenge_dir_in(super::paths())
}

/// `DRUST_ACME_DIRECTORY`, or Let's Encrypt.
pub fn directory_url() -> String {
    env::var("DRUST_ACME_DIRECTORY")
        .ok()
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| LETS_ENCRYPT.to_string())
}

/// A name a public CA can issue for: letters, digits and `-`, no wildcard,
/// no address. Also the shape of a zone name.
pub fn valid_domain_name(name: &str) -> bool {
    client::valid_name(name)
}

/// The client for this machine.
pub fn client() -> Rfc8555Client {
    Rfc8555Client::new(AcmeSettings::for_system(super::paths(), |name| env::var(name).ok()))
}

/// The certbot certificate and key for `name`, where there is one.
pub fn certbot_lineage(name: &str) -> Option<(PathBuf, PathBuf)> {
    let live = Path::new(CERTBOT_LIVE_DIR).join(name);
    let certificate = live.join("fullchain.pem");
    certificate.is_file().then(|| (certificate, live.join("privkey.pem")))
}

/// Where a certificate the customer uploaded for `domain` lives (task M5). A
/// folder of its own, so no engine ever renews or overwrites it, and taking it
/// away uncovers whatever the engine issued before.
pub fn custom_certificate_files(tls_dir: &Path, domain: &str) -> (PathBuf, PathBuf) {
    let folder = tls_dir.join("custom");
    (folder.join(format!("{domain}.crt")), folder.join(format!("{domain}.key")))
}

/// The uploaded certificate that serves `domain`: its own, or its parent
/// domain's when that one covers it (www, or any name under a wildcard).
pub fn uploaded_certificate(tls_dir: &Path, domain: &str) -> Option<(PathBuf, PathBuf)> {
    let own = custom_certificate_files(tls_dir, domain);
    if own.0.is_file() {
        return Some(own);
    }
    let (_, parent) = domain.split_once('.').filter(|(_, parent)| parent.contains('.'))?;
    let of_parent = custom_certificate_files(tls_dir, parent);
    CertInfo::read(&of_parent.0).is_some_and(|info| info.covers(domain)).then_some(of_parent)
}

/// What a certificate file says about itself, read without the `openssl`
/// program so it works the same on every OS.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct CertInfo {
    /// Seconds since the Unix epoch.
    pub not_after: i64,
    /// The DNS names it is for, lower case.
    pub names: Vec<String>,
    der: Vec<u8>,
}

impl CertInfo {
    /// The first certificate of a PEM file: the leaf of a chain.
    pub fn read(path: &Path) -> Option<Self> {
        Self::from_pem(&fs::read(path).ok()?)
    }

    pub fn from_pem(pem: &[u8]) -> Option<Self> {
        let (_, pem) = parse_x509_pem(pem).ok()?;
        let certificate = pem.parse_x509().ok()?;
        let names = certificate
            .subject_alternative_name()
            .ok()
            .flatten()
            .map(|extension| {
                extension
                    .value
                    .general_names
                    .iter()
                    .filter_map(|name| match name {
                        GeneralName::DNSName(name) => Some(name.to_ascii_lowercase()),
                        _ => None,
                    })
                    .collect()
            })
            .unwrap_or_default();
        Some(Self { not_after: certificate.validity().not_after.timestamp(), names, der: pem.contents.clone() })
    }

    pub fn der(&self) -> &[u8] {
        &self.der
    }

    /// A wildcard stands for exactly one label, as browsers read it.
    pub fn covers(&self, host: &str) -> bool {
        let host = host.to_ascii_lowercase();
        self.names.iter().any(|name| match name.strip_prefix("*.") {
            Some(parent) => host.split_once('.').is_some_and(|(label, rest)| !label.is_empty() && rest == parent),
            None => *name == host,
        })
    }

    /// Still good `margin` from `now`.
    pub fn outlives(&self, now: i64, margin: Duration) -> bool {
        self.not_after.saturating_sub(now) > i64::try_from(margin.as_secs()).unwrap_or(i64::MAX)
    }
}

/// Seconds since the Unix epoch.
pub fn unix_now() -> i64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .map(|elapsed| i64::try_from(elapsed.as_secs()).unwrap_or(i64::MAX))
        .unwrap_or(0)
}
