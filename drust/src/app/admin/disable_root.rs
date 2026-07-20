use std::{fs, path::Path, process::Command};

use crate::app::support::{current_epoch, program_exists};
use crate::app::{ensure_root, info, read_to_string, run_status, warn, write_string};

pub(crate) fn run() -> Result<(), String> {
    ensure_root()?;
    let config_path = Path::new("/etc/ssh/sshd_config");
    if !config_path.exists() {
        return Err(format!("SSH config not found: {}", config_path.display()));
    }

    let backup = format!(
        "{}.likesoft.backup.{}",
        config_path.display(),
        current_epoch()
    );
    fs::copy(config_path, &backup)
        .map_err(|error| format!("failed to backup ssh config: {error}"))?;
    let dropin_directory = Path::new("/etc/ssh/sshd_config.d");
    let dropin_file = dropin_directory.join("99-likesoft-root-login.conf");
    fs::create_dir_all(dropin_directory)
        .map_err(|error| format!("failed to create {}: {error}", dropin_directory.display()))?;
    write_string(&dropin_file, "PermitRootLogin no\n")?;

    let mut found = false;
    let mut lines = Vec::new();
    for line in read_to_string(config_path)?.lines() {
        if line.trim_start().starts_with("PermitRootLogin") {
            lines.push("PermitRootLogin no".to_string());
            found = true;
        } else {
            lines.push(line.to_string());
        }
    }
    if !found {
        lines.push("PermitRootLogin no".to_string());
    }
    write_string(config_path, &(lines.join("\n") + "\n"))?;

    if !valid_sshd_config() {
        fs::copy(&backup, config_path)
            .map_err(|error| format!("failed to restore ssh config: {error}"))?;
        let _ = fs::remove_file(&dropin_file);
        return Err("SSH config validation failed. Original file restored.".into());
    }

    let service = if Command::new("systemctl")
        .args(["list-unit-files", "ssh.service"])
        .status()
        .is_ok_and(|status| status.success())
    {
        "ssh"
    } else {
        "sshd"
    };
    let _ = run_status("systemctl", &["restart", service]);
    println!("Root SSH login disabled.");
    info("Root SSH login disabled.");
    Ok(())
}

fn valid_sshd_config() -> bool {
    if program_exists("sshd") {
        run_status("sshd", &["-t"]).is_ok()
    } else if Path::new("/usr/sbin/sshd").exists() {
        Command::new("/usr/sbin/sshd")
            .arg("-t")
            .status()
            .is_ok_and(|status| status.success())
    } else {
        warn("sshd binary not found; skipping config syntax check.");
        true
    }
}
