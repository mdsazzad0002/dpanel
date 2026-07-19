use std::env;
use std::fs;
use std::path::{Component, Path, PathBuf};

use crate::app::{ensure_root, info, run_output, run_status, user_group, valid_username, warn};

pub fn run(args: Vec<String>) -> Result<(), String> {
    let action = args.first().cloned().unwrap_or_else(|| "install".into());
    let rest = if args.is_empty() {
        Vec::new()
    } else {
        args[1..].to_vec()
    };
    match action.as_str() {
        "install" | "update" => filemanager_dispatch(rest),
        "remove" => filemanager_remove(&rest),
        "exists" => filemanager_exists(&rest, true),
        "file-exists" => filemanager_exists(&rest, false),
        "user" => filemanager_user(rest),
        _ => Err(format!("Unsupported filemanager action: {action}")),
    }
}

fn filemanager_usage() {
    println!(
        "\
Usage:
  filemanager install [create|remove|exists|file-exists] <path>...
  filemanager remove <path>...
  filemanager update [create|remove|exists|file-exists] <path>...
  filemanager user create <username> [--home <path>] [--shell <shell>]
  filemanager user ensure <username> [--home <path>] [--shell <shell>]
"
    );
}

fn filemanager_dispatch(args: Vec<String>) -> Result<(), String> {
    if args.is_empty() {
        filemanager_usage();
        return Err("Missing filemanager command.".into());
    }
    match args[0].as_str() {
        "create" => filemanager_create(&args[1..]),
        "remove" => filemanager_remove(&args[1..]),
        "exists" => filemanager_exists(&args[1..], true),
        "file-exists" => filemanager_exists(&args[1..], false),
        other => Err(format!(
            "Unsupported filemanager install/update command: {other}"
        )),
    }
}

fn filemanager_validate_target(target: &str) -> Result<PathBuf, String> {
    if !target.starts_with('/') {
        return Err(format!("Path must be absolute: {target}"));
    }
    Ok(PathBuf::from(target))
}

fn invoking_user_home() -> Option<String> {
    env::var("SUDO_USER")
        .ok()
        .and_then(|user| run_output("getent", &["passwd", &user]).ok())
        .and_then(|line| line.split(':').nth(5).map(|s| s.to_string()))
        .or_else(|| env::var("HOME").ok())
}

fn filemanager_is_protected_target(target: &Path) -> bool {
    invoking_user_home()
        .map(|home| Path::new(&home) == target)
        .unwrap_or(false)
}

fn filemanager_apply_owner(path: &Path) {
    if env::var("EUID").is_ok() {
        // ignore; runtime env may not expose it
    }
    if let (Ok(sudo_user), Ok(uid), Ok(gid)) = (
        env::var("SUDO_USER"),
        env::var("SUDO_UID"),
        env::var("SUDO_GID"),
    ) {
        let _ = run_status(
            "chown",
            &[&format!("{uid}:{gid}"), path.to_string_lossy().as_ref()],
        );
        let _ = sudo_user;
    }
}

fn filemanager_create(paths: &[String]) -> Result<(), String> {
    if paths.is_empty() {
        filemanager_usage();
        return Err("Missing path argument.".into());
    }

    for target in paths {
        let path = filemanager_validate_target(target)?;
        if path.is_dir() {
            info(&format!("folder exists: {}", path.display()));
        } else {
            fs::create_dir_all(&path)
                .map_err(|e| format!("failed to create {}: {e}", path.display()))?;
            info(&format!("folder created: {}", path.display()));
        }
        filemanager_apply_owner(&path);
    }
    Ok(())
}

fn filemanager_remove(paths: &[String]) -> Result<(), String> {
    if paths.is_empty() {
        filemanager_usage();
        return Err("Missing path argument.".into());
    }

    for target in paths {
        let path = filemanager_validate_target(target)?;
        if filemanager_is_protected_target(&path) {
            return Err(format!(
                "Refusing to remove your home directory: {}",
                path.display()
            ));
        }
        if path.exists() {
            if path.is_dir() {
                fs::remove_dir_all(&path)
                    .map_err(|e| format!("failed to remove {}: {e}", path.display()))?;
            } else {
                fs::remove_file(&path)
                    .map_err(|e| format!("failed to remove {}: {e}", path.display()))?;
            }
            info(&format!("removed: {}", path.display()));
        } else {
            warn(&format!("nothing to remove: {}", path.display()));
        }
    }
    Ok(())
}

