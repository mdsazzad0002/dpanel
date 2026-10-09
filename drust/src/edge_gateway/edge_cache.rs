//! Shared response cache in front of each site, the job a CDN cache does.
//!
//! A site opts in from the panel. "Standard" follows the origin's
//! Cache-Control and caches static-looking paths; "Everything" also caches
//! HTML. Anything personal is never stored: responses that set cookies, say
//! private/no-store, or vary on more than encoding, and requests carrying
//! credentials or a bypass cookie. When the origin fails, an expired copy can
//! be served instead of the error. Every response says what happened in
//! `x-dpanel-cache` (HIT, MISS, STALE, EXPIRED, BYPASS, DYNAMIC, STATIC).

use std::{
    collections::HashMap,
    sync::{
        Arc, Mutex, RwLock,
        atomic::{AtomicU64, Ordering},
    },
    time::{Duration, Instant, SystemTime, UNIX_EPOCH},
};

use axum::{
    body::Body,
    http::{HeaderMap, HeaderValue, Method, Request, StatusCode, header},
    response::Response,
};
use bytes::Bytes;
use serde::Serialize;
use tokio::sync::watch;

use super::SiteConfig;

pub const CACHE_STATUS_HEADER: &str = "x-dpanel-cache";

/// How long an expired copy is kept to stand in for a failing origin.
const STALE_WINDOW: Duration = Duration::from_secs(86_400);
/// 404s are cached briefly so a page published a moment later shows up.
const MAX_NOT_FOUND_TTL: Duration = Duration::from_secs(60);

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum CacheMode {
    #[default]
    Off,
    Standard,
    Everything,
}

impl CacheMode {
    pub fn parse(value: &str) -> Self {
        match value.trim().to_ascii_lowercase().as_str() {
            "standard" => Self::Standard,
            "everything" => Self::Everything,
            _ => Self::Off,
        }
    }
}

#[derive(Clone, Debug, Default)]
pub struct SiteCacheConfig {
    pub mode: CacheMode,
    /// TTL when the origin gives none ("Everything", static paths).
    pub edge_ttl: Duration,
    /// Replaces the browser Cache-Control on cacheable responses.
    pub browser_ttl: Option<Duration>,
    pub bypass_paths: Arc<[String]>,
    /// Cookie name prefixes, e.g. `wordpress_logged_in_`.
    pub bypass_cookies: Arc<[String]>,
    pub ignore_query: bool,
    pub serve_stale: bool,
    /// Unix seconds; until then the cache is skipped (development mode).
    pub development_mode_until: u64,
}

/// Put in a response's extensions when it was read straight from disk; the
/// static file layer already keeps those in memory and checks mtimes.
#[derive(Clone, Copy, Debug)]
pub struct StaticFileResponse;

pub enum Fill {
    /// Go to the origin; waiters wake when the guard drops, also if this
    /// request fails or the client hangs up.
    Leader(Option<FillGuard>),
    Wait(watch::Receiver<()>),
}

pub struct FillGuard {
    cache: Arc<EdgeCache>,
    key: String,
}

impl Drop for FillGuard {
    fn drop(&mut self) {
        if let Ok(mut filling) = self.cache.filling.lock() {
            filling.remove(&self.key);
        }
    }
}

/// Waits for another request's origin fetch, at most `limit`, like nginx's
/// proxy_cache_lock_timeout: a response that turns out uncacheable must not
/// hold the waiters up for long.
pub async fn wait_for_fill(mut receiver: watch::Receiver<()>, limit: Duration) {
    let _ = tokio::time::timeout(limit, receiver.changed()).await;
}

#[derive(Debug, PartialEq, Eq)]
pub enum Decision {
    /// The site does not use the edge cache.
    Off,
    Bypass,
    Lookup { key: String, static_path: bool },
}

