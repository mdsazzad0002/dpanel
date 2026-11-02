use crate::{
    AppState, api_error, check_key, profile,
    registry::{meminfo, model_path, valid_id},
    tune,
};
use axum::{
    Json, Router,
    extract::{Path, State},
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::{delete, get, post},
};
use futures_util::{StreamExt, future::try_join_all};
use reqwest::{StatusCode as HttpStatus, header};
use serde::{Deserialize, Serialize};
use serde_json::json;
use std::{
    collections::HashMap,
    path::Path as FsPath,
    sync::{
        Arc,
        atomic::{AtomicU64, Ordering},
    },
    time::Duration,
};
use tokio::{
    io::{AsyncSeekExt, AsyncWriteExt},
    sync::Mutex,
    task::AbortHandle,
};

#[derive(Clone, Serialize)]
pub struct PullStatus {
    pub state: &'static str, // downloading | done | failed
    pub downloaded_bytes: u64,
    pub total_bytes: Option<u64>,
    /// Bytes per second, averaged over the last few seconds.
    pub speed_bps: u64,
    /// Connections in use; 1 when the server doesn't take range requests.
    pub connections: usize,
    pub error: Option<String>,
    #[serde(skip)]
    abort: Option<AbortHandle>,
}

pub type Pulls = Mutex<HashMap<String, PullStatus>>;

/// Ranged downloads are split into pieces of this size, handed out to the
/// connections in turn, so a slow connection doesn't hold up the end.
const PIECE_BYTES: u64 = 16 * 1024 * 1024;
/// Attempts per piece before the whole download fails.
const PIECE_ATTEMPTS: u32 = 5;

pub fn routes() -> Router<Arc<AppState>> {
    Router::new()
        .route("/admin/status", get(status))
        .route("/admin/models", get(list))
        .route("/admin/models/pull", post(pull))
        .route("/admin/pulls/{id}", delete(cancel))
        .route("/admin/models/{id}/load", post(load))
        .route("/admin/models/{id}/unload", post(unload))
        .route("/admin/models/{id}/tune", post(start_tune))
        .route("/admin/models/{id}", delete(remove))
}

async fn list(State(state): State<Arc<AppState>>, headers: HeaderMap) -> Response {
    if let Err(response) = check_key(&state, &headers) {
        return response;
    }
    let models = state.registry.list(&state.config.models_dir).await;
    let pulls = state.pulls.lock().await.clone();
    let tunes = state.tunes.lock().await.clone();
    Json(json!({ "models": models, "pulls": pulls, "tunes": tunes })).into_response()
}

/// Settings and memory for the panel's status card.
async fn status(State(state): State<Arc<AppState>>, headers: HeaderMap) -> Response {
    if let Err(response) = check_key(&state, &headers) {
        return response;
    }
    let config = &state.config;
    let loaded = state.registry.loaded_detail().await;
    Json(json!({
        "version": env!("CARGO_PKG_VERSION"),
        "loaded": loaded,
        "gpus": profile::gpus(),
        "memory": {
            "total_bytes": meminfo("MemTotal:").unwrap_or(0),
            "available_bytes": meminfo("MemAvailable:").unwrap_or(0),
        },
        "config": {
            "default_model": config.default_model,
            "ctx_size": config.ctx_size,
            "max_tokens": config.max_tokens,
            "threads": config.threads,
            "gpu_layers": config.gpu_layers,
            "gpu_backend": if cfg!(feature = "vulkan") { Some("vulkan") } else { None },
            "parallel": config.parallel,
            "max_loaded": config.max_loaded,
            "idle_unload_secs": config.idle_unload.as_secs(),
        },
    }))
    .into_response()
}

#[derive(Deserialize)]
struct PullRequest {
    url: String,
    /// Model id to save as; defaults to the file name in the URL.
    #[serde(default)]
    id: Option<String>,
}

