use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    app,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    pub root_path: String,
    pub domain: String,
    pub php_version: Option<String>,
    pub start_directory: Option<String>,
    pub db_name: Option<String>,
    pub db_user: Option<String>,
    pub db_password: Option<String>,
    pub db_host: Option<String>,
    pub db_port: Option<String>,
    pub no_demo: Option<bool>,
    pub no_db: Option<bool>,
    pub no_vhost: Option<bool>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let mut args = vec![request.root_path, request.domain];
    if let Some(version) = &request.php_version {
        args.push(version.clone());
    }
    if let Some(directory) = &request.start_directory {
        args.push(directory.clone());
    }
    if let Some(name) = &request.db_name {
        args.push("--db-name".into());
        args.push(name.clone());
    }
    if let Some(user) = &request.db_user {
        args.push("--db-user".into());
        args.push(user.clone());
    }
    if let Some(password) = &request.db_password {
        args.push("--db-password".into());
        args.push(password.clone());
    }
    if let Some(host) = &request.db_host {
        args.push("--db-host".into());
        args.push(host.clone());
    }
    if let Some(port) = &request.db_port {
        args.push("--db-port".into());
        args.push(port.clone());
    }
    if request.no_demo.unwrap_or(false) {
        args.push("--no-demo".into());
    }
    if request.no_db.unwrap_or(false) {
        args.push("--no-db".into());
    }
    if request.no_vhost.unwrap_or(false) {
        args.push("--no-vhost".into());
    }

    match app::run_laravel_install(args) {
        Ok(()) => ApiResponse::ok("Laravel install completed").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
