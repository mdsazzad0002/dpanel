//! The panel's own zip of a website (trash, clone), with its database dumps.

#[allow(unused_imports)]
use super::*;

pub(super) fn archive_website(zip_path: &str, website: &WebsiteArchive) -> Result<serde_json::Value, String> {
    if !matches!(
        website.content.as_str(),
        "all" | "files" | "database" | "quick_files"
    ) {
        return Err("invalid archive content; use all, files, database, or quick_files".into());
    }
    let zip_path = normalize_path(zip_path);
    if zip_path.as_os_str().is_empty() {
        return Err("Missing zip path.".into());
    }
    // website-trash holds the pre-delete archive written when a website is
    // removed; restore_archive and the trash delete endpoint already use it.
    let allowed_roots = [
        platform::paths().storage_dir().join("backups"),
        platform::paths().storage_dir().join("website-trash"),
    ];
    if !allowed_roots.iter().any(|root| zip_path.starts_with(root))
        || zip_path
            .components()
            .any(|part| matches!(part, Component::ParentDir))
    {
        return Err("refusing to write archive outside the backup directory".into());
    }

    let owner = website.site_owner.as_deref().unwrap_or("").trim();
    if owner.is_empty()
        || !owner
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-')
    {
        return Err("invalid website owner".into());
    }
    let owner_home = crate::platform::paths().site_home(owner);
    for candidate in [&website.root_path, &website.project_root] {
        if candidate.trim().is_empty() {
            continue;
        }
        let path = normalize_path(candidate);
        if (path != owner_home && !path.starts_with(&owner_home))
            || path
                .components()
                .any(|part| matches!(part, Component::ParentDir))
        {
            return Err(format!(
                "refusing to archive path outside {}",
                owner_home.display()
            ));
        }
    }

    if let Some(parent) = zip_path.parent() {
        fs::create_dir_all(parent)
            .map_err(|e| format!("failed to create archive directory: {e}"))?;
    }

    if zip_path.to_string_lossy().ends_with(".tar.gz") {
        return archive_cpmove(&zip_path, website);
    }

    let file = fs::File::create(&zip_path)
        .map_err(|e| format!("failed to create zip archive {}: {e}", zip_path.display()))?;
    let mut writer = ZipWriter::new(file);
    let options = SimpleFileOptions::default()
        .compression_method(CompressionMethod::Deflated)
        .unix_permissions(0o644);
    // Directories need the execute bit or the extracted tree is unbrowsable
    // (permission denied cd'ing in) for whoever unzips it — `options` above is
    // file-only (0o644), so directories get their own mode.
    let dir_options = options.unix_permissions(0o755);
    let owner = website.site_owner.as_deref().unwrap_or("");
    let system_ids = archived_account_ids(owner);
    let system_uid = system_ids.map(|ids| ids.uid);
    let system_gid = system_ids.map(|ids| ids.gid);

    let is_quick_files = website.content.as_str() == "quick_files";
    let includes_files = matches!(website.content.as_str(), "all" | "files" | "quick_files");
    let mut stats = ArchiveStats::default();

    if !is_quick_files {
        let manifest = serde_json::json!({
            "website": {
                "id": website.id,
                "domain": website.domain,
                "root_path": website.root_path,
                "project_root": website.project_root,
                "start_directory": website.start_directory,
                "site_owner": website.site_owner,
                "system_uid": system_uid,
                "system_gid": system_gid,
                "php_version": website.php_version,
                "status": website.status,
                "type": website.type_field,
                "enable_ssl": website.enable_ssl.unwrap_or(false),
                "assigned_user_id": website.assigned_user_id,
                "assigned_reseller_id": website.assigned_reseller_id,
                "content": website.content,
                "databases": website.database_requests.iter().map(|database| serde_json::json!({
                    "name": database.name,
                    "user": database.user,
                    "password": database.password,
                    "host": database.host,
                })).collect::<Vec<_>>(),
                "archived_at": chrono_like_now(),
            }
        });
        writer
            .start_file("meta/manifest.json", options)
            .map_err(|e| format!("failed to write manifest: {e}"))?;
        writer
            .write_all(manifest.to_string().as_bytes())
            .map_err(|e| format!("failed to write manifest: {e}"))?;
    }

    if is_quick_files {
        // Quick export is scoped to this one website, so it must zip only its own
        // docroot (root_path) — not project_root, which is the whole account home
        // and would pull in sibling sites/other data sharing that account.
        let source_path = normalize_path(if website.root_path.trim().is_empty() {
            website.project_root.as_str()
        } else {
            website.root_path.as_str()
        });
        if !source_path.is_dir() {
            return Err(format!(
                "website files directory not found: {}",
                source_path.display()
            ));
        }
        add_dir(&mut writer, &source_path, "", options, dir_options, &mut stats)?;

        // For frameworks (Laravel etc.) root_path is a docroot subdirectory
        // (e.g. project_root/public) while .env, composer.json and similar
        // top-level app config live one level up in project_root. Only the
        // *files* sitting directly in project_root are pulled in — never its
        // subdirectories — so sibling sites/data sharing that account home
        // are never touched. They're archived under a marker directory so
        // restore can place them in the target's project_root instead of
        // flattening them into the docroot alongside root_path's contents.
        let project_root = normalize_path(website.project_root.as_str());
        if project_root.exists() && project_root.is_dir() && project_root != source_path {
            if let Ok(entries) = fs::read_dir(&project_root) {
                for entry in entries.flatten() {
                    let path = entry.path();
                    if !path.is_file() {
                        continue;
                    }
                    let Some(name) = path.file_name().and_then(|v| v.to_str()) else {
                        continue;
                    };
                    if path == source_path.join(name) {
                        continue;
                    }
                    let mut file = match fs::File::open(&path) {
                        Ok(file) => file,
                        Err(error) => {
                            stats.skip(&path, error);
                            continue;
                        }
                    };
                    let size = file.metadata().map(|meta| meta.len()).unwrap_or(0);
                    let file_options = file_permissions(&path)
                        .map(|mode| options.unix_permissions(mode))
                        .unwrap_or(options)
                        .large_file(size >= u64::from(u32::MAX));
                    let file_options = with_modified_time(&path, file_options);
                    let archive_path = format!("__project_root_extras__/{name}");
                    if let Err(error) = writer.start_file(&archive_path, file_options) {
                        stats.skip(&path, error);
                        continue;
                    }
                    if let Err(error) = std::io::copy(&mut file, &mut writer) {
                        let _ = writer.abort_file();
                        stats.skip(&path, error);
                        continue;
                    }
                    stats.files += 1;
                }
            }
        }
    }

    if matches!(website.content.as_str(), "all" | "files") {
        let source_path = normalize_path(if website.project_root.trim().is_empty() {
            website.root_path.as_str()
        } else {
            website.project_root.as_str()
        });
        if !source_path.is_dir() {
            return Err(format!(
                "website files directory not found: {}",
                source_path.display()
            ));
        }
        writer
            .add_directory("homedir/public_html/", dir_options)
            .map_err(|e| format!("failed to create homedir layout: {e}"))?;
        add_dir(
            &mut writer,
            &source_path,
            "homedir/public_html",
            options,
            dir_options,
            &mut stats,
        )?;
    }

    if matches!(website.content.as_str(), "all" | "database") {
        for database in &website.database_requests {
            add_database_dump(&mut writer, database, options)?;
        }
    }

    writer
        .finish()
        .map_err(|e| format!("failed to finalize zip: {e}"))?;
    if includes_files && stats.files == 0 && stats.skipped > 0 {
        fs::remove_file(&zip_path).ok();
        return Err(format!(
            "no website files could be read ({} skipped), e.g. {}",
            stats.skipped,
            stats.skipped_samples.join("; ")
        ));
    }
    platform::files()
        .set_mode(&zip_path, FileMode::SiteFile)
        .map_err(|e| format!("failed to protect archive: {e}"))?;
    crate::app::try_chown(&["root:www-data", zip_path.to_string_lossy().as_ref()]);
    Ok(serde_json::json!({
        "zip_path": zip_path.display().to_string(),
        "files": stats.files,
        "skipped": stats.skipped,
        "skipped_samples": stats.skipped_samples,
    }))
}

