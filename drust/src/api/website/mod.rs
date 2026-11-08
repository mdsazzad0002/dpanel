//! Website archives over the API: the panel's zip (trash, clone), cPanel-style
//! account backups, their restore, and deleting a site's files and user.

use std::fs;
use std::io::{Read, Write};
use std::path::{Component, Path, PathBuf};
use std::process::{Command, Stdio};
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use zip::{CompressionMethod, ZipArchive, ZipWriter, write::SimpleFileOptions};

use crate::api::{ApiResponse, ApiState, check_token};
use crate::database::cli;
use crate::platform::{
    self,
    account::{AccountSpec, NO_LOGIN_SHELL},
    fs::FileMode,
};

use axum::Router;
use axum::routing::post;


mod clone;
mod cpmove;
mod progress;
mod restore;
mod zip_archive;

use clone::*;
use cpmove::*;
use progress::*;
use restore::*;
use zip_archive::*;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/website/archive", post(handle))
        .route("/api/v1/website/clone-package", post(clone_package_handle))
        .route(
            "/api/v1/website/archive/restore",
            post(restore_archive_handle),
        )
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

pub(super) fn delete_archive(value: &str) -> Result<(), String> {
    let path = normalize_path(value);
    let root = platform::paths().storage_dir().join("website-trash");
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

pub(super) fn delete_website(request: &DeleteRequest) -> Result<(), String> {
    let user = request.site_owner.trim();
    if user.is_empty()
        || !user
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-')
    {
        return Err("invalid website owner".into());
    }

    let home = crate::platform::paths().site_home(user);
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
        if platform::accounts().exists(user).unwrap_or(false) {
            platform::accounts()
                .delete(user, false)
                .map_err(|error| error.to_string())?;
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
    pub assigned_user_id: Option<u64>,
    pub assigned_reseller_id: Option<u64>,
    #[serde(default = "default_archive_content")]
    pub content: String,
    #[serde(default)]
    pub database_requests: Vec<DatabaseArchive>,
}

#[derive(Deserialize)]
pub(crate) struct DatabaseArchive {
    pub name: String,
    pub user: String,
    pub password: String,
    pub host: String,
}

pub(super) fn default_archive_content() -> String {
    "all".into()
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

pub(crate) async fn clone_package_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<ArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result =
        tokio::task::spawn_blocking(move || build_clone_package(&request.zip_path, request.website))
            .await
            .unwrap_or_else(|error| Err(format!("clone package worker crashed: {error}")));
    match result {
        Ok(data) => ApiResponse::ok_data("Clone package created", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

#[derive(Deserialize)]
pub(crate) struct RestoreArchiveRequest {
    pub zip_path: String,
    /// Optional JSON file the panel polls while this (long, synchronous)
    /// restore runs — see RestoreProgress.
    #[serde(default)]
    pub progress_path: Option<String>,
}

pub(crate) async fn restore_archive_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<RestoreArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    // A multi-GB restore runs tar/cp/mysql for many minutes; keep that off the
    // async runtime's worker threads.
    let result = tokio::task::spawn_blocking(move || {
        let progress = RestoreProgress::new(request.progress_path.as_deref());
        let result = restore_archive(&request.zip_path, &progress);
        if let Err(error) = &result {
            progress.fail(error);
        }
        result
    })
    .await
    .unwrap_or_else(|error| Err(format!("restore worker crashed: {error}")));
    match result {
        Ok(website) => ApiResponse::ok_data(
            "Website archive restored",
            serde_json::json!({ "website": website }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&format!("Restore failed: {error}")).into_response(),
    }
}

pub(super) fn normalize_path(input: &str) -> PathBuf {
    let trimmed = input.trim();
    if trimmed.is_empty() {
        return PathBuf::new();
    }
    PathBuf::from(trimmed)
}

pub(super) fn chrono_like_now() -> String {
    // Keep the manifest lightweight without adding a date dependency.
    format!("{:?}", std::time::SystemTime::now())
}
