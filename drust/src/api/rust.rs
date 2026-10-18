use std::sync::Arc;
use axum::{Router, extract::{Json, State}, response::IntoResponse, routing::post};
use serde::Deserialize;
use serde_json::json;
use crate::{api::{ApiResponse, ApiState, check_token}, edge_gateway::rust_process::{control_rust, rust_status}};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/rust/control", post(handle))
}

#[derive(Deserialize)]
struct Request {
    site_id: String,
    action: String,
    site_owner: Option<String>,
    project_root: Option<String>,
    rust_start_command: Option<String>,
    port: u16,
}

async fn handle(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap, Json(req): Json<Request>) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) { return error.into_response(); }
    if req.action == "status" {
        let (exists, active, listening) = rust_status(&req.site_id, req.port).await;
        return ApiResponse::ok_data("Rust process status", json!({"unit_exists":exists,"active_state":active,"listening":listening})).into_response();
    }
    match control_rust(&req.site_id, &req.action, req.site_owner.as_deref(), req.project_root.as_deref(), req.rust_start_command.as_deref(), req.port).await {
        Ok(()) => ApiResponse::ok("Rust process updated").into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}
