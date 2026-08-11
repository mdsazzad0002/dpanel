use std::fs;
use std::sync::Arc;

use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

const MAX_EDITOR_BYTES: u64 = 1024 * 1024;

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
}

fn read(request: &Request) -> Result<serde_json::Value, String> {
    let (_, canonical_home, _) = validate_account(&request.username)?;
    let path = validate_user_path(&request.username, &request.path)?;
    let path = ensure_canonical_inside_home(&canonical_home, &path, "File")?;
    if !path.is_file() {
        return Err("Selected path is not a file.".into());
    }

    let metadata = fs::metadata(&path).map_err(|e| format!("Cannot inspect file: {e}"))?;
    if metadata.len() > MAX_EDITOR_BYTES {
        return Ok(serde_json::json!({
            "content": "",
            "readonly": true,
            "message": "File is larger than 1MB and was not loaded in the editor.",
            "size": metadata.len(),
        }));
    }

    let bytes = fs::read(&path).map_err(|e| format!("Cannot read file: {e}"))?;
    if bytes.contains(&0) {
        return Err("Binary files cannot be opened in the text editor.".into());
    }
    let content = String::from_utf8(bytes)
        .map_err(|_| "Binary files cannot be opened in the text editor.".to_string())?;
    let control_count = content
        .chars()
        .filter(|character| character.is_control() && !matches!(character, '\n' | '\r' | '\t'))
        .count();
    if control_count > 8 && control_count * 100 > content.chars().count().max(1) {
        return Err("Binary files cannot be opened in the text editor.".into());
    }
    Ok(serde_json::json!({
        "content": content,
        "readonly": false,
        "message": null,
        "size": metadata.len(),
    }))
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match read(&request) {
        Ok(data) => ApiResponse::ok_data("File read", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
