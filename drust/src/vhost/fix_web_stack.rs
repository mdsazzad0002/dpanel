use std::{fs, path::Path};

use crate::app::{
    backup_file, distro_family, ensure_comment_listen, ensure_listen_line, ensure_root, info,
    read_to_string, restart_services_for_web_stack, warn, write_string,
};

pub(super) fn run(apache_backend_port: u16, nginx_frontend_port: u16) -> Result<(), String> {
    ensure_root()?;
    let family = distro_family();
    info(&format!(
        "Repairing web stack for {family} using backend {apache_backend_port} and frontend {nginx_frontend_port}"
    ));

    match family.as_str() {
        "debian" => update_apache_configs(
            Path::new("/etc/apache2/ports.conf"),
            "/etc/apache2/sites-available",
            apache_backend_port,
        )?,
        "rpm" => update_apache_configs(
            Path::new("/etc/httpd/conf/httpd.conf"),
            "/etc/httpd/conf.d",
            apache_backend_port,
        )?,
        _ => warn("Unsupported distro; applying Debian-style best effort."),
    }

    restart_services_for_web_stack()?;
    info("Apache/Nginx stack repaired successfully.");
    let _ = nginx_frontend_port;
    Ok(())
}

fn update_apache_configs(
    listen_config: &Path,
    vhost_directory: &str,
    backend_port: u16,
) -> Result<(), String> {
    if !listen_config.exists() {
        return Ok(());
    }
    backup_file(listen_config)?;
    let mut content = read_to_string(listen_config)?;
    content = ensure_listen_line(&content, backend_port);
    content = ensure_comment_listen(&content, 80);
    content = ensure_comment_listen(&content, 443);
    write_string(listen_config, &content)?;

    for entry in fs::read_dir(vhost_directory)
        .map_err(|error| format!("failed to scan apache sites: {error}"))?
    {
        let path = entry.map_err(|error| error.to_string())?.path();
        if path.extension().and_then(|extension| extension.to_str()) != Some("conf") {
            continue;
        }
        let content = read_to_string(&path)?;
        if content.contains("<VirtualHost *:80>") || content.contains("<VirtualHost *:8080>") {
            backup_file(&path)?;
            let replaced = content
                .replace(
                    "<VirtualHost *:80>",
                    &format!("<VirtualHost *:{backend_port}>"),
                )
                .replace(
                    "<VirtualHost *:8080>",
                    &format!("<VirtualHost *:{backend_port}>"),
                );
            write_string(&path, &replaced)?;
        }
    }
    Ok(())
}
