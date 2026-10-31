use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    edge_gateway::php::render_phpinfo,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    pub php_version: Option<String>,
    pub site_owner: Option<String>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let php_version = request.php_version.as_deref().map(str::trim).filter(|v| !v.is_empty());
    let site_owner = request.site_owner.as_deref().map(str::trim).filter(|v| !v.is_empty());
    match render_phpinfo(php_version, site_owner).await {
        Ok(html) => ApiResponse::ok_data("phpinfo rendered", serde_json::json!({ "html": html }))
            .into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}
