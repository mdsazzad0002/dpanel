use std::fs;
use std::io::{Read, Write};
#[cfg(unix)]
use std::os::unix::fs::PermissionsExt;
use std::path::{Component, Path, PathBuf};
use std::process::{Command, Stdio};
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use zip::{CompressionMethod, ZipArchive, ZipWriter, write::SimpleFileOptions};

use crate::api::{ApiResponse, ApiState, check_token};

use axum::Router;
use axum::routing::post;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/website/archive", post(handle))
        .route("/api/v1/website/clone-package", post(clone_package_handle))
        .route(
            "/api/v1/website/archive/restore",
            post(restore_archive_handle),
        )
        .route(
            "/api/v1/website/archive/delete",
            post(delete_archive_handle),
        )
        .route("/api/v1/website/delete", post(delete_handle))
}

#[derive(Deserialize)]
pub(crate) struct DeleteArchiveRequest {
    pub zip_path: String,
}

pub(crate) async fn delete_archive_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<DeleteArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match delete_archive(&request.zip_path) {
        Ok(()) => ApiResponse::ok("Website trash archive removed").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

fn delete_archive(value: &str) -> Result<(), String> {
    let path = normalize_path(value);
    let root = Path::new("/var/www/dpanel/storage/app/website-trash");
    if path.extension().and_then(|value| value.to_str()) != Some("zip")
        || !path.starts_with(root)
        || path
            .components()
            .any(|part| matches!(part, Component::ParentDir))
    {
        return Err("refusing to remove an invalid trash archive path".into());
    }

    if path.is_file() {
        fs::remove_file(&path)
            .map_err(|error| format!("cannot remove {}: {error}", path.display()))?;
    }
    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct DeleteRequest {
    pub site_owner: String,
    pub paths: Vec<String>,
    pub delete_user: bool,
}

pub(crate) async fn delete_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<DeleteRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match delete_website(&request) {
        Ok(()) => ApiResponse::ok("Website user and directories removed").into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

fn delete_website(request: &DeleteRequest) -> Result<(), String> {
    let user = request.site_owner.trim();
    if user.is_empty()
        || !user
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-')
    {
        return Err("invalid website owner".into());
    }

    let home = PathBuf::from(format!("/home/{user}"));
    for value in &request.paths {
        let path = normalize_path(value);
        if path != home && !path.starts_with(&home) {
            return Err(format!(
                "refusing to remove path outside {}",
                home.display()
            ));
        }
    }

    if request.delete_user {
        if Command::new("id")
            .args(["-u", user])
            .status()
            .map(|status| status.success())
            .unwrap_or(false)
        {
            let output = Command::new("userdel")
                // Website PHP workers may still be winding down when the panel
                // removes a site. Force account removal so that a short-lived
                // worker cannot leave the website stuck in a half-deleted state.
                .args(["--force", "--remove", user])
                .output()
                .map_err(|error| format!("cannot run userdel: {error}"))?;
            if !output.status.success() {
                return Err(String::from_utf8_lossy(&output.stderr).trim().to_string());
            }
        } else if home.exists() {
            fs::remove_dir_all(&home)
                .map_err(|error| format!("cannot remove {}: {error}", home.display()))?;
        }
        return Ok(());
    }

    for value in &request.paths {
        let path = normalize_path(value);
        if path == home {
            continue;
        }
        if path.is_dir() {
            fs::remove_dir_all(&path)
                .map_err(|error| format!("cannot remove {}: {error}", path.display()))?;
        } else if path.is_file() {
            fs::remove_file(&path)
                .map_err(|error| format!("cannot remove {}: {error}", path.display()))?;
        }
    }
    Ok(())
}

#[derive(Deserialize)]
pub(crate) struct ArchiveRequest {
    pub zip_path: String,
    pub website: WebsiteArchive,
}

#[derive(Deserialize)]
pub(crate) struct WebsiteArchive {
    pub id: String,
    pub domain: String,
    pub root_path: String,
    pub project_root: String,
    pub start_directory: Option<String>,
    pub site_owner: Option<String>,
    pub php_version: Option<String>,
    pub status: Option<String>,
    pub type_field: Option<String>,
    pub enable_ssl: Option<bool>,
    pub assigned_user_id: Option<u64>,
    pub assigned_reseller_id: Option<u64>,
    #[serde(default = "default_archive_content")]
    pub content: String,
    #[serde(default)]
    pub database_requests: Vec<DatabaseArchive>,
}

#[derive(Deserialize)]
pub(crate) struct DatabaseArchive {
    pub name: String,
    pub user: String,
    pub password: String,
    pub host: String,
}

fn default_archive_content() -> String {
    "all".into()
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<ArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match archive_website(&request.zip_path, &request.website) {
        Ok(data) => ApiResponse::ok_data("Website archived successfully", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

pub(crate) async fn clone_package_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<ArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result =
        tokio::task::spawn_blocking(move || build_clone_package(&request.zip_path, request.website))
            .await
            .unwrap_or_else(|error| Err(format!("clone package worker crashed: {error}")));
    match result {
        Ok(data) => ApiResponse::ok_data("Clone package created", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

/// Builds the portable package used by Clone & Share and Push to Live in one
/// root-side step: files.zip (a quick_files archive of the docroot),
/// database.sql (the site's first database) and manifest.json. Doing it here
/// rather than in PHP matters because every site's files belong to a
/// different system user, and the panel's PHP worker can neither read them
/// nor reliably write next to archives dRust created as root.
fn build_clone_package(
    package_path: &str,
    mut website: WebsiteArchive,
) -> Result<serde_json::Value, String> {
    let package = normalize_path(package_path);
    let root = Path::new("/var/www/dpanel/storage/app/backups/clone-share");
    if !package.starts_with(root)
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
        #[cfg(unix)]
        fs::set_permissions(&package, fs::Permissions::from_mode(0o640))
            .map_err(|e| format!("cannot protect clone package: {e}"))?;
        let _ = Command::new("chown")
            .args(["root:www-data", package.to_string_lossy().as_ref()])
            .status();

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
fn dump_database_to_file(database: &DatabaseArchive, target: &Path) -> Result<(), String> {
    if database.name.is_empty() || database.user.is_empty() {
        return Err("database name and user are required".into());
    }
    let output_file =
        fs::File::create(target).map_err(|e| format!("cannot create database dump file: {e}"))?;
    let output = Command::new("mysqldump")
        .env("MYSQL_PWD", &database.password)
        .args([
            "--single-transaction",
            "--skip-lock-tables",
            "--routines",
            "--triggers",
            "--host",
            database.host.as_str(),
            "--user",
            database.user.as_str(),
            database.name.as_str(),
        ])
        .stdout(Stdio::from(output_file))
        .stderr(Stdio::piped())
        .output()
        .map_err(|e| format!("cannot run mysqldump for {}: {e}", database.name))?;
    if !output.status.success() {
        return Err(format!(
            "database dump failed for {}: {}",
            database.name,
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    Ok(())
}

fn copy_into_zip(
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

fn archive_website(zip_path: &str, website: &WebsiteArchive) -> Result<serde_json::Value, String> {
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
        Path::new("/var/www/dpanel/storage/app/backups"),
        Path::new("/var/www/dpanel/storage/app/website-trash"),
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
    let owner_home = PathBuf::from(format!("/home/{owner}"));
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
    let system_uid = numeric_account_id("-u", owner);
    let system_gid = numeric_account_id("-g", owner);

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
    #[cfg(unix)]
    fs::set_permissions(&zip_path, fs::Permissions::from_mode(0o640))
        .map_err(|e| format!("failed to protect archive: {e}"))?;
    let _ = Command::new("chown")
        .args(["root:www-data", zip_path.to_string_lossy().as_ref()])
        .status();
    Ok(serde_json::json!({
        "zip_path": zip_path.display().to_string(),
        "files": stats.files,
        "skipped": stats.skipped,
        "skipped_samples": stats.skipped_samples,
    }))
}

fn archive_cpmove(
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
    for directory in ["homedir", "mysql", "dnszones", "userdata", "meta"] {
        fs::create_dir_all(package.join(directory))
            .map_err(|e| format!("cannot create cpmove layout: {e}"))?;
    }

    let owner_home = PathBuf::from(format!("/home/{owner}"));
    if matches!(website.content.as_str(), "all" | "files") && !owner_home.is_dir() {
        fs::remove_dir_all(&stage).ok();
        return Err(format!(
            "account home not found: {} — refusing to write a backup without website files",
            owner_home.display()
        ));
    }
    if matches!(website.content.as_str(), "all" | "files") {
        let source = format!("{}/.", owner_home.display());
        let target = package.join("homedir");
        let output = Command::new("cp")
            .args(["-a", &source, target.to_string_lossy().as_ref()])
            .output()
            .map_err(|e| format!("cannot copy account home: {e}"))?;
        if !output.status.success() {
            fs::remove_dir_all(&stage).ok();
            return Err(format!(
                "cannot copy account home: {}",
                String::from_utf8_lossy(&output.stderr)
            ));
        }
    }

    if matches!(website.content.as_str(), "all" | "database") {
        let mut grants = String::new();
        for database in &website.database_requests {
            let safe_name: String = database
                .name
                .chars()
                .map(|c| {
                    if c.is_ascii_alphanumeric() || c == '_' || c == '-' {
                        c
                    } else {
                        '_'
                    }
                })
                .collect();
            let output = Command::new("mysqldump")
                .env("MYSQL_PWD", &database.password)
                .args([
                    "--single-transaction",
                    "--skip-lock-tables",
                    "--routines",
                    "--events",
                    "--triggers",
                    "--host",
                    database.host.as_str(),
                    "--user",
                    database.user.as_str(),
                    database.name.as_str(),
                ])
                .output()
                .map_err(|e| format!("cannot dump {}: {e}", database.name))?;
            if !output.status.success() {
                fs::remove_dir_all(&stage).ok();
                return Err(format!(
                    "database dump failed for {}: {}",
                    database.name,
                    String::from_utf8_lossy(&output.stderr)
                ));
            }
            fs::write(
                package.join("mysql").join(format!("{safe_name}.sql")),
                output.stdout,
            )
            .map_err(|e| format!("cannot write database dump: {e}"))?;
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
    let shell = Command::new("getent")
        .args(["passwd", owner])
        .output()
        .ok()
        .and_then(|o| String::from_utf8(o.stdout).ok())
        .and_then(|v| v.trim().split(':').nth(6).map(str::to_string))
        .unwrap_or_else(|| "/usr/sbin/nologin".into());
    fs::write(package.join("shell"), format!("{shell}\n"))
        .map_err(|e| format!("cannot write shell: {e}"))?;
    let shadow = Command::new("getent")
        .args(["shadow", owner])
        .output()
        .ok()
        .and_then(|o| String::from_utf8(o.stdout).ok())
        .and_then(|v| v.trim().split(':').nth(1).map(str::to_string))
        .unwrap_or_default();
    fs::write(package.join("shadow"), format!("{shadow}\n"))
        .map_err(|e| format!("cannot write shadow: {e}"))?;

    let package_name = package
        .file_name()
        .and_then(|v| v.to_str())
        .ok_or("invalid package name")?;
    let output = Command::new("tar")
        .args([
            "-C",
            stage.to_string_lossy().as_ref(),
            "-czf",
            archive_path.to_string_lossy().as_ref(),
            package_name,
        ])
        .output()
        .map_err(|e| format!("cannot create cpmove tarball: {e}"))?;
    fs::remove_dir_all(&stage).ok();
    if !output.status.success() {
        return Err(format!(
            "cannot create cpmove tarball: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }
    fs::write(format!("{}.json", archive_path.display()), &manifest_bytes)
        .map_err(|e| format!("cannot write archive index metadata: {e}"))?;
    #[cfg(unix)]
    {
        fs::set_permissions(archive_path, fs::Permissions::from_mode(0o640))
            .map_err(|e| format!("cannot protect archive: {e}"))?;
        fs::set_permissions(
            format!("{}.json", archive_path.display()),
            fs::Permissions::from_mode(0o640),
        )
        .ok();
    }
    let _ = Command::new("chown")
        .args([
            "root:www-data",
            archive_path.to_string_lossy().as_ref(),
            &format!("{}.json", archive_path.display()),
        ])
        .status();
    Ok(
        serde_json::json!({ "archive_path": archive_path, "format": "cpanel-cpmove", "website": website_meta }),
    )
}

#[derive(Deserialize)]
pub(crate) struct RestoreArchiveRequest {
    pub zip_path: String,
    /// Optional JSON file the panel polls while this (long, synchronous)
    /// restore runs — see RestoreProgress.
    #[serde(default)]
    pub progress_path: Option<String>,
}

pub(crate) async fn restore_archive_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<RestoreArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    // A multi-GB restore runs tar/cp/mysql for many minutes; keep that off the
    // async runtime's worker threads.
    let result = tokio::task::spawn_blocking(move || {
        let progress = RestoreProgress::new(request.progress_path.as_deref());
        let result = restore_archive(&request.zip_path, &progress);
        if let Err(error) = &result {
            progress.fail(error);
        }
        result
    })
    .await
    .unwrap_or_else(|error| Err(format!("restore worker crashed: {error}")));
    match result {
        Ok(website) => ApiResponse::ok_data(
            "Website archive restored",
            serde_json::json!({ "website": website }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&format!("Restore failed: {error}")).into_response(),
    }
}

/// Stage/percent snapshot written to a JSON file the panel's restore status
/// endpoint reads, so the UI can show which step a restore is on (and which
/// one it died in) instead of a bare 500.
pub(crate) struct RestoreProgress {
    path: Option<PathBuf>,
    stage: std::cell::RefCell<String>,
}

impl RestoreProgress {
    fn new(value: Option<&str>) -> Self {
        let root = Path::new("/var/www/dpanel/storage/app/restore-progress");
        let path = value.map(normalize_path).filter(|path| {
            path.starts_with(root)
                && path.extension().and_then(|v| v.to_str()) == Some("json")
                && !path
                    .components()
                    .any(|part| matches!(part, Component::ParentDir))
        });
        Self {
            path,
            stage: std::cell::RefCell::new(String::new()),
        }
    }

    fn update(&self, stage: &str, percent: u8, message: &str) {
        *self.stage.borrow_mut() = stage.to_string();
        self.write(serde_json::json!({
            "stage": stage,
            "percent": percent.min(100),
            "message": message,
        }));
    }

    fn fail(&self, error: &str) {
        let stage = self.stage.borrow().clone();
        self.write(serde_json::json!({
            "stage": "failed",
            "failed_stage": stage,
            "message": error,
        }));
    }

    fn write(&self, value: serde_json::Value) {
        let Some(path) = &self.path else { return };
        if let Some(parent) = path.parent() {
            let _ = fs::create_dir_all(parent);
        }
        let temp = path.with_extension("json.tmp");
        if fs::write(&temp, value.to_string()).is_ok() {
            let _ = fs::rename(&temp, path);
        }
    }
}

/// Streams `path` into `command`'s stdin, reporting byte progress between
/// `from`..`to` percent. stdout/stderr are drained on their own threads so a
/// chatty child (tar -t on a big tree) can't deadlock against our writes, and a
/// child that exits early (mysql on a bad statement) surfaces its own stderr
/// instead of a bare "Broken pipe".
fn pipe_file_into(
    mut command: Command,
    path: &Path,
    progress: &RestoreProgress,
    stage: &str,
    label: &str,
    (from, to): (u8, u8),
) -> Result<Vec<u8>, String> {
    let mut input = fs::File::open(path).map_err(|e| format!("cannot open {label}: {e}"))?;
    let total = input.metadata().map(|m| m.len()).unwrap_or(0).max(1);
    let mut child = command
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| format!("cannot start {label}: {e}"))?;
    let mut stdout = child.stdout.take().ok_or("child stdout unavailable")?;
    let mut stderr = child.stderr.take().ok_or("child stderr unavailable")?;
    let stdout_reader = std::thread::spawn(move || {
        let mut buffer = Vec::new();
        let _ = stdout.read_to_end(&mut buffer);
        buffer
    });
    let stderr_reader = std::thread::spawn(move || {
        let mut buffer = Vec::new();
        let _ = stderr.read_to_end(&mut buffer);
        buffer
    });

    let mut stdin = child.stdin.take().ok_or("child stdin unavailable")?;
    let mut buffer = vec![0u8; 1024 * 1024];
    let mut sent: u64 = 0;
    let mut last_percent = u8::MAX;
    let mut write_error = None;
    loop {
        let read = match input.read(&mut buffer) {
            Ok(0) => break,
            Ok(read) => read,
            Err(e) => {
                write_error = Some(format!("cannot read {label}: {e}"));
                break;
            }
        };
        if let Err(e) = stdin.write_all(&buffer[..read]) {
            write_error = Some(format!("{label} stopped reading input: {e}"));
            break;
        }
        sent += read as u64;
        let span = u64::from(to.saturating_sub(from));
        let percent = from + ((sent.min(total) * span) / total) as u8;
        if percent != last_percent {
            last_percent = percent;
            progress.update(
                stage,
                percent,
                &format!(
                    "{label}: {} / {}",
                    human_bytes(sent),
                    human_bytes(total)
                ),
            );
        }
    }
    drop(stdin);
    let status = child
        .wait()
        .map_err(|e| format!("cannot finish {label}: {e}"))?;
    let stdout = stdout_reader.join().unwrap_or_default();
    let stderr = stderr_reader.join().unwrap_or_default();
    if !status.success() {
        let detail = String::from_utf8_lossy(&stderr).trim().to_string();
        return Err(format!(
            "{label} failed ({status}): {}",
            if detail.is_empty() {
                write_error.unwrap_or_else(|| "no error output".into())
            } else {
                detail
            }
        ));
    }
    if let Some(error) = write_error {
        return Err(error);
    }
    Ok(stdout)
}

fn human_bytes(bytes: u64) -> String {
    const GB: f64 = 1024.0 * 1024.0 * 1024.0;
    const MB: f64 = 1024.0 * 1024.0;
    let value = bytes as f64;
    if value >= GB {
        format!("{:.2} GB", value / GB)
    } else {
        format!("{:.1} MB", value / MB)
    }
}

/// Free bytes on the filesystem holding `path` (via df, which every target has).
fn available_bytes(path: &Path) -> Option<u64> {
    let output = Command::new("df")
        .args(["--output=avail", "-B1"])
        .arg(path)
        .output()
        .ok()?;
    if !output.status.success() {
        return None;
    }
    String::from_utf8_lossy(&output.stdout)
        .lines()
        .nth(1)?
        .trim()
        .parse()
        .ok()
}

fn ensure_free_space(path: &Path, needed: u64, purpose: &str) -> Result<(), String> {
    match available_bytes(path) {
        Some(free) if free < needed => Err(format!(
            "not enough disk space for {purpose}: {} free on {}, about {} needed",
            human_bytes(free),
            path.display(),
            human_bytes(needed)
        )),
        _ => Ok(()),
    }
}

fn restore_archive(value: &str, progress: &RestoreProgress) -> Result<serde_json::Value, String> {
    progress.update("validating", 1, "Checking backup archive");
    let path = normalize_path(value);
    let allowed_roots = [
        Path::new("/var/www/dpanel/storage/app/backups"),
        Path::new("/var/www/dpanel/storage/app/website-trash"),
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
    let owner_home = PathBuf::from(format!("/home/{owner}"));
    if project_root.as_os_str().is_empty() || !project_root.starts_with(&owner_home) {
        return Err("archive project path is outside the website owner home".into());
    }

    if numeric_account_id("-u", owner).is_none() {
        let archived_uid = website.get("system_uid").and_then(|value| value.as_u64());
        let archived_gid = website.get("system_gid").and_then(|value| value.as_u64());

        let mut group_args = vec!["--force".to_string()];
        if let Some(gid) = archived_gid {
            group_args.extend(["--gid".to_string(), gid.to_string()]);
        }
        group_args.push(owner.to_string());
        let group_output = Command::new("groupadd")
            .args(&group_args)
            .output()
            .map_err(|error| format!("cannot recreate website group: {error}"))?;
        if !group_output.status.success() {
            return Err(format!(
                "cannot recreate website group: {}",
                String::from_utf8_lossy(&group_output.stderr).trim()
            ));
        }

        let mut user_args = vec!["--create-home".to_string()];
        if let Some(uid) = archived_uid {
            user_args.extend(["--uid".to_string(), uid.to_string()]);
        }
        user_args.extend([
            "--gid".to_string(),
            owner.to_string(),
            "--shell".to_string(),
            "/usr/sbin/nologin".to_string(),
            owner.to_string(),
        ]);
        let user_output = Command::new("useradd")
            .args(&user_args)
            .output()
            .map_err(|error| format!("cannot create website user: {error}"))?;
        if !user_output.status.success() {
            return Err(format!(
                "cannot recreate website user: {}",
                String::from_utf8_lossy(&user_output.stderr).trim()
            ));
        }
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
            let sql_password = password.replace('\\', "\\\\").replace('\'', "\\'");
            let provision_sql = format!(
                "CREATE DATABASE IF NOT EXISTS `{name}` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '{user}'@'%' IDENTIFIED BY '{sql_password}'; ALTER USER '{user}'@'%' IDENTIFIED BY '{sql_password}'; GRANT ALL PRIVILEGES ON `{name}`.* TO '{user}'@'%'; FLUSH PRIVILEGES;"
            );
            let provision = Command::new("mysql")
                .args(["--protocol=socket", "--execute", &provision_sql])
                .output()
                .map_err(|e| format!("cannot provision database {name}: {e}"))?;
            if !provision.status.success() {
                return Err(format!(
                    "cannot provision database {name}: {}",
                    String::from_utf8_lossy(&provision.stderr)
                ));
            }
            let safe_name: String = name
                .chars()
                .map(|c| {
                    if c.is_ascii_alphanumeric() || c == '_' || c == '-' {
                        c
                    } else {
                        '_'
                    }
                })
                .collect();
            let mut dump = Vec::new();
            archive
                .by_name(&format!("mysql/{safe_name}.sql"))
                .map_err(|_| format!("database dump missing for {name}"))?
                .read_to_end(&mut dump)
                .map_err(|e| format!("cannot read database dump: {e}"))?;
            let mut child = Command::new("mysql")
                .env("MYSQL_PWD", password)
                .args(["--host", host, "--user", user, name])
                .stdin(Stdio::piped())
                .stdout(Stdio::null())
                .stderr(Stdio::piped())
                .spawn()
                .map_err(|e| format!("cannot start mysql restore for {name}: {e}"))?;
            child
                .stdin
                .as_mut()
                .ok_or("mysql restore stdin unavailable")?
                .write_all(&dump)
                .map_err(|e| format!("cannot send database dump: {e}"))?;
            let output = child
                .wait_with_output()
                .map_err(|e| format!("cannot finish database restore: {e}"))?;
            if !output.status.success() {
                return Err(format!(
                    "database restore failed for {name}: {}",
                    String::from_utf8_lossy(&output.stderr)
                ));
            }
        }
    }
    progress.update("permissions", 90, "Fixing file ownership and permissions");
    let _ = Command::new("chown")
        .args([
            "-R",
            &format!("{owner}:{owner}"),
            project_root.to_string_lossy().as_ref(),
        ])
        .status();
    crate::filemanager::fix_permissions::run(Some(owner), owner_home.to_str(), false).map_err(
        |error| format!("files restored but permissions could not be repaired: {error}"),
    )?;

    if let Some(version) = website.get("php_version").and_then(|value| value.as_str()) {
        if !version.is_empty() && version.chars().all(|ch| ch.is_ascii_digit() || ch == '.') {
            let service = format!("php{version}-fpm");
            // The archived numeric identity is reused, so a graceful reload is
            // sufficient and avoids interrupting other sites on this runtime.
            let restart = Command::new("systemctl")
                .args(["reload", service.as_str()])
                .output()
                .map_err(|error| format!("cannot reload {service}: {error}"))?;
            if !restart.status.success() {
                return Err(format!(
                    "files restored but {service} could not be reloaded: {}",
                    String::from_utf8_lossy(&restart.stderr).trim()
                ));
            }
        }
    }
    Ok(website)
}

fn numeric_account_id(flag: &str, owner: &str) -> Option<u64> {
    if owner.is_empty() {
        return None;
    }
    let output = Command::new("id").args([flag, owner]).output().ok()?;
    if !output.status.success() {
        return None;
    }
    String::from_utf8(output.stdout).ok()?.trim().parse().ok()
}

fn restore_cpmove(path: &Path, progress: &RestoreProgress) -> Result<serde_json::Value, String> {
    let staging = PathBuf::from(format!(
        "/var/www/dpanel/storage/app/restore-staging/{}",
        std::process::id()
    ));
    if staging.exists() {
        fs::remove_dir_all(&staging).ok();
    }
    // Every exit path must drop the staging copy: a failed multi-GB restore used
    // to leave its extracted tree behind, eating the disk the retry then needed.
    let result = restore_cpmove_staged(path, &staging, progress);
    fs::remove_dir_all(&staging).ok();
    result
}

fn restore_cpmove_staged(
    path: &Path,
    staging: &Path,
    progress: &RestoreProgress,
) -> Result<serde_json::Value, String> {
    let archive_size = fs::metadata(path).map(|m| m.len()).unwrap_or(0);
    let path_arg = path.to_string_lossy().to_string();

    // tar -tz reads the whole archive once; stream it so the UI sees progress.
    let mut list = Command::new("tar");
    list.args(["-tzf", "-"]);
    let listing = pipe_file_into(list, path, progress, "inspecting", "Inspecting archive", (2, 15))
        .map_err(|e| format!("invalid cpmove tarball: {e}"))?;
    for name in String::from_utf8_lossy(&listing).lines() {
        let entry = Path::new(name);
        if entry.is_absolute()
            || entry
                .components()
                .any(|part| matches!(part, Component::ParentDir))
        {
            return Err("unsafe path in cpmove archive".into());
        }
    }

    // The gzip'd archive expands (typically 1.5–3x) into staging and is then
    // copied again into /home, so check both filesystems up front rather than
    // dying with ENOSPC half way through.
    progress.update("disk_check", 15, "Checking free disk space");
    fs::create_dir_all(staging).map_err(|e| format!("cannot create restore staging: {e}"))?;
    ensure_free_space(staging, archive_size.saturating_mul(2), "extracting the archive")?;
    ensure_free_space(Path::new("/home"), archive_size.saturating_mul(2), "restoring the home directory")?;

    let mut extract = Command::new("tar");
    extract.args([
        "-xzf",
        "-",
        "-C",
        staging.to_string_lossy().as_ref(),
        "--no-same-owner",
        "--no-same-permissions",
    ]);
    pipe_file_into(extract, path, progress, "extracting", "Extracting archive", (15, 55))
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
    if !Command::new("id")
        .args(["-u", owner])
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
    {
        let _ = Command::new("groupadd").args(["--force", owner]).status();
        let output = Command::new("useradd")
            .args([
                "--create-home",
                "--gid",
                owner,
                "--shell",
                "/usr/sbin/nologin",
                owner,
            ])
            .output()
            .map_err(|e| format!("cannot recreate account user: {e}"))?;
        if !output.status.success() {
            return Err(format!(
                "cannot recreate account user: {}",
                String::from_utf8_lossy(&output.stderr).trim()
            ));
        }
    }

    progress.update("files", 60, "Copying website files into the home directory");
    let home = PathBuf::from(format!("/home/{owner}"));
    fs::create_dir_all(&home).map_err(|e| format!("cannot create account home: {e}"))?;
    let homedir_source = format!("{}/.", package.join("homedir").display());
    let copy = Command::new("cp")
        .args(["-a", &homedir_source, home.to_string_lossy().as_ref()])
        .output()
        .map_err(|e| format!("cannot restore homedir: {e}"))?;
    if !copy.status.success() {
        return Err(format!(
            "cannot restore homedir: {}",
            String::from_utf8_lossy(&copy.stderr)
        ));
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
            let escaped = password.replace('\\', "\\\\").replace('\'', "\\'");
            let sql = format!(
                "CREATE DATABASE IF NOT EXISTS `{name}` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '{user}'@'%' IDENTIFIED BY '{escaped}'; ALTER USER '{user}'@'%' IDENTIFIED BY '{escaped}'; GRANT ALL PRIVILEGES ON `{name}`.* TO '{user}'@'%'; FLUSH PRIVILEGES;"
            );
            let provision = Command::new("mysql")
                .args(["--protocol=socket", "--execute", &sql])
                .output()
                .map_err(|e| format!("cannot provision database: {e}"))?;
            if !provision.status.success() {
                return Err(format!(
                    "cannot provision database {name}: {}",
                    String::from_utf8_lossy(&provision.stderr)
                ));
            }
            let safe_name: String = name
                .chars()
                .map(|c| {
                    if c.is_ascii_alphanumeric() || c == '_' || c == '-' {
                        c
                    } else {
                        '_'
                    }
                })
                .collect();
            // Streamed rather than fs::read: a multi-GB dump loaded whole into
            // memory can exhaust RAM on a smaller server.
            let dump = package.join("mysql").join(format!("{safe_name}.sql"));
            if !dump.is_file() {
                return Err(format!("database dump missing for {name}"));
            }
            let mut import = Command::new("mysql");
            import
                .env("MYSQL_PWD", password)
                .args(["--host", host, "--user", user, name]);
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
    let _ = Command::new("chown")
        .args([
            "-R",
            &format!("{owner}:{owner}"),
            home.to_string_lossy().as_ref(),
        ])
        .status();
    progress.update("finalizing", 98, "Saving panel records");
    Ok(website)
}

fn add_database_dump(
    writer: &mut ZipWriter<std::fs::File>,
    database: &DatabaseArchive,
    options: SimpleFileOptions,
) -> Result<(), String> {
    if database.name.is_empty() || database.user.is_empty() {
        return Err("database name and user are required".into());
    }
    let output = Command::new("mysqldump")
        .env("MYSQL_PWD", &database.password)
        .args([
            "--single-transaction",
            "--skip-lock-tables",
            "--host",
            database.host.as_str(),
            "--user",
            database.user.as_str(),
            database.name.as_str(),
        ])
        .output()
        .map_err(|error| format!("cannot run mysqldump for {}: {error}", database.name))?;
    if !output.status.success() {
        return Err(format!(
            "database dump failed for {}: {}",
            database.name,
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    let safe_name: String = database
        .name
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() || character == '_' || character == '-' {
                character
            } else {
                '_'
            }
        })
        .collect();
    writer
        .start_file(format!("mysql/{safe_name}.sql"), options)
        .map_err(|error| format!("cannot add database dump: {error}"))?;
    writer
        .write_all(&output.stdout)
        .map_err(|error| format!("cannot write database dump: {error}"))?;
    Ok(())
}

/// What add_dir actually managed to put in the archive. Unreadable entries are
/// still skipped rather than aborting the export, but they are counted and
/// reported back — previously they vanished silently, so an export whose files
/// all failed still "succeeded" with nothing but the database dump in it.
#[derive(Default)]
struct ArchiveStats {
    files: u64,
    skipped: u64,
    skipped_samples: Vec<String>,
}

impl ArchiveStats {
    fn skip(&mut self, path: &Path, reason: impl std::fmt::Display) {
        self.skipped += 1;
        if self.skipped_samples.len() < 10 {
            self.skipped_samples
                .push(format!("{}: {reason}", path.display()));
        }
    }
}

fn add_dir(
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
            if let Err(error) = writer.add_directory(format!("{}/", archive_path), dir_options) {
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

// The archive doesn't store uid/gid, so a mode copied verbatim from the source
// (e.g. 600/400 owned by root or another account) can leave the extracting user
// unable to read/write their own files. Normalize instead: keep the executable
// bit for scripts/binaries, otherwise use a standard owner-writable mode.
#[cfg(unix)]
fn file_permissions(path: &Path) -> Option<u32> {
    use std::os::unix::fs::PermissionsExt;
    fs::metadata(path).ok().map(|meta| {
        let mode = meta.permissions().mode() & 0o777;
        if mode & 0o100 != 0 { 0o755 } else { 0o644 }
    })
}

#[cfg(not(unix))]
fn file_permissions(_path: &Path) -> Option<u32> {
    None
}

fn normalize_path(input: &str) -> PathBuf {
    let trimmed = input.trim();
    if trimmed.is_empty() {
        return PathBuf::new();
    }
    PathBuf::from(trimmed)
}

fn chrono_like_now() -> String {
    // Keep the manifest lightweight without adding a date dependency.
    format!("{:?}", std::time::SystemTime::now())
}
