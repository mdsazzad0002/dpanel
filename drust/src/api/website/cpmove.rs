//! cPanel-style `cpmove-<owner>.tar.gz` backups of a whole account, and their restore.

#[allow(unused_imports)]
use super::*;

pub(super) fn archive_cpmove(
    archive_path: &Path,
    website: &WebsiteArchive,
) -> Result<serde_json::Value, String> {
    let owner = website.site_owner.as_deref().unwrap_or("").trim();
    if owner.is_empty()
        || !owner
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-')
    {
        return Err("a valid cPanel account owner is required".into());
    }
    let run_dir = archive_path
        .parent()
        .ok_or("backup run directory is missing")?;
    let stage = run_dir.join(format!(".cpmove-{owner}-building"));
    let package = stage.join(format!("cpmove-{owner}"));
    if stage.exists() {
        fs::remove_dir_all(&stage).map_err(|e| format!("cannot reset staging directory: {e}"))?;
    }
    let with_home = matches!(website.content.as_str(), "all" | "files");
    for directory in ["homedir", "mysql", "dnszones", "userdata", "meta"] {
        if directory == "homedir" && with_home {
            continue;
        }
        fs::create_dir_all(package.join(directory))
            .map_err(|e| format!("cannot create cpmove layout: {e}"))?;
    }

    let owner_home = crate::platform::paths().site_home(owner);
    if matches!(website.content.as_str(), "all" | "files") && !owner_home.is_dir() {
        fs::remove_dir_all(&stage).ok();
        return Err(format!(
            "account home not found: {} — refusing to write a backup without website files",
            owner_home.display()
        ));
    }
    // The home goes straight into the archive as homedir/, without a copy.
    let include_home = matches!(website.content.as_str(), "all" | "files");
    if matches!(website.content.as_str(), "all" | "database") {
        let mut grants = String::new();
        for database in &website.database_requests {
            let safe_name = cli::dump_file_name(&database.name);
            let login = cli::Login { host: &database.host, user: &database.user, password: &database.password };
            if let Err(error) = cli::dump_to_file(
                &login,
                &database.name,
                &["--single-transaction", "--skip-lock-tables", "--routines", "--events", "--triggers"],
                &package.join("mysql").join(format!("{safe_name}.sql")),
            ) {
                fs::remove_dir_all(&stage).ok();
                return Err(error);
            }
            let escaped = database.password.replace('\\', "\\\\").replace('\'', "\\'");
            grants.push_str(&format!("CREATE DATABASE IF NOT EXISTS `{}` CHARACTER SET utf8mb4;\nCREATE USER IF NOT EXISTS '{}'@'%' IDENTIFIED BY '{}';\nGRANT ALL PRIVILEGES ON `{}`.* TO '{}'@'%';\n", database.name, database.user, escaped, database.name, database.user));
        }
        grants.push_str("FLUSH PRIVILEGES;\n");
        fs::write(package.join("mysql.sql"), grants)
            .map_err(|e| format!("cannot write database grants: {e}"))?;
    }

    let website_meta = serde_json::json!({
        "id": website.id, "domain": website.domain, "root_path": website.root_path,
        "project_root": website.project_root, "start_directory": website.start_directory,
        "site_owner": website.site_owner, "php_version": website.php_version,
        "status": website.status, "type": website.type_field,
        "enable_ssl": website.enable_ssl.unwrap_or(false),
        "assigned_user_id": website.assigned_user_id,
        "assigned_reseller_id": website.assigned_reseller_id,
        "content": website.content,
        "databases": website.database_requests.iter().map(|d| serde_json::json!({
            "name": d.name, "user": d.user, "password": d.password, "host": d.host
        })).collect::<Vec<_>>(),
        "archived_at": chrono_like_now()
    });
    let manifest = serde_json::json!({
        "format": "cpanel-cpmove", "archive_version": 4, "pkgacct_version": 10,
        "generator": "dPanel/dRust", "website": website_meta
    });
    let manifest_bytes =
        serde_json::to_vec_pretty(&manifest).map_err(|e| format!("cannot encode metadata: {e}"))?;
    fs::write(package.join("meta/dpanel-manifest.json"), &manifest_bytes)
        .map_err(|e| format!("cannot write metadata: {e}"))?;
    fs::write(
        package.join("userdata/main.json"),
        serde_json::to_vec_pretty(&website_meta).unwrap_or_default(),
    )
    .map_err(|e| format!("cannot write userdata: {e}"))?;
    fs::write(package.join("version"), "10\n4\n")
        .map_err(|e| format!("cannot write version: {e}"))?;
    fs::write(
        package.join("homedir_paths"),
        format!("{}\n", owner_home.display()),
    )
    .map_err(|e| format!("cannot write home path: {e}"))?;
    fs::write(package.join("cp"), format!("{}\n", website.domain))
        .map_err(|e| format!("cannot write primary domain: {e}"))?;
    fs::write(package.join("quota"), "0\n").map_err(|e| format!("cannot write quota: {e}"))?;
    fs::write(package.join("pds"), "").map_err(|e| format!("cannot write aliases: {e}"))?;
    fs::write(package.join("sds"), "").map_err(|e| format!("cannot write subdomains: {e}"))?;
    fs::write(package.join("sds2"), "{}\n")
        .map_err(|e| format!("cannot write subdomain map: {e}"))?;
    let shell = platform::accounts()
        .shell(owner)
        .unwrap_or_else(|| NO_LOGIN_SHELL.into());
    fs::write(package.join("shell"), format!("{shell}\n"))
        .map_err(|e| format!("cannot write shell: {e}"))?;
    let shadow = platform::accounts().password_hash(owner).unwrap_or_default();
    fs::write(package.join("shadow"), format!("{shadow}\n"))
        .map_err(|e| format!("cannot write shadow: {e}"))?;

    let package_name = package
        .file_name()
        .and_then(|v| v.to_str())
        .ok_or("invalid package name")?;
    let home_name = format!("{package_name}/homedir");
    let mut sources: Vec<(&str, &Path)> = vec![(package_name, package.as_path())];
    if include_home {
        sources.push((home_name.as_str(), owner_home.as_path()));
    }
    let created = crate::app::archive::create_tar_gz(archive_path, &sources);
    fs::remove_dir_all(&stage).ok();
    created.map_err(|e| format!("cannot create cpmove tarball: {e}"))?;
    fs::write(format!("{}.json", archive_path.display()), &manifest_bytes)
        .map_err(|e| format!("cannot write archive index metadata: {e}"))?;
    platform::files()
        .set_mode(archive_path, FileMode::SiteFile)
        .map_err(|e| format!("cannot protect archive: {e}"))?;
    let _ = platform::files().set_mode(
        Path::new(&format!("{}.json", archive_path.display())),
        FileMode::SiteFile,
    );
    crate::app::try_chown(&[
        "root:www-data",
        archive_path.to_string_lossy().as_ref(),
        &format!("{}.json", archive_path.display()),
    ]);
    Ok(
        serde_json::json!({ "archive_path": archive_path, "format": "cpanel-cpmove", "website": website_meta }),
    )
}

