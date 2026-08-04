use std::{fs, io, io::Write, path::Path, sync::Arc, time::Duration};

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token, operation_response};

use super::{
    common::{ensure_directory_inside_home, validate_account, validate_user_path},
    unzip::{
        ensure_directory_tree, fix_extracted_tree, is_symlink_entry, safe_entry_path,
        validate_archive, validate_replaceable_existing_target,
    },
};

const MAX_PACKAGE_BYTES: usize = 64 * 1024 * 1024;
const MAX_ENTRIES: usize = 100_000;
const MAX_EXPANDED_BYTES: u64 = 2 * 1024 * 1024 * 1024;

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    version: Option<String>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(response) = check_token(&state, &headers) {
        return response.into_response();
    }

    let version = request.version.unwrap_or_else(|| "latest".into());
    let url = match package_url(&version) {
        Ok(url) => url,
        Err(error) => return ApiResponse::error(&error).into_response(),
    };
    let username = request.username.clone();
    let path = request.path.clone();
    match tokio::task::spawn_blocking(move || prepare_target(&username, &path)).await {
        Ok(Ok(())) => {}
        Ok(Err(error)) => return ApiResponse::error(&error).into_response(),
        Err(error) => {
            return ApiResponse::error(&format!("WordPress preflight worker failed: {error}"))
                .into_response();
        }
    }

    let client = match reqwest::Client::builder()
        .connect_timeout(Duration::from_secs(10))
        .timeout(Duration::from_secs(90))
        .build()
    {
        Ok(client) => client,
        Err(error) => {
            return ApiResponse::error(&format!("WordPress download client failed: {error}"))
                .into_response();
        }
    };
    let response = match client
        .get(url)
        .header(reqwest::header::ACCEPT_ENCODING, "identity")
        .header(reqwest::header::USER_AGENT, "drust-wordpress-installer/1.0")
        .send()
        .await
    {
        Ok(response) => response,
        Err(error) => {
            return ApiResponse::error(&format!("WordPress download failed: {error}"))
                .into_response();
        }
    };
    if !response.status().is_success() {
        return ApiResponse::error(&format!(
            "WordPress download returned {}",
            response.status()
        ))
        .into_response();
    }
    let package = match response.bytes().await {
        Ok(bytes) if bytes.len() <= MAX_PACKAGE_BYTES => bytes.to_vec(),
        Ok(_) => {
            return ApiResponse::error("WordPress package exceeds the download limit")
                .into_response();
        }
        Err(error) => {
            return ApiResponse::error(&format!("WordPress download failed: {error}"))
                .into_response();
        }
    };

    let result = tokio::task::spawn_blocking(move || {
        install_package(&request.username, &request.path, &package)
    })
    .await;
    match result {
        Ok(result) => operation_response(result, "WordPress installed by Rust"),
        Err(error) => {
            ApiResponse::error(&format!("WordPress install worker failed: {error}")).into_response()
        }
    }
}

fn prepare_target(username: &str, target: &str) -> Result<(), String> {
    let (home, canonical_home, group) = validate_account(username)?;
    let target = validate_user_path(username, target)?;
    let target = ensure_directory_inside_home(
        username,
        &group,
        &home,
        &canonical_home,
        &target,
        "WordPress document root",
    )?;
    ensure_installable_target(&target)
}

fn package_url(version: &str) -> Result<String, String> {
    let version = version.trim().to_lowercase();
    if version.is_empty() || version == "latest" {
        return Ok("https://wordpress.org/latest.zip".into());
    }
    if !version
        .bytes()
        .all(|byte| byte.is_ascii_digit() || byte == b'.')
    {
        return Err("Invalid WordPress version".into());
    }
    Ok(format!("https://wordpress.org/wordpress-{version}.zip"))
}

fn install_package(username: &str, target: &str, package: &[u8]) -> Result<(), String> {
    let (home, canonical_home, group) = validate_account(username)?;
    let target = validate_user_path(username, target)?;
    let target = ensure_directory_inside_home(
        username,
        &group,
        &home,
        &canonical_home,
        &target,
        "WordPress document root",
    )?;
    ensure_installable_target(&target)?;

    let mut archive = zip::ZipArchive::new(io::Cursor::new(package))
        .map_err(|error| format!("Invalid WordPress package: {error}"))?;
    validate_archive(&mut archive, MAX_ENTRIES, MAX_EXPANDED_BYTES)?;
    remove_managed_demo(&target)?;

    for index in 0..archive.len() {
        let mut entry = archive
            .by_index(index)
            .map_err(|error| format!("Failed to read WordPress entry: {error}"))?;
        let path = safe_entry_path(&entry, index)?;
        let relative = path
            .strip_prefix("wordpress")
            .map_err(|_| "WordPress package layout is invalid".to_string())?;
        if relative.as_os_str().is_empty() || is_symlink_entry(&entry) {
            continue;
        }
        if entry.is_dir() {
            ensure_directory_tree(&target, relative)?;
            continue;
        }
        let parent =
            ensure_directory_tree(&target, relative.parent().unwrap_or_else(|| Path::new("")))?;
        let destination = target.join(relative);
        validate_replaceable_existing_target(&destination)?;
        let temporary = parent.join(format!(".dpanel-wordpress-{}-{index}", std::process::id()));
        let mut output = fs::File::create(&temporary)
            .map_err(|error| format!("Failed to create WordPress file: {error}"))?;
        io::copy(&mut entry, &mut output)
            .map_err(|error| format!("Failed to extract WordPress file: {error}"))?;
        output
            .flush()
            .map_err(|error| format!("Failed to flush WordPress file: {error}"))?;
        fs::rename(&temporary, &destination)
            .map_err(|error| format!("Failed to install WordPress file: {error}"))?;
    }
    fix_extracted_tree(username, &group, &target)
}

fn ensure_installable_target(target: &Path) -> Result<(), String> {
    for entry in
        fs::read_dir(target).map_err(|error| format!("Cannot inspect document root: {error}"))?
    {
        let entry =
            entry.map_err(|error| format!("Cannot inspect document root entry: {error}"))?;
        let name = entry.file_name();
        let name = name.to_string_lossy();
        let allowed = match name.as_ref() {
            "index.php" => fs::read_to_string(entry.path())
                .map(|body| body.contains("@serverpanel-starter"))
                .unwrap_or(false),
            "index.html" => fs::read_to_string(entry.path())
                .map(|body| body.contains("Starter page generated by ServerPanel"))
                .unwrap_or(false),
            "extra" => managed_extra_only(&entry.path()),
            _ => false,
        };
        if !allowed {
            return Err(format!(
                "WordPress install requires an empty document root; found: {name}"
            ));
        }
    }
    Ok(())
}

fn managed_extra_only(path: &Path) -> bool {
    fs::read_dir(path)
        .map(|mut entries| {
            entries.all(|entry| {
                entry
                    .ok()
                    .is_some_and(|item| item.file_name() == "first-site-note.txt")
            })
        })
        .unwrap_or(false)
}

fn remove_managed_demo(target: &Path) -> Result<(), String> {
    for name in ["index.php", "index.html", "extra"] {
        let path = target.join(name);
        if path.is_dir() {
            fs::remove_dir_all(&path)
                .map_err(|error| format!("Cannot remove managed demo folder: {error}"))?;
        } else if path.exists() {
            fs::remove_file(&path)
                .map_err(|error| format!("Cannot remove managed demo file: {error}"))?;
        }
    }
    Ok(())
}
