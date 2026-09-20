use std::fs;
use std::path::Path;
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
}

/// Sums the size of a directory on demand, like cPanel's "calculate size" — no
/// background job/queue, just a plain synchronous walk done when the panel asks
/// for one folder. Symlinks are skipped so this can't loop or double count.
fn directory_size(path: &Path) -> Result<u64, String> {
    let mut total = 0_u64;
    let mut stack = vec![path.to_path_buf()];

    while let Some(current) = stack.pop() {
        let entries = match fs::read_dir(&current) {
            Ok(entries) => entries,
            Err(_) => continue,
        };
        for entry in entries {
            let Ok(entry) = entry else { continue };
            let Ok(metadata) = fs::symlink_metadata(entry.path()) else {
                continue;
            };
            if metadata.file_type().is_symlink() {
                continue;
            }
            if metadata.is_dir() {
                stack.push(entry.path());
            } else if metadata.is_file() {
                total = total.saturating_add(metadata.len());
            }
        }
    }

    Ok(total)
}

fn calculate(username: &str, path: &str) -> Result<u64, String> {
    let (_, canonical_home, _) = validate_account(username)?;
    let target = validate_user_path(username, path)?;
    let canonical = ensure_canonical_inside_home(&canonical_home, &target, "Path")?;

    if !canonical.is_dir() {
        return Err("Requested path is not a folder.".into());
    }

    directory_size(&canonical)
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let result =
        tokio::task::spawn_blocking(move || calculate(&request.username, &request.path)).await;
    match result {
        Ok(Ok(size)) => {
            ApiResponse::ok_data("Folder size calculated", serde_json::json!({ "size": size }))
                .into_response()
        }
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => {
            ApiResponse::error(&format!("Folder size worker failed: {error}")).into_response()
        }
    }
}
