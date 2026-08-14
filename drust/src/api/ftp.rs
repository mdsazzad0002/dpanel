use std::{io::Write, path::Path, process::{Command, Stdio}, sync::Arc};

use axum::{Router, extract::{Json, State}, response::{IntoResponse, Response}, routing::post};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

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
    if Command::new("id").args(["-u", &request.username]).status().map_err(|error| error.to_string())?.success() {
        return Err("That FTP system account already exists.".into());
    }
    if !Command::new("id").args(["-u", owner]).status().map_err(|error| error.to_string())?.success() {
        return Err("Website system user does not exist.".into());
    }
    let directory = request.directory.as_deref().unwrap_or("");
    let owner_root = format!("/home/{owner}");
    if directory.is_empty() || (!directory.starts_with(&(owner_root.clone() + "/")) && directory != owner_root) || !Path::new(directory).is_dir() {
        return Err("FTP directory must exist inside the website owner's home.".into());
    }
    let group_output = Command::new("id").args(["-gn", owner]).output().map_err(|error| error.to_string())?;
    if !group_output.status.success() { return Err("Unable to resolve website group.".into()); }
    let group = String::from_utf8_lossy(&group_output.stdout).trim().to_string();
    let status = Command::new("useradd").args(["--no-create-home", "--home-dir", directory, "--shell", "/usr/sbin/nologin", "--gid", &group, &request.username]).status().map_err(|error| format!("Unable to start useradd: {error}"))?;
    if !status.success() { return Err("Unable to create FTP system account.".into()); }
    if let Err(error) = set_password(&request.username, password) {
        let _ = Command::new("userdel").arg(&request.username).status();
        return Err(error);
    }
    Ok(format!("FTP account {} created.", request.username))
}

fn change_password(username: &str, password: &str) -> Result<String, String> {
    validate_username(username)?; validate_password(password)?;
    if !Command::new("id").args(["-u", username]).status().map_err(|error| error.to_string())?.success() { return Err("FTP system account does not exist.".into()); }
    set_password(username, password)?;
    Ok(format!("FTP password for {username} updated."))
}

fn set_password(username: &str, password: &str) -> Result<(), String> {
    let mut child = Command::new("chpasswd").stdin(Stdio::piped()).stdout(Stdio::null()).stderr(Stdio::piped()).spawn().map_err(|error| format!("Unable to start chpasswd: {error}"))?;
    child.stdin.as_mut().ok_or("Unable to open chpasswd input.")?.write_all(format!("{username}:{password}\n").as_bytes()).map_err(|error| error.to_string())?;
    let output = child.wait_with_output().map_err(|error| error.to_string())?;
    if !output.status.success() { return Err(format!("Unable to set FTP password: {}", String::from_utf8_lossy(&output.stderr).trim())); }
    Ok(())
}

fn delete(username: &str) -> Result<String, String> {
    validate_username(username)?;
    if !Command::new("id").args(["-u", username]).status().map_err(|error| error.to_string())?.success() { return Ok("FTP account was already absent.".into()); }
    let status = Command::new("userdel").arg(username).status().map_err(|error| format!("Unable to start userdel: {error}"))?;
    if !status.success() { return Err("Unable to delete FTP system account.".into()); }
    Ok(format!("FTP account {username} deleted."))
}
