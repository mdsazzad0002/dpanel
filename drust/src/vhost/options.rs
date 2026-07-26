use std::env;

use crate::app::{parse_port, split_aliases};

use super::common::normalize_body_size;

pub(super) struct Panel {
    pub(super) domain: String,
    pub(super) backend_port: u16,
    pub(super) frontend_port: u16,
    pub(super) app_dir: Option<String>,
    pub(super) conf_name: String,
    pub(super) aliases: Vec<String>,
    pub(super) no_www: bool,
    pub(super) client_max_body_size: String,
    pub(super) panel_port: u16,
    pub(super) phpmyadmin_port: u16,
    pub(super) php_version: String,
}

impl Panel {
    pub(super) fn parse(args: Vec<String>) -> Result<Self, String> {
        let mut domain = env::var("PANEL_DOMAIN").unwrap_or_default();
        let mut backend_port = env_port("PANEL_BACKEND_PORT", 8080);
        let mut frontend_port = env_port("PANEL_FRONTEND_PORT", 80);
        let mut app_dir = env::var("PANEL_APP_DIR").ok();
        let mut conf_name = env::var("PANEL_CONF_NAME").unwrap_or_else(|_| "dpanel.conf".into());
        let mut aliases = Vec::new();
        let mut no_www = env_flag("PANEL_DISABLE_WWW_ALIAS");
        let mut client_max_body_size = "10G".to_string();
        let mut positional = Vec::new();
        let mut arguments = args.into_iter();

        while let Some(argument) = arguments.next() {
            match argument.as_str() {
                "--alias" => aliases.push(value(&mut arguments, "--alias")?.trim().to_string()),
                "--aliases" => {
                    aliases.extend(split_aliases(&value(&mut arguments, "--aliases")?));
                }
                "--backend-port" => {
                    backend_port =
                        parse_port(&value(&mut arguments, "--backend-port")?).unwrap_or(8080);
                }
                "--frontend-port" => {
                    frontend_port =
                        parse_port(&value(&mut arguments, "--frontend-port")?).unwrap_or(80);
                }
                "--app-dir" => app_dir = Some(value(&mut arguments, "--app-dir")?),
                "--conf-name" => conf_name = value(&mut arguments, "--conf-name")?,
                "--no-www" => no_www = true,
                "--client-max-body-size" => {
                    client_max_body_size = value(&mut arguments, "--client-max-body-size")?;
                }
                other if other.starts_with('-') => return Err(format!("Unknown option: {other}")),
                other => positional.push(other.to_string()),
            }
        }

        if domain.is_empty() {
            domain = positional
                .first()
                .cloned()
                .unwrap_or_else(|| "installer.localhost".into());
        }
        if positional.len() >= 2 && backend_port == 8080 {
            backend_port = parse_port(&positional[1]).unwrap_or(8080);
        }
        if positional.len() >= 3 && frontend_port == 80 {
            frontend_port = parse_port(&positional[2]).unwrap_or(80);
        }
        if positional.len() > 3 {
            aliases.extend(positional[3..].to_vec());
        }

        Ok(Self {
            domain,
            backend_port,
            frontend_port,
            app_dir,
            conf_name,
            aliases,
            no_www,
            client_max_body_size: normalize_body_size(&client_max_body_size)?,
            panel_port: env_port("PANEL_PORT", frontend_port),
            phpmyadmin_port: env_port("PHPMYADMIN_PORT", frontend_port),
            php_version: env::var("PHP_VERSION").unwrap_or_else(|_| "8.3".into()),
        })
    }
}

pub(super) struct Sync {
    pub(super) domain: String,
    pub(super) root_path: String,
    pub(super) php_version: String,
    pub(super) old_domain: Option<String>,
    pub(super) aliases: Vec<String>,
    pub(super) no_www: bool,
    pub(super) client_max_body_size: String,
    pub(super) panel_port: u16,
    pub(super) apache_backend_port: u16,
    pub(super) nginx_primary_port: u16,
    pub(super) phpmyadmin_port: u16,
}

impl Sync {
    pub(super) fn parse(args: Vec<String>) -> Result<Self, String> {
        let mut aliases = Vec::new();
        let mut no_www = env_flag("PANEL_DISABLE_WWW_ALIAS");
        let mut client_max_body_size = "2G".to_string();
        let mut positional = Vec::new();
        let mut arguments = args.into_iter();

        while let Some(argument) = arguments.next() {
            match argument.as_str() {
                "--alias" => aliases.push(value(&mut arguments, "--alias")?.trim().to_string()),
                "--aliases" => {
                    aliases.extend(split_aliases(&value(&mut arguments, "--aliases")?));
                }
                "--no-www" => no_www = true,
                "--client-max-body-size" => {
                    client_max_body_size = value(&mut arguments, "--client-max-body-size")?;
                }
                other if other.starts_with('-') => return Err(format!("Unknown option: {other}")),
                other => positional.push(other.to_string()),
            }
        }

        if positional.len() < 3 {
            return Err("Usage: sync-vhost <action> <domain> <root-path> [php-version] [old-domain] [alias...]".into());
        }
        if positional.len() > 5 {
            aliases.extend(positional[5..].to_vec());
        }

        Ok(Self {
            domain: positional[1].clone(),
            root_path: positional[2].clone(),
            php_version: positional.get(3).cloned().unwrap_or_else(|| "8.3".into()),
            old_domain: positional.get(4).cloned(),
            aliases,
            no_www,
            client_max_body_size: normalize_body_size(&client_max_body_size)?,
            panel_port: env_port("PANEL_PORT", 80),
            apache_backend_port: env_port("APACHE_BACKEND_PORT", 8080),
            nginx_primary_port: env_port("NGINX_PRIMARY_PORT", 80),
            phpmyadmin_port: env_port("PHPMYADMIN_PORT", 80),
        })
    }
}

fn env_port(name: &str, fallback: u16) -> u16 {
    env::var(name)
        .ok()
        .and_then(|value| parse_port(&value))
        .unwrap_or(fallback)
}

fn env_flag(name: &str) -> bool {
    env::var(name)
        .ok()
        .is_some_and(|value| value == "1" || value.eq_ignore_ascii_case("true"))
}

fn value(arguments: &mut impl Iterator<Item = String>, option: &str) -> Result<String, String> {
    arguments
        .next()
        .ok_or_else(|| format!("Missing value for {option}"))
}
