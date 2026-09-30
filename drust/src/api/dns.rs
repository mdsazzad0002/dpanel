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
    dns_lookup,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/dns/lookup", post(lookup))
}

#[derive(Deserialize)]
struct Request {
    queries: Vec<dns_lookup::Query>,
}

async fn lookup(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if request.queries.is_empty() || request.queries.len() > dns_lookup::MAX_QUERIES {
        return ApiResponse::error(&format!(
            "Send between 1 and {} queries.",
            dns_lookup::MAX_QUERIES
        ))
        .into_response();
    }
    let answers = futures_util::future::join_all(request.queries.iter().map(dns_lookup::lookup)).await;
    ApiResponse::ok_data("DNS lookups completed.", serde_json::json!({ "answers": answers }))
        .into_response()
}
