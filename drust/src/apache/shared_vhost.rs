use std::fs;
use std::os::unix::fs::symlink;
use std::path::PathBuf;
use std::process::Command;
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use serde_json::json;

use crate::api::{ApiResponse, ApiState, check_token};

#[derive(Deserialize)]
pub(crate) struct Request {
    path: String,
    content: String,
    reload: Option<bool>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(response) = check_token(&state, &headers) {
        return response.into_response();
    }

    match write_shared_vhost(
        &request.path,
        &request.content,
        request.reload.unwrap_or(true),
    ) {
        Ok(output) => ApiResponse::ok_data(
            "Shared websites Apache config generated successfully.",
            json!({ "output": output }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn write_shared_vhost(path: &str, content: &str, reload: bool) -> Result<String, String> {
    let path = allowed_path(path)?;
    if content.trim().is_empty() {
        return Err("Shared vhost content is empty.".into());
    }

    let parent = path
        .parent()
        .ok_or_else(|| "Shared vhost path has no parent directory.".to_string())?;
    fs::create_dir_all(parent)
        .map_err(|error| format!("failed to create {}: {error}", parent.display()))?;
    fs::write(&path, content)
        .map_err(|error| format!("failed to write {}: {error}", path.display()))?;

    let mut output = vec![format!("Wrote {}", path.display())];

    if path.starts_with("/etc/apache2/sites-available") {
        let enabled = PathBuf::from("/etc/apache2/sites-enabled").join(
            path.file_name()
                .ok_or_else(|| "Invalid vhost filename.".to_string())?,
        );
        if !enabled.exists() {
            fs::create_dir_all("/etc/apache2/sites-enabled")
                .map_err(|error| format!("failed to create sites-enabled: {error}"))?;
            symlink(&path, &enabled)
                .map_err(|error| format!("failed to enable {}: {error}", enabled.display()))?;
            output.push(format!("Enabled {}", enabled.display()));
        }
    }

    output.push(run_capture("apache2ctl", &["-t"])?);
    if reload {
        output.push(run_capture("systemctl", &["reload", "apache2"])?);
    }

    Ok(join_output(&output))
}

fn allowed_path(path: &str) -> Result<PathBuf, String> {
    let path = PathBuf::from(path.trim());
    if !path.is_absolute() {
        return Err("Shared vhost path must be absolute.".into());
    }

    if path.starts_with("/etc/apache2/sites-available")
        || path.starts_with("/etc/httpd/conf.d")
        || path.starts_with("/etc/apache2/conf-available")
    {
        return Ok(path);
    }

    Err("Shared vhost path is outside allowed Apache config directories.".into())
}

fn run_capture(program: &str, args: &[&str]) -> Result<String, String> {
    let output = Command::new(program)
        .args(args)
        .output()
        .map_err(|error| format!("failed to run {program}: {error}"))?;
    let text = join_output(&[
        String::from_utf8_lossy(&output.stdout).trim().to_string(),
        String::from_utf8_lossy(&output.stderr).trim().to_string(),
    ]);

    if output.status.success() {
        Ok(if text.is_empty() {
            "Command completed.".into()
        } else {
            text
        })
    } else if text.is_empty() {
        Err(format!(
            "{program} {:?} failed with status {}",
            args, output.status
        ))
    } else {
        Err(text)
    }
}

fn join_output(parts: &[String]) -> String {
    parts
        .iter()
        .map(|part| part.trim())
        .filter(|part| !part.is_empty())
        .collect::<Vec<_>>()
        .join("\n")
}
