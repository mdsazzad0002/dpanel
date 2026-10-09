#![allow(dead_code)]

use std::{path::PathBuf, sync::Arc, time::Duration};

use axum::{
    Json, Router,
    body::Body,
    extract::{Query, State},
    http::{HeaderMap, HeaderValue, Request, StatusCode},
    middleware::{map_request, map_response},
    response::{IntoResponse, Response},
    routing::{any, get, post},
};
use futures_util::StreamExt;
use hyper::body::Body as HttpBody;
use hyper_util::rt::{TokioExecutor, TokioIo};
use hyper_util::service::TowerToHyperService;
use rustls::ServerConfig;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use tokio::sync::Mutex;
use tokio::sync::RwLock;
use tokio::sync::mpsc;
use tracing::{info, warn};

use super::{
    edge_cache,
    BandwidthTracker, CachePolicy, DbSnapshotConfig, DispatchContext, DynamicCertResolver, EdgeCache,
    ProxyConfig, RouteAction, RouteConfig, RuntimeSnapshot, SiteConfig, SnapshotCacheConfig,
    StaticFileConfig, TlsConfig, TlsIdentity, TlsListenerConfig, TlsStore, UpstreamConfig,
    build_client, build_tls_config, dispatch, health_check_upstream, load_runtime_snapshot,
    run_h3_listener, scaffold_tls_listener_config,
};

#[derive(Clone)]
pub struct DemoServerState {
    pub snapshot: Arc<RwLock<Arc<RuntimeSnapshot>>>,
    pub source_config: DbSnapshotConfig,
    pub dispatch: DispatchContext,
    pub proxy_client: Arc<reqwest::Client>,
    pub bandwidth: BandwidthTracker,
    pub terminal_tickets: Arc<Mutex<HashMap<String, super::terminal_ws::TerminalTicket>>>,
    /// Live-swappable SNI cert store. `None` when the process has no HTTPS
    /// listener (no TLS identities configured at startup).
    pub tls_resolver: Option<Arc<DynamicCertResolver>>,
    pub edge_cache: Arc<EdgeCache>,
}

pub fn serve_gateway(bind: &str) -> Result<(), String> {
    let panel_domain = std::env::var("DRUST_PANEL_DOMAIN")
        .ok()
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| "dpanel.localhost".to_string());
    let dispatch = sample_dispatch_context();
    let cache_config = SnapshotCacheConfig::fast();
    let source_config = DbSnapshotConfig::new();
    let snapshot = load_runtime_snapshot(&source_config)
        .unwrap_or_else(|error| {
            warn!(error = %error, "live database snapshot load failed at gateway startup; using panel fallback");
            sample_panel_snapshot(&panel_domain)
        });
    let http_bind = std::env::var("DRUST_HTTP_BIND").unwrap_or_else(|_| bind.to_string());
    let https_bind =
        std::env::var("DRUST_HTTPS_BIND").unwrap_or_else(|_| "0.0.0.0:443".to_string());
    let tls = TlsListenerConfig::new(&https_bind, &http_bind, tls_store_from_snapshot(&snapshot));
    serve_gateway_with_tls(
        snapshot,
        dispatch,
        cache_config,
        source_config,
        &http_bind,
        tls,
        None,
    )
}

fn tls_store_from_snapshot(snapshot: &RuntimeSnapshot) -> TlsStore {
    let identities = snapshot
        .tls
        .iter()
        .filter(|config| config.cert_path.is_file() && config.key_path.is_file())
        .map(|config| TlsIdentity {
            hostnames: config.hostnames.clone(),
            cert_path: config.cert_path.clone(),
            key_path: config.key_path.clone(),
        })
        .collect::<Vec<_>>();
    TlsStore {
        identities: identities.into(),
    }
}

pub fn serve_gateway_with_tls(
    snapshot: RuntimeSnapshot,
    dispatch: DispatchContext,
    cache_config: SnapshotCacheConfig,
    source_config: DbSnapshotConfig,
    http_bind: &str,
    tls_config: TlsListenerConfig,
    redirect_to: Option<String>,
) -> Result<(), String> {
    let tls_bind = tls_config.bind.clone();
    // The HTTPS listener starts even with zero certificates so the first
    // certificate issued later goes live on reload; before this, a gateway
    // booted without any cert never served HTTPS until a manual restart.
    let https_required = !tls_config.store.identities.is_empty();
    let tls_built = Some(build_tls_config(&tls_config.store)?);
    let tls_resolver = tls_built.as_ref().map(|(_, resolver)| resolver.clone());
    let state = make_demo_state_with_tls(snapshot, dispatch, cache_config, source_config, tls_resolver)?;
    let runtime =
        tokio::runtime::Runtime::new().map_err(|error| format!("runtime build failed: {error}"))?;
    runtime.block_on(async move {
        state.bandwidth.spawn_periodic_flush();
        spawn_redis_reload_listener(state.clone());
        spawn_snapshot_poll(state.clone());
        let app_router = build_demo_router(state).layer(map_request(ensure_host_header));
        let https_router = app_router.clone().layer(map_request(mark_https_request));

        let http_listener = tokio::net::TcpListener::bind(http_bind)
            .await
            .map_err(|error| format!("http bind failed: {error}"))?;
        info!(bind = http_bind, "HTTP listener ready");

        let http_task = tokio::spawn(async move {
            let router = if redirect_to.is_some() {
                build_http_redirect_router(redirect_to)
            } else {
                app_router.clone()
            };
            serve_listener(http_listener, router, None).await
        });

        let https_task = if let Some((server_config, resolver)) = tls_built {
            let https_router = https_router.layer(map_response({
                let alt_svc = alt_svc_header_value(&tls_bind);
                move |mut response: Response| {
                    let alt_svc = alt_svc.clone();
                    async move {
                        if let Some(value) = alt_svc {
                            response.headers_mut().insert("alt-svc", value);
                        }
                        response
                    }
                }
            }));
            let h3_task = tokio::spawn(run_h3_listener(
                https_router.clone(),
                tls_bind.clone(),
                resolver,
            ));
            tokio::spawn(async move {
                if let Err(error) = h3_task.await {
                    warn!(%error, "HTTP/3 listener task join failed");
                }
            });
            Some(tokio::spawn(run_https_listener(
                https_router,
                tls_bind,
                server_config,
            )))
        } else {
            None
        };

        if let Some(task) = https_task {
            let mut http_task = http_task;
            tokio::select! {
                result = &mut http_task => {
                    result.map_err(|error| format!("http join failed: {error}"))??;
                }
                result = task => {
                    let result = result
                        .map_err(|error| format!("https join failed: {error}"))
                        .and_then(|inner| inner);
                    match result {
                        Err(error) if !https_required => {
                            // No site had a certificate at boot, so keep
                            // serving HTTP rather than exiting (old behaviour).
                            warn!(%error, "HTTPS listener unavailable; serving HTTP only");
                            http_task
                                .await
                                .map_err(|error| format!("http join failed: {error}"))??;
                        }
                        other => other?,
                    }
                }
            }
        } else {
            http_task
                .await
                .map_err(|error| format!("http join failed: {error}"))??;
        }
        Ok(())
    })
}

