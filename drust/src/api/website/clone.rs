//! The Clone & Share / Push to Live package: files.zip, database.sql and manifest.json.

#[allow(unused_imports)]
use super::*;

/// Builds the portable package used by Clone & Share and Push to Live in one
/// root-side step: files.zip (a quick_files archive of the docroot),
/// database.sql (the site's first database) and manifest.json. Doing it here
/// rather than in PHP matters because every site's files belong to a
/// different system user, and the panel's PHP worker can neither read them
/// nor reliably write next to archives dRust created as root.
pub(super) fn build_clone_package(
    package_path: &str,
    mut website: WebsiteArchive,
) -> Result<serde_json::Value, String> {
    let package = normalize_path(package_path);
    let root = platform::paths().storage_dir().join("backups").join("clone-share");
    if !package.starts_with(&root)
        || package.extension().and_then(|v| v.to_str()) != Some("zip")
        || package
            .components()
            .any(|part| matches!(part, Component::ParentDir))
    {
        return Err("refusing to write a clone package outside storage/app/backups/clone-share".into());
    }
    fs::create_dir_all(root).map_err(|e| format!("cannot create package directory: {e}"))?;
    let files_path = package.with_extension("files.tmp.zip");
    let sql_path = package.with_extension("sql.tmp");
    let cleanup = || {
        fs::remove_file(&files_path).ok();
        fs::remove_file(&sql_path).ok();
    };

    let result = (|| {
        let databases = std::mem::take(&mut website.database_requests);
        website.content = "quick_files".into();
        let files = archive_website(files_path.to_string_lossy().as_ref(), &website)?;

        let database = databases.first();
        if let Some(database) = database {
            dump_database_to_file(database, &sql_path)?;
        }

        let output = fs::File::create(&package)
            .map_err(|e| format!("cannot create clone package {}: {e}", package.display()))?;
        let mut writer = ZipWriter::new(output);
        // files.zip is already compressed; re-deflating multi-GB data only costs time.
        let stored = SimpleFileOptions::default()
            .compression_method(CompressionMethod::Stored)
            .unix_permissions(0o644);
        let deflated = SimpleFileOptions::default()
            .compression_method(CompressionMethod::Deflated)
            .unix_permissions(0o644);
        copy_into_zip(&mut writer, &files_path, "files.zip", stored)?;
        if database.is_some() {
            copy_into_zip(&mut writer, &sql_path, "database.sql", deflated)?;
        }
        let manifest = serde_json::json!({
            "domain": website.domain,
            "php_version": website.php_version,
            "has_database": database.is_some(),
            "created_at": chrono_like_now(),
        });
        writer
            .start_file("manifest.json", deflated)
            .map_err(|e| format!("cannot write package manifest: {e}"))?;
        writer
            .write_all(manifest.to_string().as_bytes())
            .map_err(|e| format!("cannot write package manifest: {e}"))?;
        writer
            .finish()
            .map_err(|e| format!("cannot finalize clone package: {e}"))?;

        // The panel's PHP worker reads it back (to upload or serve it).
        platform::files()
            .set_mode(&package, FileMode::SiteFile)
            .map_err(|e| format!("cannot protect clone package: {e}"))?;
        crate::app::try_chown("root", "www-data", &package, crate::app::Depth::One);

        Ok(serde_json::json!({
            "package_path": package.display().to_string(),
            "size": fs::metadata(&package).map(|m| m.len()).unwrap_or(0),
            "has_database": database.is_some(),
            "files": files.get("files").cloned().unwrap_or_default(),
            "skipped": files.get("skipped").cloned().unwrap_or_default(),
            "skipped_samples": files.get("skipped_samples").cloned().unwrap_or_default(),
        }))
    })();
    cleanup();
    if result.is_err() {
        fs::remove_file(&package).ok();
    }
    result
}

/// mysqldump straight to a file: a large database never sits in memory.
pub(super) fn dump_database_to_file(database: &DatabaseArchive, target: &Path) -> Result<(), String> {
    if database.name.is_empty() || database.user.is_empty() {
        return Err("database name and user are required".into());
    }
    let login = cli::Login { host: &database.host, user: &database.user, password: &database.password };
    cli::dump_to_file(
        &login,
        &database.name,
        &["--single-transaction", "--skip-lock-tables", "--routines", "--triggers"],
        target,
    )
}

pub(super) fn copy_into_zip(
    writer: &mut ZipWriter<std::fs::File>,
    source: &Path,
    name: &str,
    options: SimpleFileOptions,
) -> Result<(), String> {
    let mut input =
        fs::File::open(source).map_err(|e| format!("cannot open {}: {e}", source.display()))?;
    let size = input.metadata().map(|m| m.len()).unwrap_or(0);
    writer
        .start_file(name, options.large_file(size >= u64::from(u32::MAX)))
        .map_err(|e| format!("cannot add {name} to package: {e}"))?;
    std::io::copy(&mut input, writer).map_err(|e| format!("cannot write {name} to package: {e}"))?;
    Ok(())
}