pub fn decide(site: &SiteConfig, request: &Request<Body>, now_unix: u64) -> Decision {
    let config = &site.cache;
    if config.mode == CacheMode::Off || site.scope == "system" {
        return Decision::Off;
    }
    if config.development_mode_until > now_unix {
        return Decision::Bypass;
    }
    if request.method() != Method::GET && request.method() != Method::HEAD {
        return Decision::Bypass;
    }
    let headers = request.headers();
    if headers.contains_key(header::AUTHORIZATION)
        || headers.contains_key(header::RANGE)
        || headers.contains_key(header::UPGRADE)
    {
        return Decision::Bypass;
    }
    let path = request.uri().path();
    if path.starts_with("/.well-known/acme-challenge/")
        || config.bypass_paths.iter().any(|prefix| path.starts_with(prefix.as_str()))
    {
        return Decision::Bypass;
    }
    if has_bypass_cookie(headers, &config.bypass_cookies) {
        return Decision::Bypass;
    }

    let host = headers
        .get(header::HOST)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("")
        .split(':')
        .next()
        .unwrap_or("")
        .trim_end_matches('.')
        .to_ascii_lowercase();
    let scheme = if headers
        .get("x-forwarded-proto")
        .is_some_and(|value| value.as_bytes() == b"https")
    {
        "https"
    } else {
        "http"
    };
    let query = request
        .uri()
        .query()
        .filter(|query| !config.ignore_query && !query.is_empty());
    let target = match query {
        Some(query) => format!("{path}?{query}"),
        None => path.to_string(),
    };
    Decision::Lookup {
        key: format!("{scheme}://{host}{target}"),
        static_path: is_static_path(path),
    }
}

fn has_bypass_cookie(headers: &HeaderMap, prefixes: &[String]) -> bool {
    if prefixes.is_empty() {
        return false;
    }
    headers
        .get_all(header::COOKIE)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .flat_map(|value| value.split(';'))
        .filter_map(|pair| pair.split('=').next())
        .map(str::trim)
        .any(|name| prefixes.iter().any(|prefix| name.starts_with(prefix.as_str())))
}

fn is_static_path(path: &str) -> bool {
    const EXTENSIONS: &[&str] = &[
        "css", "js", "mjs", "map", "json", "xml", "txt", "ico", "png", "jpg", "jpeg", "gif",
        "webp", "avif", "svg", "bmp", "woff", "woff2", "ttf", "otf", "eot", "mp4", "webm", "mp3",
        "ogg", "wav", "pdf", "zip", "gz", "wasm",
    ];
    path.rsplit('/')
        .next()
        .and_then(|name| name.rsplit_once('.'))
        .is_some_and(|(_, ext)| EXTENSIONS.contains(&ext.to_ascii_lowercase().as_str()))
}

/// How long a response may be kept, or `None` when it must not be stored.
pub fn storable_ttl(
    config: &SiteCacheConfig,
    static_path: bool,
    status: StatusCode,
    headers: &HeaderMap,
) -> Option<Duration> {
    if !matches!(status.as_u16(), 200 | 203 | 301 | 308 | 404 | 410) {
        return None;
    }
    if headers.contains_key(header::SET_COOKIE) || headers.contains_key(header::CONTENT_ENCODING) {
        return None;
    }
    let vary_ok = headers
        .get_all(header::VARY)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .flat_map(|value| value.split(','))
        .map(|token| token.trim().to_ascii_lowercase())
        .all(|token| token.is_empty() || token == "accept-encoding");
    if !vary_ok {
        return None;
    }

    let directives = cache_control(headers);
    if directives.iter().any(|(name, _)| matches!(name.as_str(), "no-store" | "private" | "no-cache")) {
        return None;
    }
    let seconds = |name: &str| {
        directives
            .iter()
            .find(|(directive, _)| directive == name)
            .and_then(|(_, value)| value.as_deref()?.trim_matches('"').parse::<u64>().ok())
            .map(Duration::from_secs)
    };
    let shared_max_age = seconds("s-maxage");
    let ttl = match config.mode {
        CacheMode::Off => return None,
        CacheMode::Everything => shared_max_age.unwrap_or(config.edge_ttl),
        CacheMode::Standard => match shared_max_age.or_else(|| seconds("max-age")) {
            Some(ttl) => ttl,
            None if static_path => config.edge_ttl,
            None => return None,
        },
    };
    let ttl = if matches!(status.as_u16(), 404 | 410) {
        ttl.min(MAX_NOT_FOUND_TTL)
    } else {
        ttl
    };
    (!ttl.is_zero()).then_some(ttl)
}