/// Advertises HTTP/3 availability to h1/h2 clients so browsers upgrade to
/// QUIC on a later connection, per RFC 9114 §3.1.1. `tls_bind` is the same
/// "host:port" the TCP TLS listener and the UDP QUIC listener both bind, so
/// the advertised port always matches what's actually listening.
fn alt_svc_header_value(tls_bind: &str) -> Option<HeaderValue> {
    let port = tls_bind.rsplit(':').next()?;
    HeaderValue::from_str(&format!("h3=\":{port}\"; ma=86400")).ok()
}

/// HTTP/2 (negotiated by default now that TLS advertises ALPN "h2") carries
/// the request's origin in the ":authority" pseudo-header, not a literal
/// "host" header — hyper/h2 surface that as `request.uri().authority()`, but
/// every site-matching/proxy/PHP-FPM code path in this gateway reads a plain
/// "host" header. Without this, h2 requests silently fail to resolve to any
/// site and fall through to the "Site not found" response. Applied once at
/// the router's edge so every downstream consumer sees a normal header.
async fn ensure_host_header(mut request: Request<Body>) -> Request<Body> {
    let has_host = request
        .headers()
        .get(axum::http::header::HOST)
        .and_then(|value| value.to_str().ok())
        .is_some_and(|value| !value.is_empty());
    if !has_host {
        if let Some(authority) = request.uri().authority().cloned() {
            if let Ok(value) = HeaderValue::from_str(authority.as_str()) {
                request
                    .headers_mut()
                    .insert(axum::http::header::HOST, value);
            }
        }
    }
    request
}

async fn mark_https_request(mut request: Request<Body>) -> Request<Body> {
    // Hyper receives origin-form URIs on the TLS listener, so the URI itself
    // does not retain the transport scheme. Mark it explicitly for PHP-FPM and
    // reverse-proxy consumers such as WordPress.
    request
        .headers_mut()
        .insert("x-forwarded-proto", HeaderValue::from_static("https"));
    request
}

fn build_http_redirect_router(redirect_to: Option<String>) -> Router {
    Router::new().fallback(any(move |request: Request<Body>| {
        let redirect_to = redirect_to.clone();
        async move {
            let location_base = redirect_to.unwrap_or_else(|| "https://127.0.0.1".to_string());
            let host = request
                .headers()
                .get("host")
                .and_then(|value| value.to_str().ok())
                .unwrap_or("");
            let path_and_query = request
                .uri()
                .path_and_query()
                .map(|value| value.as_str())
                .unwrap_or("/");
            let location =
                if location_base.starts_with("http://") || location_base.starts_with("https://") {
                    format!("{location_base}{path_and_query}")
                } else if host.is_empty() {
                    format!("https://{location_base}{path_and_query}")
                } else {
                    format!("https://{host}{path_and_query}")
                };
            let mut response = Response::new(Body::empty());
            *response.status_mut() = StatusCode::MOVED_PERMANENTLY;
            if let Ok(value) = HeaderValue::from_str(&location) {
                response
                    .headers_mut()
                    .insert(axum::http::header::LOCATION, value);
            }
            response
        }
    }))
}

pub fn build_demo_router(state: DemoServerState) -> Router {
    Router::new()
        .fallback(any(handle_request))
        .route(
            "/__admin/terminal-ticket",
            post(super::terminal_ws::register_ticket),
        )
        .route(
            "/__dpanel/terminal-ws",
            any(super::terminal_ws::terminal_socket),
        )
        .route("/__admin/reload", post(handle_reload))
        .route("/__admin/cache/purge", post(handle_cache_purge))
        .route("/__admin/cache/stats", get(handle_cache_stats))
        .route("/__admin/health", any(handle_health))
        .route("/__admin/upstreams/health", any(handle_upstreams_health))
        .layer(super::compression::layer())
        .with_state(Arc::new(state))
}

pub async fn handle_request(
    State(state): State<Arc<DemoServerState>>,
    request: Request<Body>,
) -> Response {
    let host = request
        .headers()
        .get("host")
        .and_then(|value| value.to_str().ok())
        .unwrap_or("-")
        .to_string();
    let upload_bytes = request
        .headers()
        .get(axum::http::header::CONTENT_LENGTH)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.parse::<u64>().ok())
        .unwrap_or(0);
    let path = request.uri().path().to_string();
    tracing::debug!(host = %host, path = %path, "incoming request");
    let snapshot = get_cached_snapshot(&state).await;
    let site = super::resolve_site(snapshot.as_ref(), &host);
    let canonical_domain = site.and_then(|site| site.hostnames.first().cloned());
    let decision = site.map_or(edge_cache::Decision::Off, |site| {
        edge_cache::decide(site, &request, edge_cache::unix_now())
    });
    let head = request.method() == axum::http::Method::HEAD;
    let mut stale = None;
    let mut _fill_guard = None;
    if let (edge_cache::Decision::Lookup { key, .. }, Some(domain), Some(site)) =
        (&decision, &canonical_domain, site)
    {
        // Only the headers: `Body` is not Sync, so borrowing the whole
        // request across the wait below would make this future non-Send.
        let request_headers = request.headers();
        let shared = &state;
        let serve = |entry: Arc<edge_cache::CachedResponse>, label, outcome| async move {
            let mut response = entry.respond(request_headers, head, label);
            if !head {
                response = super::precompress::encode(response, request_headers, &entry.body, &entry.variants).await;
            }
            let bytes = response.body().size_hint().exact().unwrap_or(0);
            shared.edge_cache.record(domain, outcome, bytes);
            shared.bandwidth.record(domain, upload_bytes, bytes);
            response
        };
        match state.edge_cache.lookup(key) {
            edge_cache::Lookup::Fresh(entry) => return serve(entry, "HIT", edge_cache::Outcome::Hit).await,
            edge_cache::Lookup::Stale(entry) => stale = Some(entry),
            edge_cache::Lookup::Miss => {}
        }
        // HEAD responses are never stored, so they neither lead nor wait.
        if !head {
            match state.edge_cache.begin_fill(key) {
                edge_cache::Fill::Leader(guard) => _fill_guard = guard,
                edge_cache::Fill::Wait(receiver) => {
                    // Someone is already refreshing this copy; the old one
                    // stands in meanwhile when the site allows stale copies.
                    if let (Some(entry), true) = (&stale, site.cache.serve_stale) {
                        return serve(entry.clone(), "STALE", edge_cache::Outcome::Stale).await;
                    }
                    edge_cache::wait_for_fill(receiver, state.edge_cache.fill_wait).await;
                    if let edge_cache::Lookup::Fresh(entry) = state.edge_cache.lookup(key) {
                        return serve(entry, "HIT", edge_cache::Outcome::Hit).await;
                    }
                }
            }
        }
    }
    let stale = stale.map(|entry| (entry, request.headers().clone()));
    let response = dispatch(
        site,
        &state.dispatch,
        request,
        &state.proxy_client,
    )
    .await;
    let response = match (site, &canonical_domain) {
        (Some(site), Some(domain)) => {
            finish_cacheable(&state, site, domain, decision, head, stale, &host, response).await
        }
        _ => response,
    };
    if let Some(domain) = canonical_domain {
        // Streamed bodies (large PHP output) have no exact size up front.
        let download_bytes = response.body().size_hint().exact().unwrap_or_else(|| {
            response
                .headers()
                .get(axum::http::header::CONTENT_LENGTH)
                .and_then(|value| value.to_str().ok())
                .and_then(|value| value.parse().ok())
                .unwrap_or(0)
        });
        state
            .bandwidth
            .record(&domain, upload_bytes, download_bytes);
    }
    response
}

