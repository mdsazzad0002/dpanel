//! OpenCart 4: the panel unpacks upload/ into the site root and prepares
//! config.php, then `install/cli_install.php install` sets it up. The panel
//! removes install/ and moves system/storage afterwards.

use serde::Deserialize;

use super::service::{
    Permissions, Site, has_no_control_chars, is_db_host, is_db_identifier, is_strong_password,
};

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &["image", "system/storage"],
    secret_config: Some("config.php"),
};

#[derive(Deserialize)]
pub(crate) struct OpencartInstall {
    username: String,
    password: String,
    email: String,
    http_server: String,
    db_hostname: String,
    db_port: String,
    db_username: String,
    db_password: String,
    db_database: String,
    db_prefix: String,
}

pub(super) async fn install(site: &Site, input: &OpencartInstall) -> Result<String, String> {
    let install_dir = site.target().join("install");
    if !install_dir.join("cli_install.php").is_file() {
        return Err("OpenCart install/cli_install.php was not found in the site root.".into());
    }
    let args = arguments(input)?;
    site.php_script(&install_dir, "cli_install.php", "cli_install.php", &args)
        .await
}

/// cli_install.php reads `--option value` pairs, so no value may start with "-".
fn arguments(input: &OpencartInstall) -> Result<Vec<String>, String> {
    let username_ok = (3..=20).contains(&input.username.len())
        && input
            .username
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.'))
        && !input.username.starts_with('-');
    if !username_ok {
        return Err("Invalid OpenCart admin username.".into());
    }
    if !is_strong_password(&input.password)
        || input.password.chars().count() > 40
        || input.password.starts_with('-')
    {
        return Err("The OpenCart admin password must be 12 to 40 characters and not start with '-'.".into());
    }
    if !input.email.contains('@') || !has_no_control_chars(&input.email) || input.email.starts_with('-') {
        return Err("Invalid OpenCart admin email.".into());
    }
    let url_ok = (input.http_server.starts_with("https://") || input.http_server.starts_with("http://"))
        && input.http_server.ends_with('/')
        && !input.http_server.chars().any(|c| c.is_whitespace() || c.is_control());
    if !url_ok {
        return Err("Invalid OpenCart site URL.".into());
    }
    if !is_db_identifier(&input.db_database) || !is_db_identifier(&input.db_username) {
        return Err("Invalid OpenCart database name or user.".into());
    }
    if !is_db_host(&input.db_hostname)
        || input.db_port.is_empty()
        || !input.db_port.chars().all(|c| c.is_ascii_digit())
    {
        return Err("Invalid OpenCart database host or port.".into());
    }
    if input.db_password.is_empty()
        || input.db_password.starts_with('-')
        || !has_no_control_chars(&input.db_password)
    {
        return Err("Invalid OpenCart database password.".into());
    }
    if !is_db_identifier(&input.db_prefix) || !input.db_prefix.ends_with('_') {
        return Err("Invalid OpenCart table prefix.".into());
    }

    let mut args = vec!["install".to_string()];
    for (key, value) in [
        ("username", &input.username),
        ("password", &input.password),
        ("email", &input.email),
        ("http_server", &input.http_server),
        ("db_driver", &"mysqli".to_string()),
        ("db_hostname", &input.db_hostname),
        ("db_username", &input.db_username),
        ("db_password", &input.db_password),
        ("db_database", &input.db_database),
        ("db_port", &input.db_port),
        ("db_prefix", &input.db_prefix),
    ] {
        args.push(format!("--{key}"));
        args.push(value.trim().to_string());
    }
    Ok(args)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn store() -> OpencartInstall {
        OpencartInstall {
            username: "admin".into(),
            password: "correct horse battery".into(),
            email: "admin@example.com".into(),
            http_server: "https://shop.example.com/".into(),
            db_hostname: "127.0.0.1".into(),
            db_port: "3306".into(),
            db_username: "oc_user".into(),
            db_password: "p@ss'word\"$x".into(),
            db_database: "oc_db".into(),
            db_prefix: "oc_".into(),
        }
    }

    #[test]
    fn arguments_are_option_value_pairs() {
        let args = arguments(&store()).unwrap();
        assert_eq!(args[0], "install");
        let at = args.iter().position(|a| a == "--db_password").unwrap();
        assert_eq!(args[at + 1], "p@ss'word\"$x");
        assert_eq!(args.len(), 1 + 11 * 2);
    }

    #[test]
    fn arguments_reject_values_read_as_options() {
        let mut input = store();
        input.password = "-correct horse battery".into();
        assert!(arguments(&input).is_err());
        let mut input = store();
        input.db_password = "--db_prefix".into();
        assert!(arguments(&input).is_err());
        let mut input = store();
        input.username = "ab".into();
        assert!(arguments(&input).is_err());
        let mut input = store();
        input.http_server = "javascript:alert(1)/".into();
        assert!(arguments(&input).is_err());
    }
}
