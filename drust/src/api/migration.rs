use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    io::Write,
    path::{Component, Path, PathBuf},
    process::{Command, Stdio},
    sync::Arc,
    time::{SystemTime, UNIX_EPOCH},
};

const ROOT: &str = "/var/www/dpanel/storage/app/migrations";

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/migration/cpanel/inspect", post(inspect_handle))
        .route("/api/v1/migration/cpanel/restore", post(restore_handle))
        .route("/api/v1/migration/generic/restore", post(generic_restore_handle))
}

#[derive(Deserialize)]
struct GenericRestoreRequest {
    archive_path: String, domain: String, site_owner: String, php_version: String, target_root: String,
    sql_path: Option<String>, database_host: String, database_port: u16,
    database_name: String, database_user: String, database_password: String,
}

async fn generic_restore_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(req): Json<GenericRestoreRequest>) -> Response {
    if let Err(e) = check_token(&state, &headers) { return e.into_response(); }
    match generic_restore(&req) {
        Ok(v) => ApiResponse::ok_data("Generic website restored", v).into_response(),
        Err(e) => ApiResponse::error(&e).into_response(),
    }
}

#[derive(Deserialize)]
struct ArchiveRequest {
    archive_path: String,
}
#[derive(Deserialize, Default)]
struct Selection {
    #[serde(default)]
    domains: Vec<String>,
    #[serde(default)]
    files: Vec<String>,
    #[serde(default)]
    databases: Vec<String>,
    #[serde(default)]
    full_account: bool,
}
#[derive(Deserialize)]
struct RestoreRequest {
    archive_path: String,
    #[serde(default)]
    selection: Selection,
}

async fn inspect_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<ArchiveRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    match inspect(&req.archive_path) {
        Ok(v) => ApiResponse::ok_data("cPanel archive inspected", v).into_response(),
        Err(e) => ApiResponse::error(&e).into_response(),
    }
}
async fn restore_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<RestoreRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    match restore(&req.archive_path, &req.selection) {
        Ok(v) => ApiResponse::ok_data("Selected cPanel resources restored", v).into_response(),
        Err(e) => ApiResponse::error(&e).into_response(),
    }
}

