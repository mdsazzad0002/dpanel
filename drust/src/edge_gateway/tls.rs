#![allow(dead_code)]

use std::{fs::File, io::BufReader, path::PathBuf, sync::Arc};

use rustls::{
    ServerConfig,
    crypto::ring::default_provider,
    pki_types::{CertificateDer, PrivateKeyDer},
    server::ResolvesServerCertUsingSni,
    sign::CertifiedKey,
};
use rustls_pemfile::{certs, private_key};

#[derive(Clone, Debug)]
pub struct TlsRuntimeConfig {
    pub cert_dir: PathBuf,
    pub key_dir: PathBuf,
    pub preferred_chain: Option<String>,
}

#[derive(Clone, Debug)]
pub struct TlsIdentity {
    pub hostnames: Arc<[String]>,
    pub cert_path: PathBuf,
    pub key_path: PathBuf,
}

#[derive(Clone, Debug)]
pub struct TlsStore {
    pub identities: Arc<[TlsIdentity]>,
}

impl TlsStore {
    pub fn empty() -> Self {
        Self {
            identities: Arc::from([]),
        }
    }

    pub fn resolve(&self, host: &str) -> Option<&TlsIdentity> {
        self.identities.iter().find(|identity| {
            identity
                .hostnames
                .iter()
                .any(|candidate| candidate.eq_ignore_ascii_case(host))
        })
    }
}

pub fn default_tls_runtime() -> TlsRuntimeConfig {
    TlsRuntimeConfig {
        cert_dir: PathBuf::from("/etc/drust/tls"),
        key_dir: PathBuf::from("/etc/drust/tls"),
        preferred_chain: None,
    }
}

#[derive(Clone, Debug)]
pub struct TlsListenerConfig {
    pub bind: String,
    pub http_bind: String,
    pub store: TlsStore,
}

impl TlsListenerConfig {
    pub fn new(bind: impl Into<String>, http_bind: impl Into<String>, store: TlsStore) -> Self {
        Self {
            bind: bind.into(),
            http_bind: http_bind.into(),
            store,
        }
    }
}

pub fn scaffold_tls_listener_config(bind: &str, http_bind: &str) -> TlsListenerConfig {
    TlsListenerConfig::new(bind, http_bind, TlsStore::empty())
}

pub fn load_tls_identity(identity: &TlsIdentity) -> Result<CertifiedKey, String> {
    let certs = load_certs(&identity.cert_path)?;
    let key = load_private_key(&identity.key_path)?;
    let signing_key = rustls::crypto::ring::sign::any_supported_type(&key)
        .map_err(|error| format!("tls signing key failed: {error}"))?;
    Ok(CertifiedKey::new(certs, signing_key))
}

pub fn build_tls_config(store: &TlsStore) -> Result<ServerConfig, String> {
    let mut resolver = ResolvesServerCertUsingSni::new();
    for identity in store.identities.iter() {
        let certified = load_tls_identity(identity)?;
        for hostname in identity.hostnames.iter() {
            resolver
                .add(hostname, certified.clone())
                .map_err(|error| format!("tls hostname add failed: {error}"))?;
        }
    }

    let builder = ServerConfig::builder_with_provider(default_provider().into());
    let builder = builder
        .with_safe_default_protocol_versions()
        .map_err(|error| format!("tls versions failed: {error}"))?;
    Ok(builder
        .with_no_client_auth()
        .with_cert_resolver(Arc::new(resolver)))
}

fn load_certs(path: &PathBuf) -> Result<Vec<CertificateDer<'static>>, String> {
    let file = File::open(path).map_err(|error| format!("open cert failed: {error}"))?;
    let mut reader = BufReader::new(file);
    let parsed = certs(&mut reader)
        .collect::<Result<Vec<_>, _>>()
        .map_err(|error| format!("read cert failed: {error}"))?;
    if parsed.is_empty() {
        return Err("no certificates found".into());
    }
    Ok(parsed)
}

fn load_private_key(path: &PathBuf) -> Result<PrivateKeyDer<'static>, String> {
    let file = File::open(path).map_err(|error| format!("open key failed: {error}"))?;
    let mut reader = BufReader::new(file);
    private_key(&mut reader)
        .map_err(|error| format!("read key failed: {error}"))?
        .ok_or_else(|| "no private key found".into())
}
