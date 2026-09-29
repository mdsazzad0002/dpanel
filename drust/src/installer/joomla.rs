//! Joomla: the site files are unpacked by the panel, then Joomla's own
//! `installation/joomla.php install` command sets it up.

use serde::Deserialize;

use super::service::{
    Permissions, SHORT_TIMEOUT, Site, has_no_control_chars, is_db_host, is_db_identifier,
    is_strong_password,
};

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &[
        "cache",
        "administrator/cache",
        "administrator/logs",
        "tmp",
        "images",
    ],
    secret_config: Some("configuration.php"),
};

/// Loads the Joomla CLI installer with its arguments read from stdin, so the
/// admin and database passwords never appear in the process list.
const JOOMLA_CLI_BOOTSTRAP: &str = r#"$a = json_decode(stream_get_contents(STDIN), true);
if (!is_array($a)) { fwrite(STDERR, "Invalid installer input.\n"); exit(1); }
$_SERVER['argv'] = $argv = array_merge(['installation/joomla.php'], $a);
$_SERVER['argc'] = $argc = count($argv);
require 'installation/joomla.php';"#;

/// Answers for Joomla's `installation/joomla.php install` command.
#[derive(Deserialize)]
pub(crate) struct JoomlaInstall {
    site_name: String,
    admin_user: String,
    admin_username: String,
    admin_password: String,
    admin_email: String,
    db_host: String,
    db_user: String,
    db_pass: String,
    db_name: String,
    db_prefix: String,
}

pub(super) async fn install(site: &Site, input: &JoomlaInstall) -> Result<String, String> {
    if !site.has_file("installation/joomla.php") {
        return Err("Joomla installation/joomla.php was not found in the site root.".into());
    }
    let stdin = serde_json::to_vec(&arguments(input)?).map_err(|e| e.to_string())?;
    site.php_with_input(site.target(), &["-r", JOOMLA_CLI_BOOTSTRAP], SHORT_TIMEOUT, &stdin)
        .await
}

/// Builds `joomla.php install` options.
fn arguments(input: &JoomlaInstall) -> Result<Vec<String>, String> {
    if input.site_name.trim().is_empty()
        || !has_no_control_chars(&input.site_name)
        || input.site_name.len() > 200
    {
        return Err("Invalid Joomla site name.".into());
    }
    if input.admin_user.trim().is_empty()
        || !has_no_control_chars(&input.admin_user)
        || input.admin_user.len() > 100
    {
        return Err("Invalid Joomla admin name.".into());
    }
    let username_ok = !input.admin_username.is_empty()
        && input.admin_username.len() <= 150
        && input
            .admin_username
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.' | '@'));
    if !username_ok {
        return Err("Invalid Joomla admin username.".into());
    }
    if !is_strong_password(&input.admin_password) {
        return Err("The Joomla admin password must be at least 12 characters.".into());
    }
    if !input.admin_email.contains('@') || !has_no_control_chars(&input.admin_email) {
        return Err("Invalid Joomla admin email.".into());
    }
    if !is_db_identifier(&input.db_name) || !is_db_identifier(&input.db_user) {
        return Err("Invalid Joomla database name or user.".into());
    }
    let prefix_ok = input.db_prefix.len() <= 15
        && input.db_prefix.ends_with('_')
        && input
            .db_prefix
            .chars()
            .next()
            .is_some_and(|c| c.is_ascii_alphabetic())
        && input
            .db_prefix
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_');
    if !prefix_ok {
        return Err("Invalid Joomla table prefix.".into());
    }
    if !is_db_host(&input.db_host) {
        return Err("Invalid Joomla database host.".into());
    }
    if !has_no_control_chars(&input.db_pass) {
        return Err("Invalid Joomla database password.".into());
    }

    Ok(vec![
        "install".into(),
        format!("--site-name={}", input.site_name.trim()),
        format!("--admin-user={}", input.admin_user.trim()),
        format!("--admin-username={}", input.admin_username),
        format!("--admin-password={}", input.admin_password),
        format!("--admin-email={}", input.admin_email.trim()),
        "--db-type=mysqli".into(),
        format!("--db-host={}", input.db_host),
        format!("--db-user={}", input.db_user),
        format!("--db-pass={}", input.db_pass),
        format!("--db-name={}", input.db_name),
        format!("--db-prefix={}", input.db_prefix),
        "--db-encryption=0".into(),
        "--no-interaction".into(),
        "--no-ansi".into(),
    ])
}

#[cfg(test)]
mod tests {
    use super::*;

    fn joomla() -> JoomlaInstall {
        JoomlaInstall {
            site_name: "Example Site".into(),
            admin_user: "Site Admin".into(),
            admin_username: "admin".into(),
            admin_password: "correct horse battery".into(),
            admin_email: "admin@example.com".into(),
            db_host: "127.0.0.1".into(),
            db_user: "ex_user".into(),
            db_pass: "p@ss'word\"$x".into(),
            db_name: "ex_db".into(),
            db_prefix: "jx4_".into(),
        }
    }

    #[test]
    fn arguments_keep_values_verbatim() {
        let args = arguments(&joomla()).unwrap();
        assert_eq!(args[0], "install");
        assert!(args.contains(&"--db-pass=p@ss'word\"$x".to_string()));
        assert!(args.contains(&"--db-prefix=jx4_".to_string()));
        assert!(args.contains(&"--no-interaction".to_string()));
    }

    #[test]
    fn arguments_reject_bad_input() {
        let mut input = joomla();
        input.admin_password = "short".into();
        assert!(arguments(&input).is_err());
        let mut input = joomla();
        input.db_prefix = "1x_".into();
        assert!(arguments(&input).is_err());
        let mut input = joomla();
        input.db_name = "db; drop".into();
        assert!(arguments(&input).is_err());
        let mut input = joomla();
        input.site_name = "a\nb".into();
        assert!(arguments(&input).is_err());
    }
}
