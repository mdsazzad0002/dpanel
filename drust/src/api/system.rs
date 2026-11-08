//! `GET /system/overview` (backlog D3): the dashboard's and monitoring
//! page's machine numbers and service states, from `platform::metrics`.

use std::sync::Arc;

use axum::{
    Json, Router,
    extract::{Query, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::get,
};
use serde::Deserialize;

use super::{ApiResponse, ApiState, check_token};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/system/overview", get(overview))
        .route("/system/services", get(services))
}

fn names(list: &str) -> Vec<String> {
    list.split(',').map(str::trim).filter(|name| !name.is_empty()).take(32).map(str::to_string).collect()
}

/// Each named unit's full state; a name the service manager would read as
/// an option, or one it does not know, comes back as not installed.
async fn services(State(state): State<Arc<ApiState>>, headers: HeaderMap, Query(query): Query<OverviewQuery>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let names = names(&query.services);
    let result = tokio::task::spawn_blocking(move || {
        let manager = crate::platform::services();
        names
            .into_iter()
            .map(|name| {
                let status = crate::platform::metrics::valid_service_name(&name).then(|| manager.status(&name).ok()).flatten();
                let value = match status {
                    Some(status) => serde_json::json!({ "installed": status.installed, "active": status.active, "enabled": status.enabled, "state": status.state }),
                    None => serde_json::json!({ "installed": false, "active": false, "enabled": false, "state": "unknown" }),
                };
                (name, value)
            })
            .collect::<serde_json::Map<_, _>>()
    })
    .await;
    match result {
        Ok(services) => Json(serde_json::json!({ "success": true, "services": services })).into_response(),
        Err(error) => ApiResponse::error(&format!("Service check stopped: {error}")).into_response(),
    }
}

#[derive(Deserialize)]
struct OverviewQuery {
    /// Comma-separated unit names.
    #[serde(default)]
    services: String,
}

async fn overview(State(state): State<Arc<ApiState>>, headers: HeaderMap, Query(query): Query<OverviewQuery>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let services = names(&query.services);
    let disk_path = crate::platform::paths().panel_root().to_path_buf();
    match tokio::task::spawn_blocking(move || crate::platform::metrics::overview(&services, &disk_path)).await {
        Ok(overview) => Json(serde_json::json!({ "success": true, "overview": overview })).into_response(),
        Err(error) => ApiResponse::error(&format!("System overview stopped: {error}")).into_response(),
    }
}