fn cache_control(headers: &HeaderMap) -> Vec<(String, Option<String>)> {
    headers
        .get_all(header::CACHE_CONTROL)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .flat_map(|value| value.split(','))
        .filter_map(|directive| {
            let directive = directive.trim();
            if directive.is_empty() {
                return None;
            }
            Some(match directive.split_once('=') {
                Some((name, value)) => (name.trim().to_ascii_lowercase(), Some(value.trim().to_string())),
                None => (directive.to_ascii_lowercase(), None),
            })
        })
        .collect()
}

/// Applies the site's browser TTL to a cacheable response.
pub fn apply_browser_ttl(config: &SiteCacheConfig, headers: &mut HeaderMap) {
    if let Some(ttl) = config.browser_ttl {
        if let Ok(value) = HeaderValue::from_str(&format!("public, max-age={}", ttl.as_secs())) {
            headers.insert(header::CACHE_CONTROL, value);
        }
    }
}

pub fn label(response: &mut Response, status: &'static str) {
    response
        .headers_mut()
        .insert(CACHE_STATUS_HEADER, HeaderValue::from_static(status));
}

#[derive(Debug)]
pub struct CachedResponse {
    /// The site's canonical domain, so a purge covers www and apex alike.
    pub domain: String,
    pub host: String,
    pub target: String,
    pub status: StatusCode,
    pub headers: HeaderMap,
    pub body: Bytes,
    pub stored_at: Instant,
    pub fresh_until: Instant,
    pub stale_until: Instant,
}

impl CachedResponse {
    fn size(&self) -> u64 {
        let headers: usize = self
            .headers
            .iter()
            .map(|(name, value)| name.as_str().len() + value.len())
            .sum();
        (self.body.len() + headers + self.host.len() + self.target.len() + 128) as u64
    }

    /// Builds the response for a request served from this copy.
    pub fn respond(&self, request_headers: &HeaderMap, head: bool, status_label: &'static str) -> Response {
        let etag = self.headers.get(header::ETAG);
        let not_modified = etag.is_some_and(|etag| {
            request_headers
                .get(header::IF_NONE_MATCH)
                .and_then(|value| value.to_str().ok())
                .is_some_and(|value| {
                    value
                        .split(',')
                        .any(|tag| tag.trim() == "*" || tag.trim().as_bytes() == etag.as_bytes())
                })
        });
        let mut response = if not_modified || head {
            Response::new(Body::empty())
        } else {
            Response::new(Body::from(self.body.clone()))
        };
        *response.status_mut() = if not_modified {
            StatusCode::NOT_MODIFIED
        } else {
            self.status
        };
        let headers = response.headers_mut();
        for (name, value) in &self.headers {
            if not_modified && (name == header::CONTENT_LENGTH || name == header::CONTENT_TYPE) {
                continue;
            }
            headers.append(name.clone(), value.clone());
        }
        if let Ok(age) = HeaderValue::from_str(&self.stored_at.elapsed().as_secs().to_string()) {
            headers.insert(header::AGE, age);
        }
        headers.insert(CACHE_STATUS_HEADER, HeaderValue::from_static(status_label));
        response
    }
}

pub enum Lookup {
    Fresh(Arc<CachedResponse>),
    /// Expired but still kept to stand in for a failing origin.
    Stale(Arc<CachedResponse>),
    Miss,
}

