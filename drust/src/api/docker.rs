use std::sync::Arc;

use axum::{
    Router,
    extract::{Json, State},
    response::IntoResponse,
    routing::{get, post},
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    docker,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/docker", get(status).post(control))
        .route("/api/v1/docker/logs", post(logs))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    #[serde(default)]
    id: String,
    #[serde(default)]
    image: String,
    #[serde(default)]
    spec: Option<docker::RunSpec>,
}

#[derive(Deserialize)]
struct LogsRequest {
    id: String,
    #[serde(default)]
    lines: Option<u32>,
}

async fn status(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(docker::status).await {
        Ok(Ok(data)) => ApiResponse::ok_data("Docker status loaded.", data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

async fn control(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result = tokio::task::spawn_blocking(move || {
        let message = match request.action.as_str() {
            "start" | "stop" | "restart" | "remove" => docker::container_action(&request.action, &request.id),
            "run" => match &request.spec {
                Some(spec) => docker::run_container(spec),
                None => Err("A container spec is required.".to_string()),
            },
            "pull" => docker::pull(&request.image),
            "remove_image" => docker::remove_image(&request.image),
            "prune_images" => docker::prune_images(),
            _ => Err("Unsupported Docker action.".to_string()),
        }?;
        docker::status().map(|data| (message, data))
    })
    .await;
    match result {
        Ok(Ok((message, data))) => ApiResponse::ok_data(&message, data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

async fn logs(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<LogsRequest>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let lines = request.lines.unwrap_or(docker::DEFAULT_LOG_LINES);
    match tokio::task::spawn_blocking(move || docker::logs(&request.id, lines)).await {
        Ok(Ok(data)) => ApiResponse::ok_data("Container logs loaded.", data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