/// Stores a cacheable origin response and labels what the cache did.
#[allow(clippy::too_many_arguments)]
async fn finish_cacheable(
    state: &Arc<DemoServerState>,
    site: &SiteConfig,
    domain: &str,
    decision: edge_cache::Decision,
    head: bool,
    stale: Option<(Arc<edge_cache::CachedResponse>, HeaderMap)>,
    host: &str,
    mut response: Response,
) -> Response {
    let cache = &state.edge_cache;
    let (key, static_path) = match decision {
        edge_cache::Decision::Off => return response,
        edge_cache::Decision::Bypass => {
            cache.record(domain, edge_cache::Outcome::Bypass, 0);
            edge_cache::label(&mut response, "BYPASS");
            return response;
        }
        edge_cache::Decision::Lookup { key, static_path } => (key, static_path),
    };
    if response.status().is_server_error() && site.cache.serve_stale {
        if let Some((entry, request_headers)) = stale {
            warn!(%domain, status = %response.status(), "origin failed; serving stale cached copy");
            let response = entry.respond(&request_headers, head, "STALE");
            cache.record(domain, edge_cache::Outcome::Stale, entry.body.len() as u64);
            return response;
        }
    }
    if response.extensions().get::<edge_cache::StaticFileResponse>().is_some() {
        edge_cache::label(&mut response, "STATIC");
        return response;
    }
    let expired = if stale.is_some() { "EXPIRED" } else { "MISS" };
    let ttl = edge_cache::storable_ttl(&site.cache, static_path, response.status(), response.headers());
    let size = response.body().size_hint().exact();
    let (Some(ttl), Some(size), false) = (ttl, size, head) else {
        let outcome = if ttl.is_some() { edge_cache::Outcome::Miss } else { edge_cache::Outcome::Dynamic };
        cache.record(domain, outcome, 0);
        if ttl.is_some() {
            edge_cache::apply_browser_ttl(&site.cache, response.headers_mut());
        }
        edge_cache::label(&mut response, if ttl.is_some() { expired } else { "DYNAMIC" });
        return response;
    };
    if size > cache.max_object_bytes {
        cache.record(domain, edge_cache::Outcome::Miss, 0);
        edge_cache::label(&mut response, expired);
        return response;
    }
    let (mut parts, body) = response.into_parts();
    let body = match axum::body::to_bytes(body, cache.max_object_bytes as usize).await {
        Ok(body) => body,
        Err(error) => {
            warn!(%error, %domain, "could not read origin response for the edge cache");
            return (StatusCode::BAD_GATEWAY, "origin response could not be read").into_response();
        }
    };
    let target = key.split_once("://").and_then(|(_, rest)| rest.find('/').map(|index| rest[index..].to_string()));
    cache.store(
        key,
        edge_cache::CachedResponse {
            domain: domain.to_string(),
            host: host.split(':').next().unwrap_or(host).trim_end_matches('.').to_ascii_lowercase(),
            target: target.unwrap_or_else(|| "/".to_string()),
            status: parts.status,
            headers: parts.headers.clone(),
            body: body.clone(),
            stored_at: std::time::Instant::now(),
            fresh_until: std::time::Instant::now(),
            stale_until: std::time::Instant::now(),
            variants: Default::default(),
        },
        ttl,
        site.cache.serve_stale,
    );
    cache.record(domain, edge_cache::Outcome::Miss, 0);
    edge_cache::apply_browser_ttl(&site.cache, &mut parts.headers);
    let mut response = Response::from_parts(parts, Body::from(body));
    edge_cache::label(&mut response, expired);
    response
}

fn admin_token_ok(headers: &HeaderMap) -> bool {
    let expected = std::env::var("DRUST_API_TOKEN").unwrap_or_default();
    let supplied = headers
        .get(axum::http::header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
        .unwrap_or("");
    !expected.is_empty() && supplied.as_bytes() == expected.as_bytes()
}

#[derive(Debug, Default, Deserialize)]
pub struct CachePurgeRequest {
    /// The site's main domain. Without it, `everything` must be set.
    #[serde(default)]
    domain: Option<String>,
    #[serde(default)]
    urls: Vec<String>,
    #[serde(default)]
    prefixes: Vec<String>,
    #[serde(default)]
    everything: bool,
}

pub async fn handle_cache_purge(
    State(state): State<Arc<DemoServerState>>,
    headers: HeaderMap,
    Json(payload): Json<CachePurgeRequest>,
) -> Response {
    if !admin_token_ok(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "success": false, "message": "unauthorized" }))).into_response();
    }
    let domain = payload.domain.as_deref().map(str::trim).filter(|domain| !domain.is_empty());
    if domain.is_none() && !payload.everything {
        return (
            StatusCode::UNPROCESSABLE_ENTITY,
            Json(serde_json::json!({ "success": false, "message": "name a domain, or set everything" })),
        )
            .into_response();
    }
    let purged = state.edge_cache.purge(domain, &payload.urls, &payload.prefixes);
    info!(domain = domain.unwrap_or("*"), purged, "edge cache purged");
    Json(serde_json::json!({ "success": true, "purged": purged })).into_response()
}

