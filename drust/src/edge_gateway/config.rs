#![allow(dead_code)]

use std::{net::SocketAddr, path::PathBuf, sync::Arc, time::Duration};

#[derive(Clone, Debug)]
pub struct RuntimeSnapshot {
    pub version: u64,
    pub sites: Arc<[SiteConfig]>,
    pub tls: Arc<[TlsConfig]>,
    pub cache: CachePolicy,
}

#[derive(Clone, Debug)]
pub struct SiteConfig {
    pub id: String,
    pub hostnames: Arc<[String]>,
    pub document_root: Option<PathBuf>,
    pub php_version: Option<String>,
    pub enable_ssl: bool,
    pub spa_fallback: bool,
    pub routes: Arc<[RouteConfig]>,
}

#[derive(Clone, Debug)]
pub struct RouteConfig {
    pub path_prefix: String,
    pub action: RouteAction,
}

#[derive(Clone, Debug)]
pub enum RouteAction {
    Static,
    Proxy(UpstreamConfig),
    Redirect { location: String, code: u16 },
}

#[derive(Clone, Debug)]
pub enum UpstreamConfig {
    Http(SocketAddr),
    Unix(PathBuf),
}

#[derive(Clone, Debug)]
pub struct TlsConfig {
    pub hostnames: Arc<[String]>,
    pub cert_path: PathBuf,
    pub key_path: PathBuf,
}

#[derive(Clone, Debug)]
pub struct CachePolicy {
    pub enabled: bool,
    pub ttl: Duration,
    pub stale_while_revalidate: Duration,
}

#[derive(Clone, Debug)]
pub struct ReloadHandle {
    pub active_version: u64,
}

#[derive(Clone, Debug)]
pub struct EdgeGatewayRuntimeConfig {
    pub http_bind: String,
    pub https_bind: String,
    pub panel_domain: String,
    pub default_site_root: PathBuf,
}

impl RuntimeSnapshot {
    pub fn empty() -> Self {
        Self {
            version: 0,
            sites: Arc::from([]),
            tls: Arc::from([]),
            cache: CachePolicy {
                enabled: false,
                ttl: Duration::from_secs(0),
                stale_while_revalidate: Duration::from_secs(0),
            },
        }
    }
}

#[derive(Clone, Debug)]
pub struct SnapshotCacheConfig {
    pub ttl: Duration,
    pub source_path: Option<PathBuf>,
}

impl SnapshotCacheConfig {
    pub fn fast() -> Self {
        Self {
            ttl: Duration::from_secs(2),
            source_path: None,
        }
    }
}
