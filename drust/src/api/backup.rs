use std::path::Path;
use std::process::Command;
use std::sync::Arc;

use axum::Router;
use axum::extract::{Json, State};
use axum::http::HeaderMap;
use axum::response::{IntoResponse, Response};
use axum::routing::post;
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

const DEFAULT_PANEL_ROOT: &str = "/var/www/dpanel";

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/backup/run", post(run_handle))
}

#[derive(Deserialize)]
pub(crate) struct RunBackupRequest {
    pub only: String,
}

pub(crate) async fn run_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<RunBackupRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match run_backup(&request.only) {
        Ok(output) => ApiResponse::ok_data(
            "Backup completed through dRust",
            serde_json::json!({ "output": output }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&format!("Backup failed: {error}")).into_response(),
    }
}

fn run_backup(only: &str) -> Result<String, String> {
    if !matches!(only, "all" | "db" | "files") {
        return Err("invalid backup type; use all, db, or files".into());
    }

    let panel_root = std::env::var("DRUST_PANEL_ROOT").unwrap_or_else(|_| DEFAULT_PANEL_ROOT.into());
    let artisan = Path::new(&panel_root).join("artisan");
    if !artisan.is_file() {
        return Err(format!("artisan not found at {}", artisan.display()));
    }

    // Never use PHP_BINARY from a web/FPM process. dRust selects an explicit
    // CLI SAPI, which prevents php-fpm from printing its usage text here.
    let php_cli = std::env::var("DRUST_PHP_CLI").unwrap_or_else(|_| "/usr/bin/php".into());
    let output = Command::new(&php_cli)
        .current_dir(&panel_root)
        .arg(&artisan)
        .arg("serverpanel:backup")
        .arg(format!("--only={only}"))
        .output()
        .map_err(|error| format!("cannot execute {php_cli}: {error}"))?;

    let stdout = String::from_utf8_lossy(&output.stdout).trim().to_string();
    let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
    if !output.status.success() {
        return Err(if stderr.is_empty() {
            stdout
        } else {
            format!("{stderr}\n{stdout}").trim().to_string()
        });
    }

    Ok(stdout)
}

#[cfg(test)]
mod tests {
    use super::run_backup;

    #[test]
    fn rejects_unknown_backup_type() {
        let error = run_backup("all; reboot").expect_err("request must be rejected");
        assert!(error.contains("invalid backup type"));
    }
}
