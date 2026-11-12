//! MediaWiki: the panel unpacks the verified tarball into the site root,
//! then `maintenance/install.php` writes LocalSettings.php and the database.

use serde::Deserialize;

use super::service::{
    Permissions, Site, has_no_control_chars, is_db_host, is_db_identifier, is_strong_password,
};

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &["images", "cache"],
    secret_config: Some("LocalSettings.php"),
};

#[derive(Deserialize)]
pub(crate) struct MediawikiInstall {
    site_name: String,
    admin_user: String,
    admin_pass: String,
    server: String,
    /// "mysql" (MariaDB) or "postgres".
    db_type: String,
    db_server: String,
    db_port: String,
    db_user: String,
    db_pass: String,
    db_name: String,
}

pub(super) async fn install(site: &Site, input: &MediawikiInstall) -> Result<String, String> {
    if !site.has_file("maintenance/install.php") {
        return Err("MediaWiki maintenance/install.php was not found in the site root.".into());
    }
    let args = arguments(input)?;
    site.php_script(
        site.target(),
        "maintenance/install.php",
        "maintenance/install.php",
        &args,
    )
    .await
}

/// `install.php` options and its two positional arguments (wiki name, admin).
fn arguments(input: &MediawikiInstall) -> Result<Vec<String>, String> {
    if input.site_name.trim().is_empty()
        || input.site_name.len() > 200
        || !has_no_control_chars(&input.site_name)
        || input.site_name.chars().any(|c| "#<>[]|{}".contains(c))
    {
        return Err("Invalid MediaWiki site name.".into());
    }
    let user_ok = input.admin_user.len() <= 85
        && input
            .admin_user
            .starts_with(|c: char| c.is_ascii_alphabetic())
        && input
            .admin_user
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.' | ' '));
    if !user_ok {
        return Err("Invalid MediaWiki admin username.".into());
    }
    if !is_strong_password(&input.admin_pass) {
        return Err("The MediaWiki admin password must be at least 12 characters.".into());
    }
    let server_ok = (input.server.starts_with("https://") || input.server.starts_with("http://"))
        && !input.server.ends_with('/')
        && !input.server.chars().any(|c| c.is_whitespace() || c.is_control());
    if !server_ok {
        return Err("Invalid MediaWiki server URL.".into());
    }
    if !matches!(input.db_type.as_str(), "mysql" | "postgres") {
        return Err("Invalid MediaWiki database type.".into());
    }
    if !is_db_identifier(&input.db_name) || !is_db_identifier(&input.db_user) {
        return Err("Invalid MediaWiki database name or user.".into());
    }
    if !is_db_host(&input.db_server)
        || input.db_port.is_empty()
        || !input.db_port.chars().all(|c| c.is_ascii_digit())
        || !has_no_control_chars(&input.db_pass)
    {
        return Err("Invalid MediaWiki database host, port or password.".into());
    }

    let mut args = vec![format!("--dbtype={}", input.db_type)];
    if input.db_type == "postgres" {
        args.push(format!("--dbserver={}", input.db_server));
        args.push(format!("--dbport={}", input.db_port));
    } else {
        args.push(format!("--dbserver={}:{}", input.db_server, input.db_port));
    }
    args.extend([
        format!("--dbname={}", input.db_name),
        format!("--dbuser={}", input.db_user),
        format!("--dbpass={}", input.db_pass),
        format!("--pass={}", input.admin_pass),
        format!("--server={}", input.server),
        "--scriptpath=".into(),
        "--lang=en".into(),
        input.site_name.trim().to_string(),
        input.admin_user.trim().to_string(),
    ]);
    Ok(args)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn wiki() -> MediawikiInstall {
        MediawikiInstall {
            site_name: "Team Wiki".into(),
            admin_user: "Wiki Admin".into(),
            admin_pass: "correct horse battery".into(),
            server: "https://wiki.example.com".into(),
            db_type: "mysql".into(),
            db_server: "127.0.0.1".into(),
            db_port: "3306".into(),
            db_user: "mw_user".into(),
            db_pass: "it's $ecret".into(),
            db_name: "mw_db".into(),
        }
    }

    #[test]
    fn arguments_end_with_name_and_admin() {
        let args = arguments(&wiki()).unwrap();
        assert!(args.contains(&"--dbserver=127.0.0.1:3306".to_string()));
        assert!(args.contains(&"--dbpass=it's $ecret".to_string()));
        assert_eq!(&args[args.len() - 2..], ["Team Wiki", "Wiki Admin"]);

        let mut pg = wiki();
        pg.db_type = "postgres".into();
        pg.db_port = "5432".into();
        let args = arguments(&pg).unwrap();
        assert!(args.contains(&"--dbserver=127.0.0.1".to_string()));
        assert!(args.contains(&"--dbport=5432".to_string()));
    }

    #[test]
    fn arguments_reject_bad_input() {
        let mut input = wiki();
        input.site_name = "a[[b]]".into();
        assert!(arguments(&input).is_err());
        let mut input = wiki();
        input.admin_user = "1admin".into();
        assert!(arguments(&input).is_err());
        let mut input = wiki();
        input.db_type = "sqlite".into();
        assert!(arguments(&input).is_err());
    }
}
