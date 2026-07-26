use std::fs;
use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::info;
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

pub fn delete_user_path(username: &str, target: &str) -> Result<(), String> {
    let (home, canonical_home, _) = validate_account(username)?;
    let path = validate_user_path(username, target)?;
    if path == home {
        return Err("The account home cannot be deleted.".into());
    }

    ensure_canonical_inside_home(&canonical_home, &path, "Path")?;
    let metadata = fs::symlink_metadata(&path)
        .map_err(|e| format!("path not found: {}: {e}", path.display()))?;
    if metadata.is_dir() {
        fs::remove_dir_all(&path)
            .map_err(|e| format!("failed to delete {}: {e}", path.display()))?;
    } else {
        fs::remove_file(&path).map_err(|e| format!("failed to delete {}: {e}", path.display()))?;
    }
    info(&format!("path deleted: {}", path.display()));
    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
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
        delete_user_path(&request.username, &request.path),
        "Path deleted",
    )
}
