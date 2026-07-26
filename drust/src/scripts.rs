use std::env;
use std::path::{Path, PathBuf};
use std::process::Command;

pub fn run_script(script_name: &str, args: &[String]) -> Result<String, String> {
    let safe_name = Path::new(script_name)
        .file_name()
        .and_then(|name| name.to_str())
        .filter(|name| *name == script_name && name.ends_with(".sh"))
        .ok_or_else(|| "Invalid script name".to_string())?;
    let script_path = scripts_dir()?.join(safe_name);
    if !script_path.is_file() {
        return Err(format!("Script not found: {}", script_path.display()));
    }

    let output = Command::new("bash")
        .arg(&script_path)
        .args(args)
        .output()
        .map_err(|e| format!("failed to run {}: {e}", script_path.display()))?;

    let stdout = String::from_utf8_lossy(&output.stdout).trim().to_string();
    let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
    let combined = [stdout, stderr]
        .into_iter()
        .filter(|part| !part.is_empty())
        .collect::<Vec<_>>()
        .join("\n");

    if output.status.success() {
        Ok(combined)
    } else {
        Err(format!(
            "{} failed with status {}{}",
            script_path.display(),
            output.status,
            if combined.is_empty() {
                String::new()
            } else {
                format!(": {combined}")
            }
        ))
    }
}

fn scripts_dir() -> Result<PathBuf, String> {
    for key in ["DRUST_SCRIPTS_DIR", "DPANEL_SCRIPTS_DIR"] {
        if let Some(path) = env::var_os(key)
            .map(PathBuf::from)
            .filter(|path| path.is_dir())
        {
            return Ok(path);
        }
    }

    for path in [
        PathBuf::from("/opt/dpanel/runtime/scripts"),
        PathBuf::from("/var/www/dscript/scripts"),
    ] {
        if path.is_dir() {
            return Ok(path);
        }
    }

    Ok(repo_root()?.join("scripts"))
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