/// Starts a GGUF download (e.g. a Hugging Face `.../resolve/main/x.gguf`
/// link) in the background; progress shows under GET /admin/models.
async fn pull(
    State(state): State<Arc<AppState>>,
    headers: HeaderMap,
    Json(req): Json<PullRequest>,
) -> Response {
    if let Err(response) = check_key(&state, &headers) {
        return response;
    }
    let bad = |message: &str| api_error(StatusCode::BAD_REQUEST, "invalid_request", message);
    if !req.url.starts_with("https://") {
        return bad("url must be https");
    }
    let id = req.id.clone().unwrap_or_else(|| {
        let name = req.url.split(['?', '#']).next().unwrap_or("").rsplit('/').next().unwrap_or("");
        name.strip_suffix(".gguf").unwrap_or(name).to_string()
    });
    if !valid_id(&id) {
        return bad("invalid model id; use letters, digits, '.', '_' and '-'");
    }
    if model_path(&state.config.models_dir, &id).exists() {
        return api_error(StatusCode::CONFLICT, "model_exists", "a model with this id already exists");
    }
    let mut pulls = state.pulls.lock().await;
    if pulls.get(&id).is_some_and(|pull| pull.state == "downloading") {
        return api_error(StatusCode::CONFLICT, "pull_running", "this model is already downloading");
    }

    let task_state = state.clone();
    let task_id = id.clone();
    // Spawned while holding the lock, so the task can't finish before its
    // abort handle is stored.
    let task = tokio::spawn(async move {
        let result = download(&task_state, &task_id, &req.url).await;
        let mut pulls = task_state.pulls.lock().await;
        if let Some(pull) = pulls.get_mut(&task_id) {
            pull.abort = None;
            pull.speed_bps = 0;
            match result {
                Ok(()) => {
                    pull.state = "done";
                    // The once-a-second progress report may lag the last bytes.
                    pull.downloaded_bytes = pull.total_bytes.unwrap_or(pull.downloaded_bytes);
                    // First time this machine sees the model: find its settings.
                    let (state, id) = (task_state.clone(), task_id.clone());
                    tokio::spawn(async move {
                        let _ = tune::start(state, id).await;
                    });
                }
                Err(error) => {
                    tracing::warn!(model = %task_id, "pull failed: {error}");
                    pull.state = "failed";
                    pull.error = Some(error);
                }
            }
        }
    });
    pulls.insert(
        id.clone(),
        PullStatus {
            state: "downloading",
            downloaded_bytes: 0,
            total_bytes: None,
            speed_bps: 0,
            connections: 0,
            error: None,
            abort: Some(task.abort_handle()),
        },
    );

    (StatusCode::ACCEPTED, Json(json!({ "id": id, "state": "downloading" }))).into_response()
}

/// Stops a running download and deletes its partial file; for a finished
/// or failed one, just clears it from the list.
async fn cancel(State(state): State<Arc<AppState>>, headers: HeaderMap, Path(id): Path<String>) -> Response {
    if let Err(response) = check_key(&state, &headers).and_then(|()| checked_id(&id)) {
        return response;
    }
    let Some(pull) = state.pulls.lock().await.remove(&id) else {
        return api_error(StatusCode::NOT_FOUND, "pull_not_found", "no download with this id");
    };
    let cancelled = pull.state == "downloading";
    if let Some(abort) = pull.abort {
        abort.abort();
    }
    if cancelled {
        let part_path = model_path(&state.config.models_dir, &id).with_extension("gguf.part");
        let _ = tokio::fs::remove_file(part_path).await;
    }
    Json(json!({ "id": id, "cancelled": cancelled })).into_response()
}

async fn download(state: &AppState, id: &str, url: &str) -> Result<(), String> {
    let final_path = model_path(&state.config.models_dir, id);
    let part_path = final_path.with_extension("gguf.part");
    let progress = AtomicU64::new(0);
    let result = async {
        let fetch = fetch(state, id, url, &part_path, &progress);
        tokio::select! {
            result = fetch => result?,
            never = report_progress(state, id, &progress) => match never {},
        }
        tokio::fs::rename(&part_path, &final_path).await.map_err(|error| error.to_string())
    }
    .await;
    if result.is_err() {
        let _ = tokio::fs::remove_file(&part_path).await;
    }
    result
}

/// Copies the byte counter into the pull status once a second, with a
/// smoothed speed. Runs until `download` drops it.
async fn report_progress(state: &AppState, id: &str, progress: &AtomicU64) -> std::convert::Infallible {
    let mut ticker = tokio::time::interval(Duration::from_secs(1));
    let mut last = 0u64;
    let mut speed = 0f64;
    loop {
        ticker.tick().await;
        let now = progress.load(Ordering::Relaxed);
        let delta = now.saturating_sub(last) as f64;
        speed = if speed == 0.0 { delta } else { speed * 0.7 + delta * 0.3 };
        last = now;
        if let Some(pull) = state.pulls.lock().await.get_mut(id) {
            pull.downloaded_bytes = now;
            pull.speed_bps = speed as u64;
        }
    }
}

