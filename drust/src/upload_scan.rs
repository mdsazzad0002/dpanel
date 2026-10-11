//! Antivirus check for files uploaded through dPanel.
//!
//! Scanning on upload needs a warm ClamAV daemon: clamscan reloads the whole
//! signature database on every run, which takes far too long per file. The
//! panel switches the daemon on and off from Security Center, and while it is
//! on every finished upload is passed here before it is put in place.
//!
//! As with the website scanner, the caller only names a file; which programs
//! run and with what arguments is fixed in code.

use serde::Serialize;
use std::{
    fs,
    path::{Path, PathBuf},
    process::Command,
    thread,
    time::{Duration, Instant},
};

use crate::security_scan::{
    ALLOWED_ROOT_PREFIXES, Finding, MAX_ANALYZE_BYTES, PHP_EXTENSIONS, analyze_php, extension,
    find_binary, parse_clamscan, read_limited, signature_age_hours,
};

const CLAMD_CONF: &str = "/etc/clamav/clamd.conf";
const DEFAULT_SOCKET: &str = "/var/run/clamav/clamd.ctl";
const DAEMON_UNIT: &str = "clamav-daemon";
const FRESHCLAM_UNIT: &str = "clamav-freshclam";
/// Loading the signature database takes 20-60 seconds depending on the CPU.
const DAEMON_START_TIMEOUT: Duration = Duration::from_secs(180);

#[derive(Serialize, Debug)]
pub struct Status {
    pub clamav_installed: bool,
    pub daemon_installed: bool,
    pub daemon_active: bool,
    pub socket_ready: bool,
    pub signature_age_hours: Option<u64>,
}

#[derive(Serialize, Debug, Default)]
pub struct FileReport {
    /// False when ClamAV matched a signature.
    pub clean: bool,
    pub threats: Vec<String>,
    /// PHP code findings; the panel decides which ones block the upload.
    pub findings: Vec<Finding>,
    pub signature_age_hours: Option<u64>,
}

fn systemctl(args: &[&str]) -> Result<String, String> {
    let program = find_binary(&["/usr/bin/systemctl", "/bin/systemctl"])
        .ok_or("systemctl is not available on this server")?;
    let output = Command::new(program)
        .args(args)
        .output()
        .map_err(|e| e.to_string())?;
    let stdout = String::from_utf8_lossy(&output.stdout).trim().to_string();
    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
        return Err(if stderr.is_empty() { stdout } else { stderr });
    }
    Ok(stdout)
}

fn clamdscan() -> Option<String> {
    find_binary(&["/usr/bin/clamdscan", "/usr/local/bin/clamdscan"])
}

fn unit_installed(unit: &str) -> bool {
    [
        "/lib/systemd/system",
        "/usr/lib/systemd/system",
        "/etc/systemd/system",
    ]
    .iter()
    .any(|dir| Path::new(dir).join(format!("{unit}.service")).is_file())
}

fn socket_path() -> PathBuf {
    let configured = fs::read_to_string(CLAMD_CONF).ok().and_then(|text| {
        text.lines().find_map(|line| {
            let mut parts = line.split_whitespace();
            (parts.next()? == "LocalSocket").then(|| parts.next().map(str::to_string))?
        })
    });
    PathBuf::from(configured.unwrap_or_else(|| DEFAULT_SOCKET.to_string()))
}

pub fn status() -> Status {
    Status {
        clamav_installed: find_binary(&["/usr/bin/clamscan", "/usr/local/bin/clamscan"]).is_some(),
        daemon_installed: clamdscan().is_some() && unit_installed(DAEMON_UNIT),
        daemon_active: systemctl(&["is-active", DAEMON_UNIT]).is_ok_and(|s| s == "active"),
        socket_ready: socket_path().exists(),
        signature_age_hours: signature_age_hours(),
    }
}

/// Sets `key value` in clamd.conf, replacing an existing line or appending one.
fn set_conf(text: &str, key: &str, value: &str) -> String {
    let wanted = format!("{key} {value}");
    let mut found = false;
    let mut lines: Vec<String> = text
        .lines()
        .map(|line| {
            if line.split_whitespace().next() == Some(key) {
                found = true;
                wanted.clone()
            } else {
                line.to_string()
            }
        })
        .collect();
    if !found {
        lines.push(wanted);
    }
    let mut out = lines.join("\n");
    out.push('\n');
    out
}

