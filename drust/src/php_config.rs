use std::fs;
use std::path::Path;

use crate::app::{ensure_root, info, run_status};

pub struct PhpConfigValues<'a> {
    pub version: &'a str,
    pub memory_limit: &'a str,
    pub upload_max_filesize: &'a str,
    pub post_max_size: &'a str,
    pub max_execution_time: u32,
    pub max_input_vars: u32,
    pub display_errors: &'a str,
    pub log_errors: &'a str,
    pub allow_url_fopen: &'a str,
}

pub fn apply(values: PhpConfigValues<'_>) -> Result<Vec<String>, String> {
    ensure_root()?;
    validate_version(values.version)?;
    validate_size("memory_limit", values.memory_limit, true)?;
    validate_size("upload_max_filesize", values.upload_max_filesize, false)?;
    validate_size("post_max_size", values.post_max_size, false)?;
    validate_on_off("display_errors", values.display_errors)?;
    validate_on_off("log_errors", values.log_errors)?;
    validate_on_off("allow_url_fopen", values.allow_url_fopen)?;
    if values.max_execution_time == 0 || values.max_execution_time > 3600 {
        return Err("max_execution_time must be between 1 and 3600.".into());
    }
    if values.max_input_vars < 100 || values.max_input_vars > 50_000 {
        return Err("max_input_vars must be between 100 and 50000.".into());
    }

    let content = format!(
        "; Managed by drust. Changes made here may be overwritten.\nmemory_limit = {}\nupload_max_filesize = {}\npost_max_size = {}\nmax_execution_time = {}\nmax_input_vars = {}\ndisplay_errors = {}\nlog_errors = {}\nallow_url_fopen = {}\n",
        values.memory_limit,
        values.upload_max_filesize,
        values.post_max_size,
        values.max_execution_time,
        values.max_input_vars,
        values.display_errors,
        values.log_errors,
        values.allow_url_fopen,
    );

    let mut written = Vec::new();
    for sapi in ["fpm", "cli", "apache2"] {
        let directory = format!("/etc/php/{}/{sapi}/conf.d", values.version);
        if !Path::new(&directory).is_dir() {
            continue;
        }
        let path = format!("{directory}/99-serverpanel.ini");
        fs::write(&path, &content).map_err(|e| format!("failed to write {path}: {e}"))?;
        written.push(path);
    }
    if written.is_empty() {
        return Err(format!(
            "PHP {} configuration directories were not found.",
            values.version
        ));
    }

    info(&format!("PHP {} configuration applied", values.version));
    Ok(written)
}

pub fn reload_fpm(version: &str) -> Result<(), String> {
    validate_version(version)?;
    let service = format!("php{version}-fpm");
    run_status("systemctl", &["reload", &service])
}

fn validate_version(value: &str) -> Result<(), String> {
    let mut parts = value.split('.');
    let valid = parts
        .next()
        .is_some_and(|part| !part.is_empty() && part.chars().all(|c| c.is_ascii_digit()))
        && parts
            .next()
            .is_some_and(|part| !part.is_empty() && part.chars().all(|c| c.is_ascii_digit()))
        && parts.next().is_none();
    if valid {
        Ok(())
    } else {
        Err("Invalid PHP version.".into())
    }
}

fn validate_size(name: &str, value: &str, allow_unlimited: bool) -> Result<(), String> {
    let value = value.trim();
    if allow_unlimited && value == "-1" {
        return Ok(());
    }
    let split = value
        .find(|c: char| !c.is_ascii_digit())
        .unwrap_or(value.len());
    let (number, suffix) = value.split_at(split);
    if number.is_empty()
        || number.parse::<u64>().unwrap_or(0) == 0
        || !matches!(suffix.to_ascii_uppercase().as_str(), "K" | "M" | "G" | "T")
    {
        return Err(format!("Invalid {name}. Use a value such as 512M or 5G."));
    }
    Ok(())
}

fn validate_on_off(name: &str, value: &str) -> Result<(), String> {
    if value.eq_ignore_ascii_case("on") || value.eq_ignore_ascii_case("off") {
        Ok(())
    } else {
        Err(format!("{name} must be On or Off."))
    }
}
