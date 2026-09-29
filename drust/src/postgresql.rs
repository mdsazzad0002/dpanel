use std::{path::Path, process::Command};

/// Maps the panel's service key to its systemd unit. The caller only ever picks
/// a key, never a unit name, so this endpoint cannot touch any other service.
fn unit_for(service: &str) -> Option<&'static str> {
    match service {
        "postgresql" => Some("postgresql"),
        "pgadmin" => Some("dpanel-pgadmin"),
        _ => None,
    }
}

fn systemctl() -> Option<&'static str> {
    ["/usr/bin/systemctl", "/bin/systemctl"]
        .into_iter()
        .find(|candidate| Path::new(candidate).is_file())
}

/// Turns a PostgreSQL-stack service on (start + enable at boot) or off
/// (stop + disable at boot). Runs here because drust is not sandboxed: the
/// panel's php-fpm has ProtectSystem=full, so /etc is read-only for anything
/// it spawns, including sudo, and `systemctl enable` must write there.
pub fn set_service(service: &str, enabled: bool) -> Result<String, String> {
    let unit = unit_for(service).ok_or_else(|| "Unknown service.".to_string())?;
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

    Ok(format!(
        "{unit} {}.",
        if enabled { "started and enabled" } else { "stopped and disabled" }
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_known_services_map_to_units() {
        assert_eq!(unit_for("postgresql"), Some("postgresql"));
        assert_eq!(unit_for("pgadmin"), Some("dpanel-pgadmin"));
        assert_eq!(unit_for("mariadb"), None);
        assert_eq!(unit_for("postgresql; rm -rf /"), None);
    }

    #[test]
    fn unknown_service_is_rejected_before_running_anything() {
        assert_eq!(set_service("ssh", true), Err("Unknown service.".to_string()));
    }
}
