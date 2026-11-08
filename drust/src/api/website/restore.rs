//! Restoring the panel's own website zip.

#[allow(unused_imports)]
use super::*;

pub(super) fn restore_archive(value: &str, progress: &RestoreProgress) -> Result<serde_json::Value, String> {
    progress.update("validating", 1, "Checking backup archive");
    let path = normalize_path(value);
    let allowed_roots = [
        platform::paths().storage_dir().join("backups"),
        platform::paths().storage_dir().join("website-trash"),
    ];
    if !allowed_roots.iter().any(|root| path.starts_with(root))
        || path
            .components()
            .any(|part| matches!(part, Component::ParentDir))
        || !path.is_file()
    {
        return Err("invalid backup archive path".into());
    }
    if path.to_string_lossy().ends_with(".tar.gz") {
        return restore_cpmove(&path, progress);
    }
    if path.extension().and_then(|v| v.to_str()) != Some("zip") {
        return Err("unsupported backup archive type".into());
    }

    let file = fs::File::open(&path).map_err(|e| format!("cannot open archive: {e}"))?;
    let mut archive = ZipArchive::new(file).map_err(|e| format!("invalid zip archive: {e}"))?;
    let mut manifest_raw = String::new();
    archive
        .by_name("meta/manifest.json")
        .map_err(|_| "archive metadata is missing".to_string())?
        .read_to_string(&mut manifest_raw)
        .map_err(|e| format!("cannot read archive metadata: {e}"))?;
    let manifest: serde_json::Value = serde_json::from_str(&manifest_raw)
        .map_err(|e| format!("invalid archive metadata: {e}"))?;
    let website = manifest
        .get("website")
        .cloned()
        .ok_or("website metadata is missing")?;
    let owner = website
        .get("site_owner")
        .and_then(|v| v.as_str())
        .unwrap_or("");
    if owner.is_empty()
        || !owner
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-')
    {
        return Err("invalid website owner in archive".into());
    }
    let project_root = normalize_path(
        website
            .get("project_root")
            .and_then(|v| v.as_str())
            .unwrap_or(""),
    );
    let owner_home = crate::platform::paths().site_home(owner);
    if project_root.as_os_str().is_empty() || !project_root.starts_with(&owner_home) {
        return Err("archive project path is outside the website owner home".into());
    }

    if !platform::accounts().exists(owner).unwrap_or(false) {
        let archived_id = |key: &str| {
            website
                .get(key)
                .and_then(|value| value.as_u64())
                .and_then(|id| u32::try_from(id).ok())
        };
        let spec = AccountSpec::restored(owner, archived_id("system_uid"), archived_id("system_gid"));
        platform::accounts()
            .create(&spec)
            .map_err(|error| format!("cannot recreate website user: {error}"))?;
    }
    fs::create_dir_all(&project_root).map_err(|e| format!("cannot create website root: {e}"))?;

    progress.update("files", 20, "Restoring website files");
    for index in 0..archive.len() {
        let mut entry = archive
            .by_index(index)
            .map_err(|e| format!("cannot read zip entry: {e}"))?;
        let name = entry
            .enclosed_name()
            .ok_or("unsafe path in archive")?
            .to_path_buf();
        let prefix = Path::new("homedir/public_html");
        if !name.starts_with(prefix) {
            continue;
        }
        let relative = name
            .strip_prefix(prefix)
            .map_err(|_| "invalid homedir path")?;
        let target = project_root.join(relative);
        if entry.is_dir() {
            fs::create_dir_all(&target).map_err(|e| format!("cannot restore directory: {e}"))?;
        } else {
            if let Some(parent) = target.parent() {
                fs::create_dir_all(parent)
                    .map_err(|e| format!("cannot create restore directory: {e}"))?;
            }
            let mut output =
                fs::File::create(&target).map_err(|e| format!("cannot restore file: {e}"))?;
            std::io::copy(&mut entry, &mut output)
                .map_err(|e| format!("cannot write restored file: {e}"))?;
        }
    }

    if let Some(databases) = website.get("databases").and_then(|v| v.as_array()) {
        for database in databases {
            let name = database.get("name").and_then(|v| v.as_str()).unwrap_or("");
            let user = database.get("user").and_then(|v| v.as_str()).unwrap_or("");
            let password = database
                .get("password")
                .and_then(|v| v.as_str())
                .unwrap_or("");
            let host = database
                .get("host")
                .and_then(|v| v.as_str())
                .unwrap_or("127.0.0.1");
            if name.is_empty()
                || user.is_empty()
                || !name
                    .chars()
                    .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$')
                || !user
                    .chars()
                    .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$')
            {
                return Err("invalid database identity in archive".into());
            }
            progress.update("databases", 70, &format!("Importing database {name}"));
            cli::provision(name, user, password)?;
            let safe_name = cli::dump_file_name(&name);
            let mut dump = Vec::new();
            archive
                .by_name(&format!("mysql/{safe_name}.sql"))
                .map_err(|_| format!("database dump missing for {name}"))?
                .read_to_end(&mut dump)
                .map_err(|e| format!("cannot read database dump: {e}"))?;
            cli::import(&cli::Login { host, user, password }, name, &dump)?;
        }
    }
    progress.update("permissions", 90, "Fixing file ownership and permissions");
    crate::app::try_chown(&[
        "-R",
        &format!("{owner}:{owner}"),
        project_root.to_string_lossy().as_ref(),
    ]);
    crate::filemanager::fix_permissions::run(Some(owner), owner_home.to_str(), false).map_err(
        |error| format!("files restored but permissions could not be repaired: {error}"),
    )?;

    if let Some(version) = website.get("php_version").and_then(|value| value.as_str()) {
        if !version.is_empty() && version.chars().all(|ch| ch.is_ascii_digit() || ch == '.') {
            let service = format!("php{version}-fpm");
            // The archived numeric identity is reused, so a graceful reload is
            // sufficient and avoids interrupting other sites on this runtime.
            crate::platform::services().reload(&service).map_err(|error| {
                format!("files restored but {service} could not be reloaded: {error}")
            })?;
        }
    }
    Ok(website)
}
