use std::{
    fs,
    io::Write,
    path::Path,
    process::{Command, Stdio},
};

use super::{disable_root, options::Options};
use crate::app::support::{user_home, valid_email};
use crate::app::{
    ensure_root, info, random_hex, read_to_string, run_status, user_group, valid_username, warn,
};

pub(crate) fn run(args: Vec<String>) -> Result<(), String> {
    create(Options::parse(args)?)
}

pub(super) fn create(options: Options) -> Result<(), String> {
    ensure_root()?;
    if !valid_username(&options.username) {
        return Err(format!("Invalid username: {}", options.username));
    }
    if let Some(email) = &options.email {
        if !valid_email(email) {
            return Err(format!("Invalid email address: {email}"));
        }
    }

    if Command::new("getent")
        .args(["passwd", &options.username])
        .status()
        .is_ok_and(|status| status.success())
    {
        run_status("usermod", &["-s", &options.shell_path, &options.username])?;
        info(&format!(
            "Updated shell for existing user {}.",
            options.username
        ));
    } else {
        run_status(
            "useradd",
            &["-m", "-s", &options.shell_path, &options.username],
        )?;
        info(&format!("Created user {}.", options.username));
    }

    add_admin_groups(&options.username);

    if let Some(email) = &options.email {
        run_status(
            "usermod",
            &["-c", &format!("panel-email={email}"), &options.username],
        )?;
        info(&format!("Panel email recorded for {}.", options.username));
    }

    let mut password = options.password.clone();
    if password.is_none() && options.ssh_key.is_none() {
        let generated = random_hex(16)?;
        println!(
            "Generated temporary password for {}: {}",
            options.username, generated
        );
        warn(&format!(
            "No password or SSH key provided. Generated temporary password for {}: {}",
            options.username, generated
        ));
        password = Some(generated);
    }

    if let Some(password) = password {
        set_password(&options.username, &password)?;
    } else {
        let _ = run_status("passwd", &["-l", &options.username]);
    }

    if let Some(ssh_key) = &options.ssh_key {
        install_ssh_key(&options.username, ssh_key)?;
    }
    if options.password.is_some() || options.ssh_key.is_some() {
        let _ = run_status("passwd", &["-u", &options.username]);
    }
    if options.disable_root {
        disable_root::run()?;
    }

    println!("Admin user setup completed for {}", options.username);
    info(&format!(
        "Admin user setup completed for {}.",
        options.username
    ));
    Ok(())
}

fn add_admin_groups(username: &str) {
    for group in ["sudo", "wheel"] {
        if Command::new("getent")
            .args(["group", group])
            .status()
            .is_ok_and(|status| status.success())
        {
            let _ = run_status("usermod", &["-aG", group, username]);
        }
    }
}

fn set_password(username: &str, password: &str) -> Result<(), String> {
    let mut child = Command::new("chpasswd")
        .stdin(Stdio::piped())
        .spawn()
        .map_err(|error| format!("failed to start chpasswd: {error}"))?;
    if let Some(stdin) = child.stdin.as_mut() {
        writeln!(stdin, "{username}:{password}")
            .map_err(|error| format!("failed to write password: {error}"))?;
    }
    if !child
        .wait()
        .map_err(|error| format!("failed waiting for chpasswd: {error}"))?
        .success()
    {
        return Err("Password configuration failed.".into());
    }
    info(&format!("Password configured for {username}."));
    Ok(())
}

fn install_ssh_key(username: &str, ssh_key: &str) -> Result<(), String> {
    let key_data = if Path::new(ssh_key).is_file() {
        read_to_string(Path::new(ssh_key))?
    } else {
        ssh_key.to_string()
    };
    let home = user_home(username)?;
    let group = user_group(username).unwrap_or_else(|_| username.to_string());
    let ssh_directory = home.join(".ssh");
    let authorized_keys = ssh_directory.join("authorized_keys");
    fs::create_dir_all(&ssh_directory)
        .map_err(|error| format!("failed to create {}: {error}", ssh_directory.display()))?;
    let _ = run_status(
        "install",
        &[
            "-d",
            "-m",
            "0700",
            "-o",
            username,
            "-g",
            &group,
            ssh_directory.to_string_lossy().as_ref(),
        ],
    );
    fs::write(&authorized_keys, format!("{key_data}\n"))
        .map_err(|error| format!("failed to write {}: {error}", authorized_keys.display()))?;
    let _ = run_status(
        "chown",
        &[
            &format!("{username}:{group}"),
            authorized_keys.to_string_lossy().as_ref(),
        ],
    );
    let _ = run_status(
        "chmod",
        &["0600", authorized_keys.to_string_lossy().as_ref()],
    );
    info(&format!("SSH key installed for {username}."));
    Ok(())
}
