use std::env;
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
        if let (Ok(uid), Ok(gid)) = (env::var("SUDO_UID"), env::var("SUDO_GID")) {
            let _ = run_status(
                "chown",
                &[&format!("{uid}:{gid}"), path.to_string_lossy().as_ref()],
            );
        }
    }
    Ok(())
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
        apply_owner_and_mode(username, &group, &canonical, "0755")?;
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
