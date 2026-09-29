//! Drupal: `drupal/recommended-project`, then Drush, then `drush site:install`.
//! Database credentials are not part of this: the panel writes them into
//! settings.php first, and Drush reads them from there.

use serde::Deserialize;

use super::service::{
    Permissions, SHORT_TIMEOUT, Site, has_no_control_chars, is_strong_password,
};

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &["web/sites/default/files"],
    secret_config: Some("web/sites/default/settings.php"),
};

/// Drupal's scaffold plugin creates web/index.php and friends from Composer
/// script events, so create-project must run scripts.
pub(super) const RUNS_COMPOSER_SCRIPTS: bool = true;

/// Loads Drush with its arguments read from stdin, so the admin password
/// stays out of argv.
const DRUSH_BOOTSTRAP: &str = r#"$a = json_decode(stream_get_contents(STDIN), true);
if (!is_array($a)) { fwrite(STDERR, "Invalid installer input.\n"); exit(1); }
$_SERVER['argv'] = $argv = array_merge(['vendor/bin/drush'], $a);
$_SERVER['argc'] = $argc = count($argv);
$_composer_autoload_path = getcwd() . '/vendor/autoload.php';
require 'vendor/drush/drush/drush.php';"#;

/// Answers for `drush site:install`.
#[derive(Deserialize)]
pub(crate) struct DrupalInstall {
    site_name: String,
    account_name: String,
    account_pass: String,
    account_mail: String,
}

pub(super) fn package(version: &str) -> Result<(&'static str, &'static str), String> {
    match version {
        "11" => Ok(("drupal/recommended-project", "^11.0")),
        "10" => Ok(("drupal/recommended-project", "^10.0")),
        _ => Err("Unsupported Drupal version.".into()),
    }
}

pub(super) async fn require_drush(site: &Site, version: &str) -> Result<String, String> {
    if !site.has_file("web/core/lib/Drupal.php") {
        return Err("Drupal core was not found under web/.".into());
    }
    let constraint = match version {
        "11" => "drush/drush:^13",
        "10" => "drush/drush:^12.5 || ^13",
        _ => return Err("Unsupported Drupal version.".into()),
    };
    site.composer_require(constraint, false).await
}

pub(super) async fn site_install(site: &Site, input: &DrupalInstall) -> Result<String, String> {
    if !site.has_file("vendor/drush/drush/drush.php") {
        return Err("Drush was not found in vendor/.".into());
    }
    let stdin = serde_json::to_vec(&arguments(input)?).map_err(|e| e.to_string())?;
    site.php_with_input(site.target(), &["-r", DRUSH_BOOTSTRAP], SHORT_TIMEOUT, &stdin)
        .await
}

/// `drush site:install` arguments. Validated here as well as in the panel.
fn arguments(input: &DrupalInstall) -> Result<Vec<String>, String> {
    if input.site_name.trim().is_empty()
        || !has_no_control_chars(&input.site_name)
        || input.site_name.len() > 200
    {
        return Err("Invalid Drupal site name.".into());
    }
    let name_ok = !input.account_name.is_empty()
        && input.account_name.len() <= 60
        && input
            .account_name
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.' | '@'));
    if !name_ok {
        return Err("Invalid Drupal admin username.".into());
    }
    if !is_strong_password(&input.account_pass) {
        return Err("The Drupal admin password must be at least 12 characters.".into());
    }
    if !input.account_mail.contains('@')
        || !has_no_control_chars(&input.account_mail)
        || input.account_mail.len() > 190
    {
        return Err("Invalid Drupal admin email.".into());
    }

    Ok(vec![
        "site:install".into(),
        "standard".into(),
        format!("--site-name={}", input.site_name.trim()),
        format!("--site-mail={}", input.account_mail.trim()),
        format!("--account-name={}", input.account_name),
        format!("--account-pass={}", input.account_pass),
        format!("--account-mail={}", input.account_mail.trim()),
        "--yes".into(),
        "--no-interaction".into(),
        "--no-ansi".into(),
    ])
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn arguments_validate_and_keep_values() {
        let input = DrupalInstall {
            site_name: "Example".into(),
            account_name: "admin".into(),
            account_pass: "it's a $ecret pass".into(),
            account_mail: "a@example.com".into(),
        };
        let args = arguments(&input).unwrap();
        assert_eq!(&args[..2], ["site:install", "standard"]);
        assert!(args.contains(&"--account-pass=it's a $ecret pass".to_string()));
        assert!(
            arguments(&DrupalInstall {
                account_pass: "short".into(),
                ..input
            })
            .is_err()
        );
    }

    #[test]
    fn supported_versions() {
        assert_eq!(package("11").unwrap(), ("drupal/recommended-project", "^11.0"));
        assert!(package("9").is_err());
    }
}
