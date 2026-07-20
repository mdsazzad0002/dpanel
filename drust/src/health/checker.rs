use std::sync::Arc;

use axum::{extract::State, response::IntoResponse};

use crate::api::{ApiResponse, ApiState, check_token};

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    ApiResponse::ok_data(
        "Health check passed",
        serde_json::json!({
            "status": "ok",
            "service": "drust",
            "version": env!("CARGO_PKG_VERSION")
        }),
    )
    .into_response()
}
