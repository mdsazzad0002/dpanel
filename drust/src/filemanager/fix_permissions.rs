use std::fs;
use std::path::{Path, PathBuf};
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    app::{ensure_root, run_status, user_group, valid_username},
};

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

const WEB_GROUP: &str = "www-data";

#[derive(Deserialize)]
pub(crate) struct Request {
    username: Option<String>,
    root_path: Option<String>,
    all: Option<bool>,
}

pub fn run(
    username: Option<&str>,
    root_path: Option<&str>,
    all: bool,
) -> Result<Vec<String>, String> {
    ensure_root()?;
    ensure_web_group()?;

    let targets = if all {
        discover_public_html_targets()?
    } else {
        vec![resolve_target(username, root_path)?]
    };

    let mut fixed = Vec::new();
    for target in targets {
        fix_target(&target.username, &target.root_path)?;
        fixed.push(format!(
            "{}:{}",
            target.username,
            target.root_path.display()
        ));
    }

    Ok(fixed)
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match run(
        request.username.as_deref(),
        request.root_path.as_deref(),
        request.all.unwrap_or(false),
    ) {
        Ok(fixed) => ApiResponse::ok_data(
            "Permissions fixed",
            serde_json::json!({
                "fixed": fixed,
                "count": fixed.len(),
            }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

struct Target {
    username: String,
    root_path: PathBuf,
}

fn ensure_web_group() -> Result<(), String> {
    run_status("getent", &["group", WEB_GROUP])
}

fn resolve_target(username: Option<&str>, root_path: Option<&str>) -> Result<Target, String> {
    let username = username
        .filter(|value| !value.trim().is_empty())
        .or_else(|| root_path.and_then(infer_username_from_home_path))
        .ok_or_else(|| "username or root_path is required unless all=true".to_string())?;

    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }

    let (user_home, canonical_home, _) = validate_account(username)?;
    let path = root_path
        .map(|value| validate_user_path(username, value))
        .transpose()?
        .unwrap_or_else(|| user_home.join("public_html"));
    let canonical = ensure_canonical_inside_home(&canonical_home, &path, "Root path")?;

    Ok(Target {
        username: username.to_string(),
        root_path: canonical,
    })
}

fn infer_username_from_home_path(path: &str) -> Option<&str> {
    let mut parts = path.trim_start_matches('/').split('/');
    match (parts.next(), parts.next()) {
        (Some("home"), Some(username)) if !username.is_empty() => Some(username),
        _ => None,
    }
}

fn discover_public_html_targets() -> Result<Vec<Target>, String> {
    let mut targets = Vec::new();
    let homes = fs::read_dir("/home").map_err(|e| format!("failed to read /home: {e}"))?;

    for entry in homes {
        let entry = entry.map_err(|e| format!("failed to read /home entry: {e}"))?;
        let username = entry.file_name().to_string_lossy().to_string();
        if !valid_username(&username) || user_group(&username).is_err() {
            continue;
        }

        let public_html = entry.path().join("public_html");
        if !public_html.is_dir() {
            continue;
        }

        if let Ok(target) = resolve_target(Some(&username), public_html.to_str()) {
            targets.push(target);
        }
    }

    if targets.is_empty() {
        return Err("No /home/*/public_html projects found.".into());
    }

    Ok(targets)
}

fn fix_target(username: &str, root_path: &Path) -> Result<(), String> {
    let path = root_path.to_string_lossy();
    let owner = format!("{username}:{WEB_GROUP}");

    run_status("chown", &["-R", &owner, path.as_ref()])?;
    run_status("chmod", &["-R", "u+rwX,g+rwX,o-rwx", path.as_ref()])?;
    run_status(
        "find",
        &[
            path.as_ref(),
            "-type",
            "d",
            "-exec",
            "chmod",
            "g+s",
            "{}",
            "+",
        ],
    )?;

    if command_exists("setfacl") {
        run_status(
            "setfacl",
            &[
                "-R",
                "-m",
                &format!("u:{username}:rwx,u:{WEB_GROUP}:rwx,g:{WEB_GROUP}:rwx"),
                path.as_ref(),
            ],
        )?;
        run_status(
            "setfacl",
            &[
                "-R",
                "-d",
                "-m",
                &format!("u:{username}:rwx,u:{WEB_GROUP}:rwx,g:{WEB_GROUP}:rwx"),
                path.as_ref(),
            ],
        )?;
    }

    for relative in ["storage", "bootstrap/cache", "public"] {
        let writable = root_path.join(relative);
        if writable.is_dir() {
            let writable_path = writable.to_string_lossy();
            run_status("chgrp", &["-R", WEB_GROUP, writable_path.as_ref()])?;
            run_status(
                "find",
                &[
                    writable_path.as_ref(),
                    "-type",
                    "d",
                    "-exec",
                    "chmod",
                    "2775",
                    "{}",
                    "+",
                ],
            )?;
            run_status(
                "find",
                &[
                    writable_path.as_ref(),
                    "-type",
                    "f",
                    "-exec",
                    "chmod",
                    "ug+rw,o-rwx",
                    "{}",
                    "+",
                ],
            )?;
        }
    }

    Ok(())
}

fn command_exists(program: &str) -> bool {
    std::process::Command::new("sh")
        .arg("-c")
        .arg(format!("command -v {program} >/dev/null 2>&1"))
        .status()
        .map(|status| status.success())
        .unwrap_or(false)
}
