use std::fs;
use std::io::Write;
use std::path::{Component, Path, PathBuf};
use std::process::Command;
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use zip::{CompressionMethod, ZipWriter, write::SimpleFileOptions};

use crate::api::{ApiResponse, ApiState, check_token};

use axum::Router;
use axum::routing::post;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/website/archive", post(handle))
        .route(
            "/api/v1/website/archive/delete",
            post(delete_archive_handle),
        )
        .route("/api/v1/website/delete", post(delete_handle))
}

#[derive(Deserialize)]
pub(crate) struct DeleteArchiveRequest {
    pub zip_path: String,
}

pub(crate) async fn delete_archive_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<DeleteArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match delete_archive(&request.zip_path) {
        Ok(()) => ApiResponse::ok("Website trash archive removed").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

fn delete_archive(value: &str) -> Result<(), String> {
    let path = normalize_path(value);
    let root = Path::new("/var/www/dpanel/storage/app/website-trash");
    if path.extension().and_then(|value| value.to_str()) != Some("zip")
        || !path.starts_with(root)
        || path
            .components()
            .any(|part| matches!(part, Component::ParentDir))
    {
        return Err("refusing to remove an invalid trash archive path".into());
    }

    if path.is_file() {
        fs::remove_file(&path)
            .map_err(|error| format!("cannot remove {}: {error}", path.display()))?;
    }
    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct DeleteRequest {
    pub site_owner: String,
    pub paths: Vec<String>,
    pub delete_user: bool,
}

pub(crate) async fn delete_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<DeleteRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match delete_website(&request) {
        Ok(()) => ApiResponse::ok("Website user and directories removed").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

fn delete_website(request: &DeleteRequest) -> Result<(), String> {
    let user = request.site_owner.trim();
    if user.is_empty()
        || !user
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-')
    {
        return Err("invalid website owner".into());
    }

    let home = PathBuf::from(format!("/home/{user}"));
    for value in &request.paths {
        let path = normalize_path(value);
        if path != home && !path.starts_with(&home) {
            return Err(format!(
                "refusing to remove path outside {}",
                home.display()
            ));
        }
    }

    if request.delete_user {
        if Command::new("id")
            .args(["-u", user])
            .status()
            .map(|status| status.success())
            .unwrap_or(false)
        {
            let output = Command::new("userdel")
                .args(["-r", user])
                .output()
                .map_err(|error| format!("cannot run userdel: {error}"))?;
            if !output.status.success() {
                return Err(String::from_utf8_lossy(&output.stderr).trim().to_string());
            }
        } else if home.exists() {
            fs::remove_dir_all(&home)
                .map_err(|error| format!("cannot remove {}: {error}", home.display()))?;
        }
        return Ok(());
    }

    for value in &request.paths {
        let path = normalize_path(value);
        if path == home {
            continue;
        }
        if path.is_dir() {
            fs::remove_dir_all(&path)
                .map_err(|error| format!("cannot remove {}: {error}", path.display()))?;
        } else if path.is_file() {
            fs::remove_file(&path)
                .map_err(|error| format!("cannot remove {}: {error}", path.display()))?;
        }
    }
    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct ArchiveRequest {
    pub zip_path: String,
    pub website: WebsiteArchive,
}

#[derive(Deserialize)]
pub(crate) struct WebsiteArchive {
    pub id: String,
    pub domain: String,
    pub root_path: String,
    pub project_root: String,
    pub start_directory: Option<String>,
    pub site_owner: Option<String>,
    pub php_version: Option<String>,
    pub status: Option<String>,
    pub type_field: Option<String>,
    pub enable_ssl: Option<bool>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<ArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match archive_website(&request.zip_path, &request.website) {
        Ok(data) => ApiResponse::ok_data("Website archived successfully", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

fn archive_website(zip_path: &str, website: &WebsiteArchive) -> Result<serde_json::Value, String> {
    let zip_path = normalize_path(zip_path);
    if zip_path.as_os_str().is_empty() {
        return Err("Missing zip path.".into());
    }

    if let Some(parent) = zip_path.parent() {
        fs::create_dir_all(parent)
            .map_err(|e| format!("failed to create archive directory: {e}"))?;
    }

    let file = fs::File::create(&zip_path)
        .map_err(|e| format!("failed to create zip archive {}: {e}", zip_path.display()))?;
    let mut writer = ZipWriter::new(file);
    let options = SimpleFileOptions::default()
        .compression_method(CompressionMethod::Deflated)
        .unix_permissions(0o644);

    let manifest = serde_json::json!({
        "website": {
            "id": website.id,
            "domain": website.domain,
            "root_path": website.root_path,
            "project_root": website.project_root,
            "start_directory": website.start_directory,
            "site_owner": website.site_owner,
            "php_version": website.php_version,
            "status": website.status,
            "type": website.type_field,
            "enable_ssl": website.enable_ssl.unwrap_or(false),
            "archived_at": chrono_like_now(),
        }
    });
    writer
        .start_file("manifest.json", options)
        .map_err(|e| format!("failed to write manifest: {e}"))?;
    writer
        .write_all(manifest.to_string().as_bytes())
        .map_err(|e| format!("failed to write manifest: {e}"))?;

    for source in [website.project_root.as_str(), website.root_path.as_str()] {
        let source_path = normalize_path(source);
        if source_path.as_os_str().is_empty() || !source_path.exists() || !source_path.is_dir() {
            continue;
        }
        let base_name = source_path
            .file_name()
            .and_then(|v| v.to_str())
            .unwrap_or("site")
            .to_string();
        add_dir(&mut writer, &source_path, &base_name, options)?;
    }

    writer
        .finish()
        .map_err(|e| format!("failed to finalize zip: {e}"))?;
    Ok(serde_json::json!({ "zip_path": zip_path.display().to_string() }))
}

fn add_dir(
    writer: &mut ZipWriter<std::fs::File>,
    source: &Path,
    zip_root: &str,
    options: SimpleFileOptions,
) -> Result<(), String> {
    if !source.exists() {
        return Ok(());
    }

    let entries = fs::read_dir(source)
        .map_err(|e| format!("failed to read directory {}: {e}", source.display()))?;
    for entry in entries {
        let entry = match entry {
            Ok(item) => item,
            Err(_) => continue,
        };
        let path = entry.path();
        if !path.exists() {
            continue;
        }

        let name = match path.file_name().and_then(|v| v.to_str()) {
            Some(v) => v.to_string(),
            None => continue,
        };
        let archive_path = format!("{}/{}", zip_root.trim_matches('/'), name);

        if path.is_dir() {
            writer
                .add_directory(format!("{}/", archive_path), options)
                .map_err(|e| format!("failed to add directory {}: {e}", path.display()))?;
            add_dir(writer, &path, &archive_path, options)?;
            continue;
        }

        if path.is_file() {
            if let Err(_) = fs::File::open(&path) {
                continue;
            }
            writer
                .start_file(archive_path, options)
                .map_err(|e| format!("failed to add file {}: {e}", path.display()))?;
            let mut file = fs::File::open(&path)
                .map_err(|e| format!("failed to open file {}: {e}", path.display()))?;
            std::io::copy(&mut file, writer)
                .map_err(|e| format!("failed to write file {}: {e}", path.display()))?;
        }
    }

    Ok(())
}

fn normalize_path(input: &str) -> PathBuf {
    let trimmed = input.trim();
    if trimmed.is_empty() {
        return PathBuf::new();
    }
    PathBuf::from(trimmed)
}

fn chrono_like_now() -> String {
    // Keep the manifest lightweight without adding a date dependency.
    format!("{:?}", std::time::SystemTime::now())
}
