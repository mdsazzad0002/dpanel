//! WHMCS: the licensed files are unpacked by the panel, then
//! `install/bin/installer.php -i -n -c` reads its configuration as one line of
//! JSON on stdin.

use serde::Deserialize;

use super::service::{
    Permissions, SHORT_TIMEOUT, Site, has_no_control_chars, is_db_host, is_db_identifier,
    is_strong_password,
};

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &["attachments", "downloads", "templates_c"],
    secret_config: Some("configuration.php"),
};

#[derive(Deserialize)]
pub(crate) struct WhmcsInstall {
    admin_username: String,
    admin_password: String,
    license: String,
    db_host: String,
    db_username: String,
    db_password: String,
    db_name: String,
    cc_encryption_hash: String,
}

pub(super) async fn install(site: &Site, input: &WhmcsInstall) -> Result<String, String> {
    let install_dir = site.target().join("install");
    if !install_dir.join("bin/installer.php").is_file() {
        return Err("WHMCS install/bin/installer.php was not found in the site root.".into());
    }
    let stdin = config(input)?;
    site.php_with_input(
        &install_dir,
        &["-f", "bin/installer.php", "--", "-i", "-n", "-c"],
        SHORT_TIMEOUT,
        &stdin,
    )
    .await
}

/// One line of JSON for the CLI installer.
fn config(input: &WhmcsInstall) -> Result<Vec<u8>, String> {
    let username_ok = !input.admin_username.is_empty()
        && input.admin_username.len() <= 64
        && input
            .admin_username
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.'));
    if !username_ok {
        return Err("Invalid WHMCS admin username.".into());
    }
    if !is_strong_password(&input.admin_password) {
        return Err("The WHMCS admin password must be at least 12 characters.".into());
    }
    let license_ok = !input.license.is_empty()
        && input.license.len() <= 64
        && input
            .license
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '-');
    if !license_ok {
        return Err("Invalid WHMCS license key.".into());
    }
    if !is_db_identifier(&input.db_name) || !is_db_identifier(&input.db_username) {
        return Err("Invalid WHMCS database name or user.".into());
    }
    if !is_db_host(&input.db_host) || !has_no_control_chars(&input.db_password) {
        return Err("Invalid WHMCS database host or password.".into());
    }
    if input.cc_encryption_hash.len() != 64
        || !input
            .cc_encryption_hash
            .chars()
            .all(|c| c.is_ascii_alphanumeric())
    {
        return Err("The WHMCS encryption hash must be 64 letters and digits.".into());
    }

    serde_json::to_vec(&serde_json::json!({
        "admin": {
            "username": input.admin_username,
            "password": input.admin_password,
        },
        "configuration": {
            "license": input.license,
            "db_host": input.db_host,
            "db_username": input.db_username,
            "db_password": input.db_password,
            "db_name": input.db_name,
            "cc_encryption_hash": input.cc_encryption_hash,
            "mysql_charset": "utf8",
        },
    }))
    .map_err(|e| e.to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn config_is_one_json_line() {
        let input = WhmcsInstall {
            admin_username: "admin".into(),
            admin_password: "p\"ss word 12345".into(),
            license: "Owned-abc123".into(),
            db_host: "127.0.0.1".into(),
            db_username: "wh_user".into(),
            db_password: "it's $ecret".into(),
            db_name: "wh_db".into(),
            cc_encryption_hash: "a".repeat(64),
        };
        let bytes = config(&input).unwrap();
        assert!(!bytes.contains(&b'\n'));
        let value: serde_json::Value = serde_json::from_slice(&bytes).unwrap();
        assert_eq!(value["admin"]["password"], "p\"ss word 12345");
        assert_eq!(value["configuration"]["db_password"], "it's $ecret");
        assert_eq!(value["configuration"]["license"], "Owned-abc123");

        let bad = WhmcsInstall {
            cc_encryption_hash: "short".into(),
            ..input
        };
        assert!(config(&bad).is_err());
    }
}
