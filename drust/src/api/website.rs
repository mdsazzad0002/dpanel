use std::fs;
use std::io::{Read, Write};
use std::path::{Component, Path, PathBuf};
use std::process::{Command, Stdio};
use std::sync::Arc;
#[cfg(unix)]
use std::os::unix::fs::PermissionsExt;

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
        .route("/api/v1/website/archive/restore", post(restore_archive_handle))
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

fn archive_website(zip_path: &str, website: &WebsiteArchive) -> Result<serde_json::Value, String> {
    if !matches!(website.content.as_str(), "all" | "files" | "database") {
        return Err("invalid archive content; use all, files, or database".into());
    }
    let zip_path = normalize_path(zip_path);
    if zip_path.as_os_str().is_empty() {
        return Err("Missing zip path.".into());
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
    let owner = website.site_owner.as_deref().unwrap_or("");
    let system_uid = numeric_account_id("-u", owner);
    let system_gid = numeric_account_id("-g", owner);

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

    if matches!(website.content.as_str(), "all" | "files") {
        let source_path = normalize_path(if website.project_root.trim().is_empty() {
            website.root_path.as_str()
        } else {
            website.project_root.as_str()
        });
        if source_path.exists() && source_path.is_dir() {
            writer.add_directory("homedir/public_html/", options)
                .map_err(|e| format!("failed to create homedir layout: {e}"))?;
            add_dir(&mut writer, &source_path, "homedir/public_html", options)?;
        }
    }

    if matches!(website.content.as_str(), "all" | "database") {
        for database in &website.database_requests {
            add_database_dump(&mut writer, database, options)?;
        }
    }

    writer
        .finish()
        .map_err(|e| format!("failed to finalize zip: {e}"))?;
    #[cfg(unix)]
    fs::set_permissions(&zip_path, fs::Permissions::from_mode(0o640))
        .map_err(|e| format!("failed to protect archive: {e}"))?;
    let _ = Command::new("chown").args(["root:www-data", zip_path.to_string_lossy().as_ref()]).status();
    Ok(serde_json::json!({ "zip_path": zip_path.display().to_string() }))
}

fn archive_cpmove(archive_path: &Path, website: &WebsiteArchive) -> Result<serde_json::Value, String> {
    let owner = website.site_owner.as_deref().unwrap_or("").trim();
    if owner.is_empty() || !owner.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-') {
        return Err("a valid cPanel account owner is required".into());
    }
    let run_dir = archive_path.parent().ok_or("backup run directory is missing")?;
    let stage = run_dir.join(format!(".cpmove-{owner}-building"));
    let package = stage.join(format!("cpmove-{owner}"));
    if stage.exists() { fs::remove_dir_all(&stage).map_err(|e| format!("cannot reset staging directory: {e}"))?; }
    for directory in ["homedir", "mysql", "dnszones", "userdata", "meta"] {
        fs::create_dir_all(package.join(directory)).map_err(|e| format!("cannot create cpmove layout: {e}"))?;
    }

    let owner_home = PathBuf::from(format!("/home/{owner}"));
    if matches!(website.content.as_str(), "all" | "files") && owner_home.is_dir() {
        let source = format!("{}/.", owner_home.display());
        let target = package.join("homedir");
        let output = Command::new("cp").args(["-a", &source, target.to_string_lossy().as_ref()]).output()
            .map_err(|e| format!("cannot copy account home: {e}"))?;
        if !output.status.success() { fs::remove_dir_all(&stage).ok(); return Err(format!("cannot copy account home: {}", String::from_utf8_lossy(&output.stderr))); }
    }

    if matches!(website.content.as_str(), "all" | "database") {
        let mut grants = String::new();
        for database in &website.database_requests {
            let safe_name: String = database.name.chars().map(|c| if c.is_ascii_alphanumeric() || c == '_' || c == '-' { c } else { '_' }).collect();
            let output = Command::new("mysqldump").env("MYSQL_PWD", &database.password)
                .args(["--single-transaction", "--skip-lock-tables", "--routines", "--events", "--triggers", "--host", database.host.as_str(), "--user", database.user.as_str(), database.name.as_str()])
                .output().map_err(|e| format!("cannot dump {}: {e}", database.name))?;
            if !output.status.success() { fs::remove_dir_all(&stage).ok(); return Err(format!("database dump failed for {}: {}", database.name, String::from_utf8_lossy(&output.stderr))); }
            fs::write(package.join("mysql").join(format!("{safe_name}.sql")), output.stdout)
                .map_err(|e| format!("cannot write database dump: {e}"))?;
            let escaped = database.password.replace('\\', "\\\\").replace('\'', "\\'");
            grants.push_str(&format!("CREATE DATABASE IF NOT EXISTS `{}` CHARACTER SET utf8mb4;\nCREATE USER IF NOT EXISTS '{}'@'%' IDENTIFIED BY '{}';\nGRANT ALL PRIVILEGES ON `{}`.* TO '{}'@'%';\n", database.name, database.user, escaped, database.name, database.user));
        }
        grants.push_str("FLUSH PRIVILEGES;\n");
        fs::write(package.join("mysql.sql"), grants).map_err(|e| format!("cannot write database grants: {e}"))?;
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
    let manifest_bytes = serde_json::to_vec_pretty(&manifest).map_err(|e| format!("cannot encode metadata: {e}"))?;
    fs::write(package.join("meta/dpanel-manifest.json"), &manifest_bytes).map_err(|e| format!("cannot write metadata: {e}"))?;
    fs::write(package.join("userdata/main.json"), serde_json::to_vec_pretty(&website_meta).unwrap_or_default()).map_err(|e| format!("cannot write userdata: {e}"))?;
    fs::write(package.join("version"), "10\n4\n").map_err(|e| format!("cannot write version: {e}"))?;
    fs::write(package.join("homedir_paths"), format!("{}\n", owner_home.display())).map_err(|e| format!("cannot write home path: {e}"))?;
    fs::write(package.join("cp"), format!("{}\n", website.domain)).map_err(|e| format!("cannot write primary domain: {e}"))?;
    fs::write(package.join("quota"), "0\n").map_err(|e| format!("cannot write quota: {e}"))?;
    fs::write(package.join("pds"), "").map_err(|e| format!("cannot write aliases: {e}"))?;
    fs::write(package.join("sds"), "").map_err(|e| format!("cannot write subdomains: {e}"))?;
    fs::write(package.join("sds2"), "{}\n").map_err(|e| format!("cannot write subdomain map: {e}"))?;
    let shell = Command::new("getent").args(["passwd", owner]).output().ok()
        .and_then(|o| String::from_utf8(o.stdout).ok()).and_then(|v| v.trim().split(':').nth(6).map(str::to_string))
        .unwrap_or_else(|| "/usr/sbin/nologin".into());
    fs::write(package.join("shell"), format!("{shell}\n")).map_err(|e| format!("cannot write shell: {e}"))?;
    let shadow = Command::new("getent").args(["shadow", owner]).output().ok()
        .and_then(|o| String::from_utf8(o.stdout).ok()).and_then(|v| v.trim().split(':').nth(1).map(str::to_string)).unwrap_or_default();
    fs::write(package.join("shadow"), format!("{shadow}\n")).map_err(|e| format!("cannot write shadow: {e}"))?;

    let package_name = package.file_name().and_then(|v| v.to_str()).ok_or("invalid package name")?;
    let output = Command::new("tar").args(["-C", stage.to_string_lossy().as_ref(), "-czf", archive_path.to_string_lossy().as_ref(), package_name])
        .output().map_err(|e| format!("cannot create cpmove tarball: {e}"))?;
    fs::remove_dir_all(&stage).ok();
    if !output.status.success() { return Err(format!("cannot create cpmove tarball: {}", String::from_utf8_lossy(&output.stderr))); }
    fs::write(format!("{}.json", archive_path.display()), &manifest_bytes).map_err(|e| format!("cannot write archive index metadata: {e}"))?;
    #[cfg(unix)]
    {
        fs::set_permissions(archive_path, fs::Permissions::from_mode(0o640)).map_err(|e| format!("cannot protect archive: {e}"))?;
        fs::set_permissions(format!("{}.json", archive_path.display()), fs::Permissions::from_mode(0o640)).ok();
    }
    let _ = Command::new("chown").args(["root:www-data", archive_path.to_string_lossy().as_ref(), &format!("{}.json", archive_path.display())]).status();
    Ok(serde_json::json!({ "archive_path": archive_path, "format": "cpanel-cpmove", "website": website_meta }))
}

pub(crate) async fn restore_archive_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<DeleteArchiveRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match restore_archive(&request.zip_path) {
        Ok(website) => ApiResponse::ok_data("Website archive restored", serde_json::json!({ "website": website })).into_response(),
        Err(error) => ApiResponse::error(&format!("Restore failed: {error}")).into_response(),
    }
}

