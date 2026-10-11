/// Maps the panel's service key to its systemd unit. The caller only ever picks
/// a key, never a unit name, so this endpoint cannot touch any other service.
fn unit_for(service: &str) -> Option<&'static str> {
    match service {
        "postgresql" => Some("postgresql"),
        "pgadmin" => Some("dpanel-pgadmin"),
        _ => None,
    }
}

/// Turns a PostgreSQL-stack service on (start + enable at boot) or off
/// (stop + disable at boot). Runs here because drust is not sandboxed: the
/// panel's php-fpm has ProtectSystem=full, so /etc is read-only for anything
/// it spawns, including sudo, and `systemctl enable` must write there.
pub fn set_service(service: &str, enabled: bool) -> Result<String, String> {
    let unit = unit_for(service).ok_or_else(|| "Unknown service.".to_string())?;
    crate::service_toggle::switch_unit(unit, enabled)
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
