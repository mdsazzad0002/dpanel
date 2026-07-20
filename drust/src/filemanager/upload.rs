use std::fs;
use std::io;
use std::os::unix::fs::OpenOptionsExt;
use std::path::{Path, PathBuf};
use std::sync::Arc;
use std::time::{SystemTime, UNIX_EPOCH};

use crate::api::{ApiResponse, ApiState, check_token, operation_response};
use crate::app::info;
use axum::{
    extract::{Multipart, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use tokio::io::AsyncWriteExt;

use super::common::{
    apply_owner_and_mode, ensure_canonical_inside_home, validate_account, validate_user_path,
};

pub fn install_uploaded_file(username: &str, target: &str, staged: &Path) -> Result<(), String> {
    let (user_home, canonical_home, group) = validate_account(username)?;
    if !staged.is_file() {
        return Err("Staged upload is unavailable.".into());
    }

    let path = validate_user_path(username, target)?;
    if path == user_home {
        return Err("The account home cannot be replaced by a file.".into());
    }

    let parent = path
        .parent()
        .ok_or_else(|| "File parent is missing.".to_string())?;
    ensure_canonical_inside_home(&canonical_home, parent, "Upload folder")?;

    if let Ok(metadata) = fs::symlink_metadata(&path) {
        if metadata.file_type().is_symlink() {
            return Err("Refusing to replace a symbolic link.".into());
        }
        if metadata.is_dir() {
            return Err("The upload target is a directory.".into());
        }
    }

    let temporary = parent.join(format!(
        ".dpanel-upload-{}-{}",
        std::process::id(),
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
    ));

    let install_result = (|| -> Result<(), String> {
        let mut source =
            fs::File::open(staged).map_err(|e| format!("failed to open staged upload: {e}"))?;
        let mut destination = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&temporary)
            .map_err(|e| format!("failed to create {}: {e}", temporary.display()))?;
        io::copy(&mut source, &mut destination)
            .map_err(|e| format!("failed to copy upload to {}: {e}", temporary.display()))?;
        apply_owner_and_mode(username, &group, &temporary, "0644")?;
        fs::rename(&temporary, &path)
            .map_err(|e| format!("failed to install upload at {}: {e}", path.display()))?;
        Ok(())
    })();

    if install_result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    install_result?;
    info(&format!("file uploaded: {}", path.display()));
    Ok(())
}
pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    mut multipart: Multipart,
) -> Response {
    if let Err(response) = check_token(&state, &headers) {
        return response.into_response();
    }

    let mut username: Option<String> = None;
    let mut path: Option<String> = None;
    let mut staged_upload: Option<PathBuf> = None;
    let max_bytes = std::env::var("DRUST_MAX_UPLOAD_SIZE_BYTES")
        .ok()
        .and_then(|value| value.parse::<u64>().ok())
        .unwrap_or(10 * 1024 * 1024 * 1024);

    loop {
        let field = match multipart.next_field().await {
            Ok(Some(field)) => field,
            Ok(None) => break,
            Err(error) => {
                cleanup(staged_upload.as_deref()).await;
                return ApiResponse::error(&format!("Invalid multipart upload: {error}"))
                    .into_response();
            }
        };
        let name = field.name().unwrap_or("").to_string();

        match name.as_str() {
            "username" => match field.text().await {
                Ok(value) => username = Some(value),
                Err(error) => {
                    cleanup(staged_upload.as_deref()).await;
                    return ApiResponse::error(&format!("Invalid username field: {error}"))
                        .into_response();
                }
            },
            "path" => match field.text().await {
                Ok(value) => path = Some(value),
                Err(error) => {
                    cleanup(staged_upload.as_deref()).await;
                    return ApiResponse::error(&format!("Invalid path field: {error}"))
                        .into_response();
                }
            },
            "upload" => {
                if staged_upload.is_some() {
                    cleanup(staged_upload.as_deref()).await;
                    return ApiResponse::error("Only one upload file is allowed").into_response();
                }

                let staged_path = std::env::temp_dir().join(format!(
                    "drust-upload-{}-{}",
                    std::process::id(),
                    SystemTime::now()
                        .duration_since(UNIX_EPOCH)
                        .unwrap_or_default()
                        .as_nanos()
                ));
                let mut staged_file = match tokio::fs::OpenOptions::new()
                    .write(true)
                    .create_new(true)
                    .mode(0o600)
                    .open(&staged_path)
                    .await
                {
                    Ok(file) => file,
                    Err(error) => {
                        return ApiResponse::error(&format!(
                            "Failed to prepare upload staging file: {error}"
                        ))
                        .into_response();
                    }
                };

                let mut received = 0_u64;
                let mut upload_field = field;
                loop {
                    match upload_field.chunk().await {
                        Ok(Some(chunk)) => {
                            received = received.saturating_add(chunk.len() as u64);
                            if received > max_bytes {
                                let _ = tokio::fs::remove_file(&staged_path).await;
                                return ApiResponse::error("Upload exceeds the server size limit")
                                    .into_response();
                            }
                            if let Err(error) = staged_file.write_all(&chunk).await {
                                let _ = tokio::fs::remove_file(&staged_path).await;
                                return ApiResponse::error(&format!(
                                    "Failed while receiving upload: {error}"
                                ))
                                .into_response();
                            }
                        }
                        Ok(None) => break,
                        Err(error) => {
                            let _ = tokio::fs::remove_file(&staged_path).await;
                            return ApiResponse::error(&format!("Failed to read upload: {error}"))
                                .into_response();
                        }
                    }
                }
                if let Err(error) = staged_file.flush().await {
                    let _ = tokio::fs::remove_file(&staged_path).await;
                    return ApiResponse::error(&format!("Failed to flush upload: {error}"))
                        .into_response();
                }
                staged_upload = Some(staged_path);
            }
            _ => {}
        }
    }

    let result = match (username, path, staged_upload.as_deref()) {
        (Some(username), Some(path), Some(staged_path)) => {
            install_uploaded_file(&username, &path, staged_path)
        }
        _ => Err("The username, path, and upload fields are required.".to_string()),
    };
    cleanup(staged_upload.as_deref()).await;
    operation_response(result, "File uploaded")
}

async fn cleanup(path: Option<&Path>) {
    if let Some(path) = path {
        let _ = tokio::fs::remove_file(path).await;
    }
}