fn restore_archive(value: &str) -> Result<serde_json::Value, String> {
    let path = normalize_path(value);
    let allowed_roots = [
        Path::new("/var/www/dpanel/storage/app/backups"),
        Path::new("/var/www/dpanel/storage/app/website-trash"),
    ];
    if !allowed_roots.iter().any(|root| path.starts_with(root))
        || path.components().any(|part| matches!(part, Component::ParentDir))
        || !path.is_file()
    {
        return Err("invalid backup archive path".into());
    }
    if path.to_string_lossy().ends_with(".tar.gz") {
        return restore_cpmove(&path);
    }
    if path.extension().and_then(|v| v.to_str()) != Some("zip") {
        return Err("unsupported backup archive type".into());
    }

    let file = fs::File::open(&path).map_err(|e| format!("cannot open archive: {e}"))?;
    let mut archive = ZipArchive::new(file).map_err(|e| format!("invalid zip archive: {e}"))?;
    let mut manifest_raw = String::new();
    archive.by_name("meta/manifest.json")
        .map_err(|_| "archive metadata is missing".to_string())?
        .read_to_string(&mut manifest_raw)
        .map_err(|e| format!("cannot read archive metadata: {e}"))?;
    let manifest: serde_json::Value = serde_json::from_str(&manifest_raw)
        .map_err(|e| format!("invalid archive metadata: {e}"))?;
    let website = manifest.get("website").cloned().ok_or("website metadata is missing")?;
    let owner = website.get("site_owner").and_then(|v| v.as_str()).unwrap_or("");
    if owner.is_empty() || !owner.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-') {
        return Err("invalid website owner in archive".into());
    }
    let project_root = normalize_path(website.get("project_root").and_then(|v| v.as_str()).unwrap_or(""));
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
        let group_output = Command::new("groupadd").args(&group_args).output()
            .map_err(|error| format!("cannot recreate website group: {error}"))?;
        if !group_output.status.success() {
            return Err(format!("cannot recreate website group: {}", String::from_utf8_lossy(&group_output.stderr).trim()));
        }

        let mut user_args = vec!["--create-home".to_string()];
        if let Some(uid) = archived_uid {
            user_args.extend(["--uid".to_string(), uid.to_string()]);
        }
        user_args.extend([
            "--gid".to_string(), owner.to_string(),
            "--shell".to_string(), "/usr/sbin/nologin".to_string(),
            owner.to_string(),
        ]);
        let user_output = Command::new("useradd").args(&user_args).output()
            .map_err(|error| format!("cannot create website user: {error}"))?;
        if !user_output.status.success() {
            return Err(format!("cannot recreate website user: {}", String::from_utf8_lossy(&user_output.stderr).trim()));
        }
    }
    fs::create_dir_all(&project_root).map_err(|e| format!("cannot create website root: {e}"))?;

    for index in 0..archive.len() {
        let mut entry = archive.by_index(index).map_err(|e| format!("cannot read zip entry: {e}"))?;
        let name = entry.enclosed_name().ok_or("unsafe path in archive")?.to_path_buf();
        let prefix = Path::new("homedir/public_html");
        if !name.starts_with(prefix) { continue; }
        let relative = name.strip_prefix(prefix).map_err(|_| "invalid homedir path")?;
        let target = project_root.join(relative);
        if entry.is_dir() {
            fs::create_dir_all(&target).map_err(|e| format!("cannot restore directory: {e}"))?;
        } else {
            if let Some(parent) = target.parent() { fs::create_dir_all(parent).map_err(|e| format!("cannot create restore directory: {e}"))?; }
            let mut output = fs::File::create(&target).map_err(|e| format!("cannot restore file: {e}"))?;
            std::io::copy(&mut entry, &mut output).map_err(|e| format!("cannot write restored file: {e}"))?;
        }
    }

    if let Some(databases) = website.get("databases").and_then(|v| v.as_array()) {
        for database in databases {
            let name = database.get("name").and_then(|v| v.as_str()).unwrap_or("");
            let user = database.get("user").and_then(|v| v.as_str()).unwrap_or("");
            let password = database.get("password").and_then(|v| v.as_str()).unwrap_or("");
            let host = database.get("host").and_then(|v| v.as_str()).unwrap_or("127.0.0.1");
            if name.is_empty() || user.is_empty()
                || !name.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$')
                || !user.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$')
            {
                return Err("invalid database identity in archive".into());
            }
            let sql_password = password.replace('\\', "\\\\").replace('\'', "\\'");
            let provision_sql = format!(
                "CREATE DATABASE IF NOT EXISTS `{name}` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '{user}'@'%' IDENTIFIED BY '{sql_password}'; ALTER USER '{user}'@'%' IDENTIFIED BY '{sql_password}'; GRANT ALL PRIVILEGES ON `{name}`.* TO '{user}'@'%'; FLUSH PRIVILEGES;"
            );
            let provision = Command::new("mysql").args(["--protocol=socket", "--execute", &provision_sql])
                .output().map_err(|e| format!("cannot provision database {name}: {e}"))?;
            if !provision.status.success() {
                return Err(format!("cannot provision database {name}: {}", String::from_utf8_lossy(&provision.stderr)));
            }
            let safe_name: String = name.chars().map(|c| if c.is_ascii_alphanumeric() || c == '_' || c == '-' { c } else { '_' }).collect();
            let mut dump = Vec::new();
            archive.by_name(&format!("mysql/{safe_name}.sql"))
                .map_err(|_| format!("database dump missing for {name}"))?
                .read_to_end(&mut dump).map_err(|e| format!("cannot read database dump: {e}"))?;
            let mut child = Command::new("mysql").env("MYSQL_PWD", password)
                .args(["--host", host, "--user", user, name]).stdin(Stdio::piped()).stdout(Stdio::null()).stderr(Stdio::piped())
                .spawn().map_err(|e| format!("cannot start mysql restore for {name}: {e}"))?;
            child.stdin.as_mut().ok_or("mysql restore stdin unavailable")?.write_all(&dump)
                .map_err(|e| format!("cannot send database dump: {e}"))?;
            let output = child.wait_with_output().map_err(|e| format!("cannot finish database restore: {e}"))?;
            if !output.status.success() { return Err(format!("database restore failed for {name}: {}", String::from_utf8_lossy(&output.stderr))); }
        }
    }
    let _ = Command::new("chown").args(["-R", &format!("{owner}:{owner}"), project_root.to_string_lossy().as_ref()]).status();
    crate::filemanager::fix_permissions::run(
        Some(owner),
        project_root.to_str(),
        false,
    )
    .map_err(|error| format!("files restored but permissions could not be repaired: {error}"))?;

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

