//! Starting, stopping and reading system services. `plans/platform-spec.md`
//! section 6.3. Only Linux has a backend until the supervisor (E2, F4).

use super::{PlatformError, capabilities::Capability, paths::Tool};
use std::{path::Path, process::Command};

const UNIT_DIRS: [&str; 3] = ["/lib/systemd/system", "/usr/lib/systemd/system", "/etc/systemd/system"];

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ServiceStatus {
    pub installed: bool,
    pub active: bool,
    pub enabled: bool,
    /// What the OS calls it, such as `active`, `inactive` or `failed`.
    pub state: String,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LogStyle {
    /// Each line with its time.
    Timestamped,
    /// Only the messages.
    Messages,
    /// Only the messages of the last minute.
    RecentMessages,
}

pub trait ServiceManager: Send + Sync + std::fmt::Debug {
    fn status(&self, unit: &str) -> Result<ServiceStatus, PlatformError>;
    fn start(&self, unit: &str) -> Result<(), PlatformError>;
    fn restart(&self, unit: &str) -> Result<(), PlatformError>;
    fn reload(&self, unit: &str) -> Result<(), PlatformError>;
    fn reload_or_restart(&self, unit: &str) -> Result<(), PlatformError>;
    /// How many times the OS restarted it after it stopped by itself.
    fn restart_count(&self, unit: &str) -> Result<u64, PlatformError>;
    /// Whether it starts at boot; leaves it running or stopped as it is.
    fn enable(&self, unit: &str) -> Result<(), PlatformError>;
    /// Starts and enables every unit, or stops and disables them.
    fn switch(&self, units: &[&str], on: bool) -> Result<(), PlatformError>;
    /// Forgets an earlier failure, so a start is not refused for it.
    fn reset_failed(&self, unit: &str) -> Result<(), PlatformError>;
    /// Picks up unit definitions that changed on disk.
    fn reload_definitions(&self) -> Result<(), PlatformError>;
    /// Changes resource settings such as `MemoryMax=512M` on a running unit
    /// at once; they last until the next boot, when the unit file applies.
    fn set_runtime_properties(&self, unit: &str, properties: &[String]) -> Result<(), PlatformError>;
    /// Loaded units whose name matches a `name*` pattern.
    fn unit_names(&self, pattern: &str) -> Result<Vec<String>, PlatformError>;
    /// `unit` can be a `name*` pattern.
    fn logs(&self, unit: &str, lines: u32, style: LogStyle) -> Result<String, PlatformError>;
}

fn failed(action: &str, detail: impl Into<String>) -> PlatformError {
    PlatformError::Failed {
        action: action.to_string(),
        detail: detail.into(),
    }
}

fn binary(tool: Tool) -> Result<std::path::PathBuf, PlatformError> {
    super::paths().binary(tool)
}

fn systemctl(args: &[&str]) -> Result<(), PlatformError> {
    let output = Command::new(binary(Tool::Systemctl)?)
        .args(args)
        .output()
        .map_err(|error| failed("systemctl", format!("cannot run systemctl {args:?}: {error}")))?;
    if output.status.success() {
        return Ok(());
    }
    let error = String::from_utf8_lossy(&output.stderr).trim().to_string();
    Err(failed(
        "systemctl",
        if error.is_empty() { format!("systemctl {args:?} exited unsuccessfully") } else { error },
    ))
}

/// What systemctl printed, whatever it exited with: `is-active` exits non-zero
/// for every state but `active`, and that state is the answer.
fn systemctl_text(args: &[&str]) -> Result<String, PlatformError> {
    let output = Command::new(binary(Tool::Systemctl)?)
        .args(args)
        .output()
        .map_err(|error| failed("systemctl", format!("cannot run systemctl {args:?}: {error}")))?;
    Ok(String::from_utf8_lossy(&output.stdout).trim().to_string())
}

fn unit_file_exists(unit: &str, is_file: impl Fn(&Path) -> bool) -> bool {
    let name = unit.strip_suffix(".service").unwrap_or(unit);
    UNIT_DIRS.iter().any(|dir| is_file(&Path::new(dir).join(format!("{name}.service"))))
}

fn switch_args<'a>(units: &[&'a str], on: bool) -> Vec<&'a str> {
    let mut args = vec![if on { "enable" } else { "disable" }, "--now"];
    args.extend_from_slice(units);
    args
}

fn log_args(unit: &str, lines: u32, style: LogStyle) -> Vec<String> {
    let mut args: Vec<String> = ["--no-pager", "-n"].map(String::from).to_vec();
    args.push(lines.to_string());
    args.extend(["-u".to_string(), unit.to_string(), "-o".to_string()]);
    match style {
        LogStyle::Timestamped => args.push("short-iso".to_string()),
        LogStyle::Messages => args.push("cat".to_string()),
        LogStyle::RecentMessages => args.extend(["cat", "--since", "-1min"].map(String::from)),
    }
    args
}

fn first_column(text: &str) -> Vec<String> {
    text.lines().filter_map(|line| line.split_whitespace().next().map(str::to_string)).collect()
}

/// `systemctl` and `journalctl`.
#[derive(Debug)]
pub struct SystemdServices;