/// The ids a backup records so a restore can bring the owner back with them.
/// A local profile has none: its stand-in ids must not reach a server as root's.
pub(super) fn archived_account_ids(owner: &str) -> Option<platform::fs::OwnerId> {
    if owner.is_empty() || platform::current().one_user() {
        return None;
    }
    platform::accounts().lookup(owner).ok()
}

pub(super) fn restore_cpmove(path: &Path, progress: &RestoreProgress) -> Result<serde_json::Value, String> {
    let staging = platform::paths()
        .storage_dir()
        .join("restore-staging")
        .join(std::process::id().to_string());
    if staging.exists() {
        fs::remove_dir_all(&staging).ok();
    }
    // Every exit path must drop the staging copy: a failed multi-GB restore used
    // to leave its extracted tree behind, eating the disk the retry then needed.
    let result = restore_cpmove_staged(path, &staging, progress);
    fs::remove_dir_all(&staging).ok();
    result
}

pub(super) fn restore_cpmove_staged(
    path: &Path,
    staging: &Path,
    progress: &RestoreProgress,
) -> Result<serde_json::Value, String> {
    let archive_size = fs::metadata(path).map(|m| m.len()).unwrap_or(0);
    let path_arg = path.to_string_lossy().to_string();

    // Listing reads the whole archive once; report how far it is.
    let report = byte_progress(progress, "inspecting", "Inspecting archive", (2, 15));
    let listing = crate::app::archive::list_with(path, Some(&report))
        .map_err(|e| format!("invalid cpmove tarball: {e}"))?;
    for entry in &listing {
        if !crate::app::archive::safe_relative(&entry.name) {
            return Err("unsafe path in cpmove archive".into());
        }
    }

    // The gzip'd archive expands (typically 1.5–3x) into staging and is then
    // copied again into /home, so check both filesystems up front rather than
    // dying with ENOSPC half way through.
    progress.update("disk_check", 15, "Checking free disk space");
    fs::create_dir_all(staging).map_err(|e| format!("cannot create restore staging: {e}"))?;
    ensure_free_space(staging, archive_size.saturating_mul(2), "extracting the archive")?;
    ensure_free_space(platform::paths().sites_root(), archive_size.saturating_mul(2), "restoring the home directory")?;

    // dPanel's own backup of a home, so links (a Laravel public/storage)
    // come back as links; devices and FIFOs are still refused.
    let report = byte_progress(progress, "extracting", "Extracting archive", (15, 55));
    crate::app::archive::extract_with(
        path,
        staging,
        None,
        &crate::app::archive::Options { links: true, progress: Some(&report) },
    )
    .map_err(|e| format!("cannot extract cpmove archive {path_arg}: {e}"))?;

    progress.update("reading_manifest", 55, "Reading backup metadata");
    let package = fs::read_dir(staging)
        .map_err(|e| format!("cannot read restore staging: {e}"))?
        .filter_map(Result::ok)
        .map(|entry| entry.path())
        .find(|entry| {
            entry.is_dir()
                && entry
                    .file_name()
                    .and_then(|v| v.to_str())
                    .map(|v| v.starts_with("cpmove-"))
                    .unwrap_or(false)
        })
        .ok_or("cpmove account directory is missing")?;
    let raw = fs::read_to_string(package.join("meta/dpanel-manifest.json")).map_err(|_| {
        "dPanel migration metadata is missing; external cPanel import mapping is required"
            .to_string()
    })?;
    let manifest: serde_json::Value =
        serde_json::from_str(&raw).map_err(|e| format!("invalid migration metadata: {e}"))?;
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
        return Err("invalid account owner".into());
    }

    progress.update("account", 58, &format!("Preparing system user {owner}"));
    if !platform::accounts().exists(owner).unwrap_or(false) {
        platform::accounts()
            .create(&AccountSpec::restored(owner, None, None))
            .map_err(|e| format!("cannot recreate account user: {e}"))?;
    }

    progress.update("files", 60, "Copying website files into the home directory");
    let home = crate::platform::paths().site_home(owner);
    fs::create_dir_all(&home).map_err(|e| format!("cannot create account home: {e}"))?;
    if package.join("homedir").exists() {
        crate::app::archive::copy_tree(&package.join("homedir"), &home)
            .map_err(|e| format!("cannot restore homedir: {e}"))?;
    }

    if let Some(databases) = website.get("databases").and_then(|v| v.as_array()) {
        let count = databases.len().max(1);
        for (index, database) in databases.iter().enumerate() {
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
                return Err("invalid database identity in cpmove metadata".into());
            }
            let from = 75 + (index * 20 / count) as u8;
            let to = 75 + ((index + 1) * 20 / count) as u8;
            progress.update("databases", from, &format!("Creating database {name}"));
            cli::provision(name, user, password)?;
            let safe_name = cli::dump_file_name(&name);
            // Streamed rather than fs::read: a multi-GB dump loaded whole into
            // memory can exhaust RAM on a smaller server.
            let dump = package.join("mysql").join(format!("{safe_name}.sql"));
            if !dump.is_file() {
                return Err(format!("database dump missing for {name}"));
            }
            let import = cli::import_command(&cli::Login { host, user, password }, name);
            pipe_file_into(
                import,
                &dump,
                progress,
                "databases",
                &format!("Importing database {name}"),
                (from, to),
            )
            .map_err(|e| format!("database restore failed for {name}: {e}"))?;
        }
    }
    progress.update("permissions", 96, "Fixing file ownership");
    crate::app::try_chown(&[
        "-R",
        &format!("{owner}:{owner}"),
        home.to_string_lossy().as_ref(),
    ]);
    progress.update("finalizing", 98, "Saving panel records");
    Ok(website)
}
