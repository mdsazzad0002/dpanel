//! Nextcloud: the panel unpacks the server zip into the site root, then
//! `occ maintenance:install` sets it up. The data folder is outside the web
//! root (the gateway does not read .htaccess), and the CLI install only
//! trusts localhost, so the site's domain and URL are set afterwards.

use serde::Deserialize;

use super::service::{
    Permissions, Site, has_no_control_chars, is_db_host, is_db_identifier, is_hostname,
    is_strong_password,
};

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &["config", "apps", "custom_apps"],
    secret_config: Some("config/config.php"),
};

#[derive(Deserialize)]
pub(crate) struct NextcloudInstall {
    admin_user: String,
    admin_pass: String,
    admin_email: String,
    /// "mysql" (MariaDB) or "pgsql".
    db_type: String,
    db_host: String,
    db_user: String,
    db_pass: String,
    db_name: String,
    data_dir: String,
    domain: String,
    url: String,
}

pub(super) async fn install(site: &Site, input: &NextcloudInstall) -> Result<String, String> {
    if !site.has_file("occ") || !site.has_file("console.php") {
        return Err("Nextcloud occ was not found in the site root.".into());
    }
    let args = install_arguments(input)?;
    site.prepare_outside_dir(&input.data_dir, "Nextcloud data folder")?;
    let mut output = site
        .php_script(site.target(), "occ", "console.php", &args)
        .await?;
    for command in config_commands(input) {
        output.push('\n');
        output.push_str(
            &site
                .php_script(site.target(), "occ", "console.php", &command)
                .await?,
        );
    }
    Ok(output)
}

/// `occ maintenance:install` options; passwords travel on stdin.
fn install_arguments(input: &NextcloudInstall) -> Result<Vec<String>, String> {
    let user_ok = !input.admin_user.is_empty()
        && input.admin_user.len() <= 64
        && input
            .admin_user
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.' | '@'));
    if !user_ok {
        return Err("Invalid Nextcloud admin username.".into());
    }
    if !is_strong_password(&input.admin_pass) {
        return Err("The Nextcloud admin password must be at least 12 characters.".into());
    }
    if !input.admin_email.contains('@') || !has_no_control_chars(&input.admin_email) {
        return Err("Invalid Nextcloud admin email.".into());
    }
    if !matches!(input.db_type.as_str(), "mysql" | "pgsql") {
        return Err("Invalid Nextcloud database type.".into());
    }
    if !is_db_identifier(&input.db_name) || !is_db_identifier(&input.db_user) {
        return Err("Invalid Nextcloud database name or user.".into());
    }
    if !is_db_host(&input.db_host) || !has_no_control_chars(&input.db_pass) {
        return Err("Invalid Nextcloud database host or password.".into());
    }
    if !input.data_dir.starts_with('/') || !has_no_control_chars(&input.data_dir) {
        return Err("Invalid Nextcloud data folder.".into());
    }
    if !is_hostname(&input.domain)
        || (input.url != format!("https://{}", input.domain)
            && input.url != format!("http://{}", input.domain))
    {
        return Err("Invalid Nextcloud domain or URL.".into());
    }

    Ok(vec![
        "maintenance:install".into(),
        format!("--database={}", input.db_type),
        format!("--database-name={}", input.db_name),
        format!("--database-host={}", input.db_host),
        format!("--database-user={}", input.db_user),
        format!("--database-pass={}", input.db_pass),
        format!("--admin-user={}", input.admin_user),
        format!("--admin-pass={}", input.admin_pass),
        format!("--admin-email={}", input.admin_email.trim()),
        format!("--data-dir={}", input.data_dir),
        "--no-interaction".into(),
        "--no-ansi".into(),
    ])
}

/// Trust the site's domain, set its URL and run background jobs from cron.
fn config_commands(input: &NextcloudInstall) -> Vec<Vec<String>> {
    let set = |key: &str, extra: Option<&str>, value: &str| {
        let mut command = vec!["config:system:set".to_string(), key.to_string()];
        if let Some(extra) = extra {
            command.push(extra.to_string());
        }
        command.push(format!("--value={value}"));
        command.push("--no-ansi".into());
        command
    };
    let mut commands = vec![
        set("trusted_domains", Some("1"), &input.domain),
        set("overwrite.cli.url", None, &input.url),
    ];
    if input.url.starts_with("https://") {
        commands.push(set("overwriteprotocol", None, "https"));
    }
    commands.push(vec!["background:cron".into(), "--no-ansi".into()]);
    commands
}

#[cfg(test)]
mod tests {
    use super::*;

    fn nextcloud() -> NextcloudInstall {
        NextcloudInstall {
            admin_user: "admin".into(),
            admin_pass: "correct horse battery".into(),
            admin_email: "admin@example.com".into(),
            db_type: "mysql".into(),
            db_host: "127.0.0.1:3306".into(),
            db_user: "nc_user".into(),
            db_pass: "p@ss'word\"$x".into(),
            db_name: "nc_db".into(),
            data_dir: "/home/alice/public_html-nextcloud-data".into(),
            domain: "cloud.example.com".into(),
            url: "https://cloud.example.com".into(),
        }
    }

    #[test]
    fn arguments_keep_values_verbatim() {
        let args = install_arguments(&nextcloud()).unwrap();
        assert_eq!(args[0], "maintenance:install");
        assert!(args.contains(&"--database-pass=p@ss'word\"$x".to_string()));
        assert!(args.contains(&"--data-dir=/home/alice/public_html-nextcloud-data".to_string()));
    }

    #[test]
    fn domain_and_https_are_configured() {
        let commands = config_commands(&nextcloud());
        assert_eq!(
            commands[0],
            ["config:system:set", "trusted_domains", "1", "--value=cloud.example.com", "--no-ansi"]
        );
        assert!(commands.iter().any(|c| c.contains(&"overwriteprotocol".to_string())));
        assert_eq!(commands.last().unwrap()[0], "background:cron");
        let mut http = nextcloud();
        http.url = "http://cloud.example.com".into();
        assert!(!config_commands(&http).iter().any(|c| c.contains(&"overwriteprotocol".to_string())));
    }

    #[test]
    fn arguments_reject_bad_input() {
        let mut input = nextcloud();
        input.admin_pass = "short".into();
        assert!(install_arguments(&input).is_err());
        let mut input = nextcloud();
        input.db_type = "sqlite".into();
        assert!(install_arguments(&input).is_err());
        let mut input = nextcloud();
        input.url = "https://evil.example".into();
        assert!(install_arguments(&input).is_err());
        let mut input = nextcloud();
        input.data_dir = "relative/data".into();
        assert!(install_arguments(&input).is_err());
    }
}
