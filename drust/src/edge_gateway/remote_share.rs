//! Port shares that point at other servers: a reverse proxy to one remote
//! backend, or a small load balancer over several.
//!
//! Targets are base URLs (`http://192.168.0.106:8080`, `https://app.lan`).
//! A target that fails to answer is skipped for [`DOWN_FOR`] (passive health
//! checking); when every target is marked down they are all tried anyway, so
//! a backend that comes back is picked up without waiting. Bodiless GET,
//! HEAD and OPTIONS requests move on to the next target when one fails;
//! anything with a body is sent once, since a retry could repeat a write.

use std::{
    collections::HashMap,
    hash::{Hash, Hasher},
    net::IpAddr,
    sync::{
        Arc, Mutex, OnceLock,
        atomic::{AtomicUsize, Ordering},
    },
    time::{Duration, Instant},
};

use axum::{
    body::Body,
    http::{HeaderValue, Method, Request, Response, StatusCode, header},
};

use super::proxy::{ProxyConfig, build_insecure_client, proxy_to_base};

/// How long a failed target sits out before it is tried again.
const DOWN_FOR: Duration = Duration::from_secs(15);
/// Per-share timeouts are clamped to this range (seconds).
const MIN_TIMEOUT: u64 = 5;
const MAX_TIMEOUT: u64 = 300;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum BalancePolicy {
    /// Each request goes to the next target in turn.
    RoundRobin,
    /// A visitor IP always lands on the same target (sticky sessions).
    IpHash,
    /// The first healthy target in the list; the rest are standbys.
    Failover,
}

impl BalancePolicy {
    pub fn parse(value: &str) -> Self {
        match value.trim().to_ascii_lowercase().as_str() {
            "ip_hash" | "sticky" => Self::IpHash,
            "failover" | "first" => Self::Failover,
            _ => Self::RoundRobin,
        }
    }
}

#[derive(Debug)]
pub struct RemoteShare {
    /// Normalized base URLs without a trailing slash.
    pub targets: Arc<[Arc<str>]>,
    pub policy: BalancePolicy,
    pub strip_prefix: bool,
    /// Send the visitor's Host header instead of the target's, for backends
    /// that pick a virtual host by name.
    pub preserve_host: bool,
    /// Off for LAN backends with self-signed certificates.
    pub verify_tls: bool,
    pub timeout: Duration,
    cursor: AtomicUsize,
}

impl RemoteShare {
    pub fn new(
        targets: Vec<Arc<str>>,
        policy: BalancePolicy,
        strip_prefix: bool,
        preserve_host: bool,
        verify_tls: bool,
        timeout_secs: u64,
    ) -> Option<Self> {
        if targets.is_empty() {
            return None;
        }
        Some(Self {
            targets: Arc::from(targets),
            policy,
            strip_prefix,
            preserve_host,
            verify_tls,
            timeout: Duration::from_secs(timeout_secs.clamp(MIN_TIMEOUT, MAX_TIMEOUT)),
            cursor: AtomicUsize::new(0),
        })
    }

    /// Order to try the targets in for one request: the policy's choice
    /// first, healthy targets before ones marked down.
    fn candidates(&self, client_ip: Option<IpAddr>) -> Vec<usize> {
        let count = self.targets.len();
        let start = match self.policy {
            BalancePolicy::RoundRobin => self.cursor.fetch_add(1, Ordering::Relaxed) % count,
            BalancePolicy::IpHash => client_ip.map_or(0, |ip| {
                let mut hasher = std::collections::hash_map::DefaultHasher::new();
                ip.to_canonical().hash(&mut hasher);
                (hasher.finish() as usize) % count
            }),
            BalancePolicy::Failover => 0,
        };
        let ordered: Vec<usize> = (0..count).map(|offset| (start + offset) % count).collect();
        let (up, down): (Vec<usize>, Vec<usize>) =
            ordered.into_iter().partition(|&index| !is_down(&self.targets[index]));
        up.into_iter().chain(down).collect()
    }
}