pub(super) fn add_database_dump(
    writer: &mut ZipWriter<std::fs::File>,
    database: &DatabaseArchive,
    options: SimpleFileOptions,
) -> Result<(), String> {
    if database.name.is_empty() || database.user.is_empty() {
        return Err("database name and user are required".into());
    }
    let login = cli::Login { host: &database.host, user: &database.user, password: &database.password };
    let dump = cli::dump(&login, &database.name, &["--single-transaction", "--skip-lock-tables"])?;
    let safe_name = cli::dump_file_name(&database.name);
    writer
        .start_file(format!("mysql/{safe_name}.sql"), options)
        .map_err(|error| format!("cannot add database dump: {error}"))?;
    writer
        .write_all(&dump)
        .map_err(|error| format!("cannot write database dump: {error}"))?;
    Ok(())
}

/// What add_dir actually managed to put in the archive. Unreadable entries are
/// still skipped rather than aborting the export, but they are counted and
/// reported back — previously they vanished silently, so an export whose files
/// all failed still "succeeded" with nothing but the database dump in it.
#[derive(Default)]
pub(super) struct ArchiveStats {
    files: u64,
    skipped: u64,
    skipped_samples: Vec<String>,
}

impl ArchiveStats {
    pub(super) fn skip(&mut self, path: &Path, reason: impl std::fmt::Display) {
        self.skipped += 1;
        if self.skipped_samples.len() < 10 {
            self.skipped_samples
                .push(format!("{}: {reason}", path.display()));
        }
    }
}

