use std::{
    fs,
    net::SocketAddr,
    path::{Path, PathBuf},
    process::Stdio,
    sync::{Mutex, OnceLock},
    time::Duration,
};

use tokio::{net::TcpStream, time::timeout};

/// Ensures the systemd-managed gunicorn process for a Python site is running
/// and listening on `port`, provisioning (or repairing) its virtualenv and
/// unit file on demand. Mirrors `node_process::ensure_node_process_running`.
pub async fn ensure_python_process_running(
    site_id: &str,
    owner: &str,
    project_root: &Path,
    entry_module: Option<&str>,
    start_command: Option<&str>,
    python_version: Option<&str>,
    port: u16,
) -> Result<(), String> {
    if port_is_listening(port).await {
        return Ok(());
    }

    let owner = validate_system_user(owner)?;
    let unit_name = unit_name(site_id);
    let site_id = site_id.to_string();
    let project_root = project_root.to_path_buf();
    let entry_module = entry_module.map(str::to_string);
    let start_command = start_command.map(str::to_string);
    let python_version = python_version.map(str::to_string);

    tokio::task::spawn_blocking(move || {
        ensure_unit_provisioned(
            &site_id,
            &owner,
            &project_root,
            entry_module.as_deref(),
            start_command.as_deref(),
            python_version.as_deref(),
            port,
        )
    })
    .await
    .map_err(|error| format!("python process provision worker failed: {error}"))??;

    for _ in 0..60 {
        if port_is_listening(port).await {
            return Ok(());
        }
        tokio::time::sleep(Duration::from_millis(250)).await;
    }
    Err(format!(
        "python process for site {unit_name} did not start listening on port {port}"
    ))
}

async fn port_is_listening(port: u16) -> bool {
    let addr = SocketAddr::from(([127, 0, 0, 1], port));
    timeout(Duration::from_millis(300), TcpStream::connect(addr))
        .await
        .is_ok_and(|result| result.is_ok())
}

pub fn unit_name(site_id: &str) -> String {
    format!("dpanel-python-{site_id}")
}

/// Stops a site's process without deleting its systemd unit. The gateway
/// will not bring it back up on the next request as long as the caller
/// also marks the site's `python_process_status` as stopped, since only that
/// flag (not this call) is what keeps the route out of `RouteAction::Proxy`.
pub fn stop_python_process(site_id: &str) -> Result<(), String> {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return Ok(());
    }
    run_systemctl(&["stop", &unit])
}

pub fn restart_python_process(site_id: &str) -> Result<(), String> {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return Err(format!("python process {unit} has not been provisioned yet"));
    }
    run_systemctl(&["restart", &unit])
}

pub struct PythonProcessStatus {
    pub unit_exists: bool,
    pub active_state: String,
    pub listening: bool,
}

pub async fn python_process_status(site_id: &str, port: u16) -> PythonProcessStatus {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return PythonProcessStatus {
            unit_exists: false,
            active_state: "not-provisioned".to_string(),
            listening: false,
        };
    }
    let active_state = std::process::Command::new("systemctl")
        .args(["is-active", &unit])
        .output()
        .map(|output| String::from_utf8_lossy(&output.stdout).trim().to_string())
        .unwrap_or_else(|_| "unknown".to_string());
    PythonProcessStatus {
        unit_exists: true,
        active_state,
        listening: port_is_listening(port).await,
    }
}

fn unit_file_exists(unit: &str) -> bool {
    Path::new(&format!("/etc/systemd/system/{unit}.service")).is_file()
}

fn ensure_unit_provisioned(
    site_id: &str,
    owner: &str,
    project_root: &Path,
    entry_module: Option<&str>,
    start_command: Option<&str>,
    python_version: Option<&str>,
    port: u16,
) -> Result<(), String> {
    static PROVISION_LOCK: OnceLock<Mutex<()>> = OnceLock::new();
    let _guard = PROVISION_LOCK
        .get_or_init(|| Mutex::new(()))
        .lock()
        .map_err(|_| "python process provision lock is poisoned".to_string())?;

    if !project_root.is_dir() {
        return Err(format!(
            "project root is unavailable: {}",
            project_root.display()
        ));
    }

    let python_binary = resolve_python_binary(python_version)?;
    let venv_path = project_root.join(".venv");
    ensure_virtualenv(&python_binary, &venv_path)?;
    install_dependencies(&venv_path, project_root)?;

    let gunicorn_bin = venv_path.join("bin").join("gunicorn");
    let exec_start = match start_command {
        Some(command) if !command.trim().is_empty() => {
            format!("/bin/bash -lc {}", shell_quote(command.trim()))
        }
        _ => {
            let module = entry_module
                .map(str::trim)
                .filter(|value| !value.is_empty())
                .ok_or_else(|| "python entry module (WSGI app path) is not configured".to_string())?;
            format!(
                "{} {} --bind 127.0.0.1:{port}",
                gunicorn_bin.display(),
                shell_quote(module)
            )
        }
    };

    let unit_name = unit_name(site_id);
    let unit_path = PathBuf::from(format!("/etc/systemd/system/{unit_name}.service"));
    let content = format!(
        "; Managed dynamically by drust edge gateway. Do not edit by hand.\n\
         [Unit]\n\
         Description=dPanel Python site {site_id}\n\
         After=network.target\n\
         \n\
         [Service]\n\
         Type=simple\n\
         User={owner}\n\
         Group={owner}\n\
         WorkingDirectory={workdir}\n\
         Environment=PORT={port}\n\
         Environment=PYTHONUNBUFFERED=1\n\
         ExecStart={exec_start}\n\
         Restart=always\n\
         RestartSec=3\n\
         StandardOutput=journal\n\
         StandardError=journal\n\
         \n\
         [Install]\n\
         WantedBy=multi-user.target\n",
        workdir = project_root.display(),
    );

    let existing = fs::read_to_string(&unit_path).ok();
    let needs_write = existing.as_deref() != Some(content.as_str());
    if needs_write {
        fs::write(&unit_path, &content)
            .map_err(|error| format!("cannot write systemd unit {}: {error}", unit_path.display()))?;
        run_systemctl(&["daemon-reload"])?;
    }

    run_systemctl(&["enable", "--now", &unit_name])
}

