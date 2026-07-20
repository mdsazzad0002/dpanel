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
    pub username: String,
    pub password: Option<String>,
    pub email: Option<String>,
    pub ssh_key: Option<String>,
    pub shell: Option<String>,
    pub disable_root: Option<bool>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let mut args = vec!["--username".into(), request.username];
    if let Some(password) = &request.password {
        args.push("--password".into());
        args.push(password.clone());
    }
    if let Some(email) = &request.email {
        args.push("--email".into());
        args.push(email.clone());
    }
    if let Some(ssh_key) = &request.ssh_key {
        args.push("--ssh-key".into());
        args.push(ssh_key.clone());
    }
    if let Some(shell) = &request.shell {
        args.push("--shell".into());
        args.push(shell.clone());
    }
    if request.disable_root.unwrap_or(true) {
        args.push("--disable-root".into());
    }

    match app::run_admin_user(args) {
        Ok(()) => ApiResponse::ok("Admin user created successfully").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
