use std::fs;
use std::path::PathBuf;
use std::sync::Arc;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::{ensure_root, info, run_status, user_group, valid_username};
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
    let mut shell = "/bin/bash".to_string();
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
        .unwrap_or_else(|| PathBuf::from(format!("/home/{username}")));
    if let Some(home_str) = home.to_str() {
        validate_absolute_path(home_str)?;
    }
    if std::process::Command::new("id")
        .args(["-u", username])
        .status()
        .map(|status| status.success())
        .unwrap_or(false)
    {
        info(&format!("user exists: {username}"));
    } else {
        run_status(
            "useradd",
            &[
                "-m",
                "-d",
                home.to_string_lossy().as_ref(),
                "-s",
                shell,
                "-U",
                username,
            ],
        )?;
        info(&format!("user created: {username}"));
    }
    let group = user_group(username).unwrap_or_else(|_| username.to_string());
    fs::create_dir_all(&home).map_err(|e| format!("failed to create {}: {e}", home.display()))?;
    apply_directory_owner(username, &group, &home);

    let site_directory = site_directory.trim_matches('/');
    if site_directory.is_empty() || site_directory.contains("..") || site_directory.contains('/') {
        return Err("Invalid site directory.".into());
    }
    let site_path = home.join(site_directory);
    fs::create_dir_all(&site_path)
        .map_err(|e| format!("failed to create {}: {e}", site_path.display()))?;
    apply_directory_owner(username, &group, &site_path);
    info(&format!("home prepared: {}", home.display()));
    Ok(())
}

fn apply_directory_owner(username: &str, group: &str, path: &std::path::Path) {
    let _ = run_status(
        "chown",
        &[
            &format!("{username}:{group}"),
            path.to_string_lossy().as_ref(),
        ],
    );
    let _ = run_status("chmod", &["0755", path.to_string_lossy().as_ref()]);
}

#[derive(Deserialize)]
pub(crate) struct Request {
    action: String,
    username: String,
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
