#![allow(dead_code)]

use std::{path::PathBuf, sync::Arc, time::Duration};

use axum::{
    body::Body,
    extract::State,
    http::Request,
    response::Response,
    routing::{any, post},
    Json, Router,
};
use serde::Serialize;
use tokio::sync::RwLock;
use tower_http::compression::CompressionLayer;
use tracing::info;

use super::{
    build_client, build_tls_config, dispatch, health_check_upstream, scaffold_tls_listener_config,
    CachePolicy, DispatchContext, ProxyConfig, RouteAction, RouteConfig, RuntimeSnapshot, SiteConfig,
    StaticFileConfig, TlsIdentity, TlsListenerConfig, TlsStore, TlsConfig, UpstreamConfig,
};

#[derive(Clone)]
pub struct DemoServerState {
    pub snapshot: Arc<RwLock<RuntimeSnapshot>>,
    pub dispatch: DispatchContext,
    pub proxy_client: Arc<reqwest::Client>,
}

pub fn serve_gateway(bind: &str) -> Result<(), String> {
    let snapshot = sample_snapshot();
    let dispatch = sample_dispatch_context();
    serve_demo_with_tls(snapshot, dispatch, bind, scaffold_tls_listener_config(bind, bind))
}

pub fn build_demo_router(state: DemoServerState) -> Router {
    Router::new()
        .route("/{*path}", any(handle_request))
        .route("/__admin/reload", post(handle_reload))
        .route("/__admin/health", any(handle_health))
        .route("/__admin/upstreams/health", any(handle_upstreams_health))
        .layer(CompressionLayer::new())
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
        .unwrap_or("-");
    let path = request.uri().path().to_string();
    info!(host = host, path = %path, "incoming request");
    let snapshot = state.snapshot.read().await.clone();
    dispatch(&snapshot, &state.dispatch, request, &state.proxy_client).await
}

pub async fn handle_reload(State(state): State<Arc<DemoServerState>>) -> Json<ReloadResponse> {
    let next = sample_snapshot();
    let version = next.version;
    *state.snapshot.write().await = next;
    Json(ReloadResponse {
        success: true,
        message: "snapshot reloaded".to_string(),
        version,
    })
}

pub async fn handle_health(State(state): State<Arc<DemoServerState>>) -> Json<HealthResponse> {
    let snapshot = state.snapshot.read().await;
    Json(HealthResponse {
        status: "ok".to_string(),
        version: snapshot.version,
        sites: snapshot.sites.len() as u64,
    })
}

pub async fn handle_upstreams_health(
    State(state): State<Arc<DemoServerState>>,
) -> Json<UpstreamsHealthResponse> {
    let snapshot = state.snapshot.read().await.clone();
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

pub fn make_demo_state(snapshot: RuntimeSnapshot, dispatch: DispatchContext) -> Result<DemoServerState, String> {
    let client = build_client(&ProxyConfig::default())?;
    Ok(DemoServerState {
        snapshot: Arc::new(RwLock::new(snapshot)),
        dispatch,
        proxy_client: Arc::new(client),
    })
}

pub fn serve_demo(snapshot: RuntimeSnapshot, dispatch: DispatchContext, bind: &str) -> Result<(), String> {
    let tls = scaffold_tls_listener_config("0.0.0.0:8443", bind);
    serve_demo_with_tls(snapshot, dispatch, bind, tls)
}

pub fn serve_demo_with_tls(
    snapshot: RuntimeSnapshot,
    dispatch: DispatchContext,
    http_bind: &str,
    tls_config: TlsListenerConfig,
) -> Result<(), String> {
    let state = make_demo_state(snapshot, dispatch)?;
    let runtime = tokio::runtime::Runtime::new().map_err(|error| format!("runtime build failed: {error}"))?;
    runtime.block_on(async move {
        let router = build_demo_router(state);
        let http_listener = tokio::net::TcpListener::bind(http_bind)
            .await
            .map_err(|error| format!("http bind failed: {error}"))?;
        info!(bind = http_bind, "HTTP listener ready");

        let http_task = tokio::spawn({
            let router = router.clone();
            async move {
                axum::serve(http_listener, router)
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
                Ok(_tls_stream) => {
                    tracing::info!(peer = %peer, "TLS connection accepted");
                    let _ = router;
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
    RuntimeSnapshot {
        version: 1,
        sites: Arc::from([SiteConfig {
            hostnames: Arc::from(["demo.local".to_string(), "www.demo.local".to_string()]),
            document_root: Some(PathBuf::from("/var/www/demo/public")),
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
        }]),
        tls: Arc::from([TlsConfig {
            hostnames: Arc::from(["demo.local".to_string(), "www.demo.local".to_string()]),
            cert_path: PathBuf::from("/etc/drust/tls/demo.local.crt"),
            key_path: PathBuf::from("/etc/drust/tls/demo.local.key"),
        }]),
        cache: CachePolicy {
            enabled: true,
            ttl: Duration::from_secs(60),
            stale_while_revalidate: Duration::from_secs(30),
        },
    }
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
    serve_demo_with_tls(snapshot, dispatch, bind, scaffold_tls_listener_config("127.0.0.1:8443", bind))
}
