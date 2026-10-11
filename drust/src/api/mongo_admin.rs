use std::sync::Arc;

use axum::{
    Router,
    extract::{DefaultBodyLimit, Json, State},
    response::IntoResponse,
    routing::post,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    mongo_admin,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new().route(
        "/api/v1/mongodb/admin",
        // Imports send a whole JSON file at once.
        post(run).layer(DefaultBodyLimit::max(64 * 1024 * 1024)),
    )
}

#[derive(Deserialize)]
pub(crate) struct Request {
    pub connection: mongo_admin::Connection,
    pub op: mongo_admin::Op,
}

async fn run(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match mongo_admin::run(&request.connection, request.op).await {
        Ok(data) => {
            let message = data["message"].as_str().unwrap_or("OK").to_string();
            ApiResponse::ok_data(&message, data).into_response()
        }
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}