async fn set_pull(state: &AppState, id: &str, total: Option<u64>, connections: usize) {
    if let Some(pull) = state.pulls.lock().await.get_mut(id) {
        pull.total_bytes = total;
        pull.connections = connections;
    }
}

fn client() -> Result<reqwest::Client, String> {
    reqwest::Client::builder()
        .connect_timeout(Duration::from_secs(20))
        // A stalled connection fails its piece and is retried.
        .read_timeout(Duration::from_secs(60))
        .build()
        .map_err(|error| error.to_string())
}

async fn fetch(state: &AppState, id: &str, url: &str, part_path: &FsPath, progress: &AtomicU64) -> Result<(), String> {
    let client = client()?;
    // The first 4 bytes say whether ranges work (206), how big the file is
    // and whether it is a GGUF file at all, before anything is written.
    let probe = client
        .get(url)
        .header(header::RANGE, "bytes=0-3")
        .send()
        .await
        .and_then(reqwest::Response::error_for_status)
        .map_err(|error| error.to_string())?;
    let total = (probe.status() == HttpStatus::PARTIAL_CONTENT)
        .then(|| {
            probe.headers().get(header::CONTENT_RANGE)?.to_str().ok()?.rsplit('/').next()?.parse::<u64>().ok()
        })
        .flatten();
    let Some(total) = total.filter(|&total| total > PIECE_BYTES) else {
        drop(probe);
        return fetch_single(state, id, &client, url, part_path, progress).await;
    };
    if !probe.bytes().await.map_err(|error| error.to_string())?.starts_with(b"GGUF") {
        return Err("not a GGUF file".to_string());
    }

    let connections = state.config.pull_connections.min(total.div_ceil(PIECE_BYTES) as usize);
    set_pull(state, id, Some(total), connections).await;
    let file = tokio::fs::File::create(part_path).await.map_err(|error| error.to_string())?;
    file.set_len(total).await.map_err(|error| error.to_string())?;
    drop(file);

    let next_piece = AtomicU64::new(0);
    let workers = (0..connections).map(|_| fetch_pieces(&client, url, part_path, total, &next_piece, progress));
    try_join_all(workers).await?;
    Ok(())
}

/// One connection: takes the next unclaimed piece until none are left.
async fn fetch_pieces(
    client: &reqwest::Client,
    url: &str,
    part_path: &FsPath,
    total: u64,
    next_piece: &AtomicU64,
    progress: &AtomicU64,
) -> Result<(), String> {
    let mut file = tokio::fs::OpenOptions::new().write(true).open(part_path).await.map_err(|error| error.to_string())?;
    loop {
        let start = next_piece.fetch_add(PIECE_BYTES, Ordering::Relaxed);
        if start >= total {
            return file.flush().await.map_err(|error| error.to_string());
        }
        let end = (start + PIECE_BYTES).min(total);
        let mut pos = start;
        let mut attempt = 0;
        while pos < end {
            if let Err(error) = fetch_range(client, url, &mut pos, end, &mut file, progress).await {
                attempt += 1;
                if attempt >= PIECE_ATTEMPTS {
                    return Err(error);
                }
                tracing::debug!("piece at {start} failed (attempt {attempt}): {error}");
                tokio::time::sleep(Duration::from_secs(2 * attempt as u64)).await;
            }
        }
    }
}

/// Downloads bytes `pos..end` into the file, advancing `pos` as it goes so
/// a retry resumes where this left off. Requests the original URL each
/// time, so an expired CDN redirect is renewed.
async fn fetch_range(
    client: &reqwest::Client,
    url: &str,
    pos: &mut u64,
    end: u64,
    file: &mut tokio::fs::File,
    progress: &AtomicU64,
) -> Result<(), String> {
    let response = client
        .get(url)
        .header(header::RANGE, format!("bytes={}-{}", *pos, end - 1))
        .send()
        .await
        .and_then(reqwest::Response::error_for_status)
        .map_err(|error| error.to_string())?;
    if response.status() != HttpStatus::PARTIAL_CONTENT {
        return Err(format!("expected a partial response, got HTTP {}", response.status()));
    }
    file.seek(std::io::SeekFrom::Start(*pos)).await.map_err(|error| error.to_string())?;
    let mut body = response.bytes_stream();
    while let Some(chunk) = body.next().await {
        let chunk = chunk.map_err(|error| error.to_string())?;
        let take = chunk.len().min((end - *pos) as usize);
        file.write_all(&chunk[..take]).await.map_err(|error| error.to_string())?;
        *pos += take as u64;
        progress.fetch_add(take as u64, Ordering::Relaxed);
        if *pos >= end {
            break;
        }
    }
    if *pos < end { Err("connection closed early".to_string()) } else { Ok(()) }
}