fn restore_cpmove(path: &Path) -> Result<serde_json::Value, String> {
    let listing = Command::new("tar").args(["-tzf", path.to_string_lossy().as_ref()]).output()
        .map_err(|e| format!("cannot inspect cpmove archive: {e}"))?;
    if !listing.status.success() { return Err("invalid cpmove tarball".into()); }
    for name in String::from_utf8_lossy(&listing.stdout).lines() {
        let entry = Path::new(name);
        if entry.is_absolute() || entry.components().any(|part| matches!(part, Component::ParentDir)) {
            return Err("unsafe path in cpmove archive".into());
        }
    }
    let staging = PathBuf::from(format!("/var/www/dpanel/storage/app/restore-staging/{}", std::process::id()));
    if staging.exists() { fs::remove_dir_all(&staging).ok(); }
    fs::create_dir_all(&staging).map_err(|e| format!("cannot create restore staging: {e}"))?;
    let extract = Command::new("tar").args(["-xzf", path.to_string_lossy().as_ref(), "-C", staging.to_string_lossy().as_ref(), "--no-same-owner", "--no-same-permissions"])
        .output().map_err(|e| format!("cannot extract cpmove archive: {e}"))?;
    if !extract.status.success() { fs::remove_dir_all(&staging).ok(); return Err(format!("cannot extract cpmove archive: {}", String::from_utf8_lossy(&extract.stderr))); }
    let package = fs::read_dir(&staging).map_err(|e| format!("cannot read restore staging: {e}"))?
        .filter_map(Result::ok).map(|entry| entry.path()).find(|entry| entry.is_dir() && entry.file_name().and_then(|v| v.to_str()).map(|v| v.starts_with("cpmove-")).unwrap_or(false))
        .ok_or("cpmove account directory is missing")?;
    let raw = fs::read_to_string(package.join("meta/dpanel-manifest.json"))
        .map_err(|_| "dPanel migration metadata is missing; external cPanel import mapping is required".to_string())?;
    let manifest: serde_json::Value = serde_json::from_str(&raw).map_err(|e| format!("invalid migration metadata: {e}"))?;
    let website = manifest.get("website").cloned().ok_or("website metadata is missing")?;
    let owner = website.get("site_owner").and_then(|v| v.as_str()).unwrap_or("");
    if owner.is_empty() || !owner.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-') {
        return Err("invalid account owner".into());
    }
    if !Command::new("id").args(["-u", owner]).status().map(|s| s.success()).unwrap_or(false) {
        let _ = Command::new("groupadd").args(["--force", owner]).status();
        let status = Command::new("useradd").args(["--create-home", "--gid", owner, "--shell", "/usr/sbin/nologin", owner]).status()
            .map_err(|e| format!("cannot recreate account user: {e}"))?;
        if !status.success() { return Err("cannot recreate account user".into()); }
    }
    let home = PathBuf::from(format!("/home/{owner}"));
    fs::create_dir_all(&home).map_err(|e| format!("cannot create account home: {e}"))?;
    let homedir_source = format!("{}/.", package.join("homedir").display());
    let copy = Command::new("cp").args(["-a", &homedir_source, home.to_string_lossy().as_ref()]).output()
        .map_err(|e| format!("cannot restore homedir: {e}"))?;
    if !copy.status.success() { return Err(format!("cannot restore homedir: {}", String::from_utf8_lossy(&copy.stderr))); }

    if let Some(databases) = website.get("databases").and_then(|v| v.as_array()) {
        for database in databases {
            let name = database.get("name").and_then(|v| v.as_str()).unwrap_or("");
            let user = database.get("user").and_then(|v| v.as_str()).unwrap_or("");
            let password = database.get("password").and_then(|v| v.as_str()).unwrap_or("");
            let host = database.get("host").and_then(|v| v.as_str()).unwrap_or("127.0.0.1");
            if name.is_empty() || user.is_empty() || !name.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$') || !user.chars().all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$') {
                return Err("invalid database identity in cpmove metadata".into());
            }
            let escaped = password.replace('\\', "\\\\").replace('\'', "\\'");
            let sql = format!("CREATE DATABASE IF NOT EXISTS `{name}` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '{user}'@'%' IDENTIFIED BY '{escaped}'; ALTER USER '{user}'@'%' IDENTIFIED BY '{escaped}'; GRANT ALL PRIVILEGES ON `{name}`.* TO '{user}'@'%'; FLUSH PRIVILEGES;");
            let provision = Command::new("mysql").args(["--protocol=socket", "--execute", &sql]).output().map_err(|e| format!("cannot provision database: {e}"))?;
            if !provision.status.success() { return Err(format!("cannot provision database {name}: {}", String::from_utf8_lossy(&provision.stderr))); }
            let safe_name: String = name.chars().map(|c| if c.is_ascii_alphanumeric() || c == '_' || c == '-' { c } else { '_' }).collect();
            let dump = fs::read(package.join("mysql").join(format!("{safe_name}.sql"))).map_err(|e| format!("cannot read database dump {name}: {e}"))?;
            let mut child = Command::new("mysql").env("MYSQL_PWD", password).args(["--host", host, "--user", user, name]).stdin(Stdio::piped()).stderr(Stdio::piped()).spawn().map_err(|e| format!("cannot start database restore: {e}"))?;
            child.stdin.as_mut().ok_or("database restore stdin unavailable")?.write_all(&dump).map_err(|e| format!("cannot stream database dump: {e}"))?;
            let output = child.wait_with_output().map_err(|e| format!("cannot finish database restore: {e}"))?;
            if !output.status.success() { return Err(format!("database restore failed for {name}: {}", String::from_utf8_lossy(&output.stderr))); }
        }
    }
    let _ = Command::new("chown").args(["-R", &format!("{owner}:{owner}"), home.to_string_lossy().as_ref()]).status();
    fs::remove_dir_all(&staging).ok();
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
        .map(|character| if character.is_ascii_alphanumeric() || character == '_' || character == '-' { character } else { '_' })
        .collect();
    writer
        .start_file(format!("mysql/{safe_name}.sql"), options)
        .map_err(|error| format!("cannot add database dump: {error}"))?;
    writer
        .write_all(&output.stdout)
        .map_err(|error| format!("cannot write database dump: {error}"))?;
    Ok(())
}

