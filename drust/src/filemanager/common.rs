use std::fs;
use std::path::{Component, Path, PathBuf};

use crate::app::{ensure_root, run_status, user_group, valid_username};
use crate::platform::{self, fs::{FileMode, ModeChange}};

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
    under_home(&path, &user_home).ok_or_else(|| format!("Path is outside the account home: {}", path.display()))
}

/// `path` spelled from `home` on: the home as the platform names it, the
/// rest with the OS's own separator (a panel path may hold `/` on Windows).
/// Where file names ignore case, `c:\users\…` is the same home.
fn under_home(path: &Path, home: &Path) -> Option<PathBuf> {
    let mut parts = path.components();
    for expected in home.components() {
        let given = parts.next()?;
        let same = given == expected
            || (crate::platform::paths::file_names_ignore_case()
                && given.as_os_str().to_string_lossy().to_lowercase() == expected.as_os_str().to_string_lossy().to_lowercase());
        if !same {
            return None;
        }
    }
    Some(parts.fold(home.to_path_buf(), |full, part| full.join(part)))
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
    let files = platform::files();
    let failed = |e: platform::PlatformError| format!("{}: {e}", path.display());
    let owner = platform::fs::owner_named(username, group).map_err(failed)?;
    files.set_owner(path, &owner).map_err(failed)?;
    platform::fs::change_mode(files, path, ModeChange::Exact(mode.bits())).map_err(failed)
}

/// `chown -R`, for a tree that a copy or move just put in the account home.
pub(super) fn apply_owner_to_tree(username: &str, group: &str, path: &Path) -> Result<(), String> {
    if platform::current().one_user() {
        return Ok(());
    }
    let failed = |e: platform::PlatformError| format!("{}: {e}", path.display());
    let owner = platform::fs::owner_named(username, group).map_err(failed)?;
    platform::fs::set_owner_tree(platform::files(), path, &owner).map_err(failed)
}

/// Copies a file or folder, merging into a folder that is already there.
pub(super) fn copy_tree(source: &Path, destination: &Path) -> Result<(), String> {
    if platform::current().one_user() {
        return copy_natively(source, destination);
    }
    // Shell out to `cp -a` rather than hand-rolling a recursive walk: it
    // preserves directory structure atomically for large trees.
    // `--remove-destination` unlinks an
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
    fn a_path_in_the_home_is_spelled_from_the_home() {
        let home = std::env::temp_dir().join("sites").join("Shop");
        let given = format!("{}/public_html/a b.txt", home.display());
        assert_eq!(under_home(Path::new(&given), &home), Some(home.join("public_html").join("a b.txt")));
        assert_eq!(under_home(&home, &home), Some(home.clone()));
        assert_eq!(under_home(&home.with_file_name("Shopper").join("x"), &home), None);
        assert_eq!(under_home(home.parent().unwrap(), &home), None);

        let other_case = PathBuf::from(home.display().to_string().to_uppercase()).join("x");
        let taken = under_home(&other_case, &home);
        assert_eq!(taken.is_some(), crate::platform::paths::file_names_ignore_case());
        if let Some(taken) = taken {
            assert_eq!(taken, home.join("x"));
        }
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
