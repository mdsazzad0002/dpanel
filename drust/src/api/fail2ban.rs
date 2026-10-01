use std::sync::Arc;

use axum::{
    Router,
    extract::{Json, State},
    response::IntoResponse,
    routing::get,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    fail2ban,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/fail2ban", get(status).post(control))
        .route("/api/v1/fail2ban/ssh-history", get(ssh_history))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    #[serde(default)]
    ip: String,
    #[serde(default)]
    max_retry: u32,
}

async fn ssh_history(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(fail2ban::ssh_history).await {
        Ok(Ok(data)) => ApiResponse::ok_data("SSH login history loaded.", data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

async fn status(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(fail2ban::status).await {
        Ok(Ok(data)) => ApiResponse::ok_data("fail2ban status loaded.", data).into_response(),
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
            "unban" => fail2ban::unban(&request.ip),
            "ban" => fail2ban::ban(&request.ip),
            "policy" => fail2ban::set_policy(request.max_retry),
            "whitelist_add" => fail2ban::whitelist_add(&request.ip),
            "whitelist_remove" => fail2ban::whitelist_remove(&request.ip),
            _ => Err("Unsupported fail2ban action.".to_string()),
        }?;
        fail2ban::status().map(|data| (message, data))
    })
    .await;
    match result {
        Ok(Ok((message, data))) => ApiResponse::ok_data(&message, data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
