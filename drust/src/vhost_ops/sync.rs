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
    pub action: String,
    pub domain: String,
    pub root_path: String,
    pub php_version: Option<String>,
    pub old_domain: Option<String>,
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

    let mut args = vec![
        request.action,
        request.domain,
        request.root_path,
        request.php_version.unwrap_or_else(|| "8.3".into()),
    ];
    if let Some(old_domain) = &request.old_domain {
        args.push(old_domain.clone());
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

    match vhost::run_sync_vhost(args) {
        Ok(()) => ApiResponse::ok("Vhost synchronized successfully").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
