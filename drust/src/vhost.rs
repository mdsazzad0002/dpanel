use std::env;
use std::fs;
use std::path::{Path, PathBuf};

use crate::app::{
    backup_file, conf_basename, detect_app_root, distro_family, ensure_comment_listen,
    ensure_listen_line, ensure_root, info, normalize_php_version, panel_aliases_for, parse_port,
    read_to_string, remove_legacy_panel_vhosts, restart_services_for_web_stack, run_status,
    split_aliases, warn, write_string,
};

fn normalize_body_size(value: &str) -> Result<String, String> {
    let value = value.trim().to_ascii_uppercase();
    let split_at = value
        .find(|c: char| !c.is_ascii_digit())
        .unwrap_or(value.len());
    let (number, suffix) = value.split_at(split_at);
    if number.is_empty()
        || number.parse::<u64>().unwrap_or(0) == 0
        || !matches!(suffix, "K" | "M" | "G" | "T")
    {
        return Err("Invalid client_max_body_size. Use a value such as 512M, 2G, or 3G.".into());
    }
    Ok(format!("{number}{suffix}"))
}

pub(crate) fn run_fix_web_stack(
    apache_backend_port: u16,
    nginx_frontend_port: u16,
) -> Result<(), String> {
    fix_web_stack(apache_backend_port, nginx_frontend_port)
}

pub(crate) fn run_fix_panel_web_stack(args: Vec<String>) -> Result<(), String> {
    let opts = PanelVhostOptions::parse(args)?;
    fix_panel_web_stack(opts)
}

pub(crate) fn run_sync_vhost(args: Vec<String>) -> Result<(), String> {
    let opts = SyncVhostOptions::parse(args)?;
    sync_vhost(opts)
}

fn fix_web_stack(apache_backend_port: u16, nginx_frontend_port: u16) -> Result<(), String> {
    ensure_root()?;

    let family = distro_family();
    info(&format!(
        "Repairing web stack for {family} using backend {apache_backend_port} and frontend {nginx_frontend_port}"
    ));

    match family.as_str() {
        "debian" => {
            let ports_conf = Path::new("/etc/apache2/ports.conf");
            if ports_conf.exists() {
                backup_file(ports_conf)?;
                let mut content = read_to_string(ports_conf)?;
                content = ensure_listen_line(&content, apache_backend_port);
                content = ensure_comment_listen(&content, 80);
                content = ensure_comment_listen(&content, 443);
                write_string(ports_conf, &content)?;

                for entry in fs::read_dir("/etc/apache2/sites-available")
                    .map_err(|e| format!("failed to scan apache sites: {e}"))?
                {
                    let path = entry.map_err(|e| e.to_string())?.path();
                    if path.extension().and_then(|s| s.to_str()) == Some("conf") {
                        let text = read_to_string(&path)?;
                        if text.contains("<VirtualHost *:80>")
                            || text.contains("<VirtualHost *:8080>")
                        {
                            backup_file(&path)?;
                            let replaced = text
                                .replace(
                                    "<VirtualHost *:80>",
                                    &format!("<VirtualHost *:{apache_backend_port}>"),
                                )
                                .replace(
                                    "<VirtualHost *:8080>",
                                    &format!("<VirtualHost *:{apache_backend_port}>"),
                                );
                            write_string(&path, &replaced)?;
                        }
                    }
                }
            }
        }
        "rpm" => {
            let conf = Path::new("/etc/httpd/conf/httpd.conf");
            if conf.exists() {
                backup_file(conf)?;
                let mut content = read_to_string(conf)?;
                content = ensure_listen_line(&content, apache_backend_port);
                content = ensure_comment_listen(&content, 80);
                content = ensure_comment_listen(&content, 443);
                write_string(conf, &content)?;

                for entry in fs::read_dir("/etc/httpd/conf.d")
                    .map_err(|e| format!("failed to scan httpd conf.d: {e}"))?
                {
                    let path = entry.map_err(|e| e.to_string())?.path();
                    if path.extension().and_then(|s| s.to_str()) == Some("conf") {
                        let text = read_to_string(&path)?;
                        if text.contains("<VirtualHost *:80>")
                            || text.contains("<VirtualHost *:8080>")
                        {
                            backup_file(&path)?;
                            let replaced = text
                                .replace(
                                    "<VirtualHost *:80>",
                                    &format!("<VirtualHost *:{apache_backend_port}>"),
                                )
                                .replace(
                                    "<VirtualHost *:8080>",
                                    &format!("<VirtualHost *:{apache_backend_port}>"),
                                );
                            write_string(&path, &replaced)?;
                        }
                    }
                }
            }
        }
        _ => {
            warn("Unsupported distro; applying Debian-style best effort.");
        }
    }

    restart_services_for_web_stack()?;
    info("Apache/Nginx stack repaired successfully.");
    let _ = nginx_frontend_port;
    Ok(())
}