/// For servers without range support (or small files): one plain stream.
async fn fetch_single(
    state: &AppState,
    id: &str,
    client: &reqwest::Client,
    url: &str,
    part_path: &FsPath,
    progress: &AtomicU64,
) -> Result<(), String> {
    let response = client
        .get(url)
        .send()
        .await
        .and_then(reqwest::Response::error_for_status)
        .map_err(|error| error.to_string())?;
    set_pull(state, id, response.content_length(), 1).await;

    let mut file = tokio::fs::File::create(part_path).await.map_err(|error| error.to_string())?;
    let mut body = response.bytes_stream();
    let mut checked_magic = false;
    while let Some(chunk) = body.next().await {
        let chunk = chunk.map_err(|error| error.to_string())?;
        // Every GGUF file starts with "GGUF"; an HTML error page doesn't.
        if !checked_magic {
            if !chunk.starts_with(b"GGUF") {
                return Err("not a GGUF file".to_string());
            }
            checked_magic = true;
        }
        file.write_all(&chunk).await.map_err(|error| error.to_string())?;
        progress.fetch_add(chunk.len() as u64, Ordering::Relaxed);
    }
    file.flush().await.map_err(|error| error.to_string())
}

fn checked_id(id: &str) -> Result<(), Response> {
    if valid_id(id) {
        Ok(())
    } else {
        Err(api_error(StatusCode::BAD_REQUEST, "invalid_request", "invalid model id"))
    }
}

async fn load(State(state): State<Arc<AppState>>, headers: HeaderMap, Path(id): Path<String>) -> Response {
    if let Err(response) = check_key(&state, &headers).and_then(|()| checked_id(&id)) {
        return response;
    }
    match state.registry.get(&state, &id).await {
        Ok(_) => Json(json!({ "id": id, "loaded": true })).into_response(),
        Err(error) => api_error(StatusCode::SERVICE_UNAVAILABLE, "model_load_failed", &error),
    }
}

async fn unload(State(state): State<Arc<AppState>>, headers: HeaderMap, Path(id): Path<String>) -> Response {
    if let Err(response) = check_key(&state, &headers).and_then(|()| checked_id(&id)) {
        return response;
    }
    let was_loaded = state.registry.unload(&state.config.models_dir, &id).await;
    Json(json!({ "id": id, "loaded": false, "was_loaded": was_loaded })).into_response()
}

/// Starts the self-test (GPU settings and speed) in the background;
/// progress shows under GET /admin/models as `tunes`.
async fn start_tune(State(state): State<Arc<AppState>>, headers: HeaderMap, Path(id): Path<String>) -> Response {
    if let Err(response) = check_key(&state, &headers).and_then(|()| checked_id(&id)) {
        return response;
    }
    if !model_path(&state.config.models_dir, &id).exists() {
        return api_error(StatusCode::NOT_FOUND, "model_not_found", "model not found");
    }
    match tune::start(state.clone(), id.clone()).await {
        Ok(()) => (StatusCode::ACCEPTED, Json(json!({ "id": id, "state": "queued" }))).into_response(),
        Err(error) => api_error(StatusCode::CONFLICT, "tune_running", &error),
    }
}

async fn remove(State(state): State<Arc<AppState>>, headers: HeaderMap, Path(id): Path<String>) -> Response {
    if let Err(response) = check_key(&state, &headers).and_then(|()| checked_id(&id)) {
        return response;
    }
    state.registry.unload(&state.config.models_dir, &id).await;
    state.pulls.lock().await.remove(&id);
    state.tunes.lock().await.remove(&id);
    let _ = tokio::fs::remove_file(profile::path(&state.config.models_dir, &id)).await;
    match tokio::fs::remove_file(model_path(&state.config.models_dir, &id)).await {
        Ok(()) => Json(json!({ "id": id, "deleted": true })).into_response(),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
            api_error(StatusCode::NOT_FOUND, "model_not_found", "model not found")
        }
        Err(error) => api_error(StatusCode::INTERNAL_SERVER_ERROR, "delete_failed", &error.to_string()),
    }
}
