use std::{fs, path::Path, path::PathBuf};

use crate::app::{
    conf_basename, ensure_root, info, normalize_php_version, panel_aliases_for, run_status,
    write_string,
};

use super::{common::remove_duplicate_domain_vhosts, options::Sync};

pub(super) fn run(options: Sync) -> Result<(), String> {
    ensure_root()?;
    let app_root = PathBuf::from(&options.root_path);
    if !app_root.exists() {
        return Err(format!("Path does not exist: {}", app_root.display()));
    }

    let aliases = panel_aliases_for(&options.domain, &options.aliases, options.no_www);
    let conf_name = format!("{}.conf", conf_basename(&options.domain));
    let apache_path = Path::new("/etc/apache2/sites-available").join(&conf_name);
    let nginx_path = Path::new("/etc/nginx/sites-available").join(&conf_name);
    let nginx_enabled = Path::new("/etc/nginx/sites-enabled").join(&conf_name);
    for directory in [
        "/etc/apache2/sites-available",
        "/etc/nginx/sites-available",
        "/etc/nginx/sites-enabled",
    ] {
        fs::create_dir_all(directory)
            .map_err(|error| format!("failed to create {directory}: {error}"))?;
    }

    remove_duplicate_domain_vhosts(
        "/etc/apache2/sites-available",
        &options.domain,
        &apache_path,
    );
    remove_duplicate_domain_vhosts("/etc/apache2/sites-enabled", &options.domain, &apache_path);
    remove_duplicate_domain_vhosts("/etc/nginx/sites-available", &options.domain, &nginx_path);
    remove_duplicate_domain_vhosts("/etc/nginx/sites-enabled", &options.domain, &nginx_enabled);

    let apache = format!(
        "\
<VirtualHost *:{apache_port}>
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
        apache_port = options.apache_backend_port,
        domain = options.domain,
        aliases = aliases,
        root = app_root.display(),
        php_version = normalize_php_version(&options.php_version, "8.3")
    );
    write_string(&apache_path, &apache)?;

    let certificate = format!("/etc/letsencrypt/live/{}/fullchain.pem", options.domain);
    let private_key = format!("/etc/letsencrypt/live/{}/privkey.pem", options.domain);
    let tls = if Path::new(&certificate).is_file() && Path::new(&private_key).is_file() {
        format!(
            "    listen 443 ssl;\n    listen [::]:443 ssl;\n    ssl_certificate {certificate};\n    ssl_certificate_key {private_key};\n"
        )
    } else {
        String::new()
    };
    let nginx = format!(
        "\
server {{
    listen {nginx_port};
    listen [::]:{nginx_port};
{tls}    server_name {domain} {aliases};
    client_max_body_size {body_size};

    location / {{
        proxy_pass http://127.0.0.1:{apache_port};
        proxy_http_version 1.1;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }}
}}
",
        nginx_port = options.nginx_primary_port,
        tls = tls,
        domain = options.domain,
        aliases = aliases,
        apache_port = options.apache_backend_port,
        body_size = options.client_max_body_size
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
        options.domain,
        app_root.display()
    ));
    let _ = options.panel_port;
    let _ = options.phpmyadmin_port;
    let _ = options.old_domain;
    Ok(())
}
