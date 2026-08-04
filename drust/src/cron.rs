use std::fs;
use std::os::unix::fs::PermissionsExt;
use std::path::PathBuf;

fn cron_paths(id: &str) -> Result<(PathBuf, PathBuf), String> {
    if id.is_empty() || !id.chars().all(|ch| ch.is_ascii_alphanumeric() || ch == '-') {
        return Err("Cron job id contains invalid characters.".to_string());
    }

    Ok((
        PathBuf::from(format!("/etc/cron.d/dpanel-{id}")),
        PathBuf::from(format!("/etc/cron.d/serverpanel-{id}")),
    ))
}

pub(crate) fn upsert(
    id: &str,
    user: &str,
    expression: &str,
    command: &str,
    enabled: bool,
) -> Result<String, String> {
    let (path, legacy_path) = cron_paths(id)?;
    if !enabled {
        return delete(id);
    }
    if user.is_empty()
        || !user
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-')
    {
        return Err("Cron user is invalid.".to_string());
    }
    if expression.split_whitespace().count() != 5 {
        return Err("Cron expression must contain exactly five fields.".to_string());
    }
    if expression.contains(['\n', '\r'])
        || command.trim().is_empty()
        || command.contains(['\n', '\r'])
    {
        return Err("Cron expression and command must be single-line values.".to_string());
    }

    let content = format!(
        "# Managed by dPanel; job-id={id}\n{} {} {}\n",
        expression.trim(),
        user,
        command.trim()
    );
    let temp = path.with_extension(format!("tmp-{}", std::process::id()));
    fs::write(&temp, content).map_err(|error| format!("Cannot write cron file: {error}"))?;
    fs::set_permissions(&temp, fs::Permissions::from_mode(0o644))
        .map_err(|error| format!("Cannot set cron file permissions: {error}"))?;
    fs::rename(&temp, &path).map_err(|error| format!("Cannot activate cron file: {error}"))?;
    if legacy_path.exists() {
        fs::remove_file(&legacy_path)
            .map_err(|error| format!("Cannot remove legacy cron file: {error}"))?;
    }

    Ok(format!("System cron job {id} installed."))
}

pub(crate) fn delete(id: &str) -> Result<String, String> {
    let (path, legacy_path) = cron_paths(id)?;
    for candidate in [path, legacy_path] {
        if candidate.exists() {
            fs::remove_file(&candidate)
                .map_err(|error| format!("Cannot remove cron file: {error}"))?;
        }
    }

    Ok(format!("System cron job {id} removed."))
}
