use std::sync::Arc;

use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::IntoResponse,
    routing::get,
};

use super::{ApiState, check_token};
use crate::platform::capabilities;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/health", get(crate::health::status::handle))
        .route("/capabilities", get(report_capabilities))
        .route(
            "/api/v1/health-checker",
            get(crate::health::checker::handle),
        )
}

async fn report_capabilities(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    Json(capabilities::report()).into_response()
}
