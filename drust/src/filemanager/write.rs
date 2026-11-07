use std::fs;
use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::info;
use crate::platform::{self, fs::FileMode};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{
    apply_owner_and_mode, ensure_directory_inside_home, validate_account, validate_user_path,
};

pub fn write_user_file(
    username: &str,
    target: &str,
    content: &[u8],
    must_exist: bool,
) -> Result<(), String> {
    let (user_home, canonical_home, group) = validate_account(username)?;
    let path = validate_user_path(username, target)?;
    if must_exist && !path.is_file() {
        return Err(format!("File not found: {}", path.display()));
    }
    // Keep the mode of an existing file: logs and caches are often group-writable
    // for PHP-FPM, and resetting them to 0644 breaks the application writing them.
    let existing = fs::symlink_metadata(&path)
        .ok()
        .filter(|metadata| metadata.is_file());
    let mode = existing
        .as_ref()
        .and_then(|metadata| platform::files().mode_bits(metadata))
        .map(|bits| FileMode::Raw(bits & 0o7777));
    // Where there are no bits to read back the ACL survives the write by
    // itself, and resetting it would open a private file to everyone.
    let keeps_its_acl = existing.is_some() && mode.is_none();

    let parent = path
        .parent()
        .ok_or_else(|| "File parent is missing.".to_string())?;
    let parent = ensure_directory_inside_home(
        username,
        &group,
        &user_home,
        &canonical_home,
        parent,
        "File folder",
    )?;
    fs::write(&path, content).map_err(|e| format!("failed to write {}: {e}", path.display()))?;

    if !keeps_its_acl {
        apply_owner_and_mode(username, &group, &path, mode.unwrap_or(FileMode::Shared))?;
    }
    let _ = apply_owner_and_mode(username, &group, &parent, FileMode::Executable);
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
