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
    working_directory: Option<String>,
    command: String,
}

async fn handle(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap, Json(request): Json<Request>) -> Response {
    if let Err(error) = check_token(&state, &headers) { return error.into_response(); }
    match execute(&request) {
        Ok((output, code, working_directory)) => ApiResponse::ok_data("Command completed.", serde_json::json!({"output": output, "exit_code": code, "working_directory": working_directory})).into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn execute(request: &Request) -> Result<(String, i32, String), String> {
    validate(request)?;
    let root = Path::new(&request.project_root).canonicalize().map_err(|_| "Project root is unavailable.".to_string())?;
    let root_value = root.to_string_lossy().to_string();
    let working_directory = request.working_directory.as_deref().unwrap_or(&request.project_root);
    let working_directory = Path::new(working_directory).canonicalize().map_err(|_| "Working directory is unavailable.".to_string())?;
    if !working_directory.starts_with(&root) || !working_directory.is_dir() {
        return Err("Working directory must stay inside the website home.".into());
    }
    let working_directory_value = working_directory.to_string_lossy().to_string();
    let owner_home = Path::new("/home").join(&request.site_owner);
    let owner_home_value = owner_home.to_string_lossy().to_string();
    let owner_uid = account_id("-u", &request.site_owner)?;
    let owner_gid = account_id("-g", &request.site_owner)?;
    let mut command = Command::new("timeout");
    command.args(["--signal=KILL", "35s", "prlimit", "--as=1073741824", "--cpu=30", "--nproc=128", "--nofile=256", "--",
            "bwrap",
            "--unshare-user", "--unshare-pid", "--unshare-net", "--unshare-ipc", "--unshare-uts", "--unshare-cgroup-try",
            "--die-with-parent", "--new-session",
            "--uid", &owner_uid, "--gid", &owner_gid,
            "--ro-bind", "/usr", "/usr", "--symlink", "usr/bin", "/bin",
            "--symlink", "usr/lib", "/lib", "--symlink", "usr/lib64", "/lib64",
            "--proc", "/proc", "--dev", "/dev", "--tmpfs", "/tmp", "--dir", "/etc", "--dir", "/home"]);
    command.args(["--ro-bind", "/etc/passwd", "/etc/passwd", "--ro-bind", "/etc/group", "/etc/group"]);
    command.args(["--dir", &owner_home_value]);
    let relative = if root == owner_home {
        Path::new("")
    } else {
        root.strip_prefix(&owner_home).map_err(|_| "Invalid project root.".to_string())?
    };
    let mut sandbox_parent = owner_home.clone();
    let relative_parts = relative.components().collect::<Vec<_>>();
    for part in relative_parts.iter().take(relative_parts.len().saturating_sub(1)) {
        sandbox_parent.push(part.as_os_str());
        command.args(["--dir", sandbox_parent.to_string_lossy().as_ref()]);
    }
    let tracked_command = format!("{}; __dpanel_status=$?; printf '\\n__DPANEL_CWD__%s\\n' \"$PWD\"; exit $__dpanel_status", request.command);
    command.args(["--bind", &root_value, &root_value, "--chdir", &working_directory_value,
            "--clearenv", "--setenv", "HOME", &owner_home_value, "--setenv", "PWD", &working_directory_value,
            "--setenv", "PATH", "/usr/local/bin:/usr/bin:/bin", "--setenv", "TERM", "xterm-256color",
            "bash", "--noprofile", "--norc", "-lc", &tracked_command]);
    let output = command
        .current_dir(&root)
        .output().map_err(|error| format!("Unable to start sandbox: {error}"))?;
    let stdout = String::from_utf8_lossy(&output.stdout);
    let marker = "__DPANEL_CWD__";
    let next_directory = stdout.lines().rev().find_map(|line| line.strip_prefix(marker)).unwrap_or(&working_directory_value).to_string();
    let clean_stdout = stdout.lines().filter(|line| !line.starts_with(marker)).collect::<Vec<_>>().join("\n");
    let combined = format!("{}{}", clean_stdout, String::from_utf8_lossy(&output.stderr));
    let safe = tail_chars(combined.trim(), 40_000);
    Ok((safe, output.status.code().unwrap_or(124), next_directory))
}

fn account_id(flag: &str, username: &str) -> Result<String, String> {
    let output = Command::new("id")
        .args([flag, username])
        .output()
        .map_err(|error| format!("Unable to resolve website owner: {error}"))?;
    let value = String::from_utf8_lossy(&output.stdout).trim().to_string();
    if !output.status.success() || value.is_empty() || !value.chars().all(|character| character.is_ascii_digit()) {
        return Err("Unable to resolve website owner.".into());
    }
    Ok(value)
}

fn validate(request: &Request) -> Result<(), String> {
    let owner_ok = !request.site_owner.is_empty() && request.site_owner.chars().enumerate().all(|(i, c)| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || (c == '-' && i > 0));
    if !owner_ok { return Err("Invalid website owner.".into()); }
    let root = Path::new(&request.project_root);
    let canonical = root.canonicalize().map_err(|_| "Invalid project root.".to_string())?;
    let owner_home = Path::new("/home").join(&request.site_owner).canonicalize().map_err(|_| "Invalid website owner home.".to_string())?;
    let inside_owner_home = canonical == owner_home || canonical.starts_with(&owner_home);
    if !inside_owner_home || !canonical.is_dir() || root.components().any(|part| matches!(part, std::path::Component::ParentDir)) { return Err("Invalid project root.".into()); }
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
