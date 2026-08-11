use std::{fs, os::unix::fs::PermissionsExt, path::Path, process::Command, sync::Arc};

use axum::{
    Router,
    extract::{Json, State},
    response::IntoResponse,
    routing::post,
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/mailbox-storage", post(handle))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    mail_home: Option<String>,
    site_owner: Option<String>,
    previous_mail_home: Option<String>,
    mail_homes: Option<Vec<String>>,
}

async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result = match request.action.as_str() {
        "create" => create(
            request.mail_home.as_deref().unwrap_or(""),
            request.site_owner.as_deref().unwrap_or(""),
        ),
        "move" => move_maildir(
            request.previous_mail_home.as_deref().unwrap_or(""),
            request.mail_home.as_deref().unwrap_or(""),
            request.site_owner.as_deref().unwrap_or(""),
        ),
        "remove" => remove(
            request.mail_home.as_deref().unwrap_or(""),
            request.site_owner.as_deref().unwrap_or(""),
        ),
        "usage" => {
            return match usage(request.mail_homes.as_deref().unwrap_or(&[])) {
                Ok(bytes) => ApiResponse::ok_data(
                    "Mailbox storage usage measured.",
                    serde_json::json!({"bytes": bytes}),
                )
                .into_response(),
                Err(error) => ApiResponse::error(&error).into_response(),
            };
        }
        _ => Err("Unsupported mailbox storage action.".into()),
    };
    match result {
        Ok(()) => ApiResponse::ok("Mailbox storage synchronized.").into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn valid(owner: &str, path: &str) -> bool {
    !owner.is_empty()
        && owner.chars().enumerate().all(|(i, c)| {
            c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || (c == '-' && i > 0)
        })
        && path.starts_with(&format!("/home/{owner}/mail/"))
        && path.ends_with("/Maildir")
}

fn owner_exists(owner: &str) -> Result<(), String> {
    if Command::new("id")
        .args(["-u", owner])
        .status()
        .map_err(|e| e.to_string())?
        .success()
    {
        Ok(())
    } else {
        Err("Website system user does not exist.".into())
    }
}

fn own(path: &str, owner: &str) -> Result<(), String> {
    let output = Command::new("chown")
        .args(["-R", &format!("{owner}:{owner}"), path])
        .output()
        .map_err(|e| e.to_string())?;
    if output.status.success() {
        Ok(())
    } else {
        Err(String::from_utf8_lossy(&output.stderr).trim().to_string())
    }
}

fn create(path: &str, owner: &str) -> Result<(), String> {
    if !valid(owner, path) {
        return Err("Invalid mailbox storage path or owner.".into());
    }
    owner_exists(owner)?;
    for folder in ["", "cur", "new", "tmp"] {
        let directory = Path::new(path).join(folder);
        fs::create_dir_all(&directory).map_err(|e| e.to_string())?;
        fs::set_permissions(&directory, fs::Permissions::from_mode(0o700))
            .map_err(|e| e.to_string())?;
    }
    own(path, owner)?;
    Ok(())
}

fn move_maildir(source: &str, target: &str, owner: &str) -> Result<(), String> {
    if !valid(owner, source) || !valid(owner, target) {
        return Err("Invalid mailbox storage path or owner.".into());
    }
    owner_exists(owner)?;
    if Path::new(source).is_dir() && !Path::new(target).exists() {
        fs::create_dir_all(Path::new(target).parent().ok_or("Invalid target path.")?)
            .map_err(|e| e.to_string())?;
        fs::rename(source, target).map_err(|e| format!("Unable to move Maildir: {e}"))?;
    }
    create(target, owner)
}

fn remove(path: &str, owner: &str) -> Result<(), String> {
    if !valid(owner, path) {
        return Err("Invalid mailbox storage path or owner.".into());
    }
    if Path::new(path).exists() {
        fs::remove_dir_all(path).map_err(|e| format!("Unable to remove Maildir: {e}"))?;
    }
    Ok(())
}

fn usage(paths: &[String]) -> Result<u64, String> {
    let mut total = 0u64;
    for path in paths {
        let parts: Vec<&str> = path.split('/').collect();
        let owner = parts.get(2).copied().unwrap_or("");
        if !valid(owner, path) {
            return Err("Invalid mailbox storage path.".into());
        }
        if !Path::new(path).is_dir() {
            continue;
        }
        let output = Command::new("du")
            .args(["-sb", "--", path])
            .output()
            .map_err(|e| e.to_string())?;
        if !output.status.success() {
            return Err(String::from_utf8_lossy(&output.stderr).trim().to_string());
        }
        let bytes = String::from_utf8_lossy(&output.stdout)
            .split_whitespace()
            .next()
            .unwrap_or("0")
            .parse::<u64>()
            .map_err(|_| "Invalid disk-usage result.".to_string())?;
        total = total.saturating_add(bytes);
    }
    Ok(total)
}
