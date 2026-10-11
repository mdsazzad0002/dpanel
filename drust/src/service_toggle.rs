//! Turns a systemd unit on (start + enable at boot) or off (stop + disable at
//! boot). Shared by the PostgreSQL and MongoDB modules.

use std::{path::Path, process::Command};

fn systemctl() -> Option<&'static str> {
    ["/usr/bin/systemctl", "/bin/systemctl"]
        .into_iter()
        .find(|candidate| Path::new(candidate).is_file())
}

/// `systemctl enable|disable --now <unit>`. Callers must pass a unit from
/// their own fixed allow-list (see postgresql.rs, mongodb.rs).
pub(crate) fn switch_unit(unit: &str, enabled: bool) -> Result<String, String> {
    let systemctl = systemctl().ok_or_else(|| "systemctl is not available.".to_string())?;
    let action = if enabled { "enable" } else { "disable" };

    let output = Command::new(systemctl)
        .args([action, "--now", unit])
        .output()
        .map_err(|e| format!("cannot run systemctl: {e}"))?;
    if !output.status.success() {
        let error = String::from_utf8_lossy(&output.stderr).trim().to_string();
        return Err(if error.is_empty() {
            format!("systemctl {action} --now {unit} exited unsuccessfully")
        } else {
            error
        });
    }

    // `enable --now` succeeds as soon as the process is spawned, even when it
    // exits a moment later. Check it stayed up; if not, switch it back off so
    // it doesn't crash-loop at boot, and report why it died.
    if enabled {
        std::thread::sleep(std::time::Duration::from_secs(3));
        let active = Command::new(systemctl)
            .args(["is-active", "--quiet", unit])
            .status()
            .is_ok_and(|status| status.success());
        if !active {
            let _ = Command::new(systemctl).args(["disable", "--now", unit]).output();
            let reason = last_log_message(unit);
            return Err(match reason {
                Some(reason) => format!("{unit} stopped right after starting: {reason}"),
                None => format!("{unit} stopped right after starting. Check: journalctl -u {unit}"),
            });
        }
    }

    Ok(format!(
        "{unit} {}.",
        if enabled { "started and enabled" } else { "stopped and disabled" }
    ))
}

/// The last line the unit logged, with MongoDB's JSON log lines reduced to
/// their "msg" field.
fn last_log_message(unit: &str) -> Option<String> {
    let output = Command::new("journalctl")
        .args(["-u", unit, "-n", "20", "-o", "cat", "--no-pager", "--since", "-1min"])
        .output()
        .ok()?;
    let text = String::from_utf8_lossy(&output.stdout);
    let line = text
        .lines()
        .rev()
        .map(str::trim)
        .find(|line| !line.is_empty() && !line.starts_with("Started ") && !line.contains(".service: "))?;
    Some(json_msg(line).unwrap_or_else(|| line.to_string()))
}

fn json_msg(line: &str) -> Option<String> {
    let value: serde_json::Value = serde_json::from_str(line).ok()?;
    value.get("msg")?.as_str().map(str::to_string)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn mongodb_json_log_lines_reduce_to_their_message() {
        assert_eq!(
            json_msg(r#"{"t":{},"s":"F","msg":"MongoDB cannot start"}"#),
            Some("MongoDB cannot start".to_string())
        );
        assert_eq!(json_msg("plain text"), None);
    }
}
