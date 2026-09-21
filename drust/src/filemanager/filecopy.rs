use std::fs;
use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::{info, run_status};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{
    ensure_canonical_inside_home, ensure_directory_inside_home, validate_account,
    validate_user_path,
};

pub fn copy_user_path(username: &str, source: &str, destination: &str) -> Result<(), String> {
    let (user_home, canonical_home, group) = validate_account(username)?;
    let source_path = validate_user_path(username, source)?;
    let destination_path = validate_user_path(username, destination)?;

    if source_path == user_home {
        return Err("The account home cannot be copied.".into());
    }
    if source_path == destination_path {
        return Err("Source and destination are the same.".into());
    }
    if destination_path.starts_with(&source_path) {
        return Err("A path cannot be copied inside itself.".into());
    }

    ensure_canonical_inside_home(&canonical_home, &source_path, "Source")?;
    let destination_parent = destination_path
        .parent()
        .ok_or_else(|| "Destination parent is missing.".to_string())?;
    let canonical_parent = ensure_directory_inside_home(
        username,
        &group,
        &user_home,
        &canonical_home,
        destination_parent,
        "Destination",
    )?;
    if !canonical_parent.is_dir() {
        return Err("Destination parent is not a folder.".into());
    }
    if fs::symlink_metadata(&destination_path).is_ok() {
        return Err(format!(
            "Target already exists: {}",
            destination_path.display()
        ));
    }

    let source_metadata = fs::symlink_metadata(&source_path)
        .map_err(|e| format!("failed to inspect {}: {e}", source_path.display()))?;
    if source_metadata.file_type().is_symlink() {
        return Err("Symbolic links cannot be copied.".into());
    }

    // Shell out to `cp -a` rather than hand-rolling a recursive walk: it
    // preserves directory structure atomically for large trees, the same
    // way the rest of this module relies on system tools (chown/chmod)
    // instead of reimplementing them.
    run_status(
        "cp",
        &[
            "-a",
            "--no-target-directory",
            source_path.to_string_lossy().as_ref(),
            destination_path.to_string_lossy().as_ref(),
        ],
    )
    .map_err(|e| {
        format!(
            "failed to copy {} to {}: {e}",
            source_path.display(),
            destination_path.display()
        )
    })?;

    // `cp -a` preserves the source's owner; re-stamp the copy as the
    // account's own user/group the same way newly created paths are.
    run_status(
        "chown",
        &[
            "-R",
            &format!("{username}:{group}"),
            destination_path.to_string_lossy().as_ref(),
        ],
    )?;

    info(&format!(
        "path copied: {} -> {}",
        source_path.display(),
        destination_path.display()
    ));
    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    source: String,
    destination: String,
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
        copy_user_path(&request.username, &request.source, &request.destination),
        "Path copied",
    )
}