fn archive(path: &str) -> Result<PathBuf, String> {
    let path = PathBuf::from(path);
    let root = Path::new(ROOT)
        .canonicalize()
        .map_err(|_| "migration storage is unavailable")?;
    let real = path
        .canonicalize()
        .map_err(|_| "migration archive was not found")?;
    if !real.starts_with(&root) || !real.is_file() {
        return Err("invalid migration archive path".into());
    }
    let name = real
        .file_name()
        .and_then(|v| v.to_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    if !name.ends_with(".tar.gz") && !name.ends_with(".tgz") {
        return Err("cPanel provider currently accepts .tar.gz or .tgz archives".into());
    }
    Ok(real)
}
fn listing(path: &Path) -> Result<Vec<String>, String> {
    let out = Command::new("tar")
        .args(["-tzf", path.to_string_lossy().as_ref()])
        .output()
        .map_err(|e| e.to_string())?;
    if !out.status.success() {
        return Err(format!(
            "invalid cPanel archive: {}",
            String::from_utf8_lossy(&out.stderr).trim()
        ));
    }
    let lines: Vec<String> = String::from_utf8_lossy(&out.stdout)
        .lines()
        .map(|v| v.trim_end_matches('/').to_string())
        .filter(|v| !v.is_empty())
        .collect();
    if lines.iter().any(|v| {
        Path::new(v).is_absolute()
            || Path::new(v)
                .components()
                .any(|c| matches!(c, Component::ParentDir))
    }) {
        return Err("unsafe path in cPanel archive".into());
    }
    let verbose = Command::new("tar")
        .args(["-tvzf", path.to_string_lossy().as_ref()])
        .output()
        .map_err(|e| e.to_string())?;
    if !verbose.status.success()
        || String::from_utf8_lossy(&verbose.stdout)
            .lines()
            .any(|line| !matches!(line.as_bytes().first(), Some(b'-' | b'd')))
    {
        return Err("cPanel archive contains unsupported links or special files".into());
    }
    Ok(lines)
}
fn package(lines: &[String]) -> Result<(String, String), String> {
    let first = lines
        .iter()
        .filter_map(|v| v.split('/').next())
        .find(|v| v.starts_with("cpmove-"))
        .ok_or("cpmove account directory is missing")?;
    let owner = first.trim_start_matches("cpmove-");
    if owner.is_empty()
        || !owner
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-')
    {
        return Err("invalid cPanel account owner".into());
    }
    Ok((first.to_string(), owner.to_string()))
}
fn member(path: &Path, name: &str) -> Option<String> {
    let out = Command::new("tar")
        .args(["-xOzf", path.to_string_lossy().as_ref(), name])
        .output()
        .ok()?;
    out.status
        .success()
        .then(|| String::from_utf8_lossy(&out.stdout).into_owned())
}
fn yaml_value(raw: &str, keys: &[&str]) -> Option<String> {
    raw.lines()
        .find_map(|line| {
            let (k, v) = line.trim().split_once(':')?;
            keys.iter()
                .any(|x| k.trim().eq_ignore_ascii_case(x))
                .then(|| v.trim().trim_matches(['\'', '"']).to_string())
        })
        .filter(|v| !v.is_empty())
}
fn inventory(
    path: &Path,
) -> Result<(serde_json::Value, BTreeMap<String, String>, String, String), String> {
    let lines = listing(path)?;
    let (prefix, owner) = package(&lines)?;
    let mut domains = BTreeMap::new();
    for name in lines
        .iter()
        .filter(|n| n.starts_with(&format!("{prefix}/userdata/")) && !n.ends_with("/main.json"))
    {
        if let Some(raw) = member(path, name) {
            if let Some(domain) = yaml_value(&raw, &["server_name", "servername"]) {
                let root = yaml_value(&raw, &["documentroot", "document_root"])
                    .unwrap_or_else(|| format!("/home/{owner}/public_html"));
                domains.insert(domain, root);
            }
        }
    }
    if domains.is_empty() {
        if let Some(domain) = member(path, &format!("{prefix}/cp"))
            .map(|v| v.trim().to_string())
            .filter(|v| !v.is_empty())
        {
            domains.insert(domain, format!("/home/{owner}/public_html"));
        }
    }
    let files: BTreeSet<String> = lines
        .iter()
        .filter_map(|n| n.strip_prefix(&format!("{prefix}/homedir/")))
        .filter_map(|r| r.split('/').next())
        .filter(|v| !v.is_empty())
        .map(str::to_string)
        .collect();
    let databases: BTreeSet<String> = lines
        .iter()
        .filter_map(|n| n.strip_prefix(&format!("{prefix}/mysql/")))
        .filter(|v| v.ends_with(".sql"))
        .map(|v| v.trim_end_matches(".sql").to_string())
        .collect();
    let value = serde_json::json!({"provider":"cpanel","account":owner,"domains":domains.iter().map(|(d,r)|serde_json::json!({"id":d,"label":d,"document_root":r})).collect::<Vec<_>>(),"files":files.iter().map(|v|serde_json::json!({"id":v,"label":v})).collect::<Vec<_>>(),"databases":databases.iter().map(|v|serde_json::json!({"id":v,"label":v})).collect::<Vec<_>>()});
    Ok((value, domains, prefix, owner))
}
fn inspect(value: &str) -> Result<serde_json::Value, String> {
    let path = archive(value)?;
    inventory(&path).map(|v| v.0)
}

fn restore(value: &str, selection: &Selection) -> Result<serde_json::Value, String> {
    let path = archive(value)?;
    let (inv, domains, prefix, owner) = inventory(&path)?;
    if !selection.full_account
        && selection.domains.is_empty()
        && selection.files.is_empty()
        && selection.databases.is_empty()
    {
        return Err("select at least one domain, file, or database".into());
    }
    let allowed_domains: BTreeSet<_> = domains.keys().cloned().collect();
    if selection
        .domains
        .iter()
        .any(|v| !allowed_domains.contains(v))
    {
        return Err("unknown domain selection".into());
    }
    let allowed_files: BTreeSet<String> = inv["files"]
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(|v| v["id"].as_str().map(str::to_string))
        .collect();
    if selection.files.iter().any(|v| !allowed_files.contains(v)) {
        return Err("unknown file selection".into());
    }
    let allowed_db: BTreeSet<String> = inv["databases"]
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(|v| v["id"].as_str().map(str::to_string))
        .collect();
    if selection.databases.iter().any(|v| !allowed_db.contains(v)) {
        return Err("unknown database selection".into());
    }
    if allowed_db.iter().any(|v| {
        !v.chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$')
    }) {
        return Err("unsafe database name in cPanel archive".into());
    }
    let nonce = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| e.to_string())?
        .as_nanos();
    let stage = PathBuf::from(format!("{ROOT}/.restore-{}-{nonce}", std::process::id()));
    fs::create_dir(&stage).map_err(|e| e.to_string())?;
    let out = Command::new("tar")
        .args([
            "-xzf",
            path.to_string_lossy().as_ref(),
            "-C",
            stage.to_string_lossy().as_ref(),
            "--no-same-owner",
            "--no-same-permissions",
        ])
        .output()
        .map_err(|e| e.to_string())?;
    if !out.status.success() {
        return Err(String::from_utf8_lossy(&out.stderr).into_owned());
    }
    let source = stage.join(&prefix);
    let home = PathBuf::from(format!("/home/{owner}"));
    if !home.exists() {
        let _ = Command::new("groupadd").args(["--force", &owner]).status();
        let s = Command::new("useradd")
            .args([
                "--create-home",
                "--gid",
                &owner,
                "--shell",
                "/usr/sbin/nologin",
                &owner,
            ])
            .status()
            .map_err(|e| e.to_string())?;
        if !s.success() {
            return Err("cannot create target account".into());
        }
    }
    let mut paths: BTreeSet<String> = if selection.full_account {
        allowed_files.clone()
    } else {
        selection.files.iter().cloned().collect()
    };
    for domain in &selection.domains {
        if let Some(root) = domains.get(domain) {
            if let Some(rel) = root.strip_prefix(&format!("/home/{owner}/")) {
                paths.insert(rel.split('/').next().unwrap_or(rel).to_string());
            }
        }
    }
    for rel in &paths {
        let from = source.join("homedir").join(rel);
        if from.exists() {
            let to = home.join(rel);
            if let Some(p) = to.parent() {
                fs::create_dir_all(p).ok();
            }
            let o = Command::new("cp")
                .args([
                    "-a",
                    from.to_string_lossy().as_ref(),
                    to.to_string_lossy().as_ref(),
                ])
                .output()
                .map_err(|e| e.to_string())?;
            if !o.status.success() {
                return Err(String::from_utf8_lossy(&o.stderr).into_owned());
            }
        }
    }
    let selected_db: Vec<String> = if selection.full_account {
        allowed_db.into_iter().collect()
    } else {
        selection.databases.clone()
    };
    let mut restored_db = Vec::new();
    for db in selected_db {
        let dump = source.join("mysql").join(format!("{db}.sql"));
        if !dump.is_file() {
            continue;
        }
        let create = Command::new("mysql")
            .args([
                "--protocol=socket",
                "--execute",
                &format!("CREATE DATABASE IF NOT EXISTS `{db}` CHARACTER SET utf8mb4"),
            ])
            .status()
            .map_err(|e| e.to_string())?;
        if !create.success() {
            return Err(format!("cannot create database {db}"));
        }
        let bytes = fs::read(&dump).map_err(|e| e.to_string())?;
        let mut child = Command::new("mysql")
            .args(["--protocol=socket", &db])
            .stdin(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|e| e.to_string())?;
        child
            .stdin
            .as_mut()
            .ok_or("mysql stdin unavailable")?
            .write_all(&bytes)
            .map_err(|e| e.to_string())?;
        let o = child.wait_with_output().map_err(|e| e.to_string())?;
        if !o.status.success() {
            return Err(format!(
                "database restore failed for {db}: {}",
                String::from_utf8_lossy(&o.stderr)
            ));
        }
        restored_db.push(db);
    }
    let _ = Command::new("chown")
        .args([
            "-R",
            &format!("{owner}:{owner}"),
            home.to_string_lossy().as_ref(),
        ])
        .status();
    crate::filemanager::fix_permissions::run(Some(&owner), home.to_str(), false)?;
    fs::remove_dir_all(stage).ok();
    Ok(
        serde_json::json!({"account":owner,"domains":if selection.full_account{domains.keys().cloned().collect::<Vec<_>>()}else{selection.domains.clone()},"files":paths,"databases":restored_db}),
    )
}

