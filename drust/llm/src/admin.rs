use crate::{
    AppState, api_error, check_key,
    registry::{meminfo, model_path, valid_id},
};
use axum::{
    Json, Router,
    extract::{Path, State},
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::{delete, get, post},
};
use futures_util::StreamExt;
use serde::{Deserialize, Serialize};
use serde_json::json;
use std::{collections::HashMap, sync::Arc};
use tokio::{io::AsyncWriteExt, sync::Mutex};

#[derive(Clone, Serialize)]
pub struct PullStatus {
    pub state: &'static str, // downloading | done | failed
    pub downloaded_bytes: u64,
    pub total_bytes: Option<u64>,
    pub error: Option<String>,
}

pub type Pulls = Mutex<HashMap<String, PullStatus>>;

pub fn routes() -> Router<Arc<AppState>> {
    Router::new()
        .route("/admin/status", get(status))
        .route("/admin/models", get(list))
        .route("/admin/models/pull", post(pull))
        .route("/admin/models/{id}/load", post(load))
        .route("/admin/models/{id}/unload", post(unload))
        .route("/admin/models/{id}", delete(remove))
}

async fn list(State(state): State<Arc<AppState>>, headers: HeaderMap) -> Response {
    if let Err(response) = check_key(&state, &headers) {
        return response;
    }
    let models = state.registry.list(&state.config.models_dir).await;
    let pulls = state.pulls.lock().await.clone();
    Json(json!({ "models": models, "pulls": pulls })).into_response()
}

/// Settings and memory for the panel's status card.
async fn status(State(state): State<Arc<AppState>>, headers: HeaderMap) -> Response {
    if let Err(response) = check_key(&state, &headers) {
        return response;
    }
    let config = &state.config;
    let loaded: Vec<_> = state
        .registry
        .loaded_detail()
        .await
        .into_iter()
        .map(|(id, idle)| json!({ "id": id, "idle_seconds": idle }))
        .collect();
    Json(json!({
        "version": env!("CARGO_PKG_VERSION"),
        "loaded": loaded,
        "memory": {
            "total_bytes": meminfo("MemTotal:").unwrap_or(0),
            "available_bytes": meminfo("MemAvailable:").unwrap_or(0),
        },
        "config": {
            "default_model": config.default_model,
            "ctx_size": config.ctx_size,
            "max_tokens": config.max_tokens,
            "threads": config.threads,
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
    {
        let mut pulls = state.pulls.lock().await;
        if pulls.get(&id).is_some_and(|pull| pull.state == "downloading") {
            return api_error(StatusCode::CONFLICT, "pull_running", "this model is already downloading");
        }
        pulls.insert(
            id.clone(),
            PullStatus { state: "downloading", downloaded_bytes: 0, total_bytes: None, error: None },
        );
    }

    let task_state = state.clone();
    let task_id = id.clone();
    tokio::spawn(async move {
        let result = download(&task_state, &task_id, &req.url).await;
        let mut pulls = task_state.pulls.lock().await;
        if let Some(pull) = pulls.get_mut(&task_id) {
            match result {
                Ok(()) => pull.state = "done",
                Err(error) => {
                    tracing::warn!(model = %task_id, "pull failed: {error}");
                    pull.state = "failed";
                    pull.error = Some(error);
                }
            }
        }
    });

    (StatusCode::ACCEPTED, Json(json!({ "id": id, "state": "downloading" }))).into_response()
}

async fn download(state: &AppState, id: &str, url: &str) -> Result<(), String> {
    let final_path = model_path(&state.config.models_dir, id);
    let part_path = final_path.with_extension("gguf.part");
    let result = async {
        let response = reqwest::Client::new()
            .get(url)
            .send()
            .await
            .and_then(reqwest::Response::error_for_status)
            .map_err(|error| error.to_string())?;
        let total = response.content_length();
        if let Some(pull) = state.pulls.lock().await.get_mut(id) {
            pull.total_bytes = total;
        }

        let mut file = tokio::fs::File::create(&part_path).await.map_err(|error| error.to_string())?;
        let mut body = response.bytes_stream();
        let mut downloaded = 0u64;
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
            downloaded += chunk.len() as u64;
            if let Some(pull) = state.pulls.lock().await.get_mut(id) {
                pull.downloaded_bytes = downloaded;
            }
        }
        file.flush().await.map_err(|error| error.to_string())?;
        tokio::fs::rename(&part_path, &final_path).await.map_err(|error| error.to_string())
    }
    .await;
    if result.is_err() {
        let _ = tokio::fs::remove_file(&part_path).await;
    }
    result
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
    let was_loaded = state.registry.unload(&id).await;
    Json(json!({ "id": id, "loaded": false, "was_loaded": was_loaded })).into_response()
}

async fn remove(State(state): State<Arc<AppState>>, headers: HeaderMap, Path(id): Path<String>) -> Response {
    if let Err(response) = check_key(&state, &headers).and_then(|()| checked_id(&id)) {
        return response;
    }
    state.registry.unload(&id).await;
    state.pulls.lock().await.remove(&id);
    match tokio::fs::remove_file(model_path(&state.config.models_dir, &id)).await {
        Ok(()) => Json(json!({ "id": id, "deleted": true })).into_response(),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
            api_error(StatusCode::NOT_FOUND, "model_not_found", "model not found")
        }
        Err(error) => api_error(StatusCode::INTERNAL_SERVER_ERROR, "delete_failed", &error.to_string()),
    }
}