fn filemanager_exists(paths: &[String], directories: bool) -> Result<(), String> {
    if paths.is_empty() {
        filemanager_usage();
        return Err("Missing path argument.".into());
    }
    let mut missing = false;
    for target in paths {
        let path = filemanager_validate_target(target)?;
        let exists = if directories {
            path.is_dir()
        } else {
            path.is_file()
        };
        if exists {
            info(&format!(
                "{} exists: {}",
                if directories { "folder" } else { "file" },
                path.display()
            ));
        } else {
            warn(&format!(
                "{} missing: {}",
                if directories { "folder" } else { "file" },
                path.display()
            ));
            missing = true;
        }
    }
    if missing {
        Err("One or more targets missing.".into())
    } else {
        Ok(())
    }
}

fn filemanager_user(args: Vec<String>) -> Result<(), String> {
    if args.len() < 2 {
        filemanager_usage();
        return Err("Missing username argument.".into());
    }
    let action = args[0].clone();
    let username = args[1].clone();
    let mut home: Option<String> = None;
    let mut shell = "/bin/bash".to_string();
    let mut site_directory = "public_html".to_string();
    let mut iter = args.into_iter().skip(2);
    while let Some(arg) = iter.next() {
        match arg.as_str() {
            "--home" => {
                home = Some(
                    iter.next()
                        .ok_or_else(|| "Missing value for --home".to_string())?,
                );
            }
            "--shell" => {
                shell = iter
                    .next()
                    .ok_or_else(|| "Missing value for --shell".to_string())?;
            }
            "--site-directory" => {
                site_directory = iter
                    .next()
                    .ok_or_else(|| "Missing value for --site-directory".to_string())?;
            }
            other => return Err(format!("Unsupported user option: {other}")),
        }
    }
    match action.as_str() {
        "create" | "ensure" => {
            filemanager_user_create(&username, home.as_deref(), &shell, &site_directory)
        }
        _ => Err(format!("Unsupported filemanager user action: {action}")),
    }
}

fn filemanager_user_create(
    username: &str,
    home: Option<&str>,
    shell: &str,
    site_directory: &str,
) -> Result<(), String> {
    ensure_root()?;
    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }
    let home = home
        .map(PathBuf::from)
        .unwrap_or_else(|| PathBuf::from(format!("/home/{username}")));
    if let Some(home_str) = home.to_str() {
        let _ = filemanager_validate_target(home_str)?;
    }
    if std::process::Command::new("id")
        .args(["-u", username])
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
    {
        info(&format!("user exists: {username}"));
    } else {
        run_status(
            "useradd",
            &[
                "-m",
                "-d",
                home.to_string_lossy().as_ref(),
                "-s",
                shell,
                "-U",
                username,
            ],
        )?;
        info(&format!("user created: {username}"));
    }
    let group = user_group(username).unwrap_or_else(|_| username.to_string());
    fs::create_dir_all(&home).map_err(|e| format!("failed to create {}: {e}", home.display()))?;
    let _ = run_status(
        "chown",
        &[
            &format!("{username}:{group}"),
            home.to_string_lossy().as_ref(),
        ],
    );
    // The panel/web server must be able to traverse the account home to
    // verify and serve the website root. Keep ownership with the account,
    // while allowing directory traversal for the web service.
    let _ = run_status("chmod", &["0755", home.to_string_lossy().as_ref()]);
    let site_directory = site_directory.trim_matches('/');
    if site_directory.is_empty() || site_directory.contains("..") || site_directory.contains('/') {
        return Err("Invalid site directory.".into());
    }
    let site_path = home.join(site_directory);
    fs::create_dir_all(&site_path)
        .map_err(|e| format!("failed to create {}: {e}", site_path.display()))?;
    let _ = run_status(
        "chown",
        &[
            &format!("{username}:{group}"),
            site_path.to_string_lossy().as_ref(),
        ],
    );
    let _ = run_status("chmod", &["0755", site_path.to_string_lossy().as_ref()]);
    info(&format!("home prepared: {}", home.display()));
    Ok(())
}

// Public API wrappers

pub fn run_filemanager_create(paths: &[String]) -> Result<(), String> {
    filemanager_create(paths)
}

pub fn run_filemanager_remove(paths: &[String]) -> Result<(), String> {
    filemanager_remove(paths)
}

pub fn run_filemanager_exists(paths: &[String], directories: bool) -> Result<(), String> {
    filemanager_exists(paths, directories)
}

pub fn run_filemanager_user(args: Vec<String>) -> Result<(), String> {
    filemanager_user(args)
}

