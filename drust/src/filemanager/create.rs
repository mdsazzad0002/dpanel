use std::env;
use std::fs;
use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::info;
use crate::platform::fs::FileMode;
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{
    apply_owner_and_mode, ensure_directory_inside_home, validate_absolute_path, validate_account,
    validate_user_path,
};

pub fn run(paths: &[String]) -> Result<(), String> {
    if paths.is_empty() {
        return Err("Missing path argument.".into());
    }

    for target in paths {
        let path = validate_absolute_path(target)?;
        if path.is_dir() {
            info(&format!("folder exists: {}", path.display()));
        } else {
            fs::create_dir_all(&path)
                .map_err(|e| format!("failed to create {}: {e}", path.display()))?;
            info(&format!("folder created: {}", path.display()));
        }
        give_to_sudo_user(&path);
    }
    Ok(())
}

/// Best effort: a folder made through `sudo` belongs to the user who ran it.
/// A local profile has no other owner to give it to.
fn give_to_sudo_user(path: &std::path::Path) {
    if crate::platform::current().one_user() {
        return;
    }
    let id = |name: &str| env::var(name).ok().and_then(|value| value.parse::<u32>().ok());
    if let (Some(uid), Some(gid)) = (id("SUDO_UID"), id("SUDO_GID")) {
        let owner = crate::platform::fs::OwnerId { uid, gid };
        let _ = crate::platform::files().set_owner(path, &owner);
    }
}

pub fn run_for_user(username: &str, paths: &[String]) -> Result<(), String> {
    if paths.is_empty() {
        return Err("Missing path argument.".into());
    }

    let (user_home, canonical_home, group) = validate_account(username)?;
    for target in paths {
        let path = validate_user_path(username, target)?;
        let canonical = ensure_directory_inside_home(
            username,
            &group,
            &user_home,
            &canonical_home,
            &path,
            "Folder",
        )?;
        apply_owner_and_mode(username, &group, &canonical, FileMode::Executable)?;
        info(&format!("folder ensured: {}", path.display()));
    }

    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct Request {
    paths: Vec<String>,
    username: Option<String>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result = match request.username.as_deref() {
        Some(username) if !username.trim().is_empty() => run_for_user(username, &request.paths),
        _ => run(&request.paths),
    };
    operation_response(result, "Directories created")
}
