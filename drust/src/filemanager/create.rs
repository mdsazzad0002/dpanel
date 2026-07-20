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

use super::common::validate_absolute_path;

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

#[derive(Deserialize)]
pub(crate) struct Request {
    paths: Vec<String>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    operation_response(run(&request.paths), "Directories created")
}
