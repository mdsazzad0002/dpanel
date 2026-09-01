use std::{
    fs,
    net::SocketAddr,
    path::{Path, PathBuf},
    process::Stdio,
    sync::{Mutex, OnceLock},
    time::Duration,
};

use tokio::{net::TcpStream, time::timeout};

/// Ensures the systemd-managed process for a Node.js site is running and
/// listening on `port`, provisioning (or repairing) its unit file on demand.
/// Mirrors `php::resolve_fpm_socket`'s lazy-provision-then-poll pattern.
pub async fn ensure_node_process_running(
    site_id: &str,
    owner: &str,
    project_root: &Path,
    entry_file: Option<&str>,
    start_command: Option<&str>,
    node_version: Option<&str>,
    port: u16,
) -> Result<(), String> {
    if port_is_listening(port).await {
        return Ok(());
    }

    let owner = validate_system_user(owner)?;
    let unit_name = unit_name(site_id);
    let site_id = site_id.to_string();
    let project_root = project_root.to_path_buf();
    let entry_file = entry_file.map(str::to_string);
    let start_command = start_command.map(str::to_string);
    let node_version = node_version.map(str::to_string);

    tokio::task::spawn_blocking(move || {
        ensure_unit_provisioned(
            &site_id,
            &owner,
            &project_root,
            entry_file.as_deref(),
            start_command.as_deref(),
            node_version.as_deref(),
            port,
        )
    })
    .await
    .map_err(|error| format!("node process provision worker failed: {error}"))??;

    for _ in 0..60 {
        if port_is_listening(port).await {
            return Ok(());
        }
        tokio::time::sleep(Duration::from_millis(250)).await;
    }
    Err(format!(
        "node process for site {unit_name} did not start listening on port {port}"
    ))
}

async fn port_is_listening(port: u16) -> bool {
    let addr = SocketAddr::from(([127, 0, 0, 1], port));
    timeout(Duration::from_millis(300), TcpStream::connect(addr))
        .await
        .is_ok_and(|result| result.is_ok())
}

pub fn unit_name(site_id: &str) -> String {
    format!("dpanel-node-{site_id}")
}

/// Stops a site's process without deleting its systemd unit. The gateway
/// will not bring it back up on the next request as long as the caller
/// also marks the site's `node_process_status` as stopped, since only that
/// flag (not this call) is what keeps the route out of `RouteAction::Proxy`.
pub fn stop_node_process(site_id: &str) -> Result<(), String> {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return Ok(());
    }
    run_systemctl(&["stop", &unit])
}

pub fn restart_node_process(site_id: &str) -> Result<(), String> {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return Err(format!("node process {unit} has not been provisioned yet"));
    }
    run_systemctl(&["restart", &unit])
}

pub struct NodeProcessStatus {
    pub unit_exists: bool,
    pub active_state: String,
    pub listening: bool,
}

pub async fn node_process_status(site_id: &str, port: u16) -> NodeProcessStatus {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return NodeProcessStatus {
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
    NodeProcessStatus {
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
    entry_file: Option<&str>,
    start_command: Option<&str>,
    node_version: Option<&str>,
    port: u16,
) -> Result<(), String> {
    static PROVISION_LOCK: OnceLock<Mutex<()>> = OnceLock::new();
    let _guard = PROVISION_LOCK
        .get_or_init(|| Mutex::new(()))
        .lock()
        .map_err(|_| "node process provision lock is poisoned".to_string())?;

    if !project_root.is_dir() {
        return Err(format!(
            "project root is unavailable: {}",
            project_root.display()
        ));
    }

    let node_binary = resolve_node_binary(node_version)?;
    let exec_start = match start_command {
        Some(command) if !command.trim().is_empty() => {
            format!("/bin/bash -lc {}", shell_quote(command.trim()))
        }
        _ => {
            let entry = entry_file.unwrap_or("server.js").trim();
            if entry.is_empty() {
                return Err("node entry file is not configured".to_string());
            }
            format!(
                "{} {}",
                node_binary.display(),
                shell_quote(&project_root.join(entry).to_string_lossy())
            )
        }
    };

    let unit_name = unit_name(site_id);
    let unit_path = PathBuf::from(format!("/etc/systemd/system/{unit_name}.service"));
    let content = format!(
        "; Managed dynamically by drust edge gateway. Do not edit by hand.\n\
         [Unit]\n\
         Description=dPanel Node.js site {site_id}\n\
         After=network.target\n\
         \n\
         [Service]\n\
         Type=simple\n\
         User={owner}\n\
         Group={owner}\n\
         WorkingDirectory={workdir}\n\
         Environment=PORT={port}\n\
         Environment=NODE_ENV=production\n\
         Environment=HOST=127.0.0.1\n\
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

fn resolve_node_binary(version: Option<&str>) -> Result<PathBuf, String> {
    if let Some(version) = version {
        let versioned = PathBuf::from(format!("/usr/local/n/versions/node/{version}/bin/node"));
        if versioned.is_file() {
            return Ok(versioned);
        }
        let nvm_style = PathBuf::from(format!(
            "/usr/local/nvm/versions/node/v{version}/bin/node"
        ));
        if nvm_style.is_file() {
            return Ok(nvm_style);
        }
    }
    for candidate in ["/usr/bin/node", "/usr/local/bin/node"] {
        let path = PathBuf::from(candidate);
        if path.is_file() {
            return Ok(path);
        }
    }
    Err("no node binary found on this server; install Node.js or configure DRUST_NODE_BIN".to_string())
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
        assert_eq!(shell_quote("npm run start"), "'npm run start'");
        assert_eq!(shell_quote("it's"), "'it'\\''s'");
    }

    #[test]
    fn rejects_unsafe_site_owner_names() {
        assert!(validate_system_user("../root").is_err());
        assert!(validate_system_user("bad name").is_err());
    }
}
