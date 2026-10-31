use std::fs::{self, File};
use std::io::{Read, Seek, SeekFrom};
use std::path::Path;
use std::sync::Arc;

use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

/// Files above this size open as a read-only tail preview unless `full` is set.
const PREVIEW_THRESHOLD_BYTES: u64 = 1024 * 1024;
/// Hard ceiling for loading a whole file into the editor.
const MAX_FULL_BYTES: u64 = 32 * 1024 * 1024;
const TAIL_LINES: usize = 200;
/// Upper bound on bytes scanned for the tail, so one giant line cannot pull in the whole file.
const MAX_TAIL_BYTES: u64 = 1024 * 1024;

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    #[serde(default)]
    full: bool,
}

fn looks_binary(content: &str) -> bool {
    let control_count = content
        .chars()
        .filter(|character| character.is_control() && !matches!(character, '\n' | '\r' | '\t'))
        .count();
    control_count > 8 && control_count * 100 > content.chars().count().max(1)
}

fn read_tail(path: &Path, size: u64) -> Result<String, String> {
    let mut file = File::open(path).map_err(|e| format!("Cannot read file: {e}"))?;
    let start = size.saturating_sub(MAX_TAIL_BYTES);
    file.seek(SeekFrom::Start(start))
        .map_err(|e| format!("Cannot read file: {e}"))?;
    let mut bytes = Vec::with_capacity((size - start) as usize);
    file.read_to_end(&mut bytes)
        .map_err(|e| format!("Cannot read file: {e}"))?;
    if bytes.contains(&0) {
        return Err("Binary files cannot be opened in the text editor.".into());
    }

    // Skip a trailing newline so it does not count as an empty last line.
    let body_end = if bytes.ends_with(b"\n") {
        bytes.len() - 1
    } else {
        bytes.len()
    };
    let mut cut = 0;
    let mut seen = 0;
    for index in (0..body_end).rev() {
        if bytes[index] == b'\n' {
            seen += 1;
            if seen == TAIL_LINES {
                cut = index + 1;
                break;
            }
        }
    }
    // A window that starts mid-file begins with a partial line; drop it.
    if cut == 0 && start > 0 {
        cut = bytes
            .iter()
            .position(|byte| *byte == b'\n')
            .map_or(0, |index| index + 1);
    }

    let content = String::from_utf8_lossy(&bytes[cut..]).into_owned();
    if looks_binary(&content) {
        return Err("Binary files cannot be opened in the text editor.".into());
    }
    Ok(content)
}

fn format_size(bytes: u64) -> String {
    format!("{:.1}MB", bytes as f64 / (1024.0 * 1024.0))
}

fn read(request: &Request) -> Result<serde_json::Value, String> {
    let (_, canonical_home, _) = validate_account(&request.username)?;
    let path = validate_user_path(&request.username, &request.path)?;
    let path = ensure_canonical_inside_home(&canonical_home, &path, "File")?;
    if !path.is_file() {
        return Err("Selected path is not a file.".into());
    }

    let metadata = fs::metadata(&path).map_err(|e| format!("Cannot inspect file: {e}"))?;
    let size = metadata.len();
    let can_load_full = size <= MAX_FULL_BYTES;
    if size > PREVIEW_THRESHOLD_BYTES && (!request.full || !can_load_full) {
        let message = if can_load_full {
            format!(
                "Large file ({}): showing the last {TAIL_LINES} lines read-only.",
                format_size(size)
            )
        } else {
            format!(
                "File is {} (over the {} editor limit): showing the last {TAIL_LINES} lines read-only. Download it to view everything.",
                format_size(size),
                format_size(MAX_FULL_BYTES)
            )
        };
        return Ok(serde_json::json!({
            "content": read_tail(&path, size)?,
            "readonly": true,
            "partial": true,
            "can_load_full": can_load_full,
            "message": message,
            "size": size,
        }));
    }

    let bytes = fs::read(&path).map_err(|e| format!("Cannot read file: {e}"))?;
    if bytes.contains(&0) {
        return Err("Binary files cannot be opened in the text editor.".into());
    }
    let content = String::from_utf8(bytes)
        .map_err(|_| "Binary files cannot be opened in the text editor.".to_string())?;
    if looks_binary(&content) {
        return Err("Binary files cannot be opened in the text editor.".into());
    }
    Ok(serde_json::json!({
        "content": content,
        "readonly": false,
        "partial": false,
        "can_load_full": true,
        "message": null,
        "size": size,
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

#[cfg(test)]
mod tests {
    use super::{MAX_TAIL_BYTES, TAIL_LINES, read_tail};
    use std::fs;

    fn temp_file(name: &str, content: &[u8]) -> std::path::PathBuf {
        let path =
            std::env::temp_dir().join(format!("drust-read-test-{}-{name}", std::process::id()));
        fs::write(&path, content).unwrap();
        path
    }

    #[test]
    fn tail_returns_last_lines_only() {
        let content: String = (1..=5000).map(|line| format!("line {line}\n")).collect();
        let path = temp_file("tail", content.as_bytes());

        let tail = read_tail(&path, content.len() as u64).unwrap();
        fs::remove_file(&path).unwrap();

        let lines: Vec<&str> = tail.lines().collect();
        assert_eq!(lines.len(), TAIL_LINES);
        assert_eq!(lines.first(), Some(&"line 4801"));
        assert_eq!(lines.last(), Some(&"line 5000"));
    }

    #[test]
    fn tail_drops_partial_first_line_when_window_is_capped() {
        let long_line = "x".repeat(MAX_TAIL_BYTES as usize);
        let content = format!("{long_line}\nlast one\n");
        let path = temp_file("capped", content.as_bytes());

        let tail = read_tail(&path, content.len() as u64).unwrap();
        fs::remove_file(&path).unwrap();

        assert_eq!(tail, "last one\n");
    }
}