/// Parses the panel's comma- or whitespace-separated target list. Only
/// http(s) URLs with a host are kept; a path or query is dropped, since the
/// request path is appended to the base.
pub fn parse_targets(raw: &str) -> Vec<Arc<str>> {
    raw.split(|c: char| c == ',' || c.is_whitespace())
        .filter_map(|item| {
            let item = item.trim();
            if item.is_empty() {
                return None;
            }
            let with_scheme = if item.contains("://") { item.to_string() } else { format!("http://{item}") };
            let url = reqwest::Url::parse(&with_scheme).ok()?;
            if !matches!(url.scheme(), "http" | "https") {
                return None;
            }
            let host = url.host_str()?;
            let host = if host.contains(':') && !host.starts_with('[') { format!("[{host}]") } else { host.to_string() };
            let base = match url.port() {
                Some(port) => format!("{}://{host}:{port}", url.scheme()),
                None => format!("{}://{host}", url.scheme()),
            };
            Some(Arc::from(base))
        })
        .fold(Vec::new(), |mut all, target| {
            if !all.contains(&target) {
                all.push(target);
            }
            all
        })
}

fn down_map() -> &'static Mutex<HashMap<String, Instant>> {
    static DOWN: OnceLock<Mutex<HashMap<String, Instant>>> = OnceLock::new();
    DOWN.get_or_init(|| Mutex::new(HashMap::new()))
}

fn is_down(target: &str) -> bool {
    let mut map = down_map().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    match map.get(target) {
        Some(until) if *until > Instant::now() => true,
        Some(_) => {
            map.remove(target);
            false
        }
        None => false,
    }
}

fn mark_down(target: &str) {
    let mut map = down_map().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    map.insert(target.to_string(), Instant::now() + DOWN_FOR);
}

fn mark_up(target: &str) {
    let mut map = down_map().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    map.remove(target);
}

fn insecure_client() -> Option<&'static reqwest::Client> {
    static CLIENT: OnceLock<Option<reqwest::Client>> = OnceLock::new();
    CLIENT
        .get_or_init(|| build_insecure_client(&ProxyConfig::default()).ok())
        .as_ref()
}

/// Only requests that cannot have changed anything on a failed attempt are
/// sent to a second target.
fn retryable(request: &Request<Body>) -> bool {
    let method = request.method();
    if !(method == Method::GET || method == Method::HEAD || method == Method::OPTIONS) {
        return false;
    }
    let headers = request.headers();
    !headers.contains_key(header::TRANSFER_ENCODING)
        && headers
            .get(header::CONTENT_LENGTH)
            .and_then(|value| value.to_str().ok())
            .is_none_or(|value| value.trim() == "0")
}

/// Visitor address for the backend: X-Real-IP is the connecting peer, and the
/// peer is appended to any X-Forwarded-For chain the request arrived with.
fn add_forwarded_headers(request: &mut Request<Body>, peer: Option<IpAddr>) {
    let Some(peer) = peer.map(|ip| ip.to_canonical()) else {
        return;
    };
    let headers = request.headers_mut();
    let chain = match headers.get("x-forwarded-for").and_then(|value| value.to_str().ok()) {
        Some(existing) if !existing.trim().is_empty() => format!("{}, {peer}", existing.trim()),
        _ => peer.to_string(),
    };
    if let Ok(value) = HeaderValue::from_str(&chain) {
        headers.insert("x-forwarded-for", value);
    }
    if let Ok(value) = HeaderValue::from_str(&peer.to_string()) {
        headers.insert("x-real-ip", value);
    }
}

fn clone_bodiless(request: &Request<Body>) -> Request<Body> {
    let mut copy = Request::new(Body::empty());
    *copy.method_mut() = request.method().clone();
    *copy.uri_mut() = request.uri().clone();
    *copy.version_mut() = request.version();
    *copy.headers_mut() = request.headers().clone();
    copy
}