#[derive(Clone, Copy, Debug)]
pub enum Outcome {
    Hit,
    Miss,
    Stale,
    Bypass,
    Dynamic,
}

#[derive(Clone, Debug, Default, Serialize)]
pub struct DomainStats {
    pub hits: u64,
    pub misses: u64,
    pub stale: u64,
    pub bypass: u64,
    pub dynamic: u64,
    pub bytes_served_from_cache: u64,
    pub entries: u64,
    pub stored_bytes: u64,
}

pub struct EdgeCache {
    entries: RwLock<HashMap<String, Arc<CachedResponse>>>,
    /// Keys an origin fetch is under way for. Dropping the sender wakes
    /// everyone who subscribed while waiting on that fetch.
    filling: Mutex<HashMap<String, watch::Sender<()>>>,
    pub fill_wait: Duration,
    bytes: AtomicU64,
    stats: Mutex<HashMap<String, DomainStats>>,
    pub max_bytes: u64,
    pub max_object_bytes: u64,
    max_entries: usize,
}

impl EdgeCache {
    pub fn new(max_bytes: u64, max_object_bytes: u64, max_entries: usize) -> Self {
        Self {
            entries: RwLock::new(HashMap::new()),
            filling: Mutex::new(HashMap::new()),
            fill_wait: Duration::from_secs(5),
            bytes: AtomicU64::new(0),
            stats: Mutex::new(HashMap::new()),
            max_bytes,
            max_object_bytes,
            max_entries,
        }
    }

    pub fn from_env() -> Self {
        let env = |name: &str, default: u64| {
            std::env::var(name)
                .ok()
                .and_then(|value| value.parse::<u64>().ok())
                .unwrap_or(default)
        };
        let mut cache = Self::new(
            env("DRUST_EDGE_CACHE_MAX_BYTES", 256 * 1024 * 1024),
            env("DRUST_EDGE_CACHE_MAX_OBJECT_BYTES", 8 * 1024 * 1024),
            env("DRUST_EDGE_CACHE_MAX_ENTRIES", 100_000) as usize,
        );
        cache.fill_wait = Duration::from_millis(env("DRUST_EDGE_CACHE_LOCK_TIMEOUT_MS", 5_000));
        cache
    }

    /// Lets one request per key go to the origin when a copy is missing or
    /// expired; the rest wait for it instead of all hitting PHP at once.
    pub fn begin_fill(self: &Arc<Self>, key: &str) -> Fill {
        let Ok(mut filling) = self.filling.lock() else {
            return Fill::Leader(None);
        };
        if let Some(sender) = filling.get(key) {
            return Fill::Wait(sender.subscribe());
        }
        filling.insert(key.to_string(), watch::channel(()).0);
        Fill::Leader(Some(FillGuard { cache: self.clone(), key: key.to_string() }))
    }

    pub fn lookup(&self, key: &str) -> Lookup {
        let Some(entry) = self.entries.read().ok().and_then(|entries| entries.get(key).cloned()) else {
            return Lookup::Miss;
        };
        let now = Instant::now();
        if now < entry.fresh_until {
            Lookup::Fresh(entry)
        } else if now < entry.stale_until {
            Lookup::Stale(entry)
        } else {
            Lookup::Miss
        }
    }

    pub fn store(&self, key: String, mut entry: CachedResponse, ttl: Duration, serve_stale: bool) {
        for name in ["connection", "keep-alive", "transfer-encoding", CACHE_STATUS_HEADER, "age"] {
            entry.headers.remove(name);
        }
        let now = Instant::now();
        entry.stored_at = now;
        entry.fresh_until = now + ttl;
        entry.stale_until = entry.fresh_until + if serve_stale { STALE_WINDOW } else { Duration::ZERO };
        let size = entry.size();
        if size > self.max_object_bytes.saturating_add(64 * 1024) {
            return;
        }
        let Ok(mut entries) = self.entries.write() else {
            return;
        };
        if let Some(old) = entries.insert(key, Arc::new(entry)) {
            self.bytes.fetch_sub(old.size(), Ordering::Relaxed);
        }
        self.bytes.fetch_add(size, Ordering::Relaxed);
        if self.bytes.load(Ordering::Relaxed) > self.max_bytes || entries.len() > self.max_entries {
            self.evict(&mut entries);
        }
    }