#[derive(Debug, Default, Deserialize)]
pub struct CacheStatsQuery {
    #[serde(default)]
    domain: Option<String>,
}

pub async fn handle_cache_stats(
    State(state): State<Arc<DemoServerState>>,
    headers: HeaderMap,
    Query(query): Query<CacheStatsQuery>,
) -> Response {
    if !admin_token_ok(&headers) {
        return (StatusCode::UNAUTHORIZED, Json(serde_json::json!({ "success": false, "message": "unauthorized" }))).into_response();
    }
    Json(serde_json::json!({ "success": true, "data": state.edge_cache.stats(query.domain.as_deref()) })).into_response()
}

pub async fn handle_reload(
    State(state): State<Arc<DemoServerState>>,
    headers: HeaderMap,
    payload: Option<Json<ReloadRequest>>,
) -> Response {
    let expected = std::env::var("DRUST_API_TOKEN").unwrap_or_default();
    let supplied = headers
        .get(axum::http::header::AUTHORIZATION)
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
        .unwrap_or("");
    if expected.is_empty() || supplied.as_bytes() != expected.as_bytes() {
        return (
            StatusCode::UNAUTHORIZED,
            Json(ReloadResponse {
                success: false,
                message: "unauthorized".into(),
                version: state.snapshot.read().await.version,
            }),
        )
            .into_response();
    }
    let domains = payload
        .map(|Json(payload)| payload.domains)
        .unwrap_or_default();
    let result = if domains.is_empty() {
        reload_snapshot(&state).await
    } else {
        reload_domains(&state, &domains).await
    };
    match result {
        Ok(version) => Json(ReloadResponse {
            success: true,
            message: "snapshot reloaded".to_string(),
            version,
        })
        .into_response(),
        Err((error, version)) => {
            warn!(%error, version, "snapshot reload failed; keeping last-known-good snapshot");
            (
                StatusCode::SERVICE_UNAVAILABLE,
                Json(ReloadResponse {
                    success: false,
                    message: format!("reload failed; retained snapshot: {error}"),
                    version,
                }),
            )
                .into_response()
        }
    }
}

#[derive(Clone, Debug, Default, Deserialize)]
pub struct ReloadRequest {
    #[serde(default)]
    domains: Vec<String>,
}

async fn reload_snapshot(state: &Arc<DemoServerState>) -> Result<u64, (String, u64)> {
    let source = state.source_config.clone();
    let loaded = tokio::task::spawn_blocking(move || load_runtime_snapshot(&source)).await;
    match loaded {
        Ok(Ok(next)) => Ok(apply_snapshot(state, next).await),
        Ok(Err(error)) => Err((error, state.snapshot.read().await.version)),
        Err(error) => Err((
            format!("snapshot reload worker failed: {error}"),
            state.snapshot.read().await.version,
        )),
    }
}

async fn reload_domains(
    state: &Arc<DemoServerState>,
    requested_domains: &[String],
) -> Result<u64, (String, u64)> {
    let domains = requested_domains
        .iter()
        .map(|domain| domain.trim().trim_end_matches('.').to_ascii_lowercase())
        .filter(|domain| !domain.is_empty())
        .collect::<std::collections::HashSet<_>>();
    if domains.is_empty() {
        return Ok(state.snapshot.read().await.version);
    }

    let source = state.source_config.clone();
    let wanted = domains.iter().cloned().collect::<Vec<_>>();
    let loaded =
        tokio::task::spawn_blocking(move || super::load_domain_sites(&source, &wanted)).await;
    match loaded {
        Ok(Ok((fresh_sites, fresh_tls))) => {
            let current = state.snapshot.read().await.clone();
            let targeted = |hostnames: &[String]| {
                hostnames.first().is_some_and(|domain| domains.contains(domain))
            };
            let mut roots = Vec::new();
            let mut sites = current
                .sites
                .iter()
                .filter(|site| {
                    if targeted(&site.hostnames) {
                        if let Some(root) = &site.document_root {
                            roots.push(root.clone());
                        }
                        return false;
                    }
                    true
                })
                .cloned()
                .collect::<Vec<_>>();
            for site in fresh_sites {
                if let Some(root) = &site.document_root {
                    roots.push(root.clone());
                }
                sites.push(site);
            }
            // Only the targeted domains' certificates change: enabling SSL or
            // issuing a certificate arrives as a per-domain reload.
            let mut tls = current
                .tls
                .iter()
                .filter(|config| !targeted(&config.hostnames))
                .cloned()
                .collect::<Vec<_>>();
            tls.extend(fresh_tls);
            let updated = RuntimeSnapshot::new(
                current.version,
                Arc::from(sites),
                Arc::from(tls),
                current.cache.clone(),
            );
            if let Some(resolver) = &state.tls_resolver {
                let store = tls_store_from_snapshot(&updated);
                if let Err(error) = resolver.update(&store) {
                    warn!(%error, version = updated.version, "TLS certificate reload failed; keeping previously loaded certificates");
                }
            }
            let version = updated.version;
            *state.snapshot.write().await = Arc::new(updated);
            super::clear_static_cache_under(&roots);
            super::clear_canonical_root_cache_under(&roots);
            // A site reload follows a settings change (cache mode, TTLs,
            // runtime), so its old copies must not outlive it.
            for domain in &domains {
                state.edge_cache.purge(Some(domain), &[], &[]);
            }
            Ok(version)
        }
        Ok(Err(error)) => Err((error, state.snapshot.read().await.version)),
        Err(error) => Err((
            format!("snapshot reload worker failed: {error}"),
            state.snapshot.read().await.version,
        )),
    }
}

async fn apply_snapshot(state: &Arc<DemoServerState>, next: RuntimeSnapshot) -> u64 {
    let version = next.version;
    if let Some(resolver) = &state.tls_resolver {
        let store = tls_store_from_snapshot(&next);
        if let Err(error) = resolver.update(&store) {
            warn!(%error, version, "TLS certificate reload failed; keeping previously loaded certificates");
        }
    }
    *state.snapshot.write().await = Arc::new(next);
    super::clear_static_cache();
    super::clear_canonical_root_cache();
    version
}

/// Identifies a snapshot's content regardless of site order (a per-domain
/// reload appends its sites, a full load sorts by last update).
fn snapshot_fingerprint(snapshot: &RuntimeSnapshot) -> u64 {
    use std::hash::{BuildHasher, BuildHasherDefault, DefaultHasher};
    let hasher = BuildHasherDefault::<DefaultHasher>::default();
    let mut parts = snapshot
        .sites
        .iter()
        .map(|site| hasher.hash_one(format!("{site:?}")))
        .chain(snapshot.tls.iter().map(|tls| hasher.hash_one(format!("tls {tls:?}"))))
        .collect::<Vec<_>>();
    parts.sort_unstable();
    hasher.hash_one(parts)
}