#[derive(Clone)]
struct PanelVhostOptions {
    domain: String,
    backend_port: u16,
    frontend_port: u16,
    app_dir: Option<String>,
    conf_name: String,
    aliases: Vec<String>,
    no_www: bool,
    client_max_body_size: String,
    panel_port: u16,
    phpmyadmin_port: u16,
    php_version: String,
}

impl PanelVhostOptions {
    fn parse(args: Vec<String>) -> Result<Self, String> {
        let mut domain = env::var("PANEL_DOMAIN").unwrap_or_default();
        let mut backend_port = env::var("PANEL_BACKEND_PORT")
            .ok()
            .and_then(|v| parse_port(&v))
            .unwrap_or(8080);
        let mut frontend_port = env::var("PANEL_FRONTEND_PORT")
            .ok()
            .and_then(|v| parse_port(&v))
            .unwrap_or(80);
        let mut app_dir = env::var("PANEL_APP_DIR").ok();
        let mut conf_name = env::var("PANEL_CONF_NAME").unwrap_or_else(|_| "dpanel.conf".into());
        let mut aliases = Vec::new();
        let mut no_www = env::var("PANEL_DISABLE_WWW_ALIAS")
            .ok()
            .map(|v| v == "1" || v.eq_ignore_ascii_case("true"))
            .unwrap_or(false);
        // Filemanager uploads reach the panel host before Laravel resolves the
        // target website, so the panel gateway must not be lower than a site's
        // database-configured limit. Per-site vhosts still default to 2G.
        let mut client_max_body_size = "10G".to_string();
        let panel_port = env::var("PANEL_PORT")
            .ok()
            .and_then(|v| parse_port(&v))
            .unwrap_or(frontend_port);
        let phpmyadmin_port = env::var("PHPMYADMIN_PORT")
            .ok()
            .and_then(|v| parse_port(&v))
            .unwrap_or(frontend_port);
        let php_version = env::var("PHP_VERSION").unwrap_or_else(|_| "8.3".into());

        let mut positional = Vec::new();
        let mut iter = args.into_iter();
        while let Some(arg) = iter.next() {
            match arg.as_str() {
                "--alias" => {
                    let value = iter
                        .next()
                        .ok_or_else(|| "Missing value for --alias".to_string())?;
                    aliases.push(value.trim().to_string());
                }
                "--aliases" => {
                    let value = iter
                        .next()
                        .ok_or_else(|| "Missing value for --aliases".to_string())?;
                    aliases.extend(split_aliases(&value));
                }
                "--backend-port" => {
                    let value = iter
                        .next()
                        .ok_or_else(|| "Missing value for --backend-port".to_string())?;
                    backend_port = parse_port(&value).unwrap_or(8080);
                }
                "--frontend-port" => {
                    let value = iter
                        .next()
                        .ok_or_else(|| "Missing value for --frontend-port".to_string())?;
                    frontend_port = parse_port(&value).unwrap_or(80);
                }
                "--app-dir" => {
                    app_dir = Some(
                        iter.next()
                            .ok_or_else(|| "Missing value for --app-dir".to_string())?,
                    );
                }
                "--conf-name" => {
                    conf_name = iter
                        .next()
                        .ok_or_else(|| "Missing value for --conf-name".to_string())?;
                }
                "--no-www" => {
                    no_www = true;
                }
                "--client-max-body-size" => {
                    client_max_body_size = iter
                        .next()
                        .ok_or_else(|| "Missing value for --client-max-body-size".to_string())?;
                }
                other if other.starts_with('-') => {
                    return Err(format!("Unknown option: {other}"));
                }
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
            panel_port,
            phpmyadmin_port,
            php_version,
        })
    }
}

