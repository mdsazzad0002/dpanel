use std::{
    fs::{self, OpenOptions},
    io::Write,
    path::PathBuf,
    process::Command,
    sync::Arc,
};

use axum::{
    Router,
    extract::{Json, State},
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    app,
    platform::{fs::FileMode, paths::Tool},
};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/ssh-key/generate", post(generate))
}

#[derive(Deserialize)]
struct Request {
    site_owner: String,
    comment: Option<String>,
}

struct TemporaryDirectory(PathBuf);
impl Drop for TemporaryDirectory {
    fn drop(&mut self) {
        let _ = fs::remove_dir_all(&self.0);
    }
}

async fn generate(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match create_and_install(&request) {
        Ok((private_key, public_key, fingerprint)) => ApiResponse::ok_data(
            "SSH key generated and installed.",
            serde_json::json!({
                "private_key": private_key,
                "public_key": public_key,
                "fingerprint": fingerprint,
            }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn create_and_install(request: &Request) -> Result<(String, String, String), String> {
    validate_owner(&request.site_owner)?;
    let comment = request.comment.as_deref().unwrap_or("dpanel-deploy").trim();
    if comment.is_empty() || comment.len() > 120 || comment.contains(['\r', '\n']) {
        return Err("Invalid SSH key comment.".into());
    }

    let suffix = app::random_hex(12)
        .map_err(|error| format!("Unable to create temporary key path: {error}"))?;
    let directory = crate::platform::paths().temp_dir().join(format!("dpanel-ssh-key-{suffix}"));
    fs::create_dir(&directory)
        .map_err(|error| format!("Unable to create temporary key directory: {error}"))?;
    let _temporary = TemporaryDirectory(directory.clone());
    let key_path = directory.join("deploy_key");

    let output = Command::new(ssh_keygen())
        .args(["-q", "-t", "ed25519", "-N", "", "-C", comment, "-f"])
        .arg(&key_path)
        .output()
        .map_err(|error| format!("Unable to start ssh-keygen: {error}"))?;
    if !output.status.success() {
        return Err(format!(
            "ssh-keygen failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }

    let private_key = fs::read_to_string(&key_path)
        .map_err(|error| format!("Unable to read private key: {error}"))?;
    let public_key = fs::read_to_string(key_path.with_extension("pub"))
        .map_err(|error| format!("Unable to read public key: {error}"))?;
    install_public_key(&request.site_owner, public_key.trim())?;

    let fingerprint_output = Command::new(ssh_keygen())
        .args(["-lf"])
        .arg(key_path.with_extension("pub"))
        .output()
        .map_err(|error| format!("Unable to read key fingerprint: {error}"))?;
    let fingerprint = String::from_utf8_lossy(&fingerprint_output.stdout)
        .trim()
        .to_string();
    Ok((private_key, public_key.trim().to_string(), fingerprint))
}

fn ssh_keygen() -> PathBuf {
    crate::platform::paths().binary_or_name(Tool::SshKeygen)
}

fn validate_owner(owner: &str) -> Result<(), String> {
    let valid = owner.chars().enumerate().all(|(index, value)| {
        value.is_ascii_lowercase()
            || value.is_ascii_digit()
            || value == '_'
            || (value == '-' && index > 0)
    });
    if owner.is_empty() || !valid {
        return Err("Invalid website owner.".into());
    }
    if !crate::platform::accounts().exists(owner).map_err(|error| error.to_string())? {
        return Err("Website system user does not exist.".into());
    }
    Ok(())
}

fn install_public_key(owner: &str, public_key: &str) -> Result<(), String> {
    let home = crate::platform::paths().site_home(owner);
    if !home.is_dir() {
        return Err("Website user home directory does not exist.".into());
    }
    let ssh_dir = home.join(".ssh");
    let authorized_keys = ssh_dir.join("authorized_keys");
    fs::create_dir_all(&ssh_dir)
        .map_err(|error| format!("Unable to create .ssh directory: {error}"))?;
    let existing = fs::read_to_string(&authorized_keys).unwrap_or_default();
    if !existing.lines().any(|line| line.trim() == public_key) {
        let mut file = OpenOptions::new()
            .create(true)
            .append(true)
            .open(&authorized_keys)
            .map_err(|error| format!("Unable to open authorized_keys: {error}"))?;
        writeln!(file, "{public_key}")
            .map_err(|error| format!("Unable to install public key: {error}"))?;
    }
    let files = crate::platform::files();
    if !crate::platform::current().one_user() {
        let owner = crate::platform::accounts()
            .lookup(owner)
            .map_err(|error| error.to_string())?;
        files
            .set_owner(&ssh_dir, &owner)
            .and_then(|_| files.set_owner(&authorized_keys, &owner))
            .map_err(|error| format!("Unable to apply SSH permissions: {error}"))?;
    }
    files
        .set_mode(&ssh_dir, FileMode::PrivateDir)
        .and_then(|_| files.set_mode(&authorized_keys, FileMode::Private))
        .map_err(|error| format!("Unable to apply SSH permissions: {error}"))?;
    Ok(())
}