/// Safety net for lost reload signals (Redis down and the HTTP fallback
/// failing, or a database edit made outside the panel): re-reads the
/// database every `DRUST_SNAPSHOT_POLL_SECONDS` (60; 0 turns it off) and
/// applies it only when something actually changed, so the in-memory
/// caches are not thrown away for nothing.
fn spawn_snapshot_poll(state: DemoServerState) {
    let seconds = std::env::var("DRUST_SNAPSHOT_POLL_SECONDS")
        .ok()
        .and_then(|value| value.trim().parse::<u64>().ok())
        .unwrap_or(60);
    if seconds == 0 {
        return;
    }
    let state = Arc::new(state);
    tokio::spawn(async move {
        let mut ticker = tokio::time::interval(Duration::from_secs(seconds.max(5)));
        ticker.set_missed_tick_behavior(tokio::time::MissedTickBehavior::Delay);
        ticker.tick().await;
        loop {
            ticker.tick().await;
            let source = state.source_config.clone();
            let next = match tokio::task::spawn_blocking(move || load_runtime_snapshot(&source)).await {
                Ok(Ok(next)) => next,
                Ok(Err(error)) => {
                    warn!(%error, "snapshot poll failed; keeping the current snapshot");
                    continue;
                }
                Err(error) => {
                    warn!(%error, "snapshot poll worker failed");
                    continue;
                }
            };
            let current = state.snapshot.read().await.clone();
            if snapshot_fingerprint(&next) == snapshot_fingerprint(&current) {
                continue;
            }
            info!("website settings changed without a reload signal; applying them");
            apply_snapshot(&state, next).await;
        }
    });
}

fn spawn_redis_reload_listener(state: DemoServerState) {
    let state = Arc::new(state);
    let (sender, mut receiver) = mpsc::channel::<Vec<String>>(16);
    let url = std::env::var("DRUST_REDIS_URL").unwrap_or_else(|_| "redis://127.0.0.1/".into());
    let channel = std::env::var("DRUST_REDIS_RELOAD_CHANNEL")
        .unwrap_or_else(|_| "edge:reload".into());
    tokio::spawn(async move {
        loop {
            let result = async {
                let client = redis::Client::open(url.as_str()).map_err(|e| e.to_string())?;
                let mut pubsub = client.get_async_pubsub().await.map_err(|e| e.to_string())?;
                pubsub
                    .subscribe(&channel)
                    .await
                    .map_err(|e| e.to_string())?;
                info!(%channel, "Redis website reload listener ready");
                let mut messages = pubsub.on_message();
                while let Some(message) = messages.next().await {
                    let payload = message
                        .get_payload::<String>()
                        .ok()
                        .and_then(|payload| serde_json::from_str::<ReloadRequest>(&payload).ok())
                        .unwrap_or_default();
                    let _ = sender.try_send(payload.domains);
                }
                Ok::<(), String>(())
            }
            .await;
            warn!(error = %result.err().unwrap_or_else(|| "connection closed".into()), "Redis reload listener reconnecting");
            tokio::time::sleep(Duration::from_secs(2)).await;
        }
    });
    tokio::spawn(async move {
        while let Some(mut domains) = receiver.recv().await {
            tokio::time::sleep(Duration::from_millis(100)).await;
            // An empty list means "reload everything" (e.g. certificate
            // issued); it must win over domain-scoped requests batched with it.
            let mut full_reload = domains.is_empty();
            while let Ok(more) = receiver.try_recv() {
                full_reload |= more.is_empty();
                domains.extend(more);
            }
            if full_reload {
                domains.clear();
            }
            let result = if domains.is_empty() {
                reload_snapshot(&state).await
            } else {
                reload_domains(&state, &domains).await
            };
            match result {
                Ok(version) => tracing::debug!(version, "Redis-triggered snapshot reload complete"),
                Err((error, version)) => {
                    warn!(%error, version, "Redis-triggered reload failed; retaining snapshot")
                }
            }
        }
    });
}

pub async fn handle_health(State(state): State<Arc<DemoServerState>>) -> Json<HealthResponse> {
    let snapshot = get_cached_snapshot(&state).await;
    Json(HealthResponse {
        status: "ok".to_string(),
        version: snapshot.version,
        sites: snapshot.sites.len() as u64,
    })
}

pub async fn handle_upstreams_health(
    State(state): State<Arc<DemoServerState>>,
) -> Json<UpstreamsHealthResponse> {
    let snapshot = get_cached_snapshot(&state).await;
    let mut results = Vec::new();
    for site in snapshot.sites.iter() {
        for route in site.routes.iter() {
            if let RouteAction::Proxy(upstream) = &route.action {
                let healthy = health_check_upstream(&state.proxy_client, upstream)
                    .await
                    .unwrap_or(false);
                results.push(UpstreamHealth {
                    site: site.hostnames.first().cloned().unwrap_or_default(),
                    route: route.path_prefix.clone(),
                    healthy,
                });
            }
        }
    }

    Json(UpstreamsHealthResponse { results })
}

pub fn make_demo_state(
    snapshot: RuntimeSnapshot,
    dispatch: DispatchContext,
    _cache_config: SnapshotCacheConfig,
    source_config: DbSnapshotConfig,
) -> Result<DemoServerState, String> {
    make_demo_state_with_tls(snapshot, dispatch, _cache_config, source_config, None)
}

pub fn make_demo_state_with_tls(
    snapshot: RuntimeSnapshot,
    dispatch: DispatchContext,
    _cache_config: SnapshotCacheConfig,
    source_config: DbSnapshotConfig,
    tls_resolver: Option<Arc<DynamicCertResolver>>,
) -> Result<DemoServerState, String> {
    let client = build_client(&ProxyConfig::default())?;
    Ok(DemoServerState {
        snapshot: Arc::new(RwLock::new(Arc::new(snapshot))),
        source_config,
        dispatch,
        proxy_client: Arc::new(client),
        bandwidth: BandwidthTracker::from_env(),
        terminal_tickets: Arc::new(Mutex::new(HashMap::new())),
        tls_resolver,
        edge_cache: Arc::new(EdgeCache::from_env()),
    })
}

pub fn serve_demo(
    snapshot: RuntimeSnapshot,
    dispatch: DispatchContext,
    bind: &str,
) -> Result<(), String> {
    let tls = scaffold_tls_listener_config("0.0.0.0:8443", bind);
    serve_demo_with_tls(
        snapshot,
        dispatch,
        SnapshotCacheConfig::fast(),
        DbSnapshotConfig::new(),
        bind,
        tls,
    )
}

