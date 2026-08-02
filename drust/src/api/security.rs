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
    security,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/security", get(status).post(control))
}

#[derive(Deserialize)]
pub(crate) struct Request {
    pub action: String,
    pub port: Option<u16>,
    pub password_authentication: Option<String>,
    pub permit_root_login: Option<String>,
    pub pubkey_authentication: Option<String>,
    pub enabled: Option<bool>,
    pub ip: Option<String>,
    pub access_action: Option<String>,
    pub default_incoming: Option<String>,
    pub default_outgoing: Option<String>,
    pub allowed_ports: Option<Vec<u16>>,
}

async fn status(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match security::status() {
        Ok(data) => ApiResponse::ok_data("Live security status loaded.", data).into_response(),
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
    let result = match request.action.as_str() {
        "ssh_config" => security::apply_ssh_config(
            request.port.unwrap_or(22),
            request.password_authentication.as_deref().unwrap_or("Off"),
            request.permit_root_login.as_deref().unwrap_or("no"),
            request.pubkey_authentication.as_deref().unwrap_or("On"),
        ),
        "ssh_service" => security::set_ssh_service(request.enabled.unwrap_or(false)),
        "ssh_access" => security::set_ssh_access(
            request.ip.as_deref().unwrap_or(""),
            request.access_action.as_deref().unwrap_or(""),
        ),
        "firewall_config" => security::apply_firewall(
            request.enabled.unwrap_or(false),
            request.default_incoming.as_deref().unwrap_or("deny"),
            request.default_outgoing.as_deref().unwrap_or("allow"),
            request.allowed_ports.as_deref().unwrap_or(&[]),
        ),
        _ => Err("Unsupported security action.".to_string()),
    };
    match result.and_then(|message| security::status().map(|data| (message, data))) {
        Ok((message, data)) => ApiResponse::ok_data(&message, data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
