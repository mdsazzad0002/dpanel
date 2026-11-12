//! PrestaShop: the panel unpacks the shop into the site root, then
//! `install/index_cli.php` installs it. The panel removes install/ and
//! renames admin/ afterwards.

use serde::Deserialize;

use super::service::{
    Permissions, Site, has_no_control_chars, is_db_host, is_db_identifier, is_hostname,
    is_strong_password,
};

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &[
        "var",
        "img",
        "upload",
        "download",
        "cache",
        "config",
        "app/config",
        "translations",
        "mails",
        "modules",
        "themes",
    ],
    secret_config: Some("app/config/parameters.php"),
};

#[derive(Deserialize)]
pub(crate) struct PrestashopInstall {
    domain: String,
    ssl: bool,
    shop_name: String,
    firstname: String,
    lastname: String,
    email: String,
    password: String,
    db_server: String,
    db_user: String,
    db_password: String,
    db_name: String,
    db_prefix: String,
}

pub(super) async fn install(site: &Site, input: &PrestashopInstall) -> Result<String, String> {
    let install_dir = site.target().join("install");
    if !install_dir.join("index_cli.php").is_file() {
        return Err("PrestaShop install/index_cli.php was not found in the site root.".into());
    }
    let args = arguments(input)?;
    site.php_script(&install_dir, "index_cli.php", "index_cli.php", &args)
        .await
}

fn is_name(value: &str) -> bool {
    !value.trim().is_empty()
        && value.len() <= 64
        && has_no_control_chars(value)
        && !value
            .chars()
            .any(|c| c.is_ascii_digit() || "!<>,;?=+()@#\"°{}_$%:¤|".contains(c))
}

/// `index_cli.php` options (`--name=value`); secrets travel on stdin.
fn arguments(input: &PrestashopInstall) -> Result<Vec<String>, String> {
    if !is_hostname(&input.domain) {
        return Err("Invalid PrestaShop domain.".into());
    }
    if input.shop_name.trim().is_empty()
        || input.shop_name.len() > 64
        || !has_no_control_chars(&input.shop_name)
        || input.shop_name.chars().any(|c| "<>;=#{}".contains(c))
    {
        return Err("Invalid PrestaShop shop name.".into());
    }
    if !is_name(&input.firstname) || !is_name(&input.lastname) {
        return Err("Invalid PrestaShop admin name.".into());
    }
    if !input.email.contains('@') || !has_no_control_chars(&input.email) {
        return Err("Invalid PrestaShop admin email.".into());
    }
    if !is_strong_password(&input.password) || input.password.len() > 72 {
        return Err("The PrestaShop admin password must be 12 to 72 characters.".into());
    }
    if !is_db_identifier(&input.db_name) || !is_db_identifier(&input.db_user) {
        return Err("Invalid PrestaShop database name or user.".into());
    }
    if !is_db_host(&input.db_server) || !has_no_control_chars(&input.db_password) {
        return Err("Invalid PrestaShop database host or password.".into());
    }
    let prefix_ok = input.db_prefix.len() <= 15
        && input.db_prefix.ends_with('_')
        && input.db_prefix.starts_with(|c: char| c.is_ascii_lowercase())
        && input
            .db_prefix
            .chars()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_');
    if !prefix_ok {
        return Err("Invalid PrestaShop table prefix.".into());
    }

    Ok(vec![
        format!("--domain={}", input.domain),
        format!("--ssl={}", u8::from(input.ssl)),
        format!("--name={}", input.shop_name.trim()),
        format!("--firstname={}", input.firstname.trim()),
        format!("--lastname={}", input.lastname.trim()),
        format!("--email={}", input.email.trim()),
        format!("--password={}", input.password),
        format!("--db_server={}", input.db_server),
        format!("--db_user={}", input.db_user),
        format!("--db_password={}", input.db_password),
        format!("--db_name={}", input.db_name),
        format!("--prefix={}", input.db_prefix),
        "--db_create=0".into(),
        "--language=en".into(),
        "--country=us".into(),
        "--newsletter=0".into(),
        "--send_email=0".into(),
    ])
}

#[cfg(test)]
mod tests {
    use super::*;

    fn shop() -> PrestashopInstall {
        PrestashopInstall {
            domain: "shop.example.com".into(),
            ssl: true,
            shop_name: "My Shop".into(),
            firstname: "Jane".into(),
            lastname: "Doe".into(),
            email: "jane@example.com".into(),
            password: "correct horse battery".into(),
            db_server: "127.0.0.1:3306".into(),
            db_user: "ps_user".into(),
            db_password: "it's $ecret".into(),
            db_name: "ps_db".into(),
            db_prefix: "psx1_".into(),
        }
    }

    #[test]
    fn arguments_keep_values_verbatim() {
        let args = arguments(&shop()).unwrap();
        assert!(args.contains(&"--db_password=it's $ecret".to_string()));
        assert!(args.contains(&"--ssl=1".to_string()));
        assert!(args.contains(&"--prefix=psx1_".to_string()));
    }

    #[test]
    fn arguments_reject_bad_input() {
        let mut input = shop();
        input.firstname = "Jane2".into();
        assert!(arguments(&input).is_err());
        let mut input = shop();
        input.domain = "shop example".into();
        assert!(arguments(&input).is_err());
        let mut input = shop();
        input.db_prefix = "PS_".into();
        assert!(arguments(&input).is_err());
        let mut input = shop();
        input.password = "x".repeat(73);
        assert!(arguments(&input).is_err());
    }
}
