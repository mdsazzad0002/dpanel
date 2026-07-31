use std::fs;
use std::path::PathBuf;

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
        "/home/dpanel/dengrweb_com/dpanel",
        "/var/www/ServerPanel",
        "/opt/dengrweb/dpanel",
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
