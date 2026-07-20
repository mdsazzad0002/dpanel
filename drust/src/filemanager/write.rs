use std::fs;
use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::info;
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{apply_owner_and_mode, validate_account, validate_user_path};

pub fn write_user_file(
    username: &str,
    target: &str,
    content: &[u8],
    must_exist: bool,
) -> Result<(), String> {
    let (_, _, group) = validate_account(username)?;
    let path = validate_user_path(username, target)?;
    if must_exist && !path.is_file() {
        return Err(format!("File not found: {}", path.display()));
    }

    let parent = path
        .parent()
        .ok_or_else(|| "File parent is missing.".to_string())?;
    fs::create_dir_all(parent)
        .map_err(|e| format!("failed to create {}: {e}", parent.display()))?;
    fs::write(&path, content).map_err(|e| format!("failed to write {}: {e}", path.display()))?;

    apply_owner_and_mode(username, &group, &path, "0644")?;
    let _ = apply_owner_and_mode(username, &group, parent, "0755");
    info(&format!("file written: {}", path.display()));
    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    content: String,
    must_exist: Option<bool>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    operation_response(
        write_user_file(
            &request.username,
            &request.path,
            request.content.as_bytes(),
            request.must_exist.unwrap_or(false),
        ),
        "File written",
    )
}