fn generic_restore(req: &GenericRestoreRequest) -> Result<serde_json::Value, String> {
    let source = archive_under_root(&req.archive_path, &[".zip", ".tar.gz", ".tgz"])?;
    if req.site_owner.is_empty() || !req.site_owner.chars().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-') {
        return Err("invalid target system user".into());
    }
    if !req.domain.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'.' || c == b'-') || !req.domain.contains('.') { return Err("invalid domain".into()); }
    if req.php_version.split_once('.').is_none() || !req.php_version.bytes().all(|c| c.is_ascii_digit() || c == b'.') { return Err("invalid PHP version".into()); }
    for value in [&req.database_name, &req.database_user] {
        if value.is_empty() || !value.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'_') { return Err("invalid database identity".into()); }
    }
    let stage = PathBuf::from(format!("{ROOT}/.generic-{}-{}", std::process::id(), SystemTime::now().duration_since(UNIX_EPOCH).map_err(|e| e.to_string())?.as_nanos()));
    fs::create_dir(&stage).map_err(|e| e.to_string())?;
    let names = generic_archive_listing(&source)?;
    if names.iter().any(|name| Path::new(name).is_absolute() || Path::new(name).components().any(|c| matches!(c, Component::ParentDir))) {
        fs::remove_dir_all(&stage).ok(); return Err("archive contains an unsafe path".into());
    }
    let status = if source.to_string_lossy().to_ascii_lowercase().ends_with(".zip") {
        Command::new("unzip").args(["-q", source.to_string_lossy().as_ref(), "-d", stage.to_string_lossy().as_ref()]).status()
    } else {
        Command::new("tar").args(["-xzf", source.to_string_lossy().as_ref(), "-C", stage.to_string_lossy().as_ref(), "--no-same-owner", "--no-same-permissions"]).status()
    }.map_err(|e| e.to_string())?;
    if !status.success() { fs::remove_dir_all(&stage).ok(); return Err("website archive extraction failed".into()); }
    let content_root = collapse_single_directory(&stage)?;
    let home = PathBuf::from(format!("/home/{}", req.site_owner));
    if !home.exists() {
        let _ = Command::new("groupadd").args(["--force", &req.site_owner]).status();
        if !Command::new("useradd").args(["--create-home", "--gid", &req.site_owner, "--shell", "/usr/sbin/nologin", &req.site_owner]).status().map_err(|e| e.to_string())?.success() {
            fs::remove_dir_all(&stage).ok(); return Err("cannot create target system user".into());
        }
    }
    let project_root = PathBuf::from(&req.target_root);
    if !project_root.starts_with(&home) || project_root.components().any(|part| matches!(part, Component::ParentDir)) { fs::remove_dir_all(&stage).ok(); return Err("website import target is outside its system user home".into()); }
    fs::create_dir_all(&project_root).map_err(|e| e.to_string())?;
    let copy_source = format!("{}/.", content_root.display());
    if !Command::new("cp").args(["-a", &copy_source, project_root.to_string_lossy().as_ref()]).status().map_err(|e| e.to_string())?.success() {
        fs::remove_dir_all(&stage).ok(); return Err("cannot copy website files".into());
    }
    if let Some(sql) = req.sql_path.as_deref() {
        let sql = archive_under_root(sql, &[".sql"])?;
        restore_generic_database(req, &sql)?;
    }
    let (framework, config_path, document_root) = detect_generic_application(&project_root);
    let _ = Command::new("chown").args(["-R", &format!("{}:{}", req.site_owner, req.site_owner), home.to_string_lossy().as_ref()]).status();
    crate::filemanager::fix_permissions::run(Some(&req.site_owner), project_root.to_str(), false)?;
    fs::remove_dir_all(stage).ok();
    Ok(serde_json::json!({"project_root":project_root,"document_root":document_root,"framework":framework,"config_path":config_path}))
}

