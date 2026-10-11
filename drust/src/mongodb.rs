/// Maps the panel's service key to its systemd unit. The caller only ever picks
/// a key, never a unit name, so this endpoint cannot touch any other service.
fn unit_for(service: &str) -> Option<&'static str> {
    match service {
        "mongodb" => Some("mongod"),
        _ => None,
    }
}

/// Turns MongoDB on (start + enable at boot) or off (stop + disable at boot).
/// Runs in drust for the same reason as PostgreSQL: php-fpm can't write /etc.
pub fn set_service(service: &str, enabled: bool) -> Result<String, String> {
    let unit = unit_for(service).ok_or_else(|| "Unknown service.".to_string())?;
    crate::postgresql::switch_unit(unit, enabled)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_known_services_map_to_units() {
        assert_eq!(unit_for("mongodb"), Some("mongod"));
        assert_eq!(unit_for("postgresql"), None);
        assert_eq!(unit_for("mongodb; rm -rf /"), None);
    }

    #[test]
    fn unknown_service_is_rejected_before_running_anything() {
        assert_eq!(set_service("ssh", true), Err("Unknown service.".to_string()));
    }
}