#[derive(Clone)]
struct SyncVhostOptions {
    domain: String,
    root_path: String,
    php_version: String,
    old_domain: Option<String>,
    aliases: Vec<String>,
    no_www: bool,
    client_max_body_size: String,
    panel_port: u16,
    apache_backend_port: u16,
    nginx_primary_port: u16,
    phpmyadmin_port: u16,
}

impl SyncVhostOptions {
    fn parse(args: Vec<String>) -> Result<Self, String> {
        let mut aliases = Vec::new();
        let mut no_www = env::var("PANEL_DISABLE_WWW_ALIAS")
            .ok()
            .map(|v| v == "1" || v.eq_ignore_ascii_case("true"))
            .unwrap_or(false);
        let mut client_max_body_size = "2G".to_string();
        let mut positional = Vec::new();
        let mut iter = args.into_iter();
        while let Some(arg) = iter.next() {
            match arg.as_str() {
                "--alias" => {
                    let value = iter
                        .next()
                        .ok_or_else(|| "Missing value for --alias".to_string())?;
                    aliases.push(value.trim().to_string());
                }
                "--aliases" => {
                    let value = iter
                        .next()
                        .ok_or_else(|| "Missing value for --aliases".to_string())?;
                    aliases.extend(split_aliases(&value));
                }
                "--no-www" => no_www = true,
                "--client-max-body-size" => {
                    client_max_body_size = iter
                        .next()
                        .ok_or_else(|| "Missing value for --client-max-body-size".to_string())?
                }
                other if other.starts_with('-') => {
                    return Err(format!("Unknown option: {other}"));
                }
                other => positional.push(other.to_string()),
            }
        }

        if positional.len() < 3 {
            return Err("Usage: sync-vhost <action> <domain> <root-path> [php-version] [old-domain] [alias...]".into());
        }

        let domain = positional[1].clone();
        let root_path = positional[2].clone();
        let php_version = positional.get(3).cloned().unwrap_or_else(|| "8.3".into());
        let old_domain = positional.get(4).cloned();
        if positional.len() > 5 {
            aliases.extend(positional[5..].to_vec());
        }

        let panel_port = env::var("PANEL_PORT")
            .ok()
            .and_then(|v| parse_port(&v))
            .unwrap_or(80);
        let apache_backend_port = env::var("APACHE_BACKEND_PORT")
            .ok()
            .and_then(|v| parse_port(&v))
            .unwrap_or(8080);
        let nginx_primary_port = env::var("NGINX_PRIMARY_PORT")
            .ok()
            .and_then(|v| parse_port(&v))
            .unwrap_or(80);
        let phpmyadmin_port = env::var("PHPMYADMIN_PORT")
            .ok()
            .and_then(|v| parse_port(&v))
            .unwrap_or(80);

        Ok(Self {
            domain,
            root_path,
            php_version,
            old_domain,
            aliases,
            no_www,
            client_max_body_size: normalize_body_size(&client_max_body_size)?,
            panel_port,
            apache_backend_port,
            nginx_primary_port,
            phpmyadmin_port,
        })
    }
}

