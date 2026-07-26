use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    vhost,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    pub domain: Option<String>,
    pub backend_port: Option<u16>,
    pub frontend_port: Option<u16>,
    pub app_dir: Option<String>,
    pub conf_name: Option<String>,
    pub aliases: Option<Vec<String>>,
    pub no_www: Option<bool>,
    pub client_max_body_size: Option<String>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let mut args = Vec::new();
    if let Some(domain) = &request.domain {
        args.push(domain.clone());
    }
    if let Some(port) = request.backend_port {
        args.push(port.to_string());
    }
    if let Some(port) = request.frontend_port {
        args.push(port.to_string());
    }
    if let Some(directory) = &request.app_dir {
        args.push("--app-dir".into());
        args.push(directory.clone());
    }
    if let Some(name) = &request.conf_name {
        args.push("--conf-name".into());
        args.push(name.clone());
    }
    if let Some(aliases) = &request.aliases {
        for alias in aliases {
            args.push("--alias".into());
            args.push(alias.clone());
        }
    }
    if request.no_www.unwrap_or(false) {
        args.push("--no-www".into());
    }
    if let Some(limit) = &request.client_max_body_size {
        args.push("--client-max-body-size".into());
        args.push(limit.clone());
    }

    match vhost::run_fix_panel_web_stack(args) {
        Ok(()) => ApiResponse::ok("Panel web stack fixed successfully").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