fn add_dir(
    writer: &mut ZipWriter<std::fs::File>,
    source: &Path,
    zip_root: &str,
    options: SimpleFileOptions,
) -> Result<(), String> {
    if !source.exists() {
        return Ok(());
    }

    let entries = fs::read_dir(source)
        .map_err(|e| format!("failed to read directory {}: {e}", source.display()))?;
    for entry in entries {
        let entry = match entry {
            Ok(item) => item,
            Err(_) => continue,
        };
        let path = entry.path();
        if !path.exists() {
            continue;
        }

        let name = match path.file_name().and_then(|v| v.to_str()) {
            Some(v) => v.to_string(),
            None => continue,
        };
        let archive_path = format!("{}/{}", zip_root.trim_matches('/'), name);

        if path.is_dir() {
            writer
                .add_directory(format!("{}/", archive_path), options)
                .map_err(|e| format!("failed to add directory {}: {e}", path.display()))?;
            add_dir(writer, &path, &archive_path, options)?;
            continue;
        }

        if path.is_file() {
            if let Err(_) = fs::File::open(&path) {
                continue;
            }
            writer
                .start_file(archive_path, options)
                .map_err(|e| format!("failed to add file {}: {e}", path.display()))?;
            let mut file = fs::File::open(&path)
                .map_err(|e| format!("failed to open file {}: {e}", path.display()))?;
            std::io::copy(&mut file, writer)
                .map_err(|e| format!("failed to write file {}: {e}", path.display()))?;
        }
    }

    Ok(())
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