    /// Drops dead copies, then the oldest, until well under the limits.
    fn evict(&self, entries: &mut HashMap<String, Arc<CachedResponse>>) {
        let now = Instant::now();
        entries.retain(|_, entry| {
            let keep = now < entry.stale_until;
            if !keep {
                self.bytes.fetch_sub(entry.size(), Ordering::Relaxed);
            }
            keep
        });
        let byte_target = self.max_bytes / 10 * 9;
        let entry_target = self.max_entries / 10 * 9;
        if self.bytes.load(Ordering::Relaxed) <= byte_target && entries.len() <= entry_target {
            return;
        }
        let mut by_age = entries
            .iter()
            .map(|(key, entry)| (entry.stored_at, key.clone()))
            .collect::<Vec<_>>();
        by_age.sort_unstable();
        for (_, key) in by_age {
            if self.bytes.load(Ordering::Relaxed) <= byte_target && entries.len() <= entry_target {
                break;
            }
            if let Some(entry) = entries.remove(&key) {
                self.bytes.fetch_sub(entry.size(), Ordering::Relaxed);
            }
        }
    }

    /// Removes a domain's copies: all of them, or those whose path matches
    /// one of `urls` exactly or starts with one of `prefixes`. URLs may be
    /// full ("https://www.example.com/a?b") or just a path ("/a").
    pub fn purge(&self, domain: Option<&str>, urls: &[String], prefixes: &[String]) -> usize {
        let domain = domain.map(|value| value.trim().trim_end_matches('.').to_ascii_lowercase());
        let urls = urls.iter().map(|url| split_url(url)).collect::<Vec<_>>();
        let prefixes = prefixes.iter().map(|url| split_url(url)).collect::<Vec<_>>();
        let Ok(mut entries) = self.entries.write() else {
            return 0;
        };
        let before = entries.len();
        entries.retain(|_, entry| {
            if domain.as_deref().is_some_and(|domain| entry.domain != domain) {
                return true;
            }
            let host_ok = |host: &Option<String>| host.as_deref().is_none_or(|host| host == entry.host);
            let path_only = entry.target.split('?').next().unwrap_or("");
            let matched = (urls.is_empty() && prefixes.is_empty())
                || urls.iter().any(|(host, target)| {
                    host_ok(host) && (entry.target == *target || (!target.contains('?') && path_only == target))
                })
                || prefixes
                    .iter()
                    .any(|(host, prefix)| host_ok(host) && entry.target.starts_with(prefix.as_str()));
            if matched {
                self.bytes.fetch_sub(entry.size(), Ordering::Relaxed);
            }
            !matched
        });
        before - entries.len()
    }

    pub fn record(&self, domain: &str, outcome: Outcome, bytes: u64) {
        let Ok(mut stats) = self.stats.lock() else {
            return;
        };
        let stats = stats.entry(domain.to_string()).or_default();
        match outcome {
            Outcome::Hit => {
                stats.hits += 1;
                stats.bytes_served_from_cache += bytes;
            }
            Outcome::Stale => {
                stats.stale += 1;
                stats.bytes_served_from_cache += bytes;
            }
            Outcome::Miss => stats.misses += 1,
            Outcome::Bypass => stats.bypass += 1,
            Outcome::Dynamic => stats.dynamic += 1,
        }
    }