fn archive_under_root(value: &str, extensions: &[&str]) -> Result<PathBuf, String> {
    let root = Path::new(ROOT).canonicalize().map_err(|_| "migration storage is unavailable")?;
    let path = Path::new(value).canonicalize().map_err(|_| "uploaded migration file was not found")?;
    let name = path.file_name().and_then(|v| v.to_str()).unwrap_or("").to_ascii_lowercase();
    if !path.starts_with(root) || !path.is_file() || !extensions.iter().any(|ext| name.ends_with(ext)) { return Err("invalid migration file".into()); }
    Ok(path)
}

fn generic_archive_listing(path: &Path) -> Result<Vec<String>, String> {
    let zip = path.to_string_lossy().to_ascii_lowercase().ends_with(".zip");
    let out = if zip { Command::new("unzip").args(["-Z1", path.to_string_lossy().as_ref()]).output() }
        else { Command::new("tar").args(["-tzf", path.to_string_lossy().as_ref()]).output() }.map_err(|e| e.to_string())?;
    if !out.status.success() { return Err("invalid website archive".into()); }
    let verbose = if zip { Command::new("zipinfo").args(["-l", path.to_string_lossy().as_ref()]).output() }
        else { Command::new("tar").args(["-tvzf", path.to_string_lossy().as_ref()]).output() }.map_err(|e| e.to_string())?;
    if !verbose.status.success() || String::from_utf8_lossy(&verbose.stdout).lines().any(|line| matches!(line.as_bytes().first(), Some(b'l' | b'b' | b'c' | b'p' | b's'))) {
        return Err("archive contains links or unsupported special files".into());
    }
    Ok(String::from_utf8_lossy(&out.stdout).lines().map(str::to_string).collect())
}