fn fix_panel_web_stack(opts: PanelVhostOptions) -> Result<(), String> {
    ensure_root()?;
    let app_root = detect_app_root(opts.app_dir.as_deref())?;
    remove_legacy_panel_vhosts();

    let apache_conf_dir = Path::new("/etc/apache2/sites-available");
    let nginx_conf_available = Path::new("/etc/nginx/sites-available");
    let nginx_conf_enabled = Path::new("/etc/nginx/sites-enabled");
    fs::create_dir_all(apache_conf_dir)
        .map_err(|e| format!("failed to create apache conf dir: {e}"))?;
    fs::create_dir_all(nginx_conf_available)
        .map_err(|e| format!("failed to create nginx conf dir: {e}"))?;
    fs::create_dir_all(nginx_conf_enabled)
        .map_err(|e| format!("failed to create nginx enabled dir: {e}"))?;

    let port = opts.backend_port;
    let alias_list = panel_aliases_for(&opts.domain, &opts.aliases, opts.no_www);
    let apache_conf = format!(
        "\
<VirtualHost *:{port}>
    ServerName {domain}
    ServerAlias {aliases}
    DocumentRoot {app_root}/public

    <Directory {app_root}/public>
        AllowOverride All
        Require all granted
        Options FollowSymlinks
        FallbackResource /index.php
    </Directory>

    DirectoryIndex index.php index.html index.htm

    <FilesMatch \\.php$>
        SetHandler \"proxy:unix:/run/php/php{php_version}-fpm.sock|fcgi://localhost/\"
    </FilesMatch>

    ErrorLog ${{APACHE_LOG_DIR}}/serverpanel_panel_error.log
    CustomLog ${{APACHE_LOG_DIR}}/serverpanel_panel_access.log combined
</VirtualHost>
",
        port = port,
        domain = opts.domain,
        aliases = alias_list,
        app_root = app_root.display(),
        php_version = normalize_php_version(&opts.php_version, "8.3")
    );

    let apache_conf_path = apache_conf_dir.join(&opts.conf_name);
    write_string(&apache_conf_path, &apache_conf)?;
    let _ = run_status(
        "a2enmod",
        &["proxy", "proxy_fcgi", "setenvif", "rewrite", "headers"],
    );
    let _ = run_status("a2ensite", &[&opts.conf_name]);
    let _ = run_status("a2dissite", &["000-default.conf"]);

    let nginx_conf = format!(
        "\
server {{
    listen {frontend_port};
    listen [::]:{frontend_port};
    server_name {domain} {aliases};
    client_max_body_size {client_max_body_size};

    location / {{
        proxy_pass http://127.0.0.1:{backend_port};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }}
}}
",
        frontend_port = opts.frontend_port,
        domain = opts.domain,
        aliases = alias_list,
        backend_port = port,
        client_max_body_size = opts.client_max_body_size
    );
    let nginx_conf_path = nginx_conf_available.join(&opts.conf_name);
    write_string(&nginx_conf_path, &nginx_conf)?;
    let nginx_link = nginx_conf_enabled.join(&opts.conf_name);
    let _ = fs::remove_file(&nginx_link);
    #[cfg(unix)]
    {
        use std::os::unix::fs::symlink;
        let _ = symlink(&nginx_conf_path, &nginx_link);
    }
    let _ = fs::remove_file("/etc/nginx/sites-enabled/default");

    run_status("apache2ctl", &["-t"]).or_else(|_| run_status("httpd", &["-t"]))?;
    run_status("nginx", &["-t"])?;
    let _ = run_status("systemctl", &["enable", "apache2"]);
    let _ = run_status("systemctl", &["enable", "nginx"]);
    let _ = run_status("systemctl", &["restart", "apache2"]);
    let _ = run_status("systemctl", &["restart", "nginx"]);

    info(&format!(
        "Panel web stack fixed for {} using {}/public.",
        opts.domain,
        app_root.display()
    ));
    let _ = opts.phpmyadmin_port;
    let _ = opts.panel_port;
    Ok(())
}