    /// Counters since the gateway started, plus what is stored now.
    pub fn stats(&self, domain: Option<&str>) -> serde_json::Value {
        let mut per_domain = self.stats.lock().map(|stats| stats.clone()).unwrap_or_default();
        if let Ok(entries) = self.entries.read() {
            for entry in entries.values() {
                let stats = per_domain.entry(entry.domain.clone()).or_default();
                stats.entries += 1;
                stats.stored_bytes += entry.size();
            }
        }
        if let Some(domain) = domain {
            let domain = domain.trim().trim_end_matches('.').to_ascii_lowercase();
            per_domain.retain(|name, _| *name == domain);
        }
        serde_json::json!({
            "max_bytes": self.max_bytes,
            "max_object_bytes": self.max_object_bytes,
            "stored_bytes": self.bytes.load(Ordering::Relaxed),
            "entries": self.entries.read().map(|entries| entries.len()).unwrap_or(0),
            "domains": per_domain,
        })
    }
}

/// "https://Host/a?b" → (Some("host"), "/a?b"); "/a" → (None, "/a").
fn split_url(url: &str) -> (Option<String>, String) {
    let url = url.trim();
    let Some(rest) = url
        .strip_prefix("https://")
        .or_else(|| url.strip_prefix("http://"))
    else {
        let target = if url.starts_with('/') { url.to_string() } else { format!("/{url}") };
        return (None, target);
    };
    let (host, target) = match rest.find('/') {
        Some(index) => (&rest[..index], rest[index..].to_string()),
        None => (rest, "/".to_string()),
    };
    let host = host.split(':').next().unwrap_or(host).to_ascii_lowercase();
    (Some(host), target)
}

