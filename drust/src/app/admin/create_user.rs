use std::{fs, path::Path};

use super::{disable_root, options::Options};
use crate::app::support::{user_home, valid_email};
use crate::platform::{
    account::AccountSpec,
    fs::{FileMode, OwnerId},
};
use crate::app::{
    ensure_root, info, random_hex, read_to_string, valid_username, warn,
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

/// Best effort, like the rest of the key install: a key that was written is
/// still reported, and sshd refuses it if the owner or mode came out wrong.
fn secure_ssh_path(path: &Path, owner: Option<&OwnerId>, mode: FileMode) {
    let files = crate::platform::files();
    if let Some(owner) = owner {
        let _ = files.set_owner(path, owner);
    }
    let _ = files.set_mode(path, mode);
}

fn install_ssh_key(username: &str, ssh_key: &str) -> Result<(), String> {
    let key_data = if Path::new(ssh_key).is_file() {
        read_to_string(Path::new(ssh_key))?
    } else {
        ssh_key.to_string()
    };
    let home = user_home(username)?;
    let ssh_directory = home.join(".ssh");
    let authorized_keys = ssh_directory.join("authorized_keys");
    fs::create_dir_all(&ssh_directory)
        .map_err(|error| format!("failed to create {}: {error}", ssh_directory.display()))?;
    let owner = crate::platform::accounts().lookup(username).ok();
    secure_ssh_path(&ssh_directory, owner.as_ref(), FileMode::PrivateDir);
    fs::write(&authorized_keys, format!("{key_data}\n"))
        .map_err(|error| format!("failed to write {}: {error}", authorized_keys.display()))?;
    secure_ssh_path(&authorized_keys, owner.as_ref(), FileMode::Private);
    info(&format!("SSH key installed for {username}."));
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ssh_paths_end_up_private_and_owned_by_the_given_owner() {
        let dir = tempfile::tempdir().unwrap();
        let file = dir.path().join("authorized_keys");
        fs::write(&file, "key\n").unwrap();
        let files = crate::platform::files();
        let owner = files.owner(&fs::metadata(&file).unwrap()).ok();

        secure_ssh_path(&file, owner.as_ref(), FileMode::Private);
        secure_ssh_path(dir.path(), None, FileMode::PrivateDir);

        let mode = |path: &Path| files.mode_bits(&fs::metadata(path).unwrap()).map(|bits| bits & 0o777);
        if let Some(bits) = mode(&file) {
            assert_eq!(bits, 0o600);
            assert_eq!(mode(dir.path()), Some(0o700));
        }
    }
}
