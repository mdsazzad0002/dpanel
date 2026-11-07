use std::{fs, path::Path};

use super::{disable_root, options::Options};
use crate::app::support::{user_home, valid_email};
use crate::platform::account::AccountSpec;
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

    let accounts = crate::platform::accounts();
    if accounts.exists(&options.username).unwrap_or(false) {
        accounts
            .set_shell(&options.username, &options.shell_path)
            .map_err(|error| error.to_string())?;
        info(&format!(
            "Updated shell for existing user {}.",
            options.username
        ));
    } else {
        accounts
            .create(&AccountSpec::login(&options.username, &options.shell_path))
            .map_err(|error| error.to_string())?;
        info(&format!("Created user {}.", options.username));
    }

    add_admin_groups(&options.username);

    if let Some(email) = &options.email {
        accounts
            .set_comment(&options.username, &format!("panel-email={email}"))
            .map_err(|error| error.to_string())?;
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
        let _ = accounts.lock(&options.username, true);
    }

    if let Some(ssh_key) = &options.ssh_key {
        install_ssh_key(&options.username, ssh_key)?;
    }
    if options.password.is_some() || options.ssh_key.is_some() {
        let _ = accounts.lock(&options.username, false);
    }
    if options.disable_root {
        match disable_root::run() {
            Ok(()) => {}
            Err(error) => {
                warn(&format!(
                    "Skipped SSH hardening after creating {}: {}",
                    options.username, error
                ));
            }
        }
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
        let _ = crate::platform::accounts().add_to_group(username, group);
    }
}

fn set_password(username: &str, password: &str) -> Result<(), String> {
    crate::platform::accounts()
        .set_password(username, password)
        .map_err(|_| "Password configuration failed.".to_string())?;
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
