use std::{fs, net::SocketAddr, path::{Path, PathBuf}, process::Command, time::Duration};
use tokio::{net::TcpStream, time::timeout};

fn valid_site_id(site: &str) -> bool { !site.is_empty() && site.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '_'|'-')) }
fn unit(site: &str) -> String { format!("dpanel-rust-{site}") }

pub async fn control_rust(site: &str, action: &str, owner: Option<&str>, root: Option<&str>, command: Option<&str>, port: u16) -> Result<(), String> {
    if !valid_site_id(site) { return Err("invalid Rust app id".into()); }
    let name = unit(site);
    match action {
        "stop" => systemctl(&["disable", "--now", &name]),
        "remove" => {
            let _ = systemctl(&["disable", "--now", &name]);
            let path = format!("/etc/systemd/system/{name}.service");
            if !Path::new(&path).is_file() { return Ok(()); }
            fs::remove_file(path).map_err(|e| format!("cannot remove Rust unit: {e}"))?;
            let _ = systemctl(&["reset-failed", &name]); systemctl(&["daemon-reload"])
        }
        "restart" | "start" => {
            let owner = owner.ok_or("site_owner is required")?.trim();
            if owner.is_empty() || !owner.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '_'|'-')) { return Err("invalid site owner".into()); }
            let root = PathBuf::from(root.ok_or("project_root is required")?).canonicalize().map_err(|e| format!("project directory is unavailable: {e}"))?;
            if !root.is_dir() || !root.starts_with(format!("/home/{owner}")) { return Err("Rust project must be inside the owner's home directory".into()); }
            let command = command.map(str::trim).filter(|s| !s.is_empty()).ok_or("Rust binary path is required (for example: ./target/release/my-api)")?;
            if command.chars().any(|c| !(c.is_ascii_alphanumeric() || matches!(c, '/' | '.' | '_' | '-' | '+'))) { return Err("enter a binary path without arguments; build arguments are not supported".into()); }
            let binary = if Path::new(command).is_absolute() { PathBuf::from(command) } else { root.join(command) };
            let binary = binary.canonicalize().map_err(|e| format!("Rust binary is unavailable: {e}"))?;
            if !binary.starts_with(&root) || !binary.is_file() { return Err("Rust binary must be a file inside the project directory".into()); }
            #[cfg(unix)] {
                use std::os::unix::fs::PermissionsExt;
                if fs::metadata(&binary).map_err(|e| e.to_string())?.permissions().mode() & 0o111 == 0 { return Err("Rust binary is not executable".into()); }
            }
            let id = Command::new("id").args(["-u", owner]).status().map_err(|e| e.to_string())?;
            if !id.success() { return Err("site owner does not exist".into()); }
            let content = format!("[Unit]\nDescription=dPanel Rust app {site}\nAfter=network.target\n[Service]\nType=simple\nUser={owner}\nGroup={owner}\nWorkingDirectory={}\nEnvironment=PORT={port}\nEnvironment=HOST=127.0.0.1\nExecStart={}\nRestart=always\nRestartSec=3\nStandardOutput=journal\nStandardError=journal\n[Install]\nWantedBy=multi-user.target\n", root.display(), binary.display());
            let path = format!("/etc/systemd/system/{name}.service");
            if fs::read_to_string(&path).ok().as_deref() != Some(content.as_str()) {
                fs::write(&path, content).map_err(|e| format!("cannot write systemd unit: {e}"))?;
                systemctl(&["daemon-reload"])?;
            }
            if action == "restart" { systemctl(&["restart", &name])?; } else { systemctl(&["enable", "--now", &name])?; }
            for _ in 0..120 { if listening(port).await { return Ok(()); } tokio::time::sleep(Duration::from_millis(250)).await; }
            Err(format!("Rust app did not listen on port {port}"))
        }
        _ => Err(format!("unknown Rust process action: {action}")),
    }
}

pub async fn rust_status(site: &str, port: u16) -> (bool, String, bool) {
    if !valid_site_id(site) { return (false, "invalid-id".into(), false); }
    let name = unit(site);
    let exists = Path::new(&format!("/etc/systemd/system/{name}.service")).is_file();
    let active = Command::new("systemctl").args(["is-active", &name]).output().ok().map(|o| String::from_utf8_lossy(&o.stdout).trim().to_string()).unwrap_or_else(|| "unknown".into());
    (exists, active, listening(port).await)
}

async fn listening(port: u16) -> bool {
    timeout(Duration::from_millis(300), TcpStream::connect(SocketAddr::from(([127,0,0,1], port)))).await.is_ok_and(|r| r.is_ok())
}
fn systemctl(args: &[&str]) -> Result<(), String> {
    let out = Command::new("systemctl").args(args).output().map_err(|e| e.to_string())?;
    if out.status.success() { Ok(()) } else { Err(String::from_utf8_lossy(&out.stderr).trim().to_string()) }
}
