use std::fs;
use std::path::Path;
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
        remove_folder(&path)?;
    } else {
        crate::platform::files()
            .remove_file(&path)
            .map_err(|e| format!("failed to delete {}: {e}", path.display()))?;
    }
    info(&format!("path deleted: {}", path.display()));
    Ok(())
}

/// Moves the folder aside under a hidden name first, then deletes it. On
/// Windows the move fails while any file in it is open in another program,
/// so the folder stays whole instead of losing every file before that one.
/// If the delete still fails partway, what is left goes back to its name.
fn remove_folder(path: &Path) -> Result<(), String> {
    use std::time::{SystemTime, UNIX_EPOCH};
    let name = path.file_name().map(|name| name.to_string_lossy().into_owned()).unwrap_or_default();
    let stamp = SystemTime::now().duration_since(UNIX_EPOCH).map(|time| time.as_nanos()).unwrap_or(0);
    let aside = path.with_file_name(format!(".{name}.deleting-{}-{stamp}", std::process::id()));
    fs::rename(path, &aside).map_err(|e| {
        format!(
            "failed to delete {}; nothing was deleted. A file in it may be open in another program: {}",
            path.display(),
            crate::platform::fs::describe(&e)
        )
    })?;
    fs::remove_dir_all(&aside).map_err(|e| {
        let restored = fs::rename(&aside, path).is_ok();
        format!(
            "failed to delete {}: {}{}",
            path.display(),
            crate::platform::fs::describe(&e),
            if restored { "; what was not deleted is still there" } else { "" }
        )
    })
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
