use std::{
    fs,
    os::unix::fs::PermissionsExt,
    path::{Path, PathBuf},
    sync::Arc,
    time::{SystemTime, UNIX_EPOCH},
};

use axum::{
    Router,
    extract::{Json, State},
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/database-config", post(handle))
}

#[derive(Deserialize)]
struct Request {
    site_owner: String,
    framework: String,
    config_path: String,
    database_name: String,
    database_user: String,
    database_password: String,
    database_host: Option<String>,
    database_port: Option<u16>,
}

async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match apply(&request) {
        Ok(backup) => ApiResponse::ok_data(
            "Database configuration connected.",
            serde_json::json!({"backup_path": backup}),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn apply(request: &Request) -> Result<PathBuf, String> {
    validate(request)?;
    let path = PathBuf::from(&request.config_path);
    let original = fs::read_to_string(&path)
        .map_err(|error| format!("Unable to read project configuration: {error}"))?;
    let stamp = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|error| error.to_string())?
        .as_secs();
    let backup_dir = PathBuf::from(format!("/home/{}/.dpanel/backups", request.site_owner));
    fs::create_dir_all(&backup_dir)
        .map_err(|error| format!("Unable to create protected backup directory: {error}"))?;
    fs::set_permissions(&backup_dir, fs::Permissions::from_mode(0o700))
        .map_err(|error| format!("Unable to protect backup directory: {error}"))?;
    let backup = backup_dir.join(format!(
        "{}-{}-{stamp}.bak",
        request.framework,
        path.file_name()
            .and_then(|name| name.to_str())
            .unwrap_or("config")
    ));
    fs::copy(&path, &backup)
        .map_err(|error| format!("Unable to back up project configuration: {error}"))?;
    fs::set_permissions(&backup, fs::Permissions::from_mode(0o600))
        .map_err(|error| format!("Unable to protect configuration backup: {error}"))?;

    let host = request
        .database_host
        .as_deref()
        .filter(|value| !value.trim().is_empty())
        .unwrap_or("127.0.0.1");
    let port = request.database_port.unwrap_or(3306).to_string();
    let updated = match request.framework.as_str() {
        "laravel" => update_env(
            original,
            &[
                ("DB_CONNECTION", "mysql"),
                ("DB_HOST", host),
                ("DB_PORT", &port),
                ("DB_DATABASE", &request.database_name),
                ("DB_USERNAME", &request.database_user),
                ("DB_PASSWORD", &request.database_password),
            ],
        ),
        "wordpress" => update_wordpress(original, request, host)?,
        _ => return Err("Only Laravel and WordPress projects are supported.".into()),
    };
    fs::write(&path, updated)
        .map_err(|error| format!("Unable to update project database configuration: {error}"))?;
    Ok(backup)
}

fn validate(request: &Request) -> Result<(), String> {
    let valid_owner = request
        .site_owner
        .chars()
        .enumerate()
        .all(|(index, value)| {
            value.is_ascii_lowercase()
                || value.is_ascii_digit()
                || value == '_'
                || (value == '-' && index > 0)
        });
    if request.site_owner.is_empty() || !valid_owner {
        return Err("Invalid website owner.".into());
    }
    let path = Path::new(&request.config_path);
    if !path.starts_with(format!("/home/{}/", request.site_owner))
        || path
            .components()
            .any(|part| matches!(part, std::path::Component::ParentDir))
    {
        return Err("Configuration path is outside the website owner's home.".into());
    }
    let expected = if request.framework == "laravel" {
        ".env"
    } else if request.framework == "wordpress" {
        "wp-config.php"
    } else {
        return Err("Unsupported project type.".into());
    };
    if path.file_name().and_then(|name| name.to_str()) != Some(expected) {
        return Err("Unexpected project configuration file.".into());
    }
    if !path.is_file() {
        return Err("Project configuration file does not exist.".into());
    }
    for (value, label) in [
        (&request.database_name, "database name"),
        (&request.database_user, "database user"),
    ] {
        if value.is_empty()
            || value.len() > 64
            || !value
                .bytes()
                .all(|byte| byte.is_ascii_alphanumeric() || byte == b'_')
        {
            return Err(format!("Invalid {label}."));
        }
    }
    if request.database_password.is_empty() {
        return Err("Database password is empty.".into());
    }
    Ok(())
}

fn env_value(value: &str) -> String {
    if value
        .bytes()
        .all(|byte| byte.is_ascii_alphanumeric() || b"._-".contains(&byte))
    {
        return value.to_string();
    }
    format!(
        "\"{}\"",
        value
            .replace('\\', "\\\\")
            .replace('"', "\\\"")
            .replace('$', "\\$")
    )
}

fn update_env(mut text: String, values: &[(&str, &str)]) -> String {
    for (key, value) in values {
        let replacement = format!("{key}={}", env_value(value));
        let prefix = format!("{key}=");
        if let Some(old) = text
            .lines()
            .find(|line| line.trim_start().starts_with(&prefix))
            .map(str::to_owned)
        {
            text = text.replacen(&old, &replacement, 1);
        } else {
            if !text.ends_with('\n') {
                text.push('\n');
            }
            text.push_str(&replacement);
            text.push('\n');
        }
    }
    text
}

fn php_string(value: &str) -> String {
    value.replace('\\', "\\\\").replace('\'', "\\'")
}

fn update_wordpress(text: String, request: &Request, host: &str) -> Result<String, String> {
    let values = [
        ("DB_NAME", request.database_name.as_str()),
        ("DB_USER", request.database_user.as_str()),
        ("DB_PASSWORD", request.database_password.as_str()),
        ("DB_HOST", host),
    ];
    let mut output = text;
    for (key, value) in values {
        let replacement = format!("define( '{key}', '{}' );", php_string(value));
        let needle = format!("'{key}'");
        let old = output
            .lines()
            .find(|line| line.contains("define") && line.contains(&needle))
            .map(str::to_owned)
            .ok_or_else(|| format!("WordPress constant {key} was not found."))?;
        output = output.replacen(&old, &replacement, 1);
    }
    Ok(output)
}
