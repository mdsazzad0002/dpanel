mod admin;
mod infer;
mod openai;
mod registry;

use axum::{
    Json, Router,
    extract::State,
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
};
use std::{net::SocketAddr, sync::Arc, time::Duration};

pub struct Config {
    pub bind: SocketAddr,
    pub api_key: String,
    pub models_dir: std::path::PathBuf,
    pub default_model: Option<String>,
    pub ctx_size: u32,
    pub threads: i32,
    pub max_loaded: usize,
    pub idle_unload: Duration,
    pub max_tokens: u32,
}

impl Config {
    fn from_env() -> Result<Self, String> {
        fn var(name: &str) -> Option<String> {
            std::env::var(name).ok().filter(|value| !value.trim().is_empty())
        }
        fn num<T: std::str::FromStr>(name: &str, default: T) -> Result<T, String> {
            match var(name) {
                Some(value) => value.trim().parse().map_err(|_| format!("{name} must be a number")),
                None => Ok(default),
            }
        }

        let api_key = var("DRUST_LLM_API_KEY").ok_or("DRUST_LLM_API_KEY is not set")?;
        let bind = var("DRUST_LLM_BIND")
            .unwrap_or_else(|| "127.0.0.1:9510".into())
            .parse()
            .map_err(|_| "DRUST_LLM_BIND must be host:port")?;
        // Leave one core for the panel and the sites; llama.cpp otherwise
        // takes every core and the box stops answering while it generates.
        let cores = std::thread::available_parallelism().map_or(2, |n| n.get());
        let threads = num("DRUST_LLM_THREADS", cores.saturating_sub(1).max(1) as i32)?;

        Ok(Self {
            bind,
            api_key,
            models_dir: var("DRUST_LLM_MODELS_DIR")
                .unwrap_or_else(|| "/var/lib/drust/llm".into())
                .into(),
            default_model: var("DRUST_LLM_DEFAULT_MODEL"),
            ctx_size: num("DRUST_LLM_CTX_SIZE", 4096)?,
            threads,
            max_loaded: num("DRUST_LLM_MAX_LOADED", 1usize)?.max(1),
            idle_unload: Duration::from_secs(num("DRUST_LLM_IDLE_UNLOAD_SECS", 900)?),
            max_tokens: num("DRUST_LLM_MAX_TOKENS", 2048)?,
        })
    }
}

pub struct AppState {
    pub config: Config,
    pub registry: Arc<registry::Registry>,
    pub pulls: admin::Pulls,
}

/// Every route, admin ones included, takes the same bearer key: the AI
/// Gateway stores it as the provider's API key.
pub fn check_key(state: &AppState, headers: &HeaderMap) -> Result<(), Response> {
    let provided = headers
        .get("authorization")
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.strip_prefix("Bearer "))
        .unwrap_or("");
    if constant_time_eq(provided.as_bytes(), state.config.api_key.as_bytes()) {
        Ok(())
    } else {
        Err(api_error(StatusCode::UNAUTHORIZED, "invalid_api_key", "Invalid API key"))
    }
}

fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    a.len() == b.len() && a.iter().zip(b).fold(0u8, |acc, (x, y)| acc | (x ^ y)) == 0
}

/// OpenAI's error shape, so OpenAI clients show the message.
pub fn api_error(status: StatusCode, code: &str, message: &str) -> Response {
    let body = serde_json::json!({
        "error": { "message": message, "type": "invalid_request_error", "code": code }
    });
    (status, Json(body)).into_response()
}

async fn health(State(state): State<Arc<AppState>>) -> Json<serde_json::Value> {
    Json(serde_json::json!({
        "status": "ok",
        "loaded": state.registry.loaded_ids().await,
    }))
}

#[tokio::main]
async fn main() -> std::process::ExitCode {
    tracing_subscriber::fmt::init();
    let config = match Config::from_env() {
        Ok(config) => config,
        Err(error) => {
            eprintln!("{error}");
            return std::process::ExitCode::from(1);
        }
    };
    if let Err(error) = std::fs::create_dir_all(&config.models_dir) {
        eprintln!("cannot create {}: {error}", config.models_dir.display());
        return std::process::ExitCode::from(1);
    }
    let registry = match registry::Registry::new() {
        Ok(registry) => registry,
        Err(error) => {
            eprintln!("{error}");
            return std::process::ExitCode::from(1);
        }
    };

    let bind = config.bind;
    let state = Arc::new(AppState {
        config,
        registry: Arc::new(registry),
        pulls: Default::default(),
    });
    registry::spawn_idle_reaper(state.clone());

    let app = Router::new()
        .route("/health", get(health))
        .merge(openai::routes())
        .merge(admin::routes())
        .with_state(state);

    let listener = match tokio::net::TcpListener::bind(bind).await {
        Ok(listener) => listener,
        Err(error) => {
            eprintln!("cannot bind {bind}: {error}");
            return std::process::ExitCode::from(1);
        }
    };
    tracing::info!("drust-llm listening on {bind}");
    match axum::serve(listener, app).await {
        Ok(()) => std::process::ExitCode::SUCCESS,
        Err(error) => {
            eprintln!("{error}");
            std::process::ExitCode::from(1)
        }
    }
}
