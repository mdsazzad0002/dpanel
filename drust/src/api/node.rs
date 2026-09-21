use std::{path::PathBuf, sync::Arc};

use axum::{
    Router,
    extract::{Json, State},
    response::IntoResponse,
    routing::post,
};
use serde::Deserialize;
use serde_json::json;

use crate::api::{ApiResponse, ApiState, check_token};
use crate::edge_gateway::{
    ensure_node_process_running, node_process_status, restart_node_process, stop_node_process,
};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/node/control", post(handle))
}

#[derive(Deserialize)]
pub(crate) struct Request {
    pub site_id: String,
    pub action: String,
    pub site_owner: Option<String>,
    pub project_root: Option<String>,
    pub node_entry_file: Option<String>,
    pub node_start_command: Option<String>,
    pub node_version: Option<String>,
    pub port: u16,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match request.action.as_str() {
        "start" => {
            let (Some(owner), Some(project_root)) =
                (request.site_owner.as_deref(), request.project_root.as_deref())
            else {
                return ApiResponse::error("site_owner and project_root are required to start")
                    .into_response();
            };
            match ensure_node_process_running(
                &request.site_id,
                owner,
                &PathBuf::from(project_root),
                request.node_entry_file.as_deref(),
                request.node_start_command.as_deref(),
                request.node_version.as_deref(),
                request.port,
            )
            .await
            {
                Ok(()) => ApiResponse::ok("Node process started").into_response(),
                Err(error) => ApiResponse::error(&format!("Failed to start: {error}")).into_response(),
            }
        }
        "stop" => match stop_node_process(&request.site_id) {
            Ok(()) => ApiResponse::ok("Node process stopped").into_response(),
            Err(error) => ApiResponse::error(&format!("Failed to stop: {error}")).into_response(),
        },
        "restart" => match restart_node_process(&request.site_id) {
            Ok(()) => ApiResponse::ok("Node process restarted").into_response(),
            Err(error) => {
                ApiResponse::error(&format!("Failed to restart: {error}")).into_response()
            }
        },
        "status" => {
            let status = node_process_status(&request.site_id, request.port).await;
            ApiResponse::ok_data(
                "Node process status",
                json!({
                    "unit_exists": status.unit_exists,
                    "active_state": status.active_state,
                    "listening": status.listening,
                }),
            )
            .into_response()
        }
        other => ApiResponse::error(&format!("unknown action: {other}")).into_response(),
    }
}