/// Creates the site's virtualenv under `{project_root}/.venv` if it doesn't
/// already exist. Idempotent: re-provisioning a redeployed site is a no-op
/// here unless the venv was removed.
fn ensure_virtualenv(python_binary: &Path, venv_path: &Path) -> Result<(), String> {
    if venv_path.join("bin").join("python").is_file() {
        return Ok(());
    }

    let output = std::process::Command::new(python_binary)
        .args(["-m", "venv"])
        .arg(venv_path)
        .output()
        .map_err(|error| format!("cannot create virtualenv at {}: {error}", venv_path.display()))?;
    if !output.status.success() {
        return Err(format!(
            "virtualenv creation failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    Ok(())
}

/// Installs `requirements.txt` (if present) plus gunicorn into the site's
/// virtualenv. Unlike Node's `npm install`, this runs automatically as part
/// of provisioning since there is no separate "install dependencies" step
/// exposed for Python sites.
fn install_dependencies(venv_path: &Path, project_root: &Path) -> Result<(), String> {
    let pip_bin = venv_path.join("bin").join("pip");
    let requirements = project_root.join("requirements.txt");

    if requirements.is_file() {
        let output = std::process::Command::new(&pip_bin)
            .args(["install", "-r"])
            .arg(&requirements)
            .output()
            .map_err(|error| format!("cannot run pip install: {error}"))?;
        if !output.status.success() {
            return Err(format!(
                "pip install -r requirements.txt failed: {}",
                String::from_utf8_lossy(&output.stderr).trim()
            ));
        }
    }

    if !venv_path.join("bin").join("gunicorn").is_file() {
        let output = std::process::Command::new(&pip_bin)
            .args(["install", "gunicorn"])
            .output()
            .map_err(|error| format!("cannot install gunicorn: {error}"))?;
        if !output.status.success() {
            return Err(format!(
                "pip install gunicorn failed: {}",
                String::from_utf8_lossy(&output.stderr).trim()
            ));
        }
    }

    Ok(())
}

fn resolve_python_binary(version: Option<&str>) -> Result<PathBuf, String> {
    if let Some(version) = version {
        let pyenv_style = PathBuf::from(format!(
            "/usr/local/pyenv/versions/{version}/bin/python3"
        ));
        if pyenv_style.is_file() {
            return Ok(pyenv_style);
        }
        let minor = version.trim();
        let system_versioned = PathBuf::from(format!("/usr/bin/python{minor}"));
        if system_versioned.is_file() {
            return Ok(system_versioned);
        }
    }
    for candidate in ["/usr/bin/python3", "/usr/local/bin/python3"] {
        let path = PathBuf::from(candidate);
        if path.is_file() {
            return Ok(path);
        }
    }
    Err("no python binary found on this server; install Python or configure DRUST_PYTHON_BIN".to_string())
}

fn shell_quote(value: &str) -> String {
    format!("'{}'", value.replace('\'', "'\\''"))
}

fn validate_system_user(owner: &str) -> Result<String, String> {
    let owner = owner.trim().to_ascii_lowercase();
    if owner.is_empty()
        || !owner
            .chars()
            .all(|character| character.is_ascii_alphanumeric() || matches!(character, '_' | '-'))
    {
        return Err(format!("invalid site owner: {owner}"));
    }
    let status = std::process::Command::new("id")
        .args(["-u", &owner])
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .map_err(|error| format!("cannot validate site owner {owner}: {error}"))?;
    if status.success() {
        Ok(owner)
    } else {
        Err(format!("site owner does not exist: {owner}"))
    }
}

fn run_systemctl(args: &[&str]) -> Result<(), String> {
    let output = std::process::Command::new("systemctl")
        .args(args)
        .output()
        .map_err(|error| format!("cannot run systemctl {args:?}: {error}"))?;
    if output.status.success() {
        Ok(())
    } else {
        Err(format!(
            "systemctl {args:?} failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quotes_start_commands_safely() {
        assert_eq!(shell_quote("gunicorn app:app"), "'gunicorn app:app'");
        assert_eq!(shell_quote("it's"), "'it'\\''s'");
    }

    #[test]
    fn rejects_unsafe_site_owner_names() {
        assert!(validate_system_user("../root").is_err());
        assert!(validate_system_user("bad name").is_err());
    }
}