pub fn serve_demo_with_tls(
    snapshot: RuntimeSnapshot,
    dispatch: DispatchContext,
    cache_config: SnapshotCacheConfig,
    source_config: DbSnapshotConfig,
    http_bind: &str,
    tls_config: TlsListenerConfig,
) -> Result<(), String> {
    let tls_bind = tls_config.bind.clone();
    let https_enabled = !tls_config.store.identities.is_empty();
    let tls_built = if https_enabled {
        Some(build_tls_config(&tls_config.store)?)
    } else {
        None
    };
    let tls_resolver = tls_built.as_ref().map(|(_, resolver)| resolver.clone());
    let state = make_demo_state_with_tls(snapshot, dispatch, cache_config, source_config, tls_resolver)?;
    let runtime =
        tokio::runtime::Runtime::new().map_err(|error| format!("runtime build failed: {error}"))?;
    runtime.block_on(async move {
        state.bandwidth.spawn_periodic_flush();
        spawn_redis_reload_listener(state.clone());
        spawn_snapshot_poll(state.clone());
        let router = build_demo_router(state);
        let http_listener = tokio::net::TcpListener::bind(http_bind)
            .await
            .map_err(|error| format!("http bind failed: {error}"))?;
        info!(bind = http_bind, "HTTP listener ready");

        let http_task = tokio::spawn({
            let router = router.clone();
            async move {
                serve_listener(http_listener, router, None).await
            }
        });

        let https_task = if let Some((server_config, _)) = tls_built {
            Some(tokio::spawn(run_https_listener(
                router.clone(),
                tls_bind,
                server_config,
            )))
        } else {
            info!(bind = %tls_bind, "HTTPS listener skipped because no TLS identities are configured");
            None
        };

        if let Some(task) = https_task {
            tokio::select! {
                result = http_task => {
                    result.map_err(|error| format!("http join failed: {error}"))??;
                }
                result = task => {
                    result.map_err(|error| format!("https join failed: {error}"))??;
                }
            }
        } else {
            http_task
                .await
                .map_err(|error| format!("http join failed: {error}"))??;
        }
        Ok(())
    })
}

async fn get_cached_snapshot(state: &Arc<DemoServerState>) -> Arc<RuntimeSnapshot> {
    state.snapshot.read().await.clone()
}

async fn refresh_cache(state: &Arc<DemoServerState>) {
    let snapshot = match load_runtime_snapshot(&state.source_config) {
        Ok(snapshot) => snapshot,
        Err(error) => {
            warn!(error = %error, "live database snapshot load failed; retaining last-known-good snapshot");
            return;
        }
    };
    *state.snapshot.write().await = Arc::new(snapshot);
}

async fn run_https_listener(
    router: Router,
    bind: String,
    server_config: ServerConfig,
) -> Result<(), String> {
    let acceptor = tokio_rustls::TlsAcceptor::from(Arc::new(server_config));
    let listener = tokio::net::TcpListener::bind(&bind)
        .await
        .map_err(|error| format!("https bind failed: {error}"))?;
    info!(bind = %bind, "HTTPS listener ready");
    serve_listener(listener, router, Some(acceptor)).await
}

/// A client that has not finished the TLS handshake by then is dropped, so
/// idle or slowloris connections cannot pile up.
const TLS_HANDSHAKE_TIMEOUT: Duration = Duration::from_secs(10);
/// Same for an HTTP/1 client that never finishes sending its headers.
const HEADER_READ_TIMEOUT: Duration = Duration::from_secs(30);

/// Open connections over both listeners (`DRUST_MAX_CONNECTIONS`, 30000).
/// At the limit new ones wait in the kernel backlog instead of failing.
fn connection_permits() -> Arc<tokio::sync::Semaphore> {
    static PERMITS: std::sync::OnceLock<Arc<tokio::sync::Semaphore>> = std::sync::OnceLock::new();
    PERMITS
        .get_or_init(|| {
            let limit = std::env::var("DRUST_MAX_CONNECTIONS")
                .ok()
                .and_then(|value| value.trim().parse::<usize>().ok())
                .filter(|value| *value > 0)
                .unwrap_or(30_000);
            Arc::new(tokio::sync::Semaphore::new(limit))
        })
        .clone()
}

/// Accept loop shared by the HTTP and HTTPS listeners.
async fn serve_listener(
    listener: tokio::net::TcpListener,
    router: Router,
    tls: Option<tokio_rustls::TlsAcceptor>,
) -> Result<(), String> {
    let permits = connection_permits();
    loop {
        let permit = permits
            .clone()
            .acquire_owned()
            .await
            .map_err(|_| "connection limit closed".to_string())?;
        let (stream, peer) = match listener.accept().await {
            Ok(connection) => connection,
            Err(error) => {
                // Out of file descriptors and the like: back off and keep
                // listening. Returning here used to stop the listener.
                warn!(%error, "accept failed");
                tokio::time::sleep(Duration::from_millis(100)).await;
                continue;
            }
        };
        let tls = tls.clone();
        let router = router.clone();
        tokio::spawn(async move {
            let _permit = permit;
            let router = router.layer(map_request(move |mut request: Request<Body>| async move {
                request.extensions_mut().insert(peer);
                request.extensions_mut().insert(axum::extract::ConnectInfo(peer));
                request
            }));
            let Some(acceptor) = tls else {
                serve_connection(TokioIo::new(stream), router, peer).await;
                return;
            };
            match tokio::time::timeout(TLS_HANDSHAKE_TIMEOUT, acceptor.accept(stream)).await {
                Ok(Ok(tls_stream)) => {
                    tracing::debug!(peer = %peer, "TLS connection accepted");
                    serve_connection(TokioIo::new(tls_stream), router, peer).await;
                }
                Ok(Err(error)) => {
                    tracing::error!(peer = %peer, error = %error, "TLS handshake failed");
                }
                Err(_) => tracing::debug!(peer = %peer, "TLS handshake timed out"),
            }
        });
    }
}

