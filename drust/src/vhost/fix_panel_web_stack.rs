use std::{fs, path::Path};

use crate::app::{
    detect_app_root, ensure_root, info, normalize_php_version, panel_aliases_for,
    remove_legacy_panel_vhosts, run_status, write_string,
};

use super::options::Panel;

pub(super) fn run(options: Panel) -> Result<(), String> {
    ensure_root()?;
    let app_root = detect_app_root(options.app_dir.as_deref())?;
    remove_legacy_panel_vhosts();

    let apache_dir = Path::new("/etc/apache2/sites-available");
    let nginx_available = Path::new("/etc/nginx/sites-available");
    let nginx_enabled = Path::new("/etc/nginx/sites-enabled");
    for directory in [apache_dir, nginx_available, nginx_enabled] {
        fs::create_dir_all(directory)
            .map_err(|error| format!("failed to create {}: {error}", directory.display()))?;
    }

    let aliases = panel_aliases_for(&options.domain, &options.aliases, options.no_www);
    let apache = format!(
        "\
<VirtualHost *:{backend_port}>
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
        backend_port = options.backend_port,
        domain = options.domain,
        aliases = aliases,
        app_root = app_root.display(),
        php_version = normalize_php_version(&options.php_version, "8.3")
    );
    let apache_path = apache_dir.join(&options.conf_name);
    write_string(&apache_path, &apache)?;
    let _ = run_status(
        "a2enmod",
        &["proxy", "proxy_fcgi", "setenvif", "rewrite", "headers"],
    );
    let _ = run_status("a2ensite", &[&options.conf_name]);
    let _ = run_status("a2dissite", &["000-default.conf"]);

    let nginx = format!(
        "\
server {{
    listen {frontend_port};
    listen [::]:{frontend_port};
    server_name {domain} {aliases};
    client_max_body_size {body_size};

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
        frontend_port = options.frontend_port,
        domain = options.domain,
        aliases = aliases,
        body_size = options.client_max_body_size,
        backend_port = options.backend_port
    );
    let nginx_path = nginx_available.join(&options.conf_name);
    write_string(&nginx_path, &nginx)?;
    let nginx_link = nginx_enabled.join(&options.conf_name);
    let _ = fs::remove_file(&nginx_link);
    #[cfg(unix)]
    {
        use std::os::unix::fs::symlink;
        let _ = symlink(&nginx_path, &nginx_link);
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
        options.domain,
        app_root.display()
    ));
    let _ = options.panel_port;
    let _ = options.phpmyadmin_port;
    Ok(())
}
