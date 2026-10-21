use std::path::Path;
use std::process::Command;
use std::sync::Arc;

use axum::Router;
use axum::extract::{Json, State};
use axum::http::HeaderMap;
use axum::response::{IntoResponse, Response};
use axum::routing::post;
use serde::Deserialize;
use std::fs;

use crate::api::{ApiResponse, ApiState, check_token};

const DEFAULT_PANEL_ROOT: &str = "/var/www/dpanel";

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/backup/run", post(run_handle))
        .route("/api/v1/backup/delete", post(delete_handle))
}

#[derive(Deserialize)]
pub(crate) struct DeleteBackupRequest {
    pub run_path: String,
}

pub(crate) async fn delete_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<DeleteBackupRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let panel_root =
        std::env::var("DRUST_PANEL_ROOT").unwrap_or_else(|_| DEFAULT_PANEL_ROOT.into());
    match delete_backup_run(&request.run_path, Path::new(&panel_root)) {
        Ok(()) => ApiResponse::ok("Backup run deleted").into_response(),
        Err(error) => ApiResponse::error(&format!("Backup delete failed: {error}")).into_response(),
    }
}

fn delete_backup_run(run_path: &str, panel_root: &Path) -> Result<(), String> {
    let root = panel_root
        .join("storage/app/backups")
        .canonicalize()
        .map_err(|error| format!("backup storage is unavailable: {error}"))?;
    let requested = Path::new(run_path.trim());
    let name = requested
        .file_name()
        .and_then(|value| value.to_str())
        .ok_or_else(|| "invalid backup run path".to_string())?;
    let valid_name = name.len() == 15
        && name.chars().enumerate().all(|(index, character)| {
            if index == 8 {
                character == '_'
            } else {
                character.is_ascii_digit()
            }
        });
    if !valid_name {
        return Err("invalid backup run name".into());
    }

    let metadata = fs::symlink_metadata(requested)
        .map_err(|error| format!("backup run not found: {error}"))?;
    if metadata.file_type().is_symlink() || !metadata.is_dir() {
        return Err("backup run must be a real directory".into());
    }
    let path = requested
        .canonicalize()
        .map_err(|error| format!("backup run not found: {error}"))?;
    if path.parent() != Some(root.as_path()) {
        return Err("backup run is outside the configured backup storage".into());
    }

    fs::remove_dir_all(&path).map_err(|error| error.to_string())?;
    if path.exists() {
        return Err("backup run still exists after deletion".into());
    }

    Ok(())
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

    let panel_root =
        std::env::var("DRUST_PANEL_ROOT").unwrap_or_else(|_| DEFAULT_PANEL_ROOT.into());
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
    use super::{delete_backup_run, run_backup};
    use std::{
        fs,
        path::Path,
        time::{SystemTime, UNIX_EPOCH},
    };

    #[test]
    fn rejects_unknown_backup_type() {
        let error = run_backup("all; reboot").expect_err("request must be rejected");
        assert!(error.contains("invalid backup type"));
    }

    #[test]
    fn deletes_backup_run_inside_the_configured_panel_root() {
        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("system clock should be after the epoch")
            .as_nanos();
        let panel_root = std::env::temp_dir().join(format!("dpanel-backup-delete-{nonce}"));
        let backup_root = panel_root.join("storage/app/backups");
        let run_path = backup_root.join("20261021_023002");
        fs::create_dir_all(&run_path).expect("backup run should be created");
        fs::write(run_path.join("backup.zip"), b"backup").expect("backup file should be created");

        delete_backup_run(
            run_path
                .to_str()
                .expect("temporary path should be valid UTF-8"),
            Path::new(&panel_root),
        )
        .expect("backup run should be deleted");

        assert!(!run_path.exists());
        fs::remove_dir_all(panel_root).expect("temporary test files should be removed");
    }

    #[test]
    fn refuses_to_delete_a_backup_run_outside_the_configured_storage() {
        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("system clock should be after the epoch")
            .as_nanos();
        let panel_root = std::env::temp_dir().join(format!("dpanel-backup-delete-{nonce}"));
        let outside_run = std::env::temp_dir().join(format!("20261021_023002-{nonce}"));
        fs::create_dir_all(&panel_root).expect("panel root should be created");
        fs::create_dir_all(&outside_run).expect("outside directory should be created");

        let result = delete_backup_run(
            outside_run
                .to_str()
                .expect("temporary path should be valid UTF-8"),
            Path::new(&panel_root),
        );

        assert!(result.is_err());
        assert!(outside_run.exists());
        fs::remove_dir_all(panel_root).expect("temporary panel root should be removed");
        fs::remove_dir_all(outside_run).expect("temporary outside directory should be removed");
    }

    #[cfg(unix)]
    #[test]
    fn refuses_to_follow_a_symlinked_backup_run() {
        use std::os::unix::fs::symlink;

        let nonce = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .expect("system clock should be after the epoch")
            .as_nanos();
        let panel_root = std::env::temp_dir().join(format!("dpanel-backup-delete-{nonce}"));
        let backup_root = panel_root.join("storage/app/backups");
        let actual_run = backup_root.join("20261020_023002");
        let linked_run = backup_root.join("20261021_023002");
        fs::create_dir_all(&actual_run).expect("backup run should be created");
        fs::write(actual_run.join("backup.zip"), b"backup").expect("backup file should be created");
        symlink(&actual_run, &linked_run).expect("backup symlink should be created");

        let result = delete_backup_run(
            linked_run
                .to_str()
                .expect("temporary path should be valid UTF-8"),
            Path::new(&panel_root),
        );

        assert!(result.is_err());
        assert!(actual_run.exists());
        assert!(linked_run.exists());
        fs::remove_dir_all(panel_root).expect("temporary test files should be removed");
    }
}
