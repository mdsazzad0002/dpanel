use std::fs;
use std::path::{Component, Path, PathBuf};

use crate::app::{ensure_root, run_status, user_group, valid_username};
use crate::platform::{self, fs::FileMode};

pub(super) fn validate_absolute_path(target: &str) -> Result<PathBuf, String> {
    if !Path::new(target).is_absolute() {
        return Err(format!("Path must be absolute: {target}"));
    }
    Ok(PathBuf::from(target))
}

pub(crate) fn validate_account(username: &str) -> Result<(PathBuf, PathBuf, String), String> {
    ensure_root()?;
    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }

    let home = crate::platform::paths().site_home(username);
    let canonical_home =
        fs::canonicalize(&home).map_err(|e| format!("account home is unavailable: {e}"))?;
    let group = user_group(username).unwrap_or_else(|_| username.to_string());

    Ok((home, canonical_home, group))
}

pub(crate) fn validate_user_path(username: &str, target: &str) -> Result<PathBuf, String> {
    let path = validate_absolute_path(target)?;
    if path
        .components()
        .any(|component| matches!(component, Component::ParentDir))
    {
        return Err("Parent path traversal is not allowed.".into());
    }

    let user_home = crate::platform::paths().site_home(username);
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

pub(crate) fn ensure_directory_inside_home(
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
                apply_owner_and_mode(username, group, &current, FileMode::Executable)?;
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
    mode: FileMode,
) -> Result<(), String> {
    // One user owns everything on a local profile, so only the mode is left.
    if platform::current().one_user() {
        return platform::files()
            .set_mode(path, mode)
            .map_err(|e| e.to_string());
    }
    run_status(
        "chown",
        &[
            &format!("{username}:{group}"),
            path.to_string_lossy().as_ref(),
        ],
    )?;
    run_status("chmod", &[&chmod_argument(mode), path.to_string_lossy().as_ref()])
}

fn chmod_argument(mode: FileMode) -> String {
    format!("{:04o}", mode.bits())
}

/// `chown -R`, for a tree that a copy or move just put in the account home.
pub(super) fn apply_owner_to_tree(username: &str, group: &str, path: &Path) -> Result<(), String> {
    if platform::current().one_user() {
        return Ok(());
    }
    run_status(
        "chown",
        &[
            "-R",
            &format!("{username}:{group}"),
            path.to_string_lossy().as_ref(),
        ],
    )
}

/// Copies a file or folder, merging into a folder that is already there.
pub(super) fn copy_tree(source: &Path, destination: &Path) -> Result<(), String> {
    if platform::current().one_user() {
        return copy_natively(source, destination);
    }
    // Shell out to `cp -a` rather than hand-rolling a recursive walk: it
    // preserves directory structure atomically for large trees, the same
    // way the rest of this module relies on system tools (chown/chmod)
    // instead of reimplementing them. `--remove-destination` unlinks an
    // existing file before writing, so a merge never writes through it.
    run_status(
        "cp",
        &[
            "-a",
            "--remove-destination",
            "--no-target-directory",
            source.to_string_lossy().as_ref(),
            destination.to_string_lossy().as_ref(),
        ],
    )
}

/// What `cp -a --remove-destination` does, where there is no `cp`. Links are
/// copied as links, and what sits at the destination is unlinked first, so a
/// merge never writes through it.
fn copy_natively(source: &Path, destination: &Path) -> Result<(), String> {
    let metadata =
        fs::symlink_metadata(source).map_err(|e| format!("{}: {e}", source.display()))?;
    if metadata.is_dir() {
        fs::create_dir_all(destination).map_err(|e| format!("{}: {e}", destination.display()))?;
        let entries = fs::read_dir(source).map_err(|e| format!("{}: {e}", source.display()))?;
        for entry in entries {
            let entry = entry.map_err(|e| format!("{}: {e}", source.display()))?;
            copy_natively(&entry.path(), &destination.join(entry.file_name()))?;
        }
        return Ok(());
    }
    if fs::symlink_metadata(destination).is_ok() {
        platform::files()
            .remove_file(destination)
            .map_err(|e| format!("{}: {e}", destination.display()))?;
    }
    if metadata.file_type().is_symlink() {
        let target = fs::read_link(source).map_err(|e| format!("{}: {e}", source.display()))?;
        return platform::files()
            .symlink(&target, destination)
            .map_err(|e| format!("{}: {e}", destination.display()));
    }
    fs::copy(source, destination)
        .map(|_| ())
        .map_err(|e| format!("{}: {e}", destination.display()))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn chmod_gets_the_same_text_as_before() {
        assert_eq!(chmod_argument(FileMode::Executable), "0755");
        assert_eq!(chmod_argument(FileMode::Shared), "0644");
        assert_eq!(chmod_argument(FileMode::SiteFile), "0640");
        assert_eq!(chmod_argument(FileMode::Raw(0o2775)), "2775");
    }

    #[test]
    fn a_native_copy_merges_and_replaces() {
        let dir = tempfile::tempdir().unwrap();
        let source = dir.path().join("source");
        let destination = dir.path().join("destination");
        fs::create_dir_all(source.join("a/b")).unwrap();
        fs::write(source.join("a/b/new.txt"), "new").unwrap();
        fs::write(source.join("same.txt"), "new").unwrap();
        fs::create_dir_all(&destination).unwrap();
        fs::write(destination.join("kept.txt"), "kept").unwrap();
        fs::write(destination.join("same.txt"), "old").unwrap();

        copy_natively(&source, &destination).unwrap();

        assert_eq!(fs::read(destination.join("a/b/new.txt")).unwrap(), b"new");
        assert_eq!(fs::read(destination.join("same.txt")).unwrap(), b"new");
        assert_eq!(fs::read(destination.join("kept.txt")).unwrap(), b"kept");
        assert_eq!(fs::read(source.join("same.txt")).unwrap(), b"new");
    }

    #[test]
    fn a_native_copy_never_writes_through_a_link_in_the_way() {
        let dir = tempfile::tempdir().unwrap();
        let outside = dir.path().join("outside.txt");
        fs::write(&outside, "keep").unwrap();
        let source = dir.path().join("source.txt");
        fs::write(&source, "new").unwrap();
        let destination = dir.path().join("destination.txt");
        platform::files().symlink(&outside, &destination).unwrap();

        copy_natively(&source, &destination).unwrap();

        assert!(!fs::symlink_metadata(&destination).unwrap().is_symlink());
        assert_eq!(fs::read(&destination).unwrap(), b"new");
        assert_eq!(fs::read(&outside).unwrap(), b"keep");
    }

    #[test]
    fn a_native_copy_keeps_a_link_as_a_link() {
        let dir = tempfile::tempdir().unwrap();
        let source = dir.path().join("source");
        fs::create_dir_all(&source).unwrap();
        let target = dir.path().join("target");
        fs::create_dir_all(&target).unwrap();
        fs::write(target.join("file"), "x").unwrap();
        platform::files().symlink(&target, &source.join("link")).unwrap();
        let destination = dir.path().join("destination");

        copy_natively(&source, &destination).unwrap();

        let copied = destination.join("link");
        assert!(fs::symlink_metadata(&copied).unwrap().is_symlink());
        assert_eq!(fs::read_link(&copied).unwrap(), target);
    }
}
