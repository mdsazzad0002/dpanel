use std::sync::Arc;

use axum::{
    Router,
    extract::{Json, State},
    response::IntoResponse,
    routing::post,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    pgadmin_sso, postgresql,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/postgresql/service", post(set_service))
        .route("/api/v1/postgresql/pgadmin-login", post(pgadmin_login))
}

#[derive(Deserialize)]
pub(crate) struct Request {
    pub service: String,
    pub enabled: bool,
}

async fn set_service(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    // pgAdmin can take a while on first start; keep it off the async workers.
    let result = tokio::task::spawn_blocking(move || {
        postgresql::set_service(&request.service, request.enabled)
    })
    .await;
    match result {
        Ok(Ok(message)) => ApiResponse::ok(&message).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: task crashed: {error}")).into_response(),
    }
}

#[derive(Deserialize)]
pub(crate) struct PgadminLoginRequest {
    /// "admin" (superuser) or "database".
    pub target: String,
    pub database_name: Option<String>,
    pub database_user: String,
    pub database_password: String,
    pub port: Option<u16>,
}

/// Prepares pgAdmin for one login and returns the one-time URL the browser
/// should open on the panel domain.
async fn pgadmin_login(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<PgadminLoginRequest>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let target = match request.target.as_str() {
        "admin" => pgadmin_sso::Target::Admin {
            username: request.database_user,
            password: request.database_password,
        },
        "database" => pgadmin_sso::Target::Database {
            name: request.database_name.unwrap_or_default(),
            username: request.database_user,
            password: request.database_password,
        },
        _ => return ApiResponse::error("Failed: Unknown pgAdmin login target.").into_response(),
    };
    let port = request.port.unwrap_or(5432);
    // setup.py boots the whole pgAdmin app per call (a few seconds each).
    let result = tokio::task::spawn_blocking(move || pgadmin_sso::issue(&target, port)).await;
    match result {
        Ok(Ok(ticket)) => ApiResponse::ok_data(
            "pgAdmin login ready.",
            serde_json::json!({ "url": format!("{}?ticket={ticket}", pgadmin_sso::SSO_PATH) }),
        )
        .into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: task crashed: {error}")).into_response(),
    }
}
