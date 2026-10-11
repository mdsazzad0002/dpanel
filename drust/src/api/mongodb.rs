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
    mongo_express_sso, mongodb,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    pub service: String,
    pub enabled: bool,
}

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/mongodb/service", post(set_service))
        .route("/api/v1/mongodb/express-login", post(express_login))
}

async fn set_service(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result = tokio::task::spawn_blocking(move || {
        mongodb::set_service(&request.service, request.enabled)
    })
    .await;
    match result {
        Ok(Ok(message)) => ApiResponse::ok(&message).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: task crashed: {error}")).into_response(),
    }
}

/// A one-time URL (valid for a minute) that signs the browser into
/// mongo-express through the edge gateway.
async fn express_login(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(mongo_express_sso::issue).await {
        Ok(Ok(ticket)) => ApiResponse::ok_data(
            "mongo-express login ready.",
            serde_json::json!({ "url": format!("{}?ticket={ticket}", mongo_express_sso::SSO_PATH) }),
        )
        .into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: task crashed: {error}")).into_response(),
    }
}
