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
            let id = Command::new("id").args(["-u", owner]).output().map_err(|e| e.to_string())?;
            if !id.status.success() { return Err("site owner does not exist".into()); }
            let uid: u32 = String::from_utf8_lossy(&id.stdout).trim().parse().map_err(|_| "cannot read the site owner's uid")?;
            // Empty command or plain arguments ("serve") run the Cargo binary;
            // a first word containing a slash is an explicit binary path.
            let mut parts = command.unwrap_or("").split_whitespace().peekable();
            let explicit = parts.next_if(|word| word.contains('/'));
            let args = parts.collect::<Vec<_>>();
            if explicit.is_some_and(|word| word.chars().any(|c| !(c.is_ascii_alphanumeric() || matches!(c, '/' | '.' | '_' | '-' | '+')))) { return Err("binary path contains unsupported characters".into()); }
            if args.iter().any(|arg| arg.chars().any(|c| !(c.is_ascii_alphanumeric() || matches!(c, '/' | '.' | '_' | '-' | '+' | ':' | '=' | ',')))) { return Err("use plain command arguments without shell syntax or quoting".into()); }
            let cargo_project = root.join("Cargo.toml").is_file();
            let target_dir = cargo_target_dir(&root, uid);
            if cargo_project {
                let (owner, root, target_dir) = (owner.to_string(), root.clone(), target_dir.clone());
                tokio::task::spawn_blocking(move || cargo_build(&owner, &root, &target_dir)).await.map_err(|e| e.to_string())??;
            }
            let binary = match explicit {
                Some(word) if Path::new(word).is_absolute() => PathBuf::from(word),
                Some(word) => root.join(word),
                None if cargo_project => target_dir.join("release").join(cargo_binary_name(owner, &root)?),
                None => return Err("no Cargo.toml in the project folder: enter the binary path (for example: ./my-api)".into()),
            };
            let binary = binary.canonicalize().map_err(|e| format!("Rust binary {} is unavailable: {e}", binary.display()))?;
            if !binary.starts_with(&root) || !binary.is_file() { return Err("Rust binary must be a file inside the project directory".into()); }
            #[cfg(unix)] {
                use std::os::unix::fs::PermissionsExt;
                if fs::metadata(&binary).map_err(|e| e.to_string())?.permissions().mode() & 0o111 == 0 { return Err("Rust binary is not executable".into()); }
            }
            let exec_start = std::iter::once(binary.display().to_string()).chain(args.into_iter().map(str::to_string)).collect::<Vec<_>>().join(" ");
            let content = format!("[Unit]\nDescription=dPanel Rust app {site}\nAfter=network.target\n[Service]\nType=simple\nUser={owner}\nGroup={owner}\nWorkingDirectory={}\nEnvironment=PORT={port}\nEnvironment=HOST=127.0.0.1\nExecStart={}\nRestart=always\nRestartSec=3\nStandardOutput=journal\nStandardError=journal\n[Install]\nWantedBy=multi-user.target\n", root.display(), exec_start);
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

/// target/ unless another user owns it (e.g. a manual build), then .dpanel-target/
/// so the owner's cargo can write without touching that user's files.
fn cargo_target_dir(root: &Path, uid: u32) -> PathBuf {
    use std::os::unix::fs::MetadataExt;
    let target = root.join("target");
    match fs::symlink_metadata(&target) {
        Ok(meta) if meta.uid() != uid => root.join(".dpanel-target"),
        _ => target,
    }
}

fn cargo_build(owner: &str, root: &Path, target_dir: &Path) -> Result<(), String> {
    // rustup toolchains live in each user's home: give a new owner stable once.
    if !cargo(owner, root, &["--version"])?.status.success() {
        let out = run_as(owner, root, "rustup", &["toolchain", "install", "stable", "--profile", "minimal"])?;
        if !out.status.success() || !run_as(owner, root, "rustup", &["default", "stable"])?.status.success() {
            return Err(format!("cannot install the Rust toolchain for {owner}: {}", String::from_utf8_lossy(&out.stderr).trim()));
        }
    }
    let out = cargo(owner, root, &["build", "--release", "--target-dir", &target_dir.display().to_string()])?;
    if out.status.success() { return Ok(()); }
    let log = String::from_utf8_lossy(&out.stderr);
    let log = log.trim();
    let tail = &log[log.char_indices().rev().nth(3000).map_or(0, |(i, _)| i)..];
    Err(format!("cargo build --release failed:\n{tail}"))
}

/// The package's only binary, or the one named after the package.
fn cargo_binary_name(owner: &str, root: &Path) -> Result<String, String> {
    let out = cargo(owner, root, &["metadata", "--no-deps", "--format-version", "1"])?;
    if !out.status.success() { return Err(format!("cargo metadata failed: {}", String::from_utf8_lossy(&out.stderr).trim())); }
    let meta: serde_json::Value = serde_json::from_slice(&out.stdout).map_err(|e| format!("cargo metadata: {e}"))?;
    let manifest = root.join("Cargo.toml");
    let package = meta["packages"].as_array().into_iter().flatten()
        .find(|p| p["manifest_path"].as_str().and_then(|m| Path::new(m).canonicalize().ok()).as_deref() == Some(manifest.as_path()))
        .ok_or("Cargo.toml is a workspace root without a package: enter the binary path")?;
    let bins = package["targets"].as_array().into_iter().flatten()
        .filter(|t| t["kind"].as_array().is_some_and(|k| k.iter().any(|k| k == "bin")))
        .filter_map(|t| t["name"].as_str()).collect::<Vec<_>>();
    match bins.as_slice() {
        [only] => Ok(only.to_string()),
        [] => Err("the Cargo package has no binary target".into()),
        many => many.iter().find(|b| Some(**b) == package["name"].as_str()).map(|b| b.to_string())
            .ok_or_else(|| format!("several binaries ({}): enter the binary path, e.g. ./target/release/{}", many.join(", "), many[0])),
    }
}

fn cargo(owner: &str, root: &Path, args: &[&str]) -> Result<std::process::Output, String> { run_as(owner, root, "cargo", args) }

fn run_as(owner: &str, root: &Path, program: &str, args: &[&str]) -> Result<std::process::Output, String> {
    Command::new("/usr/sbin/runuser").args(["-u", owner, "--", program]).args(args)
        .current_dir(root)
        .env("HOME", format!("/home/{owner}"))
        .env("PATH", "/usr/local/bin:/usr/bin:/bin")
        .env("CARGO_TERM_COLOR", "never")
        .output().map_err(|e| format!("unable to run {program}: {e}"))
}

async fn listening(port: u16) -> bool {
    timeout(Duration::from_millis(300), TcpStream::connect(SocketAddr::from(([127,0,0,1], port)))).await.is_ok_and(|r| r.is_ok())
}
fn systemctl(args: &[&str]) -> Result<(), String> {
    let out = Command::new("systemctl").args(args).output().map_err(|e| e.to_string())?;
    if out.status.success() { Ok(()) } else { Err(String::from_utf8_lossy(&out.stderr).trim().to_string()) }
}
