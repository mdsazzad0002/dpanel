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

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    root_path: String,
}

fn existing(root: &std::path::Path, candidates: &[&str]) -> Vec<String> {
    candidates
        .iter()
        .filter(|relative| root.join(relative).exists())
        .map(|value| (*value).to_string())
        .collect()
}

fn inspect(request: &Request) -> Result<serde_json::Value, String> {
    let (_, canonical_home, _) = validate_account(&request.username)?;
    let root = validate_user_path(&request.username, &request.root_path)?;
    let root = ensure_canonical_inside_home(&canonical_home, &root, "Website root")?;
    if !root.is_dir() {
        return Err("Website root is not a directory.".into());
    }

    let wordpress = existing(
        &root,
        &["wp-config.php", "wp-admin", "wp-content", "wp-includes"],
    );
    let laravel = existing(
        &root,
        &["artisan", "bootstrap/app.php", "app", "routes", "storage"],
    );
    let codeigniter = existing(
        &root,
        &[
            "spark",
            "app/Config/App.php",
            "application/config/config.php",
            "system",
        ],
    );
    let detected = if root.join("wp-config.php").is_file() && root.join("wp-content").is_dir() {
        "wordpress"
    } else if root.join("artisan").is_file() && root.join("bootstrap/app.php").is_file() {
        "laravel"
    } else if root.join("spark").is_file() || root.join("application/config/config.php").is_file() {
        "codeigniter"
    } else if fs::read_dir(&root)
        .map_err(|e| format!("Cannot inspect website root: {e}"))?
        .next()
        .is_none()
    {
        "empty"
    } else {
        "unknown"
    };
    let summary = match detected {
        "wordpress" => "WordPress application detected.",
        "laravel" => "Laravel application detected.",
        "codeigniter" => "CodeIgniter application detected.",
        "empty" => "Directory exists but is empty.",
        _ => "No supported application was detected.",
    };
    let storage_path = root.join("public/storage");

    Ok(serde_json::json!({
        "exists": true, "is_directory": true, "is_empty": detected == "empty",
        "detected_app": detected, "wordpress": detected == "wordpress", "laravel": detected == "laravel", "codeigniter": detected == "codeigniter",
        "first_directory_exists": !wordpress.is_empty() || !laravel.is_empty() || !codeigniter.is_empty(),
        "first_directory": wordpress.first().or(laravel.first()).or(codeigniter.first()).cloned().unwrap_or_default(),
        "summary": summary, "signals": { "wordpress": wordpress, "laravel": laravel, "codeigniter": codeigniter },
        "root_path": root, "has_composer_json": root.join("composer.json").is_file(), "has_package_json": root.join("package.json").is_file(),
        "storage_linked": storage_path.symlink_metadata().map(|metadata| metadata.file_type().is_symlink()).unwrap_or(false),
        "storage_link_path": storage_path,
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
    match inspect(&request) {
        Ok(data) => ApiResponse::ok_data("Website inspected", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
