use std::env;
use std::path::{Path, PathBuf};
use std::process::Command;

pub fn run_script(script_name: &str, args: &[String]) -> Result<(), String> {
    let script_path = repo_root()?.join("scripts").join(script_name);
    if !script_path.exists() {
        return Err(format!("Script not found: {}", script_path.display()));
    }

    let status = Command::new("bash")
        .arg(&script_path)
        .args(args)
        .status()
        .map_err(|e| format!("failed to run {}: {e}", script_path.display()))?;

    if status.success() {
        Ok(())
    } else {
        Err(format!(
            "{} failed with status {status}",
            script_path.display()
        ))
    }
}

fn repo_root() -> Result<PathBuf, String> {
    let mut candidates = Vec::new();
    if let Ok(current_dir) = env::current_dir() {
        candidates.push(current_dir);
    }
    if let Ok(exe) = env::current_exe() {
        if let Some(parent) = exe.parent() {
            candidates.push(parent.to_path_buf());
            if let Some(grand) = parent.parent() {
                candidates.push(grand.to_path_buf());
            }
        }
    }

    for start in candidates {
        if let Some(found) = search_upwards(&start) {
            return Ok(found);
        }
    }

    Err("Unable to locate repository root".into())
}

fn search_upwards(start: &Path) -> Option<PathBuf> {
    let mut current = start.to_path_buf();
    loop {
        if current.join("Cargo.toml").exists() && current.join("scripts").is_dir() {
            return Some(current);
        }
        if !current.pop() {
            break;
        }
    }
    None
}
