use std::fs;
use std::path::Path;
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use crate::api::{ApiState, check_token, operation_response};

use super::common::{
    apply_owner_and_mode, ensure_canonical_inside_home, validate_account, validate_user_path,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    mode: String,
    recursive: Option<bool>,
}

pub fn change_permissions(
    username: &str,
    target: &str,
    mode: &str,
    recursive: bool,
) -> Result<(), String> {
    let (_, canonical_home, group) = validate_account(username)?;
    let target_path = validate_user_path(username, target)?;
    reject_symlink(&target_path)?;
    let canonical_target = ensure_canonical_inside_home(&canonical_home, &target_path, "Path")?;
    let mode = parse_mode(mode)?;

    if recursive && canonical_target.is_dir() {
        chmod_recursive(&canonical_target, username, &group, mode)?;
    } else {
        chmod_path(&canonical_target, username, &group, mode)?;
    }

    Ok(())
}

fn parse_mode(mode: &str) -> Result<u32, String> {
    let mode = mode.trim();
    if !(mode.len() == 3 || mode.len() == 4)
        || !mode.bytes().all(|byte| (b'0'..=b'7').contains(&byte))
    {
        return Err("Mode must be a 3 or 4 digit octal value.".into());
    }

    u32::from_str_radix(mode, 8).map_err(|e| format!("invalid mode: {e}"))
}

fn chmod_recursive(path: &Path, username: &str, group: &str, mode: u32) -> Result<(), String> {
    chmod_path(path, username, group, mode)?;
    let entries =
        fs::read_dir(path).map_err(|e| format!("failed to read {}: {e}", path.display()))?;

    for entry in entries {
        let entry = entry.map_err(|e| format!("failed to read directory entry: {e}"))?;
        let child = entry.path();
        let metadata = fs::symlink_metadata(&child)
            .map_err(|e| format!("failed to inspect {}: {e}", child.display()))?;

        if metadata.file_type().is_symlink() {
            return Err(format!(
                "Refusing to change permissions through symbolic link: {}",
                child.display()
            ));
        }

        if metadata.is_dir() {
            chmod_recursive(&child, username, group, mode)?;
        } else {
            chmod_path(&child, username, group, mode)?;
        }
    }

    Ok(())
}

fn chmod_path(path: &Path, username: &str, group: &str, mode: u32) -> Result<(), String> {
    apply_owner_and_mode(username, group, path, &format!("{mode:o}"))
}

fn reject_symlink(path: &Path) -> Result<(), String> {
    let metadata = fs::symlink_metadata(path).map_err(|e| format!("path is unavailable: {e}"))?;
    if metadata.file_type().is_symlink() {
        return Err(format!(
            "Refusing to change permissions on symbolic link: {}",
            path.display()
        ));
    }

    Ok(())
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(response) = check_token(&state, &headers) {
        return response.into_response();
    }

    let result = change_permissions(
        &request.username,
        &request.path,
        &request.mode,
        request.recursive.unwrap_or(false),
    );

    operation_response(result, "Permissions updated")
}