impl ServiceManager for SystemdServices {
    fn status(&self, unit: &str) -> Result<ServiceStatus, PlatformError> {
        let state = systemctl_text(&["is-active", unit])?;
        Ok(ServiceStatus {
            installed: unit_file_exists(unit, Path::is_file),
            active: state == "active",
            enabled: systemctl_text(&["is-enabled", unit])? == "enabled",
            state,
        })
    }

    fn start(&self, unit: &str) -> Result<(), PlatformError> {
        systemctl(&["start", unit])
    }

    fn restart(&self, unit: &str) -> Result<(), PlatformError> {
        systemctl(&["restart", unit])
    }

    fn reload(&self, unit: &str) -> Result<(), PlatformError> {
        systemctl(&["reload", unit])
    }

    fn reload_or_restart(&self, unit: &str) -> Result<(), PlatformError> {
        systemctl(&["reload-or-restart", unit])
    }

    fn restart_count(&self, unit: &str) -> Result<u64, PlatformError> {
        let text = systemctl_text(&["show", "-p", "NRestarts", "--value", unit])?;
        text.parse().map_err(|_| failed("systemctl", format!("unexpected NRestarts: {text}")))
    }

    fn enable(&self, unit: &str) -> Result<(), PlatformError> {
        systemctl(&["enable", unit])
    }

    fn switch(&self, units: &[&str], on: bool) -> Result<(), PlatformError> {
        systemctl(&switch_args(units, on))
    }

    fn reset_failed(&self, unit: &str) -> Result<(), PlatformError> {
        systemctl(&["reset-failed", unit])
    }

    fn reload_definitions(&self) -> Result<(), PlatformError> {
        systemctl(&["daemon-reload"])
    }

    fn set_runtime_properties(&self, unit: &str, properties: &[String]) -> Result<(), PlatformError> {
        let mut args = vec!["set-property", "--runtime", unit];
        args.extend(properties.iter().map(String::as_str));
        systemctl(&args)
    }

    fn unit_names(&self, pattern: &str) -> Result<Vec<String>, PlatformError> {
        systemctl_text(&["list-units", "--all", "--plain", "--no-legend", pattern])
            .map(|text| first_column(&text))
    }

    fn logs(&self, unit: &str, lines: u32, style: LogStyle) -> Result<String, PlatformError> {
        let output = Command::new(binary(Tool::Journalctl)?)
            .args(log_args(unit, lines, style))
            .output()
            .map_err(|error| failed("journalctl", format!("cannot run journalctl: {error}")))?;
        Ok(String::from_utf8_lossy(&output.stdout).into_owned())
    }
}

/// Every call is `Unsupported`, until the supervisor exists.
#[derive(Debug)]
pub struct NoServices;

fn unsupported<T>() -> Result<T, PlatformError> {
    Err(PlatformError::Unsupported {
        capability: Capability::Services,
    })
}

impl ServiceManager for NoServices {
    fn status(&self, _unit: &str) -> Result<ServiceStatus, PlatformError> {
        unsupported()
    }

    fn start(&self, _unit: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn restart(&self, _unit: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn reload(&self, _unit: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn reload_or_restart(&self, _unit: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn restart_count(&self, _unit: &str) -> Result<u64, PlatformError> {
        unsupported()
    }

    fn enable(&self, _unit: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn switch(&self, _units: &[&str], _on: bool) -> Result<(), PlatformError> {
        unsupported()
    }

    fn reset_failed(&self, _unit: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn reload_definitions(&self) -> Result<(), PlatformError> {
        unsupported()
    }

    fn set_runtime_properties(&self, _unit: &str, _properties: &[String]) -> Result<(), PlatformError> {
        unsupported()
    }

    fn unit_names(&self, _pattern: &str) -> Result<Vec<String>, PlatformError> {
        unsupported()
    }

    fn logs(&self, _unit: &str, _lines: u32, _style: LogStyle) -> Result<String, PlatformError> {
        unsupported()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn switching_names_every_unit_once() {
        assert_eq!(switch_args(&["a", "b"], true).join(" "), "enable --now a b");
        assert_eq!(switch_args(&["a"], false).join(" "), "disable --now a");
    }

    #[test]
    fn log_styles_ask_for_what_the_callers_used_to() {
        assert_eq!(
            log_args("web*", 50, LogStyle::Timestamped).join(" "),
            "--no-pager -n 50 -u web* -o short-iso"
        );
        assert_eq!(
            log_args("web", 20, LogStyle::RecentMessages).join(" "),
            "--no-pager -n 20 -u web -o cat --since -1min"
        );
    }

    #[test]
    fn a_unit_is_found_with_or_without_its_suffix() {
        let there = |path: &Path| path == Path::new("/etc/systemd/system/web.service");
        assert!(unit_file_exists("web", there));
        assert!(unit_file_exists("web.service", there));
        assert!(!unit_file_exists("other", there));
    }

    #[test]
    fn unit_lists_keep_only_the_names() {
        let text = "a@1.service loaded active running A\nb@2.service loaded failed failed B\n";
        assert_eq!(first_column(text), ["a@1.service", "b@2.service"]);
    }

    #[test]
    fn a_system_without_a_backend_refuses() {
        assert!(matches!(
            NoServices.restart("web"),
            Err(PlatformError::Unsupported { capability: Capability::Services })
        ));
    }
}
