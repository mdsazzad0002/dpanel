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
        .unwrap_or_else(|| user_home.clone());
    if path != user_home {
        return Err(format!(
            "Permission repair must start at the account home: {}",
            user_home.display()
        ));
    }
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

        if let Ok(target) = resolve_target(Some(&username), entry.path().to_str()) {
            targets.push(target);
        }
    }

    if targets.is_empty() {
        return Err("No website roots found under /home.".into());
    }

    Ok(targets)
}

fn fix_target(username: &str, root_path: &Path) -> Result<(), String> {
    let path = root_path.to_string_lossy();
    let owner = format!("{username}:www-data");

    run_status("chown", &["-R", &owner, path.as_ref()])?;
    if command_exists("setfacl") {
        run_status("setfacl", &["-R", "-b", path.as_ref()])?;
        run_status("setfacl", &["-R", "-k", path.as_ref()])?;
    }
    run_status("chmod", &["-R", "u+rwX,g+rX,g-w,o-rwx", path.as_ref()])?;
    run_status("chmod", &["0750", path.as_ref()])?;

    // root_path here is always the account home (see resolve_target). The
    // terminal's bwrap sandbox drops CAP_DAC_OVERRIDE before it can create
    // e.g. <home>/.ssh, so it needs "other" execute on the home dir itself
    // to traverse into it. Re-assert that after the recursive chmod above.
    run_status("chmod", &["711", path.as_ref()])?;

    // The home directory is root:root 711 (traverse-only), so the sandboxed
    // site-owner process has no write access to it and can't mkdir .ssh
    // itself ("bwrap: Can't mkdir <home>/.ssh: Permission denied"). Pre-create
    // it here, owned by the site user, so it already exists before any
    // terminal session runs.
    let ssh_path = root_path.join(".ssh");
    let ssh_path_str = ssh_path.to_string_lossy();
    run_status(
        "install",
        &["-d", "-m", "0700", "-o", username, "-g", username, ssh_path_str.as_ref()],
    )?;

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
