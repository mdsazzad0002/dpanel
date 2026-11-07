use std::fs;
use std::path::PathBuf;
use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::{ensure_root, info, valid_username};
use crate::platform::{account::AccountSpec, fs::FileMode};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::validate_absolute_path;

pub fn run(args: Vec<String>) -> Result<(), String> {
    if args.len() < 2 {
        return Err("Missing username argument.".into());
    }
    let action = args[0].clone();
    let username = args[1].clone();
    let mut home: Option<String> = None;
    let mut password: Option<String> = None;
    let mut shell = "/usr/sbin/nologin".to_string();
    let mut site_directory = "public_html".to_string();
    let mut iter = args.into_iter().skip(2);
    while let Some(arg) = iter.next() {
        match arg.as_str() {
            "--home" => {
                home = Some(
                    iter.next()
                        .ok_or_else(|| "Missing value for --home".to_string())?,
                );
            }
            "--shell" => {
                shell = iter
                    .next()
                    .ok_or_else(|| "Missing value for --shell".to_string())?;
            }
            "--password" => {
                password = Some(
                    iter.next()
                        .ok_or_else(|| "Missing value for --password".to_string())?,
                );
            }
            "--site-directory" => {
                site_directory = iter
                    .next()
                    .ok_or_else(|| "Missing value for --site-directory".to_string())?;
            }
            other => return Err(format!("Unsupported user option: {other}")),
        }
    }
    match action.as_str() {
        "create" | "ensure" => create(&username, home.as_deref(), &shell, &site_directory),
        "password" | "set-password" => {
            let password = password.ok_or_else(|| "Missing password.".to_string())?;
            set_password(&username, &password)
        }
        _ => Err(format!("Unsupported filemanager user action: {action}")),
    }
}

fn create(
    username: &str,
    home: Option<&str>,
    shell: &str,
    site_directory: &str,
) -> Result<(), String> {
    ensure_root()?;
    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }
    let home = home
        .map(PathBuf::from)
        .unwrap_or_else(|| crate::platform::paths().site_home(username));
    if let Some(home_str) = home.to_str() {
        validate_absolute_path(home_str)?;
    }
    let accounts = crate::platform::accounts();
    if accounts.exists(username).unwrap_or(false) {
        info(&format!("user exists: {username}"));
    } else {
        accounts
            .create(&AccountSpec::site(username, &home, shell))
            .map_err(|error| error.to_string())?;
        info(&format!("user created: {username}"));
    }
    fs::create_dir_all(&home).map_err(|e| format!("failed to create {}: {e}", home.display()))?;
    apply_directory_owner(username, &home, FileMode::PrivateDir)?;

    let site_directory = site_directory.trim_matches('/');
    if site_directory.is_empty() || site_directory.contains("..") || site_directory.contains('/') {
        return Err("Invalid site directory.".into());
    }
    let site_path = home.join(site_directory);
    fs::create_dir_all(&site_path)
        .map_err(|e| format!("failed to create {}: {e}", site_path.display()))?;
    apply_directory_owner(username, &site_path, FileMode::SiteDir)?;
    info(&format!("home prepared: {}", home.display()));
    Ok(())
}

fn apply_directory_owner(
    username: &str,
    path: &std::path::Path,
    mode: FileMode,
) -> Result<(), String> {
    let owner = crate::platform::accounts()
        .lookup(username)
        .map_err(|error| error.to_string())?;
    let files = crate::platform::files();
    files.set_owner(path, &owner).map_err(|error| error.to_string())?;
    files.set_mode(path, mode).map_err(|error| error.to_string())
}

fn set_password(username: &str, password: &str) -> Result<(), String> {
    ensure_root()?;
    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }
    if password.is_empty() {
        return Err("Password cannot be empty.".into());
    }
    let accounts = crate::platform::accounts();
    if !accounts.exists(username).unwrap_or(false) {
        return Err(format!("User does not exist: {username}"));
    }
    accounts
        .set_password(username, password)
        .map_err(|_| "Password configuration failed.".to_string())?;

    info(&format!("password configured for {username}"));
    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct Request {
    action: String,
    username: String,
    password: Option<String>,
    home: Option<String>,
    shell: Option<String>,
    site_directory: Option<String>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let mut args = vec![request.action, request.username];
    if let Some(password) = request.password {
        args.extend(["--password".into(), password]);
    }
    if let Some(home) = request.home {
        args.extend(["--home".into(), home]);
    }
    if let Some(shell) = request.shell {
        args.extend(["--shell".into(), shell]);
    }
    if let Some(directory) = request.site_directory {
        args.extend(["--site-directory".into(), directory]);
    }
    operation_response(run(args), "User operation completed")
}