pub fn unix_now() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|duration| duration.as_secs())
        .unwrap_or(0)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::edge_gateway::{RouteAction, RouteConfig};

    #[tokio::test]
    async fn one_request_fills_while_the_others_wait() {
        let cache = Arc::new(EdgeCache::new(1024 * 1024, 64 * 1024, 1000));
        let Fill::Leader(Some(guard)) = cache.begin_fill("k") else { panic!("first request must lead") };
        let Fill::Wait(receiver) = cache.begin_fill("k") else { panic!("second request must wait") };
        assert!(matches!(cache.begin_fill("other"), Fill::Leader(Some(_))));
        let waiter = tokio::spawn(wait_for_fill(receiver, Duration::from_secs(30)));
        drop(guard);
        tokio::time::timeout(Duration::from_secs(1), waiter).await.unwrap().unwrap();
        assert!(matches!(cache.begin_fill("k"), Fill::Leader(Some(_))));
    }

    #[tokio::test]
    async fn waiting_gives_up_after_the_lock_timeout() {
        let cache = Arc::new(EdgeCache::new(1024 * 1024, 64 * 1024, 1000));
        let _guard = cache.begin_fill("k");
        let Fill::Wait(receiver) = cache.begin_fill("k") else { panic!("second request must wait") };
        let started = Instant::now();
        wait_for_fill(receiver, Duration::from_millis(50)).await;
        assert!(started.elapsed() < Duration::from_secs(1));
    }

    fn site(mode: CacheMode) -> SiteConfig {
        SiteConfig {
            id: "1".into(),
            scope: "user".into(),
            site_owner: None,
            hostnames: Arc::from(["example.com".to_string()]),
            document_root: None,
            php_version: None,
            runtime: "php".into(),
            project_root: None,
            node_entry_file: None,
            node_start_command: None,
            node_version: None,
            python_entry_file: None,
            python_start_command: None,
            python_version: None,
            python_run: Default::default(),
            enable_ssl: false,
            spa_fallback: false,
            routes: Arc::from([RouteConfig {
                path_prefix: "/".into(),
                action: RouteAction::Static,
            }]),
            banned_ips: Arc::from([]),
            allowed_ips: Arc::from([]),
            cache: SiteCacheConfig {
                mode,
                edge_ttl: Duration::from_secs(600),
                bypass_paths: Arc::from(["/wp-admin".to_string()]),
                bypass_cookies: Arc::from(["wordpress_logged_in_".to_string()]),
                ..SiteCacheConfig::default()
            },
        }
    }

    fn request(method: &str, uri: &str, headers: &[(&str, &str)]) -> Request<Body> {
        let mut builder = Request::builder().method(method).uri(uri).header("host", "Example.com:443");
        for (name, value) in headers {
            builder = builder.header(*name, *value);
        }
        builder.body(Body::empty()).unwrap()
    }

    fn headers(pairs: &[(&str, &str)]) -> HeaderMap {
        let mut map = HeaderMap::new();
        for (name, value) in pairs {
            map.append(
                axum::http::HeaderName::from_bytes(name.as_bytes()).unwrap(),
                HeaderValue::from_str(value).unwrap(),
            );
        }
        map
    }

    #[test]
    fn decides_by_mode_method_path_and_cookie() {
        assert_eq!(decide(&site(CacheMode::Off), &request("GET", "/", &[]), 0), Decision::Off);
        let on = site(CacheMode::Everything);
        assert_eq!(
            decide(&on, &request("GET", "/a?b=1", &[("x-forwarded-proto", "https")]), 0),
            Decision::Lookup { key: "https://example.com/a?b=1".into(), static_path: false }
        );
        assert_eq!(decide(&on, &request("POST", "/", &[]), 0), Decision::Bypass);
        assert_eq!(decide(&on, &request("GET", "/wp-admin/x", &[]), 0), Decision::Bypass);
        assert_eq!(
            decide(&on, &request("GET", "/", &[("cookie", "a=1; wordpress_logged_in_abc=x")]), 0),
            Decision::Bypass
        );
        assert_eq!(decide(&on, &request("GET", "/", &[("authorization", "Bearer x")]), 0), Decision::Bypass);

        let mut dev = site(CacheMode::Everything);
        dev.cache.development_mode_until = 100;
        assert_eq!(decide(&dev, &request("GET", "/", &[]), 99), Decision::Bypass);
        assert!(matches!(decide(&dev, &request("GET", "/", &[]), 100), Decision::Lookup { .. }));

        let mut system = site(CacheMode::Everything);
        system.scope = "system".into();
        assert_eq!(decide(&system, &request("GET", "/", &[]), 0), Decision::Off);
    }

    #[test]
    fn ignoring_the_query_string_shares_one_copy() {
        let mut on = site(CacheMode::Standard);
        on.cache.ignore_query = true;
        assert_eq!(
            decide(&on, &request("GET", "/app.css?v=3", &[]), 0),
            Decision::Lookup { key: "http://example.com/app.css".into(), static_path: true }
        );
    }

    #[test]
    fn never_stores_personal_responses() {
        let config = site(CacheMode::Everything).cache;
        let ok = StatusCode::OK;
        assert_eq!(storable_ttl(&config, false, ok, &headers(&[])), Some(Duration::from_secs(600)));
        assert_eq!(storable_ttl(&config, false, ok, &headers(&[("set-cookie", "s=1")])), None);
        assert_eq!(storable_ttl(&config, false, ok, &headers(&[("cache-control", "private, max-age=60")])), None);
        assert_eq!(storable_ttl(&config, false, ok, &headers(&[("cache-control", "no-store")])), None);
        assert_eq!(storable_ttl(&config, false, ok, &headers(&[("vary", "Cookie")])), None);
        assert_eq!(storable_ttl(&config, false, ok, &headers(&[("vary", "Accept-Encoding")])), Some(Duration::from_secs(600)));
        assert_eq!(storable_ttl(&config, false, ok, &headers(&[("content-encoding", "gzip")])), None);
        assert_eq!(storable_ttl(&config, false, StatusCode::INTERNAL_SERVER_ERROR, &headers(&[])), None);
        assert_eq!(storable_ttl(&config, false, StatusCode::NOT_FOUND, &headers(&[])), Some(MAX_NOT_FOUND_TTL));
    }

    #[test]
    fn standard_mode_follows_the_origin() {
        let config = site(CacheMode::Standard).cache;
        let ok = StatusCode::OK;
        assert_eq!(storable_ttl(&config, false, ok, &headers(&[])), None);
        assert_eq!(storable_ttl(&config, true, ok, &headers(&[])), Some(Duration::from_secs(600)));
        assert_eq!(
            storable_ttl(&config, false, ok, &headers(&[("cache-control", "public, max-age=60, s-maxage=120")])),
            Some(Duration::from_secs(120))
        );
        assert_eq!(storable_ttl(&config, true, ok, &headers(&[("cache-control", "max-age=0")])), None);
    }

    fn entry(domain: &str, host: &str, target: &str, body: &str) -> CachedResponse {
        let now = Instant::now();
        CachedResponse {
            domain: domain.into(),
            host: host.into(),
            target: target.into(),
            status: StatusCode::OK,
            headers: headers(&[("etag", "\"v1\""), ("content-type", "text/html")]),
            body: Bytes::from(body.to_string()),
            stored_at: now,
            fresh_until: now,
            stale_until: now,
        }
    }

    #[test]
    fn stores_serves_and_purges() {
        let cache = EdgeCache::new(1024 * 1024, 64 * 1024, 1000);
        let ttl = Duration::from_secs(60);
        cache.store("http://example.com/".into(), entry("example.com", "example.com", "/", "home"), ttl, false);
        cache.store("http://www.example.com/a?x=1".into(), entry("example.com", "www.example.com", "/a?x=1", "a"), ttl, false);
        cache.store("http://example.com/blog/1".into(), entry("example.com", "example.com", "/blog/1", "b"), ttl, false);
        cache.store("http://other.org/".into(), entry("other.org", "other.org", "/", "o"), ttl, false);

        let Lookup::Fresh(hit) = cache.lookup("http://example.com/") else {
            panic!("expected a fresh copy");
        };
        let response = hit.respond(&HeaderMap::new(), false, "HIT");
        assert_eq!(response.status(), StatusCode::OK);
        assert_eq!(response.headers()[CACHE_STATUS_HEADER], "HIT");
        let revalidated = hit.respond(&headers(&[("if-none-match", "\"v1\"")]), false, "HIT");
        assert_eq!(revalidated.status(), StatusCode::NOT_MODIFIED);

        assert_eq!(cache.purge(Some("example.com"), &["https://www.example.com/a".into()], &[]), 1);
        assert_eq!(cache.purge(Some("example.com"), &[], &["/blog/".into()]), 1);
        assert_eq!(cache.purge(Some("example.com"), &[], &[]), 1);
        assert!(matches!(cache.lookup("http://other.org/"), Lookup::Fresh(_)));
        assert_eq!(cache.stats(None)["entries"], 1);
    }

    #[test]
    fn expired_copies_stay_only_to_cover_origin_errors() {
        let cache = EdgeCache::new(1024 * 1024, 64 * 1024, 1000);
        cache.store("k1".into(), entry("example.com", "example.com", "/", "x"), Duration::ZERO, true);
        cache.store("k2".into(), entry("example.com", "example.com", "/2", "x"), Duration::ZERO, false);
        assert!(matches!(cache.lookup("k1"), Lookup::Stale(_)));
        assert!(matches!(cache.lookup("k2"), Lookup::Miss));
    }

    #[test]
    fn evicts_the_oldest_copies_when_full() {
        let cache = EdgeCache::new(4 * 1024, 4 * 1024, 1000);
        for index in 0..20 {
            let body = "x".repeat(512);
            cache.store(format!("k{index}"), entry("example.com", "example.com", "/", &body), Duration::from_secs(60), false);
        }
        assert!(cache.bytes.load(Ordering::Relaxed) <= cache.max_bytes);
        assert!(matches!(cache.lookup("k19"), Lookup::Fresh(_)));
        assert!(matches!(cache.lookup("k0"), Lookup::Miss));
    }
}