async fn serve_connection<I>(io: I, router: Router, peer: std::net::SocketAddr)
where
    I: hyper::rt::Read + hyper::rt::Write + Unpin + Send + 'static,
{
    let mut builder = hyper_util::server::conn::auto::Builder::new(TokioExecutor::new());
    builder
        .http1()
        .timer(hyper_util::rt::TokioTimer::new())
        .header_read_timeout(HEADER_READ_TIMEOUT);
    // hyper's HTTP/2 default max_header_list_size is 16KB. Real
    // browsers routinely exceed that once several large
    // encrypted Laravel cookies (session, XSRF-TOKEN, panel
    // proof, plus other same-domain app cookies) stack up
    // alongside normal browser headers — h2 then silently
    // drops the headers that don't fit, which showed up as
    // requests arriving at PHP missing their session/XSRF
    // cookies (CSRF mismatch) on HTTPS (h2) but not on HTTP/1.1
    // (no such limit), even with a fresh/incognito browser.
    builder
        .http2()
        .timer(hyper_util::rt::TokioTimer::new())
        .max_header_list_size(256 * 1024)
        // Pings find dead peers, so their connections are closed.
        .keep_alive_interval(Some(Duration::from_secs(30)));
    if let Err(error) = builder
        .serve_connection_with_upgrades(io, TowerToHyperService::new(router.into_service()))
        .await
    {
        tracing::debug!(peer = %peer, error = %error, "connection ended with an error");
    }
}

#[derive(Serialize)]
pub struct ReloadResponse {
    pub success: bool,
    pub message: String,
    pub version: u64,
}

#[derive(Serialize)]
pub struct HealthResponse {
    pub status: String,
    pub version: u64,
    pub sites: u64,
}

#[derive(Serialize)]
pub struct UpstreamsHealthResponse {
    pub results: Vec<UpstreamHealth>,
}

#[derive(Serialize)]
pub struct UpstreamHealth {
    pub site: String,
    pub route: String,
    pub healthy: bool,
}

pub fn sample_snapshot() -> RuntimeSnapshot {
    sample_panel_snapshot("demo.local")
}

pub fn sample_panel_snapshot(panel_domain: &str) -> RuntimeSnapshot {
    let primary_domain = {
        let value = panel_domain.trim().to_lowercase();
        if value.is_empty() {
            "demo.local".to_string()
        } else {
            value
        }
    };
    let www_domain = format!("www.{primary_domain}");
    RuntimeSnapshot::new(
        1,
        Arc::from([SiteConfig {
            id: "demo".to_string(),
            scope: "system".to_string(),
            site_owner: None,
            hostnames: Arc::from([primary_domain.clone(), www_domain.clone()]),
            document_root: Some(PathBuf::from(format!("/var/www/{primary_domain}/public"))),
            php_version: None,
            runtime: "php".to_string(),
            project_root: None,
            node_entry_file: None,
            node_start_command: None,
            node_version: None,
            python_entry_file: None,
            python_start_command: None,
            python_version: None,
            python_run: Default::default(),
            enable_ssl: false,
            spa_fallback: true,
            routes: Arc::from([
                RouteConfig {
                    path_prefix: "/api/".to_string(),
                    action: RouteAction::Proxy(UpstreamConfig::Http(
                        "127.0.0.1:3000".parse().expect("valid upstream"),
                    )),
                },
                RouteConfig {
                    path_prefix: "/".to_string(),
                    action: RouteAction::Static,
                },
            ]),
            banned_ips: Arc::from([]),
            allowed_ips: Arc::from([]),
            cache: Default::default(),
        }]),
        Arc::from([TlsConfig {
            hostnames: Arc::from([primary_domain, www_domain]),
            cert_path: PathBuf::from(format!("/etc/drust/tls/{panel_domain}.crt")),
            key_path: PathBuf::from(format!("/etc/drust/tls/{panel_domain}.key")),
        }]),
        CachePolicy {
            enabled: true,
            ttl: Duration::from_secs(60),
            stale_while_revalidate: Duration::from_secs(30),
        },
    )
}

pub fn sample_dispatch_context() -> DispatchContext {
    DispatchContext {
        static_files: Some(StaticFileConfig {
            document_root: PathBuf::from("/var/www/demo/public"),
            index_file: "index.html".to_string(),
            spa_fallback: true,
        }),
    }
}

pub fn sample_tls_store(cert_path: PathBuf, key_path: PathBuf) -> TlsStore {
    TlsStore {
        identities: Arc::from([TlsIdentity {
            hostnames: Arc::from(["demo.local".to_string(), "www.demo.local".to_string()]),
            cert_path,
            key_path,
        }]),
    }
}

pub fn serve_sample(bind: &str) -> Result<(), String> {
    let snapshot = sample_snapshot();
    let dispatch = sample_dispatch_context();
    serve_demo_with_tls(
        snapshot,
        dispatch,
        SnapshotCacheConfig::fast(),
        DbSnapshotConfig::new(),
        bind,
        scaffold_tls_listener_config("127.0.0.1:8443", bind),
    )
}

#[cfg(test)]
mod edge_cache_tests {
    use super::*;
    use crate::edge_gateway::{CacheMode, SiteCacheConfig};
    use std::sync::atomic::{AtomicU16, AtomicUsize, Ordering};
    use tower::ServiceExt;

    /// An origin that counts requests and answers with `status`.
    async fn origin(status: Arc<AtomicU16>, hits: Arc<AtomicUsize>) -> std::net::SocketAddr {
        let app = Router::new().fallback(any(move || {
            let status = status.clone();
            let hits = hits.clone();
            async move {
                let count = hits.fetch_add(1, Ordering::SeqCst) + 1;
                let status = StatusCode::from_u16(status.load(Ordering::SeqCst)).unwrap();
                (status, [("content-type", "text/html")], format!("page {count}"))
            }
        }));
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        addr
    }

