use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::{info, warn};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::validate_absolute_path;

pub fn run(paths: &[String], directories: bool) -> Result<(), String> {
    if paths.is_empty() {
        return Err("Missing path argument.".into());
    }
    let mut missing = false;
    for target in paths {
        let path = validate_absolute_path(target)?;
        let exists = if directories {
            path.is_dir()
        } else {
            path.is_file()
        };
        if exists {
            info(&format!(
                "{} exists: {}",
                if directories { "folder" } else { "file" },
                path.display()
            ));
        } else {
            warn(&format!(
                "{} missing: {}",
                if directories { "folder" } else { "file" },
                path.display()
            ));
            missing = true;
        }
    }
    if missing {
        Err("One or more targets missing.".into())
    } else {
        Ok(())
    }
}

#[derive(Deserialize)]
pub(crate) struct Request {
    paths: Vec<String>,
    check_file: Option<bool>,
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
        run(&request.paths, !request.check_file.unwrap_or(false)),
        "All targets exist",
    )
}
