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
    cron,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/cron-job", post(handle))
}

#[derive(Deserialize)]
pub(crate) struct Request {
    pub action: String,
    pub id: String,
    pub user: Option<String>,
    pub expression: Option<String>,
    pub command: Option<String>,
    pub enabled: Option<bool>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let result = match request.action.as_str() {
        "upsert" => cron::upsert(
            &request.id,
            request.user.as_deref().unwrap_or(""),
            request.expression.as_deref().unwrap_or(""),
            request.command.as_deref().unwrap_or(""),
            request.enabled.unwrap_or(true),
        ),
        "delete" => cron::delete(&request.id),
        _ => Err("Unsupported cron action.".to_string()),
    };

    match result {
        Ok(message) => ApiResponse::ok(&message).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
