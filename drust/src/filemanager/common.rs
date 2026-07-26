use std::fs;
use std::path::{Component, Path, PathBuf};

use crate::app::{ensure_root, run_status, user_group, valid_username};

pub(super) fn validate_absolute_path(target: &str) -> Result<PathBuf, String> {
    if !target.starts_with('/') {
        return Err(format!("Path must be absolute: {target}"));
    }
    Ok(PathBuf::from(target))
}

pub(super) fn validate_account(username: &str) -> Result<(PathBuf, PathBuf, String), String> {
    ensure_root()?;
    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }

    let home = PathBuf::from(format!("/home/{username}"));
    let canonical_home =
        fs::canonicalize(&home).map_err(|e| format!("account home is unavailable: {e}"))?;
    let group = user_group(username).unwrap_or_else(|_| username.to_string());

    Ok((home, canonical_home, group))
}

pub(super) fn validate_user_path(username: &str, target: &str) -> Result<PathBuf, String> {
    let path = validate_absolute_path(target)?;
    if path
        .components()
        .any(|component| matches!(component, Component::ParentDir))
    {
        return Err("Parent path traversal is not allowed.".into());
    }

    let user_home = PathBuf::from(format!("/home/{username}"));
    if path != user_home && !path.starts_with(&user_home) {
        return Err(format!(
            "Path is outside the account home: {}",
            path.display()
        ));
    }

    Ok(path)
}

pub(super) fn ensure_canonical_inside_home(
    canonical_home: &Path,
    path: &Path,
    label: &str,
) -> Result<PathBuf, String> {
    let canonical = fs::canonicalize(path)
        .map_err(|e| format!("{label} is unavailable: {}: {e}", path.display()))?;
    if canonical != canonical_home && !canonical.starts_with(canonical_home) {
        return Err(format!("{label} resolves outside the account home."));
    }

    Ok(canonical)
}

pub(super) fn ensure_directory_inside_home(
    username: &str,
    group: &str,
    user_home: &Path,
    canonical_home: &Path,
    path: &Path,
    label: &str,
) -> Result<PathBuf, String> {
    if path == user_home {
        return Ok(canonical_home.to_path_buf());
    }
    if !path.starts_with(user_home) {
        return Err(format!("{label} is outside the account home."));
    }

    let relative = path
        .strip_prefix(user_home)
        .map_err(|_| format!("{label} is outside the account home."))?;
    let mut current = canonical_home.to_path_buf();

    for component in relative.components() {
        let name = match component {
            Component::Normal(name) => name,
            Component::CurDir => continue,
            _ => return Err(format!("{label} contains an unsafe path component.")),
        };

        current.push(name);
        match fs::symlink_metadata(&current) {
            Ok(metadata) => {
                if metadata.file_type().is_symlink() {
                    return Err(format!(
                        "{label} contains a symbolic link: {}",
                        current.display()
                    ));
                }
                if !metadata.is_dir() {
                    return Err(format!(
                        "{label} contains a non-folder path: {}",
                        current.display()
                    ));
                }
            }
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => {
                fs::create_dir(&current)
                    .map_err(|e| format!("failed to create {}: {e}", current.display()))?;
                apply_owner_and_mode(username, group, &current, "0755")?;
            }
            Err(error) => {
                return Err(format!("failed to inspect {}: {error}", current.display()));
            }
        }
    }

    ensure_canonical_inside_home(canonical_home, &current, label)
}

pub(super) fn apply_owner_and_mode(
    username: &str,
    group: &str,
    path: &Path,
    mode: &str,
) -> Result<(), String> {
    run_status(
        "chown",
        &[
            &format!("{username}:{group}"),
            path.to_string_lossy().as_ref(),
        ],
    )?;
    run_status("chmod", &[mode, path.to_string_lossy().as_ref()])
}
