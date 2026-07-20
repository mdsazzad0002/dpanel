use std::sync::Arc;

use axum::{extract::State, response::IntoResponse};

use crate::{
    api::{ApiResponse, ApiState, check_token},
    app,
};

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match app::run_disable_root_login() {
        Ok(()) => ApiResponse::ok("Root SSH login disabled").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
