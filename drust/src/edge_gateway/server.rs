#![allow(dead_code)]

use std::{path::PathBuf, sync::Arc, time::Duration};

use axum::{
    Json, Router,
    body::Body,
    extract::State,
    http::{HeaderMap, HeaderValue, Request, StatusCode},
    middleware::map_request,
    response::{IntoResponse, Response},
    routing::{any, post},
};
use futures_util::StreamExt;
use hyper::body::Body as HttpBody;
use hyper_util::rt::TokioIo;
use hyper_util::service::TowerToHyperService;
use serde::{Deserialize, Serialize};
use std::collections::HashMap;
use tokio::sync::Mutex;
use tokio::sync::RwLock;
use tokio::sync::mpsc;
use tracing::{info, warn};

use super::{
    BandwidthTracker, CachePolicy, DbSnapshotConfig, DispatchContext, ProxyConfig, RouteAction,
    RouteConfig, RuntimeSnapshot, SiteConfig, SnapshotCacheConfig, StaticFileConfig, TlsConfig,
    TlsIdentity, TlsListenerConfig, TlsStore, UpstreamConfig, build_client, build_tls_config,
    dispatch, health_check_upstream, load_runtime_snapshot, scaffold_tls_listener_config,
};

#[derive(Clone)]
pub struct DemoServerState {
    pub snapshot: Arc<RwLock<Arc<RuntimeSnapshot>>>,
    pub source_config: DbSnapshotConfig,
    pub dispatch: DispatchContext,
    pub proxy_client: Arc<reqwest::Client>,
    pub bandwidth: BandwidthTracker,
    pub terminal_tickets: Arc<Mutex<HashMap<String, super::terminal_ws::TerminalTicket>>>,
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
    let state = make_demo_state(snapshot, dispatch, cache_config, source_config)?;
    let runtime =
        tokio::runtime::Runtime::new().map_err(|error| format!("runtime build failed: {error}"))?;
    runtime.block_on(async move {
        state.bandwidth.spawn_periodic_flush();
        spawn_redis_reload_listener(state.clone());
        let app_router = build_demo_router(state);
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
            axum::serve(
                http_listener,
                router.into_make_service_with_connect_info::<std::net::SocketAddr>(),
            )
                .await
                .map_err(|error| format!("http server failed: {error}"))
        });