pub(super) fn add_dir(
    writer: &mut ZipWriter<std::fs::File>,
    source: &Path,
    zip_root: &str,
    options: SimpleFileOptions,
    dir_options: SimpleFileOptions,
    stats: &mut ArchiveStats,
) -> Result<(), String> {
    if !source.exists() {
        return Ok(());
    }

    let entries = match fs::read_dir(source) {
        Ok(entries) => entries,
        // A directory we can't list (permission denied, gone mid-walk, etc.)
        // shouldn't fail the whole archive — skip it, but report it.
        Err(error) => {
            stats.skip(source, error);
            return Ok(());
        }
    };
    for entry in entries {
        let entry = match entry {
            Ok(item) => item,
            Err(error) => {
                stats.skip(source, error);
                continue;
            }
        };
        let path = entry.path();
        if !path.exists() {
            continue;
        }

        let name = match path.file_name().and_then(|v| v.to_str()) {
            Some(v) => v.to_string(),
            None => {
                stats.skip(&path, "file name is not valid UTF-8");
                continue;
            }
        };
        let archive_path = if zip_root.is_empty() {
            name
        } else {
            format!("{}/{}", zip_root.trim_matches('/'), name)
        };

        if path.is_dir() {
            if let Err(error) = writer.add_directory(
                format!("{}/", archive_path),
                with_modified_time(&path, dir_options),
            ) {
                stats.skip(&path, error);
                continue;
            }
            add_dir(writer, &path, &archive_path, options, dir_options, stats)?;
            continue;
        }

        if path.is_file() {
            let mut file = match fs::File::open(&path) {
                Ok(file) => file,
                // Unreadable file (permission denied, socket, etc.) — skip it,
                // don't abort the entire export over one file.
                Err(error) => {
                    stats.skip(&path, error);
                    continue;
                }
            };
            let size = file.metadata().map(|meta| meta.len()).unwrap_or(0);
            let file_options = file_permissions(&path)
                .map(|mode| options.unix_permissions(mode))
                .unwrap_or(options)
                // Without ZIP64 the zip writer aborts any entry past 4 GiB
                // ("Large file option has not been set"), which dropped big
                // files (media, nested backups) from large sites.
                .large_file(size >= u64::from(u32::MAX));
            let file_options = with_modified_time(&path, file_options);
            if let Err(error) = writer.start_file(&archive_path, file_options) {
                stats.skip(&path, error);
                continue;
            }
            if let Err(error) = std::io::copy(&mut file, writer) {
                // Drop the half-written entry instead of leaving a truncated
                // file in the archive.
                let _ = writer.abort_file();
                stats.skip(&path, error);
                continue;
            }
            stats.files += 1;
        }
    }

    Ok(())
}

/// Stamps an entry with the file's real modification time. Without it the
/// zip writer uses its 1980-01-01 default, and since restore extracts with
/// `unzip` (which applies entry times), every restored file looked ancient.
/// Zip times are local time, which is how `unzip` reads them back.
pub(super) fn with_modified_time(path: &Path, options: SimpleFileOptions) -> SimpleFileOptions {
    use chrono::{Datelike, Timelike};
    let Ok(modified) = fs::metadata(path).and_then(|meta| meta.modified()) else {
        return options;
    };
    let local: chrono::DateTime<chrono::Local> = modified.into();
    let (Ok(year), Ok(month), Ok(day), Ok(hour), Ok(minute), Ok(second)) = (
        u16::try_from(local.year()),
        u8::try_from(local.month()),
        u8::try_from(local.day()),
        u8::try_from(local.hour()),
        u8::try_from(local.minute()),
        u8::try_from(local.second()),
    ) else {
        return options;
    };
    match zip::DateTime::from_date_and_time(year, month, day, hour, minute, second) {
        Ok(time) => options.last_modified_time(time),
        Err(_) => options,
    }
}

// The archive doesn't store uid/gid, so a mode copied verbatim from the source
// (e.g. 600/400 owned by root or another account) can leave the extracting user
// unable to read/write their own files. Normalize instead: keep the executable
// bit for scripts/binaries, otherwise use a standard owner-writable mode.
pub(super) fn file_permissions(path: &Path) -> Option<u32> {
    let metadata = fs::metadata(path).ok()?;
    let mode = platform::files().mode_bits(&metadata)? & 0o777;
    Some(if mode & 0o100 != 0 { 0o755 } else { 0o644 })
}
