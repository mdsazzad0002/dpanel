use std::{fs, path::PathBuf, process::Command};

use super::process::{program_exists, run_status};

fn os_release_value(key: &str) -> Option<String> {
    let data = fs::read_to_string("/etc/os-release").ok()?;
    data.lines().find_map(|line| {
        let line = line.trim();
        if line.starts_with('#') || line.is_empty() {
            return None;
        }
        line.strip_prefix(&format!("{key}="))
            .map(|value| value.trim_matches('"').to_string())
    })
}

pub fn distro_family() -> String {
    match os_release_value("ID").as_deref() {
        Some("ubuntu") | Some("debian") => "debian".to_string(),
        Some("rocky") | Some("almalinux") | Some("rhel") | Some("centos") | Some("fedora") => {
            "rpm".to_string()
        }
        _ => "unknown".to_string(),
    }
}

fn apache_service_name() -> Option<&'static str> {
    if Command::new("systemctl")
        .args(["cat", "apache2.service"])
        .status()
        .ok()
        .is_some_and(|status| status.success())
    {
        Some("apache2")
    } else if Command::new("systemctl")
        .args(["cat", "httpd.service"])
        .status()
        .ok()
        .is_some_and(|status| status.success())
    {
        Some("httpd")
    } else {
        None
    }
}

fn nginx_service_available() -> bool {
    Command::new("systemctl")
        .args(["cat", "nginx.service"])
        .status()
        .ok()
        .is_some_and(|status| status.success())
        || program_exists("nginx")
}

pub fn restart_services_for_web_stack() -> Result<(), String> {
    match apache_service_name() {
        Some("apache2") => {
            run_status("apache2ctl", &["-t"])?;
            let _ = run_status("systemctl", &["enable", "apache2"]);
            run_status("systemctl", &["restart", "apache2"])?;
        }
        Some("httpd") => {
            run_status("httpd", &["-t"])?;
            let _ = run_status("systemctl", &["enable", "httpd"]);
            run_status("systemctl", &["restart", "httpd"])?;
        }
        _ => {}
    }

    if nginx_service_available() {
        run_status("nginx", &["-t"])?;
        let _ = run_status("systemctl", &["enable", "nginx"]);
        run_status("systemctl", &["restart", "nginx"])?;
    }
    Ok(())
}

pub fn ensure_listen_line(content: &str, port: u16) -> String {
    let needle = format!("Listen {port}");
    if content.lines().any(|line| line.trim() == needle) {
        return content.to_string();
    }

    let mut output = content.to_string();
    if !output.ends_with('\n') {
        output.push('\n');
    }
    output.push_str(&needle);
    output.push('\n');
    output
}

pub fn ensure_comment_listen(content: &str, port: u16) -> String {
    content
        .lines()
        .map(|line| {
            if line.trim_start() == format!("Listen {port}") {
                format!("# Listen {port}")
            } else {
                line.to_string()
            }
        })
        .collect::<Vec<_>>()
        .join("\n")
        + "\n"
}

pub fn parse_port(value: &str) -> Option<u16> {
    value.parse::<u16>().ok().filter(|port| *port >= 1)
}

pub fn detect_app_root(explicit: Option<&str>) -> Result<PathBuf, String> {
    if let Some(candidate) = explicit {
        let path = PathBuf::from(candidate);
        if path.join("public/index.php").exists() {
            return Ok(path);
        }
    }

    for candidate in [
        "/var/www/dpanel",
        "/home/dpanel/likesoftbd_com/dpanel",
        "/var/www/ServerPanel",
        "/opt/likesoft/dpanel",
    ] {
        let path = PathBuf::from(candidate);
        if path.join("public/index.php").exists() {
            return Ok(path);
        }
    }

    Err(
        "Unable to detect panel app root. Set PANEL_APP_DIR to the Laravel project directory."
            .into(),
    )
}

pub fn remove_legacy_panel_vhosts() {
    for path in [
        "/etc/apache2/sites-available/dpanel.conf",
        "/etc/apache2/sites-enabled/dpanel.conf",
        "/etc/nginx/sites-available/dpanel.conf",
        "/etc/nginx/sites-enabled/dpanel.conf",
    ] {
        let _ = fs::remove_file(path);
    }
    let _ = run_status("a2dissite", &["dpanel.conf"]);
}
