use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    vhost,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    pub apache_backend_port: Option<u16>,
    pub nginx_frontend_port: Option<u16>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let apache_port = request.apache_backend_port.unwrap_or(8080);
    let nginx_port = request.nginx_frontend_port.unwrap_or(80);

    match vhost::run_fix_web_stack(apache_port, nginx_port) {
        Ok(()) => ApiResponse::ok("Web stack repaired successfully").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
