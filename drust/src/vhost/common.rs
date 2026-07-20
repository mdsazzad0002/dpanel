use std::{fs, path::Path};

use crate::app::info;

pub(super) fn normalize_body_size(value: &str) -> Result<String, String> {
    let value = value.trim().to_ascii_uppercase();
    let split_at = value
        .find(|character: char| !character.is_ascii_digit())
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

pub(super) fn remove_duplicate_domain_vhosts(directory: &str, domain: &str, keep: &Path) {
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