pub async fn forward(
    client: &reqwest::Client,
    share: &RemoteShare,
    mut request: Request<Body>,
    peer: Option<IpAddr>,
) -> Response<Body> {
    add_forwarded_headers(&mut request, peer);
    // The shared gateway client verifies certificates; LAN backends with a
    // self-signed one opt out per share.
    let client = if share.verify_tls { client } else { insecure_client().unwrap_or(client) };

    let candidates = share.candidates(peer);
    let attempts = if retryable(&request) { candidates.len() } else { 1 };
    let mut errors = Vec::new();
    let mut pending = Some(request);

    for (attempt, index) in candidates.into_iter().take(attempts).enumerate() {
        let Some(current) = pending.take() else { break };
        if attempt + 1 < attempts {
            pending = Some(clone_bodiless(&current));
        }
        let target = &share.targets[index];
        match proxy_to_base(client, target, current, share.timeout, share.preserve_host).await {
            Ok(response) => {
                mark_up(target);
                return response;
            }
            Err(error) => {
                tracing::warn!(target = %target, %error, "remote share target failed");
                mark_down(target);
                errors.push(format!("{target}: {error}"));
            }
        }
    }

    let mut response = Response::new(Body::from(format!(
        "Bad gateway: no backend answered.\n{}\n",
        errors.join("\n")
    )));
    *response.status_mut() = StatusCode::BAD_GATEWAY;
    response
        .headers_mut()
        .insert(header::CONTENT_TYPE, HeaderValue::from_static("text/plain; charset=utf-8"));
    response
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::edge_gateway::proxy::build_client;

    #[test]
    fn targets_are_normalized_and_deduplicated() {
        let targets = parse_targets("192.168.0.106, http://192.168.0.106:80/x https://app.lan:8443 ftp://no 192.168.0.106");
        let targets: Vec<&str> = targets.iter().map(|t| t.as_ref()).collect();
        assert_eq!(targets, vec!["http://192.168.0.106", "https://app.lan:8443"]);
    }

    #[test]
    fn round_robin_rotates_and_failover_keeps_order() {
        let list = || parse_targets("http://10.9.9.1:1,http://10.9.9.2:1,http://10.9.9.3:1");
        let rr = RemoteShare::new(list(), BalancePolicy::RoundRobin, false, false, true, 30).unwrap();
        assert_eq!(rr.candidates(None)[0], 0);
        assert_eq!(rr.candidates(None)[0], 1);
        assert_eq!(rr.candidates(None)[0], 2);
        let fo = RemoteShare::new(list(), BalancePolicy::Failover, false, false, true, 30).unwrap();
        assert_eq!(fo.candidates(None), vec![0, 1, 2]);
    }

    #[test]
    fn ip_hash_is_sticky_and_down_targets_go_last() {
        let share = RemoteShare::new(
            parse_targets("http://10.8.8.1:1,http://10.8.8.2:1,http://10.8.8.3:1"),
            BalancePolicy::IpHash,
            false,
            false,
            true,
            30,
        )
        .unwrap();
        let ip: IpAddr = "203.0.113.7".parse().unwrap();
        let first = share.candidates(Some(ip))[0];
        assert_eq!(share.candidates(Some(ip))[0], first);

        mark_down(&share.targets[first]);
        let order = share.candidates(Some(ip));
        assert_eq!(*order.last().unwrap(), first);
        mark_up(&share.targets[first]);
    }

    #[test]
    fn forwarded_for_appends_the_peer() {
        let mut request = Request::builder().header("x-forwarded-for", "1.1.1.1").body(Body::empty()).unwrap();
        add_forwarded_headers(&mut request, Some("203.0.113.7".parse().unwrap()));
        assert_eq!(request.headers()["x-forwarded-for"], "1.1.1.1, 203.0.113.7");
        assert_eq!(request.headers()["x-real-ip"], "203.0.113.7");
    }

    #[tokio::test]
    async fn get_fails_over_to_the_next_target() {
        use axum::{Router, routing::get};
        let app = Router::new().route(
            "/hi",
            get(|headers: axum::http::HeaderMap| async move {
                format!("ok {}", headers.get("host").and_then(|v| v.to_str().ok()).unwrap_or(""))
            }),
        );
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let live = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });

        // Port 1 on loopback refuses connections: the dead first target.
        let share = RemoteShare::new(
            parse_targets(&format!("http://127.0.0.1:1,http://{live}")),
            BalancePolicy::Failover,
            false,
            true,
            true,
            10,
        )
        .unwrap();
        let client = build_client(&ProxyConfig::default()).unwrap();
        let request = Request::builder().uri("/hi").header("host", "site.example").body(Body::empty()).unwrap();
        let response = forward(&client, &share, request, Some("203.0.113.9".parse().unwrap())).await;
        assert_eq!(response.status(), StatusCode::OK);
        let body = axum::body::to_bytes(response.into_body(), usize::MAX).await.unwrap();
        assert_eq!(body, "ok site.example");
        assert!(is_down("http://127.0.0.1:1"));
        mark_up("http://127.0.0.1:1");
    }
}
