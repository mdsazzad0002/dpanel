use std::{path::Path, sync::Arc};

use axum::{Router, extract::{Json, State}, response::{IntoResponse, Response}, routing::post};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};
use crate::platform::account::AccountSpec;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/ftp-account", post(handle))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    username: String,
    password: Option<String>,
    directory: Option<String>,
    site_owner: Option<String>,
}

async fn handle(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap, Json(request): Json<Request>) -> Response {
    if let Err(error) = check_token(&state, &headers) { return error.into_response(); }
    let result = match request.action.as_str() {
        "create" => create(&request),
        "password" => change_password(&request.username, request.password.as_deref().unwrap_or("")),
        "delete" => delete(&request.username),
        _ => Err("Unsupported FTP account action.".to_string()),
    };
    match result {
        Ok(message) => ApiResponse::ok(&message).into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn validate_username(username: &str) -> Result<(), String> {
    if username.len() < 5 || username.len() > 32 || !username.starts_with("ftp_")
        || !username.chars().all(|value| value.is_ascii_lowercase() || value.is_ascii_digit() || value == '_') {
        return Err("FTP username must start with ftp_ and contain only lowercase letters, numbers, and underscores.".into());
    }
    Ok(())
}

fn validate_password(password: &str) -> Result<(), String> {
    if password.len() < 12 || password.len() > 128 || password.contains(['\n', '\r', ':']) {
        return Err("FTP password must be 12-128 characters and cannot contain a colon or line break.".into());
    }
    Ok(())
}

fn create(request: &Request) -> Result<String, String> {
    validate_username(&request.username)?;
    let password = request.password.as_deref().unwrap_or("");
    validate_password(password)?;
    let owner = request.site_owner.as_deref().unwrap_or("");
    if owner.is_empty() || !owner.chars().all(|value| value.is_ascii_lowercase() || value.is_ascii_digit() || value == '_' || value == '-') {
        return Err("Invalid website owner.".into());
    }
    let accounts = crate::platform::accounts();
    if accounts.exists(&request.username).map_err(|error| error.to_string())? {
        return Err("That FTP system account already exists.".into());
    }
    if !accounts.exists(owner).map_err(|error| error.to_string())? {
        return Err("Website system user does not exist.".into());
    }
    let directory = request.directory.as_deref().unwrap_or("");
    let owner_root = crate::platform::paths().site_home(owner).display().to_string();
    if directory.is_empty() || (!directory.starts_with(&(owner_root.clone() + "/")) && directory != owner_root) || !Path::new(directory).is_dir() {
        return Err("FTP directory must exist inside the website owner's home.".into());
    }
    let group = accounts.primary_group(owner).map_err(|_| "Unable to resolve website group.".to_string())?;
    // Website files are owner:www-data with no access for others, so the FTP
    // account shares the owner's UID; with its own UID vsftpd cannot even
    // chdir into public_html, and uploads would belong to the wrong user.
    let uid = accounts.lookup(owner).map_err(|_| "Unable to resolve website user ID.".to_string())?.uid;
    accounts
        .create(&AccountSpec::sharing(&request.username, Path::new(directory), uid, &group))
        .map_err(|_| "Unable to create FTP system account.".to_string())?;
    if let Err(error) = set_password(&request.username, password) {
        let _ = accounts.delete(&request.username, true);
        return Err(error);
    }
    Ok(format!("FTP account {} created.", request.username))
}

fn change_password(username: &str, password: &str) -> Result<String, String> {
    validate_username(username)?; validate_password(password)?;
    if !crate::platform::accounts().exists(username).map_err(|error| error.to_string())? { return Err("FTP system account does not exist.".into()); }
    set_password(username, password)?;
    Ok(format!("FTP password for {username} updated."))
}

fn set_password(username: &str, password: &str) -> Result<(), String> {
    crate::platform::accounts()
        .set_password(username, password)
        .map_err(|error| format!("Unable to set FTP password: {error}"))
}

fn delete(username: &str) -> Result<String, String> {
    validate_username(username)?;
    let accounts = crate::platform::accounts();
    if !accounts.exists(username).map_err(|error| error.to_string())? { return Ok("FTP account was already absent.".into()); }
    // --force: the account shares the site owner's UID, whose PHP-FPM workers
    // would otherwise make userdel refuse. Without --remove the home stays.
    accounts.delete(username, true).map_err(|_| "Unable to delete FTP system account.".to_string())?;
    Ok(format!("FTP account {username} deleted."))
}
