use std::sync::Arc;

use axum::{
    Router,
    extract::{Json, State},
    response::IntoResponse,
    routing::get,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    login_users,
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/login-users", get(list).post(control))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    user: String,
    shell: Option<String>,
    password: Option<String>,
}

async fn list(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(login_users::list).await {
        Ok(Ok(data)) => ApiResponse::ok_data("Login users loaded.", data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

async fn control(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result = tokio::task::spawn_blocking(move || {
        let user = request.user.trim();
        match request.action.as_str() {
            "set_shell" => login_users::set_shell(user, request.shell.as_deref().unwrap_or("")),
            "lock" => login_users::set_password_lock(user, true),
            "unlock" => login_users::set_password_lock(user, false),
            "set_password" => {
                login_users::set_password(user, request.password.as_deref().unwrap_or(""))
            }
            "end_sessions" => login_users::end_sessions(user),
            _ => Err("Unsupported login user action.".to_string()),
        }
    })
    .await;
    match result {
        Ok(Ok(message)) => ApiResponse::ok(&message).into_response(),
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
