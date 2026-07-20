use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
    routing::post,
    Router,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    database,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/database-request", post(handle))
}

#[derive(Deserialize)]
pub(crate) struct Request {
    pub action: String,
    pub database_name: String,
    pub database_user: String,
    pub database_password: String,
    pub database_host: Option<String>,
    pub database_port: Option<u16>,
    pub charset: Option<String>,
    pub collation: Option<String>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match database::run_database_request(
        request.action,
        request.database_name,
        request.database_user,
        request.database_password,
        request.database_host.unwrap_or_else(|| "127.0.0.1".into()),
        request.database_port.unwrap_or(3306),
        request.charset.unwrap_or_else(|| "utf8mb4".into()),
        request.collation.unwrap_or_else(|| "utf8mb4_unicode_ci".into()),
    ) {
        Ok(output) => ApiResponse::ok(&output).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