    fn gateway(upstream: std::net::SocketAddr, edge_ttl: Duration) -> Router {
        let site = SiteConfig {
            id: "1".into(),
            scope: "user".into(),
            site_owner: None,
            hostnames: Arc::from(["shop.test".to_string()]),
            document_root: None,
            php_version: None,
            runtime: "node".into(),
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
                action: RouteAction::Proxy(UpstreamConfig::Http(upstream)),
            }]),
            banned_ips: Arc::from([]),
            allowed_ips: Arc::from([]),
            cache: SiteCacheConfig {
                mode: CacheMode::Everything,
                edge_ttl,
                bypass_paths: Arc::from(["/admin".to_string()]),
                serve_stale: true,
                ..SiteCacheConfig::default()
            },
        };
        let snapshot = RuntimeSnapshot::new(1, Arc::from([site]), Arc::from([]), CachePolicy {
            enabled: true,
            ttl: Duration::from_secs(1),
            stale_while_revalidate: Duration::from_secs(1),
        });
        let state = make_demo_state(snapshot, sample_dispatch_context(), SnapshotCacheConfig::fast(), DbSnapshotConfig::new()).unwrap();
        build_demo_router(state)
    }

    async fn get(router: &Router, path: &str) -> (StatusCode, String, String) {
        let request = Request::builder().uri(path).header("host", "shop.test").body(Body::empty()).unwrap();
        let response = router.clone().oneshot(request).await.unwrap();
        let status = response.status();
        let label = response.headers().get(edge_cache::CACHE_STATUS_HEADER).map(|v| v.to_str().unwrap().to_string()).unwrap_or_default();
        let body = axum::body::to_bytes(response.into_body(), usize::MAX).await.unwrap();
        (status, label, String::from_utf8_lossy(&body).into_owned())
    }

    #[tokio::test]
    async fn caches_origin_pages_and_bypasses_admin_paths() {
        let status = Arc::new(AtomicU16::new(200));
        let hits = Arc::new(AtomicUsize::new(0));
        let router = gateway(origin(status.clone(), hits.clone()).await, Duration::from_secs(60));

        assert_eq!(get(&router, "/").await, (StatusCode::OK, "MISS".into(), "page 1".into()));
        assert_eq!(get(&router, "/").await, (StatusCode::OK, "HIT".into(), "page 1".into()));
        assert_eq!(hits.load(Ordering::SeqCst), 1);

        assert_eq!(get(&router, "/admin").await.1, "BYPASS");
        assert_eq!(get(&router, "/admin").await.1, "BYPASS");
        assert_eq!(hits.load(Ordering::SeqCst), 3);

        // Errors are passed through, never stored.
        status.store(500, Ordering::SeqCst);
        assert_eq!(get(&router, "/down").await, (StatusCode::INTERNAL_SERVER_ERROR, "DYNAMIC".into(), "page 4".into()));
    }

    #[tokio::test]
    async fn purging_needs_the_admin_token() {
        let router = gateway(origin(Arc::new(AtomicU16::new(200)), Arc::new(AtomicUsize::new(0))).await, Duration::from_secs(60));
        let purge = Request::builder().method("POST").uri("/__admin/cache/purge").header("content-type", "application/json")
            .body(Body::from(r#"{"domain":"shop.test"}"#)).unwrap();
        assert_eq!(router.oneshot(purge).await.unwrap().status(), StatusCode::UNAUTHORIZED);
    }

    #[tokio::test]
    async fn serves_the_expired_copy_when_the_origin_fails() {
        let status = Arc::new(AtomicU16::new(200));
        let hits = Arc::new(AtomicUsize::new(0));
        let router = gateway(origin(status.clone(), hits.clone()).await, Duration::from_secs(1));
        assert_eq!(get(&router, "/").await.1, "MISS");
        tokio::time::sleep(Duration::from_millis(1100)).await;

        status.store(502, Ordering::SeqCst);
        assert_eq!(get(&router, "/").await, (StatusCode::OK, "STALE".into(), "page 1".into()));

        status.store(200, Ordering::SeqCst);
        assert_eq!(get(&router, "/").await, (StatusCode::OK, "EXPIRED".into(), "page 3".into()));
        assert_eq!(get(&router, "/").await.1, "HIT");
    }

    #[test]
    fn snapshot_fingerprint_ignores_site_order_but_not_content() {
        let sample = sample_snapshot();
        let mut other = sample.sites[0].clone();
        other.id = "other".into();
        other.hostnames = Arc::from(["other.test".to_string()]);
        let mut sites = sample.sites.to_vec();
        sites.push(other);
        let snapshot = RuntimeSnapshot::new(sample.version, Arc::from(sites), sample.tls.clone(), sample.cache.clone());
        let mut reversed = snapshot.sites.to_vec();
        reversed.reverse();
        let reordered = RuntimeSnapshot::new(snapshot.version, Arc::from(reversed.clone()), snapshot.tls.clone(), snapshot.cache.clone());
        assert_eq!(snapshot_fingerprint(&snapshot), snapshot_fingerprint(&reordered));
        reversed[0].cache.serve_stale = !reversed[0].cache.serve_stale;
        let changed = RuntimeSnapshot::new(snapshot.version, Arc::from(reversed), snapshot.tls.clone(), snapshot.cache.clone());
        assert_ne!(snapshot_fingerprint(&snapshot), snapshot_fingerprint(&changed));
    }

    #[tokio::test]
    async fn cache_hits_reuse_one_compressed_copy() {
        use std::io::Read;
        let page = "<p>hello world</p>".repeat(400);
        let app = Router::new().fallback(any({
            let page = page.clone();
            move || {
                let page = page.clone();
                async move { ([("content-type", "text/html")], page) }
            }
        }));
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        let router = gateway(addr, Duration::from_secs(60));

        let fetch = |router: Router| async move {
            let request = Request::builder().uri("/").header("host", "shop.test").header("accept-encoding", "gzip")
                .body(Body::empty()).unwrap();
            let response = router.oneshot(request).await.unwrap();
            let label = response.headers()[edge_cache::CACHE_STATUS_HEADER].to_str().unwrap().to_string();
            let encoding = response.headers()[axum::http::header::CONTENT_ENCODING].to_str().unwrap().to_string();
            let body = axum::body::to_bytes(response.into_body(), usize::MAX).await.unwrap();
            (label, encoding, body)
        };
        assert_eq!(fetch(router.clone()).await.0, "MISS");
        let (label, encoding, first) = fetch(router.clone()).await;
        assert_eq!((label.as_str(), encoding.as_str()), ("HIT", "gzip"));
        let mut decoded = String::new();
        flate2::read::GzDecoder::new(&first[..]).read_to_string(&mut decoded).unwrap();
        assert_eq!(decoded, page, "compressed exactly once");
        assert_eq!(fetch(router).await.2, first, "the same copy is reused");
    }

    #[tokio::test]
    async fn concurrent_misses_reach_the_origin_once() {
        let hits = Arc::new(AtomicUsize::new(0));
        let counter = hits.clone();
        let app = Router::new().fallback(any(move || {
            let counter = counter.clone();
            async move {
                let count = counter.fetch_add(1, Ordering::SeqCst) + 1;
                tokio::time::sleep(Duration::from_millis(200)).await;
                ([("content-type", "text/html")], format!("page {count}"))
            }
        }));
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        let router = gateway(addr, Duration::from_secs(60));

        let requests = (0..20).map(|_| {
            let router = router.clone();
            tokio::spawn(async move { get(&router, "/").await })
        });
        let mut labels = Vec::new();
        for request in requests.collect::<Vec<_>>() {
            let (status, label, body) = request.await.unwrap();
            assert_eq!((status, body.as_str()), (StatusCode::OK, "page 1"));
            labels.push(label);
        }
        assert_eq!(hits.load(Ordering::SeqCst), 1);
        assert_eq!(labels.iter().filter(|label| *label == "MISS").count(), 1);
    }
}