fn sync_vhost(opts: SyncVhostOptions) -> Result<(), String> {
    ensure_root()?;
    let app_root = PathBuf::from(&opts.root_path);
    if !app_root.exists() {
        return Err(format!("Path does not exist: {}", app_root.display()));
    }

    let aliases = panel_aliases_for(&opts.domain, &opts.aliases, opts.no_www);
    let conf_name = format!("{}.conf", conf_basename(&opts.domain));
    let apache_path = Path::new("/etc/apache2/sites-available").join(&conf_name);
    let nginx_path = Path::new("/etc/nginx/sites-available").join(&conf_name);
    let nginx_enabled = Path::new("/etc/nginx/sites-enabled").join(&conf_name);

    fs::create_dir_all("/etc/apache2/sites-available")
        .map_err(|e| format!("failed to create apache dir: {e}"))?;
    fs::create_dir_all("/etc/nginx/sites-available")
        .map_err(|e| format!("failed to create nginx dir: {e}"))?;
    fs::create_dir_all("/etc/nginx/sites-enabled")
        .map_err(|e| format!("failed to create nginx enabled dir: {e}"))?;

    remove_duplicate_domain_vhosts("/etc/apache2/sites-available", &opts.domain, &apache_path);
    remove_duplicate_domain_vhosts("/etc/apache2/sites-enabled", &opts.domain, &apache_path);
    remove_duplicate_domain_vhosts("/etc/nginx/sites-available", &opts.domain, &nginx_path);
    remove_duplicate_domain_vhosts("/etc/nginx/sites-enabled", &opts.domain, &nginx_enabled);

    let apache = format!(
        "\
<VirtualHost *:{apache_backend_port}>
    ServerName {domain}
    ServerAlias {aliases}
    DocumentRoot {root}

    <Directory {root}>
        AllowOverride All
        Require all granted
        Options FollowSymlinks
        FallbackResource /index.php
    </Directory>

    DirectoryIndex index.php index.html index.htm

    <FilesMatch \\.php$>
        SetHandler \"proxy:unix:/run/php/php{php_version}-fpm.sock|fcgi://localhost/\"
    </FilesMatch>
</VirtualHost>
",
        apache_backend_port = opts.apache_backend_port,
        domain = opts.domain,
        aliases = aliases,
        root = app_root.display(),
        php_version = normalize_php_version(&opts.php_version, "8.3")
    );
    write_string(&apache_path, &apache)?;

    let certificate_path = format!("/etc/letsencrypt/live/{}/fullchain.pem", opts.domain);
    let private_key_path = format!("/etc/letsencrypt/live/{}/privkey.pem", opts.domain);
    let tls_listeners = if Path::new(&certificate_path).is_file()
        && Path::new(&private_key_path).is_file()
    {
        format!(
            "    listen 443 ssl;\n    listen [::]:443 ssl;\n    ssl_certificate {certificate_path};\n    ssl_certificate_key {private_key_path};\n"
        )
    } else {
        String::new()
    };

    let nginx = format!(
        "\
server {{
    listen {nginx_primary_port};
    listen [::]:{nginx_primary_port};
{tls_listeners}    
    server_name {domain} {aliases};
    client_max_body_size {client_max_body_size};

    location / {{
        proxy_pass http://127.0.0.1:{apache_backend_port};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }}
}}
",
        nginx_primary_port = opts.nginx_primary_port,
        tls_listeners = tls_listeners,
        domain = opts.domain,
        aliases = aliases,
        apache_backend_port = opts.apache_backend_port,
        client_max_body_size = opts.client_max_body_size
    );
    write_string(&nginx_path, &nginx)?;
    let _ = fs::remove_file(&nginx_enabled);
    #[cfg(unix)]
    {
        use std::os::unix::fs::symlink;
        let _ = symlink(&nginx_path, &nginx_enabled);
    }

    let _ = run_status("a2ensite", &[&conf_name]);
    run_status("nginx", &["-t"])?;
    run_status("apache2ctl", &["-t"])?;
    run_status("systemctl", &["reload", "apache2"])?;
    run_status("systemctl", &["reload", "nginx"])?;

    info(&format!(
        "Vhost synchronized for {} -> {}",
        opts.domain,
        app_root.display()
    ));
    let _ = opts.panel_port;
    let _ = opts.phpmyadmin_port;
    let _ = opts.old_domain;
    Ok(())
}

fn remove_duplicate_domain_vhosts(directory: &str, domain: &str, keep: &Path) {
    let Ok(entries) = fs::read_dir(directory) else {
        return;
    };
    let apache_marker = format!("ServerName {domain}");
    let nginx_marker = format!("server_name {domain} ");

    for entry in entries.flatten() {
        let path = entry.path();
        if path == keep {
            continue;
        }
        if path.is_symlink() && fs::canonicalize(&path).is_err() {
            let _ = fs::remove_file(&path);
            info(&format!("removed broken vhost symlink: {}", path.display()));
            continue;
        }
        let Ok(content) = fs::read_to_string(&path) else {
            continue;
        };
        if content.lines().any(|line| {
            let line = line.trim();
            line == apache_marker || line.starts_with(&nginx_marker)
        }) {
            let _ = fs::remove_file(&path);
            info(&format!("removed duplicate vhost: {}", path.display()));
        }
    }
}
