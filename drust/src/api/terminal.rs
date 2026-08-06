use std::{path::Path, process::Command, sync::Arc};

use axum::{extract::{Json, State}, response::{IntoResponse, Response}, routing::post, Router};
use serde::Deserialize;

use crate::api::{check_token, ApiResponse, ApiState};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/website-terminal", post(handle))
}

#[derive(Deserialize)]
struct Request {
    site_owner: String,
    project_root: String,
    command: String,
}

async fn handle(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap, Json(request): Json<Request>) -> Response {
    if let Err(error) = check_token(&state, &headers) { return error.into_response(); }
    match execute(&request) {
        Ok((output, code)) => ApiResponse::ok_data("Command completed.", serde_json::json!({"output": output, "exit_code": code})).into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn execute(request: &Request) -> Result<(String, i32), String> {
    validate(request)?;
    let root = Path::new(&request.project_root).canonicalize().map_err(|_| "Project root is unavailable.".to_string())?;
    let root_value = root.to_string_lossy().to_string();
    let output = Command::new("timeout")
        .args(["--signal=KILL", "35s", "prlimit", "--as=1073741824", "--cpu=30", "--nproc=128", "--nofile=256", "--",
            "runuser", "-u", &request.site_owner, "--", "bwrap",
            "--unshare-all", "--die-with-parent", "--new-session", "--cap-drop", "ALL",
            "--ro-bind", "/usr", "/usr", "--symlink", "usr/bin", "/bin",
            "--symlink", "usr/lib", "/lib", "--symlink", "usr/lib64", "/lib64",
            "--proc", "/proc", "--dev", "/dev", "--tmpfs", "/tmp", "--dir", "/etc",
            "--bind", &root_value, "/workspace", "--chdir", "/workspace",
            "--clearenv", "--setenv", "HOME", "/workspace", "--setenv", "PWD", "/workspace",
            "--setenv", "PATH", "/usr/local/bin:/usr/bin:/bin", "--setenv", "TERM", "xterm-256color",
            "bash", "--noprofile", "--norc", "-lc", &request.command])
        .current_dir(&root)
        .output().map_err(|error| format!("Unable to start sandbox: {error}"))?;
    let combined = format!("{}{}", String::from_utf8_lossy(&output.stdout), String::from_utf8_lossy(&output.stderr));
    let safe = tail_chars(combined.trim(), 40_000);
    Ok((safe, output.status.code().unwrap_or(124)))
}

fn validate(request: &Request) -> Result<(), String> {
    let owner_ok = !request.site_owner.is_empty() && request.site_owner.chars().enumerate().all(|(i, c)| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || (c == '-' && i > 0));
    if !owner_ok { return Err("Invalid website owner.".into()); }
    let root = Path::new(&request.project_root);
    let canonical = root.canonicalize().map_err(|_| "Invalid project root.".to_string())?;
    let owner_home = Path::new("/home").join(&request.site_owner).canonicalize().map_err(|_| "Invalid website owner home.".to_string())?;
    if !canonical.starts_with(&owner_home) || canonical == owner_home || !canonical.is_dir() || root.components().any(|part| matches!(part, std::path::Component::ParentDir)) { return Err("Invalid project root.".into()); }
    let command = request.command.trim();
    if command.is_empty() || command.chars().count() > 2000 || command.contains('\0') || command.contains('\n') || command.contains('\r') { return Err("Enter one command of at most 2000 characters.".into()); }
    let lower = command.to_ascii_lowercase();
    let blocked = ["sudo", " su ", "systemctl", "service ", "mount", "umount", "nsenter", "unshare", "bwrap", "chroot", "apt ", "apt-get", "dnf ", "yum ", "pacman ", "docker", "podman", "reboot", "shutdown", "poweroff", "kill ", "pkill", "killall", "/proc", "/sys", "/dev", "/etc", "/var", "/root", "/home/"];
    if blocked.iter().any(|item| lower.contains(item)) { return Err("This command is blocked in the website terminal.".into()); }
    Ok(())
}

fn tail_chars(value: &str, max: usize) -> String {
    if value.chars().count() <= max { return value.to_string(); }
    value.chars().rev().take(max).collect::<String>().chars().rev().collect()
}