        let https_task = if tls_config.store.identities.is_empty() {
            info!(bind = %tls_config.bind, "HTTPS listener skipped because no TLS identities are configured");
            None
        } else {
            Some(tokio::spawn(run_https_listener(https_router, tls_config)))
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
        .route("/__admin/health", any(handle_health))
        .route("/__admin/upstreams/health", any(handle_upstreams_health))
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
    let response = dispatch(
        snapshot.as_ref(),
        site,
        &state.dispatch,
        request,
        &state.proxy_client,
    )
    .await;
    if let Some(domain) = canonical_domain {
        let download_bytes = response.body().size_hint().exact().unwrap_or(0);
        state
            .bandwidth
            .record(&domain, upload_bytes, download_bytes);
    }
    response
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
        Ok(Ok(next)) => {
            let version = next.version;
            *state.snapshot.write().await = Arc::new(next);
            super::clear_static_cache();
            Ok(version)
        }
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
    let loaded = tokio::task::spawn_blocking(move || load_runtime_snapshot(&source)).await;
    match loaded {
        Ok(Ok(next)) => {
            let current = state.snapshot.read().await.clone();
            let mut roots = Vec::new();
            let mut sites = current
                .sites
                .iter()
                .filter(|site| {
                    let targeted = site
                        .hostnames
                        .first()
                        .is_some_and(|domain| domains.contains(domain));
                    if targeted {
                        if let Some(root) = &site.document_root {
                            roots.push(root.clone());
                        }
                    }
                    !targeted
                })
                .cloned()
                .collect::<Vec<_>>();
            for site in next.sites.iter().filter(|site| {
                site.hostnames
                    .first()
                    .is_some_and(|domain| domains.contains(domain))
            }) {
                if let Some(root) = &site.document_root {
                    roots.push(root.clone());
                }
                sites.push(site.clone());
            }

            let updated = RuntimeSnapshot::new(
                next.version,
                Arc::from(sites),
                current.tls.clone(),
                current.cache.clone(),
            );
            *state.snapshot.write().await = Arc::new(updated);
            super::clear_static_cache_under(&roots);
            Ok(next.version)
        }
        Ok(Err(error)) => Err((error, state.snapshot.read().await.version)),
        Err(error) => Err((
            format!("snapshot reload worker failed: {error}"),
            state.snapshot.read().await.version,
        )),
    }
}

fn spawn_redis_reload_listener(state: DemoServerState) {
    let state = Arc::new(state);
    let (sender, mut receiver) = mpsc::channel::<Vec<String>>(16);
    let url = std::env::var("DRUST_REDIS_URL").unwrap_or_else(|_| "redis://127.0.0.1/".into());
    let channel = std::env::var("DRUST_REDIS_RELOAD_CHANNEL")
        .unwrap_or_else(|_| "dpanel_database_edge:reload".into());
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
            while let Ok(more) = receiver.try_recv() {
                domains.extend(more);
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
    let client = build_client(&ProxyConfig::default())?;
    Ok(DemoServerState {
        snapshot: Arc::new(RwLock::new(Arc::new(snapshot))),
        source_config,
        dispatch,
        proxy_client: Arc::new(client),
        bandwidth: BandwidthTracker::from_env(),
        terminal_tickets: Arc::new(Mutex::new(HashMap::new())),
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
    let state = make_demo_state(snapshot, dispatch, cache_config, source_config)?;
    let runtime =
        tokio::runtime::Runtime::new().map_err(|error| format!("runtime build failed: {error}"))?;
    runtime.block_on(async move {
        state.bandwidth.spawn_periodic_flush();
        spawn_redis_reload_listener(state.clone());
        let router = build_demo_router(state);
        let http_listener = tokio::net::TcpListener::bind(http_bind)
            .await
            .map_err(|error| format!("http bind failed: {error}"))?;
        info!(bind = http_bind, "HTTP listener ready");

        let http_task = tokio::spawn({
            let router = router.clone();
            async move {
                axum::serve(
                    http_listener,
                    router.into_make_service_with_connect_info::<std::net::SocketAddr>(),
                )
                    .await
                    .map_err(|error| format!("http server failed: {error}"))
            }
        });

        let https_task = if tls_config.store.identities.is_empty() {
            info!(bind = %tls_config.bind, "HTTPS listener skipped because no TLS identities are configured");
            None
        } else {
            Some(tokio::spawn(run_https_listener(router.clone(), tls_config)))
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

async fn run_https_listener(router: Router, tls_config: TlsListenerConfig) -> Result<(), String> {
    let server_config = build_tls_config(&tls_config.store)?;
    let acceptor = tokio_rustls::TlsAcceptor::from(Arc::new(server_config));
    if let Some(identity) = tls_config.store.resolve("demo.local") {
        info!(
            bind = %tls_config.bind,
            cert = %identity.cert_path.display(),
            "TLS identity resolved"
        );
    }
    let listener = tokio::net::TcpListener::bind(&tls_config.bind)
        .await
        .map_err(|error| format!("https bind failed: {error}"))?;
    info!(bind = %tls_config.bind, "HTTPS listener ready");

    loop {
        let (stream, peer) = listener
            .accept()
            .await
            .map_err(|error| format!("https accept failed: {error}"))?;
        let acceptor = acceptor.clone();
        let router = router.clone();
        tokio::spawn(async move {
            match acceptor.accept(stream).await {
                Ok(tls_stream) => {
                    tracing::info!(peer = %peer, "TLS connection accepted");
                    let io = TokioIo::new(tls_stream);
                    let router = router.clone().layer(map_request(
                        move |mut request: Request<Body>| async move {
                            request.extensions_mut().insert(peer);
                            request
                        },
                    ));
                    if let Err(error) = hyper::server::conn::http1::Builder::new()
                        .serve_connection(io, TowerToHyperService::new(router.into_service()))
                        .await
                    {
                        tracing::error!(peer = %peer, error = %error, "HTTPS connection failed");
                    }
                }
                Err(error) => {
                    tracing::error!(peer = %peer, error = %error, "TLS handshake failed");
                }
            }
        });
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
            cache_ttl: Duration::from_secs(60),
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
