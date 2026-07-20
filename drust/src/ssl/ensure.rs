use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

use super::certificate;

#[derive(Deserialize)]
pub(crate) struct Request {
    pub domain: String,
    pub root_path: String,
    pub include_www: Option<bool>,
    pub renew_before_days: Option<u64>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match certificate::ensure(
        &request.domain,
        &request.root_path,
        request.include_www.unwrap_or(false),
        request.renew_before_days.unwrap_or(30),
    ) {
        Ok(data) => ApiResponse::ok_data("SSL certificate is valid", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
