use std::sync::Arc;

use axum::{
    extract::{Json, Query, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    edge_gateway::php_workers,
};

#[derive(Deserialize)]
pub(crate) struct ListQuery {
    /// PID of the PHP worker making this request, counted as idle.
    pub observer_pid: Option<u32>,
}

/// Every site's PHP worker limit and live usage.
pub(crate) async fn list(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Query(query): Query<ListQuery>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(move || php_workers::overview(query.observer_pid)).await {
        Ok(overview) => match serde_json::to_value(overview) {
            Ok(data) => ApiResponse::ok_data("PHP workers", data).into_response(),
            Err(error) => ApiResponse::error(&error.to_string()).into_response(),
        },
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

#[derive(Deserialize)]
pub(crate) struct UpdateRequest {
    pub owner: String,
    /// `null` returns the site to the automatic limit.
    pub max_children: Option<u16>,
}

/// Pins or unpins one site user's worker limit.
pub(crate) async fn update(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<UpdateRequest>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let owner = request.owner.clone();
    let result = tokio::task::spawn_blocking(move || php_workers::set_limit(&owner, request.max_children)).await;
    let change = match result {
        Ok(Ok(change)) => change,
        Ok(Err(error)) => return ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => return ApiResponse::error(&format!("Failed: {error}")).into_response(),
    };

    let reloads = change.reloads.clone();
    if !reloads.is_empty() {
        tokio::spawn(async move {
            // The panel may itself run on one of these masters: answer first.
            tokio::time::sleep(std::time::Duration::from_secs(2)).await;
            let _ = tokio::task::spawn_blocking(move || {
                for reload in reloads {
                    if let Err(error) = reload.run() {
                        tracing::warn!(?reload, %error, "PHP-FPM reload after a worker limit change failed");
                    }
                }
            })
            .await;
        });
    }

    let message = if change.reloads.is_empty() {
        format!("Worker limit for {} is {}", request.owner, change.limit)
    } else {
        format!("Worker limit for {} set to {}; PHP-FPM reload scheduled", request.owner, change.limit)
    };
    ApiResponse::ok_data(
        &message,
        serde_json::json!({"owner": request.owner, "max_children": change.limit, "custom": change.custom}),
    )
    .into_response()
}
