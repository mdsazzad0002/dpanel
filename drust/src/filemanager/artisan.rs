use std::process::Command;
use std::sync::Arc;

use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    project_path: String,
    command: String,
}

fn execute(request: &Request) -> Result<serde_json::Value, String> {
    const ALLOWED: &[&str] = &[
        "optimize:clear",
        "cache:clear",
        "config:clear",
        "route:clear",
        "view:clear",
        "storage:link",
        "storage:unlink",
        "migrate",
    ];
    const ALLOWED_FLAGS: &[&str] = &["--force"];

    let parts: Vec<&str> = request.command.split_whitespace().collect();
    let (base_command, flags) = parts.split_first().ok_or("Artisan command is required.")?;
    if !ALLOWED.contains(base_command) {
        return Err("Artisan command is not allowed.".into());
    }
    if flags.iter().any(|flag| !ALLOWED_FLAGS.contains(flag)) {
        return Err("Artisan command flag is not allowed.".into());
    }

    let (user_home, canonical_home, _) = validate_account(&request.username)?;
    let project = validate_user_path(&request.username, &request.project_path)?;
    let project = ensure_canonical_inside_home(&canonical_home, &project, "Laravel project")?;
    if !project.join("artisan").is_file() {
        return Err("Laravel artisan file was not found in the project root.".into());
    }

    let php = [
        "/usr/bin/php",
        "/usr/local/bin/php",
        "/usr/bin/php8.3",
        "/usr/bin/php8.2",
    ]
    .into_iter()
    .find(|path| std::path::Path::new(path).is_file())
    .ok_or("PHP CLI is unavailable.")?;
    let output = Command::new("/usr/sbin/runuser")
        .args(["-u", &request.username, "--", "/usr/bin/env", "-i"])
        .arg(format!("HOME={}", user_home.display()))
        .arg(format!("USER={}", request.username))
        .arg(format!("LOGNAME={}", request.username))
        .arg("PATH=/usr/local/bin:/usr/bin:/bin")
        .arg(php)
        .arg("artisan")
        .arg(base_command)
        .args(flags)
        .current_dir(&project)
        .output()
        .map_err(|e| format!("Cannot start Artisan: {e}"))?;
    let combined = format!(
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    )
    .trim()
    .to_string();
    if !output.status.success() {
        return Err(if combined.is_empty() {
            format!("Artisan exited with {}.", output.status)
        } else {
            combined
        });
    }
    Ok(serde_json::json!({ "output": combined, "exit_code": output.status.code().unwrap_or(0) }))
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match execute(&request) {
        Ok(data) => ApiResponse::ok_data("Artisan command completed", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
