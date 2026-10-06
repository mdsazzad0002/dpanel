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
use super::unzip::{ensure_tree_has_no_symlinks, replace_existing_target};

pub fn move_user_path(username: &str, source: &str, destination: &str) -> Result<(), String> {
    let (user_home, canonical_home, group) = validate_account(username)?;
    let source_path = validate_user_path(username, source)?;
    let destination_path = validate_user_path(username, destination)?;

    if source_path == user_home {
        return Err("The account home cannot be moved.".into());
    }
    if destination_path == user_home {
        return Err("The account home cannot be replaced.".into());
    }
    if source_path == destination_path {
        return Err("Source and destination are the same.".into());
    }
    if destination_path.starts_with(&source_path) {
        return Err("A path cannot be moved inside itself.".into());
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
    let source_is_dir = fs::symlink_metadata(&source_path)
        .map_err(|e| format!("failed to inspect {}: {e}", source_path.display()))?
        .is_dir();
    // An existing target is replaced. A folder moved onto a folder can't be
    // renamed over it, so merge it in (same-named files overwritten) and then
    // drop the source.
    if replace_existing_target(&destination_path, source_is_dir)? {
        ensure_tree_has_no_symlinks(&destination_path)?;
        run_status(
            "cp",
            &[
                "-a",
                "--remove-destination",
                "--no-target-directory",
                source_path.to_string_lossy().as_ref(),
                destination_path.to_string_lossy().as_ref(),
            ],
        )
        .map_err(|e| {
            format!(
                "failed to move {} to {}: {e}",
                source_path.display(),
                destination_path.display()
            )
        })?;
        run_status(
            "chown",
            &[
                "-R",
                &format!("{username}:{group}"),
                destination_path.to_string_lossy().as_ref(),
            ],
        )?;
        fs::remove_dir_all(&source_path)
            .map_err(|e| format!("failed to remove {}: {e}", source_path.display()))?;
    } else {
        fs::rename(&source_path, &destination_path).map_err(|e| {
            format!(
                "failed to move {} to {}: {e}",
                source_path.display(),
                destination_path.display()
            )
        })?;
    }
    info(&format!(
        "path moved: {} -> {}",
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
        move_user_path(&request.username, &request.source, &request.destination),
        "Path moved",
    )
}