fn collapse_single_directory(stage: &Path) -> Result<PathBuf, String> {
    let entries: Vec<PathBuf> = fs::read_dir(stage).map_err(|e| e.to_string())?.filter_map(Result::ok).map(|e| e.path()).collect();
    Ok(if entries.len() == 1 && entries[0].is_dir() { entries[0].clone() } else { stage.to_path_buf() })
}

fn sql_escape(value: &str) -> String { value.replace('\\', "\\\\").replace('\'', "''") }
fn restore_generic_database(req: &GenericRestoreRequest, sql: &Path) -> Result<(), String> {
    let local = matches!(req.database_host.as_str(), "127.0.0.1" | "localhost");
    if local {
        let password = sql_escape(&req.database_password);
        let statement = format!("CREATE DATABASE IF NOT EXISTS `{}` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '{}'@'localhost' IDENTIFIED BY '{}'; ALTER USER '{}'@'localhost' IDENTIFIED BY '{}'; GRANT ALL PRIVILEGES ON `{}`.* TO '{}'@'localhost'; FLUSH PRIVILEGES;", req.database_name, req.database_user, password, req.database_user, password, req.database_name, req.database_user);
        if !Command::new("mysql").args(["--protocol=socket", "--execute", &statement]).status().map_err(|e| e.to_string())?.success() { return Err("cannot provision local database".into()); }
    }
    let bytes = fs::read(sql).map_err(|e| e.to_string())?;
    let mut child = Command::new("mysql").env("MYSQL_PWD", &req.database_password).args(["--host", &req.database_host, "--port", &req.database_port.to_string(), "--user", &req.database_user, &req.database_name]).stdin(Stdio::piped()).stderr(Stdio::piped()).spawn().map_err(|e| e.to_string())?;
    child.stdin.as_mut().ok_or("database import stdin unavailable")?.write_all(&bytes).map_err(|e| e.to_string())?;
    let out = child.wait_with_output().map_err(|e| e.to_string())?;
    if !out.status.success() { return Err(format!("database import failed: {}", String::from_utf8_lossy(&out.stderr).trim())); }
    Ok(())
}

fn detect_generic_application(root: &Path) -> (Option<&'static str>, Option<PathBuf>, PathBuf) {
    if root.join("artisan").is_file() { return (Some("laravel"), Some(root.join(".env")), if root.join("public").is_dir() { root.join("public") } else { root.to_path_buf() }); }
    if root.join("wp-config.php").is_file() { return (Some("wordpress"), Some(root.join("wp-config.php")), root.to_path_buf()); }
    if root.join("spark").is_file() { return (Some("codeigniter4"), Some(root.join(".env")), if root.join("public").is_dir() { root.join("public") } else { root.to_path_buf() }); }
    if root.join("application/config/database.php").is_file() { return (Some("codeigniter3"), Some(root.join("application/config/database.php")), root.to_path_buf()); }
    (None, None, root.to_path_buf())
}