/// Starts or stops the ClamAV daemon (and its signature updater).
pub fn set_enabled(enabled: bool) -> Result<Status, String> {
    if !enabled {
        // The socket unit would start the daemon again on the next connection.
        let _ = systemctl(&["disable", "--now", &format!("{DAEMON_UNIT}.socket")]);
        systemctl(&["disable", "--now", DAEMON_UNIT, FRESHCLAM_UNIT])?;
        return Ok(status());
    }

    if clamdscan().is_none() || !unit_installed(DAEMON_UNIT) {
        return Err(
            "The ClamAV daemon is not installed. Install it with: apt install clamav-daemon".into(),
        );
    }

    // By default clamd keeps the old database loaded while it loads the new
    // one after each signature update, briefly doubling its memory. Blocking
    // scans for those seconds instead keeps it at one copy.
    if let Ok(text) = fs::read_to_string(CLAMD_CONF) {
        let updated = set_conf(&text, "ConcurrentDatabaseReload", "no");
        if updated != text {
            fs::write(CLAMD_CONF, updated)
                .map_err(|e| format!("Could not update {CLAMD_CONF}: {e}"))?;
        }
    }

    // clamd refuses to start without a signature database.
    if signature_age_hours().is_none() {
        if let Some(freshclam) = find_binary(&["/usr/bin/freshclam", "/usr/local/bin/freshclam"]) {
            let _ = Command::new("/usr/bin/timeout")
                .args(["300", &freshclam, "--quiet"])
                .status();
        }
        if signature_age_hours().is_none() {
            return Err(
                "ClamAV signatures could not be downloaded. Run freshclam and try again.".into(),
            );
        }
    }

    systemctl(&["enable", "--now", FRESHCLAM_UNIT, DAEMON_UNIT])?;

    let socket = socket_path();
    let started = Instant::now();
    while !socket.exists() {
        if started.elapsed() > DAEMON_START_TIMEOUT {
            return Err("The ClamAV daemon started but its socket did not appear. Check: journalctl -u clamav-daemon".into());
        }
        if systemctl(&["is-failed", DAEMON_UNIT]).is_ok_and(|s| s == "failed") {
            return Err(
                "The ClamAV daemon failed to start. Check: journalctl -u clamav-daemon".into(),
            );
        }
        thread::sleep(Duration::from_secs(1));
    }
    Ok(status())
}

fn validate_file(path: &str) -> Result<PathBuf, String> {
    let raw = Path::new(path);
    if !raw.is_absolute() {
        return Err("File path must be absolute.".into());
    }
    let canonical = fs::canonicalize(raw).map_err(|e| format!("File is not accessible: {e}"))?;
    let text = canonical.to_string_lossy();
    if !ALLOWED_ROOT_PREFIXES
        .iter()
        .any(|prefix| text.starts_with(prefix))
    {
        return Err(format!(
            "File must be inside {}.",
            ALLOWED_ROOT_PREFIXES.join(", ")
        ));
    }
    if !canonical.is_file() {
        return Err("Path is not a regular file.".into());
    }
    Ok(canonical)
}

/// Scans one uploaded file. `name` is the file's real name, used for the PHP
/// checks because staged uploads are often stored without an extension.
pub fn scan_file(path: &str, name: &str) -> Result<FileReport, String> {
    let file = validate_file(path)?;
    let mut report = FileReport {
        clean: true,
        signature_age_hours: signature_age_hours(),
        ..FileReport::default()
    };

    if PHP_EXTENSIONS.contains(&extension(Path::new(name)).as_str()) {
        let bytes = read_limited(&file, MAX_ANALYZE_BYTES)?;
        report.findings = analyze_php(&bytes, false)
            .into_iter()
            .map(|mut finding| {
                finding.file_path = Some(name.to_string());
                finding
            })
            .collect();
    }

    let clamdscan = clamdscan().ok_or("The ClamAV daemon is not installed.")?;
    if !socket_path().exists() {
        return Err("The ClamAV daemon is not running.".into());
    }
    // --fdpass lets clamd (which runs as clamav) read a file only root can open.
    let output = Command::new("/usr/bin/timeout")
        .args(["900", &clamdscan, "--fdpass", "--no-summary", "--stdout"])
        .arg(&file)
        .output()
        .map_err(|e| format!("Could not run clamdscan: {e}"))?;
    let stdout = String::from_utf8_lossy(&output.stdout);
    match output.status.code() {
        Some(0) => {}
        Some(1) => {
            report.clean = false;
            report.threats = parse_clamscan(&stdout)
                .into_iter()
                .map(|(_, signature)| signature)
                .collect();
            if report.threats.is_empty() {
                report.threats.push("Unknown threat".into());
            }
        }
        _ => {
            let stderr = String::from_utf8_lossy(&output.stderr);
            let detail = [stdout.trim(), stderr.trim()]
                .into_iter()
                .filter(|s| !s.is_empty())
                .collect::<Vec<_>>()
                .join(" ");
            return Err(format!(
                "clamdscan failed: {}",
                if detail.is_empty() {
                    "timed out"
                } else {
                    &detail
                }
            ));
        }
    }
    Ok(report)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn set_conf_replaces_existing_key() {
        let text = "LocalSocket /x\nConcurrentDatabaseReload yes\n";
        assert_eq!(
            set_conf(text, "ConcurrentDatabaseReload", "no"),
            "LocalSocket /x\nConcurrentDatabaseReload no\n"
        );
    }

    #[test]
    fn set_conf_appends_missing_key() {
        assert_eq!(
            set_conf("LocalSocket /x\n", "ConcurrentDatabaseReload", "no"),
            "LocalSocket /x\nConcurrentDatabaseReload no\n"
        );
    }

    #[test]
    fn rejects_paths_outside_allowed_roots() {
        assert!(validate_file("/etc/passwd").is_err());
        assert!(validate_file("relative/file").is_err());
    }
}