pub fn write_user_file(
    username: &str,
    target: &str,
    content: &[u8],
    must_exist: bool,
) -> Result<(), String> {
    ensure_root()?;
    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }

    let path = filemanager_validate_target(target)?;
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
    if must_exist && !path.is_file() {
        return Err(format!("File not found: {}", path.display()));
    }

    let parent = path
        .parent()
        .ok_or_else(|| "File parent is missing.".to_string())?;
    fs::create_dir_all(parent)
        .map_err(|e| format!("failed to create {}: {e}", parent.display()))?;
    fs::write(&path, content).map_err(|e| format!("failed to write {}: {e}", path.display()))?;

    let group = user_group(username).unwrap_or_else(|_| username.to_string());
    run_status(
        "chown",
        &[
            &format!("{username}:{group}"),
            path.to_string_lossy().as_ref(),
        ],
    )?;
    run_status("chmod", &["0644", path.to_string_lossy().as_ref()])?;
    let _ = run_status(
        "chown",
        &[
            &format!("{username}:{group}"),
            parent.to_string_lossy().as_ref(),
        ],
    );
    let _ = run_status("chmod", &["0755", parent.to_string_lossy().as_ref()]);
    info(&format!("file written: {}", path.display()));

    Ok(())
}

pub fn move_user_path(username: &str, source: &str, destination: &str) -> Result<(), String> {
    ensure_root()?;
    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }

    let source_path = validate_user_path(username, source)?;
    let destination_path = validate_user_path(username, destination)?;
    let user_home = PathBuf::from(format!("/home/{username}"));

    if source_path == user_home {
        return Err("The account home cannot be moved.".into());
    }
    if source_path == destination_path {
        return Err("Source and destination are the same.".into());
    }
    if destination_path.starts_with(&source_path) {
        return Err("A path cannot be moved inside itself.".into());
    }

    let canonical_home =
        fs::canonicalize(&user_home).map_err(|e| format!("account home is unavailable: {e}"))?;
    let canonical_source = fs::canonicalize(&source_path)
        .map_err(|e| format!("source not found: {}: {e}", source_path.display()))?;
    if canonical_source != canonical_home && !canonical_source.starts_with(&canonical_home) {
        return Err("Source resolves outside the account home.".into());
    }

    let destination_parent = destination_path
        .parent()
        .ok_or_else(|| "Destination parent is missing.".to_string())?;
    let canonical_parent = fs::canonicalize(destination_parent).map_err(|e| {
        format!(
            "destination folder not found: {}: {e}",
            destination_parent.display()
        )
    })?;
    if canonical_parent != canonical_home && !canonical_parent.starts_with(&canonical_home) {
        return Err("Destination resolves outside the account home.".into());
    }
    if !canonical_parent.is_dir() {
        return Err("Destination parent is not a folder.".into());
    }
    if fs::symlink_metadata(&destination_path).is_ok() {
        return Err(format!(
            "Target already exists: {}",
            destination_path.display()
        ));
    }

    fs::rename(&source_path, &destination_path).map_err(|e| {
        format!(
            "failed to move {} to {}: {e}",
            source_path.display(),
            destination_path.display()
        )
    })?;
    info(&format!(
        "path moved: {} -> {}",
        source_path.display(),
        destination_path.display()
    ));

    Ok(())
}

pub fn delete_user_path(username: &str, target: &str) -> Result<(), String> {
    ensure_root()?;
    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }

    let path = validate_user_path(username, target)?;
    let home = PathBuf::from(format!("/home/{username}"));
    if path == home {
        return Err("The account home cannot be deleted.".into());
    }

    let canonical_home =
        fs::canonicalize(&home).map_err(|e| format!("account home is unavailable: {e}"))?;
    let canonical_target =
        fs::canonicalize(&path).map_err(|e| format!("path not found: {}: {e}", path.display()))?;
    if canonical_target != canonical_home && !canonical_target.starts_with(&canonical_home) {
        return Err("Path resolves outside the account home.".into());
    }

    let metadata = fs::symlink_metadata(&path)
        .map_err(|e| format!("path not found: {}: {e}", path.display()))?;
    if metadata.is_dir() {
        fs::remove_dir_all(&path)
            .map_err(|e| format!("failed to delete {}: {e}", path.display()))?;
    } else {
        fs::remove_file(&path).map_err(|e| format!("failed to delete {}: {e}", path.display()))?;
    }
    info(&format!("path deleted: {}", path.display()));
    Ok(())
}

fn validate_user_path(username: &str, target: &str) -> Result<PathBuf, String> {
    let path = filemanager_validate_target(target)?;
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
