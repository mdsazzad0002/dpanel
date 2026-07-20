use std::env;
use std::fs;
use std::path::Path;
use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::{info, run_output, warn};
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
        if is_invoking_home(&path) {
            return Err(format!(
                "Refusing to remove your home directory: {}",
                path.display()
            ));
        }
        if path.exists() {
            if path.is_dir() {
                fs::remove_dir_all(&path)
                    .map_err(|e| format!("failed to remove {}: {e}", path.display()))?;
            } else {
                fs::remove_file(&path)
                    .map_err(|e| format!("failed to remove {}: {e}", path.display()))?;
            }
            info(&format!("removed: {}", path.display()));
        } else {
            warn(&format!("nothing to remove: {}", path.display()));
        }
    }
    Ok(())
}

fn is_invoking_home(target: &Path) -> bool {
    env::var("SUDO_USER")
        .ok()
        .and_then(|user| run_output("getent", &["passwd", &user]).ok())
        .and_then(|line| line.split(':').nth(5).map(|value| value.to_string()))
        .or_else(|| env::var("HOME").ok())
        .map(|home| Path::new(&home) == target)
        .unwrap_or(false)
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
    operation_response(run(&request.paths), "Paths removed")
}
