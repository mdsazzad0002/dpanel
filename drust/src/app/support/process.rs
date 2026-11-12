use std::process::Command;

pub fn ensure_root() -> Result<(), String> {
    if !crate::platform::current().needs_root() {
        return Ok(());
    }
    if !crate::platform::process::is_root()? {
        return Err("This command must run as root.".into());
    }
    Ok(())
}

pub fn run_status(program: &str, args: &[&str]) -> Result<(), String> {
    let status = Command::new(program)
        .args(args)
        .status()
        .map_err(|error| format!("failed to run {program}: {error}"))?;
    if status.success() {
        Ok(())
    } else {
        Err(format!("{program} {:?} failed with status {status}", args))
    }
}

pub fn run_output(program: &str, args: &[&str]) -> Result<String, String> {
    let output = Command::new(program)
        .args(args)
        .output()
        .map_err(|error| format!("failed to run {program}: {error}"))?;
    if !output.status.success() {
        return Err(format!("{program} {:?} failed", args));
    }
    Ok(String::from_utf8_lossy(&output.stdout).trim().to_string())
}

pub(crate) fn program_exists(program: &str) -> bool {
    crate::platform::process::program_exists(program)
}

/// Whether `try_chown` changes one path or, like `chown -R`, the whole tree.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub(crate) enum Depth {
    One,
    Tree,
}

/// Best effort `chown [-R] user:group path`. A local profile has no other
/// owner to give a file to.
pub(crate) fn try_chown(user: &str, group: &str, path: &std::path::Path, depth: Depth) {
    use crate::platform::{self, fs};
    if platform::current().one_user() {
        return;
    }
    let Ok(owner) = fs::owner_named(user, group) else {
        return;
    };
    let _ = match depth {
        Depth::One => platform::files().set_owner(path, &owner),
        Depth::Tree => fs::set_owner_tree(platform::files(), path, &owner),
    };
}
