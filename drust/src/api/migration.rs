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
    fs,
    io::Write,
    path::{Component, Path, PathBuf},
    process::{Command, Stdio},
    sync::Arc,
    time::{SystemTime, UNIX_EPOCH},
};

pub(super) const ROOT: &str = "/var/www/dpanel/storage/app/migrations";

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route(
        "/api/v1/migration/generic/restore",
        post(generic_restore_handle),
    )
}

#[derive(Deserialize)]
struct GenericRestoreRequest {
    // Absent/empty for a database-only import (see the early-return branch in
    // generic_restore): no website files are extracted or copied.
    #[serde(default)]
    archive_path: Option<String>,
    domain: String,
    site_owner: String,
    php_version: String,
    target_root: String,
    // The app root one level above target_root's docroot (e.g. Laravel's base
    // path when target_root is its "public" dir). Optional: only used to
    // place the ".env"-and-friends bundle a "quick_files" archive carries
    // under the "__project_root_extras__/" marker (see website.rs); ignored
    // for archives that don't have one.
    #[serde(default)]
    target_project_root: Option<String>,
    // Where to put the pre-overwrite mysqldump when overwrite_database is
    // set — normally a .trash/{batch}/_database dir set up by dpanel so it
    // sits next to the moved files from the same backup. Falls back to
    // home/migration_uploads when absent or outside the site owner's home.
    #[serde(default)]
    database_backup_dir: Option<String>,
    // Keep a copy of the archive/SQL in the owner's home/migration_uploads.
    // Wanted for a file the admin uploaded on the Import page; Clone & Share
    // and Push to Live send false, or every push left another package there.
    #[serde(default = "default_preserve_uploads")]
    preserve_uploads: bool,
    sql_path: Option<String>,
    database_host: String,
    database_port: u16,
    database_name: String,
    database_user: String,
    database_password: String,
    #[serde(default)]
    overwrite_database: bool,
    // The target website's address. When the import is a WordPress site
    // with its database, siteurl/home (and links in post content) are
    // moved from the source address to this one, or WordPress keeps
    // redirecting to the old domain.
    #[serde(default)]
    site_url: Option<String>,
}

async fn generic_restore_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<GenericRestoreRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    match generic_restore(&req) {
        Ok(v) => ApiResponse::ok_data("Generic website restored", v).into_response(),
        Err(e) => ApiResponse::error(&e).into_response(),
    }
}

fn generic_restore(req: &GenericRestoreRequest) -> Result<serde_json::Value, String> {
    if req.site_owner.is_empty()
        || !req
            .site_owner
            .chars()
            .all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-')
    {
        return Err("invalid target system user".into());
    }
    if !req
        .domain
        .bytes()
        .all(|c| c.is_ascii_alphanumeric() || c == b'.' || c == b'-')
        || !req.domain.contains('.')
    {
        return Err("invalid domain".into());
    }
    if req.php_version.split_once('.').is_none()
        || !req
            .php_version
            .bytes()
            .all(|c| c.is_ascii_digit() || c == b'.')
    {
        return Err("invalid PHP version".into());
    }
    if req.sql_path.as_deref().is_some_and(|path| !path.is_empty()) {
        for value in [&req.database_name, &req.database_user] {
            if value.is_empty()
                || !value
                    .bytes()
                    .all(|c| c.is_ascii_alphanumeric() || c == b'_')
            {
                return Err("invalid database identity".into());
            }
        }
    }
    let home = PathBuf::from(format!("/home/{}", req.site_owner));
    if !home.exists() {
        let _ = Command::new("groupadd")
            .args(["--force", &req.site_owner])
            .status();
        if !Command::new("useradd")
            .args([
                "--create-home",
                "--gid",
                &req.site_owner,
                "--shell",
                "/usr/sbin/nologin",
                &req.site_owner,
            ])
            .status()
            .map_err(|e| e.to_string())?
            .success()
        {
            return Err("cannot create target system user".into());
        }
    }

    let archive_path = req.archive_path.as_deref().filter(|p| !p.is_empty());
    if archive_path.is_none() {
        // Database-only import: no website archive was submitted, so skip
        // extraction/copy/chown entirely and just restore the SQL dump. This
        // is the path a plain database import takes without also requiring
        // a website files upload.
        let sql_path = req
            .sql_path
            .as_deref()
            .filter(|p| !p.is_empty())
            .ok_or_else(|| "no website archive or database dump was provided".to_string())?;
        let sql = archive_under_root(sql_path, &[".sql"])?;
        let database_backup = if req.overwrite_database {
            Some(backup_generic_database(req, &home)?)
        } else {
            None
        };
        restore_generic_database(req, &sql)?;
        let wordpress_url = rewrite_wordpress_urls(req, Path::new(&req.target_root));
        let saved_database = keep_generic_upload(req, &sql, &home)?;
        return Ok(serde_json::json!({
            "project_root": null, "document_root": null, "framework": null, "config_path": null,
            "saved_archive": null, "saved_database": saved_database, "database_backup": database_backup,
            "wordpress_url": wordpress_url,
        }));
    }
    let source = archive_under_root(archive_path.unwrap(), &[".zip", ".tar.gz", ".tgz"])?;
    let stage = PathBuf::from(format!(
        "{ROOT}/.generic-{}-{}",
        std::process::id(),
        SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map_err(|e| e.to_string())?
            .as_nanos()
    ));
    fs::create_dir(&stage).map_err(|e| e.to_string())?;
    let names = generic_archive_listing(&source)?;
    if names.iter().any(|name| {
        Path::new(name).is_absolute()
            || Path::new(name)
                .components()
                .any(|c| matches!(c, Component::ParentDir))
    }) {
        fs::remove_dir_all(&stage).ok();
        return Err("archive contains an unsafe path".into());
    }
    let status = if source
        .to_string_lossy()
        .to_ascii_lowercase()
        .ends_with(".zip")
    {
        Command::new("unzip")
            .args([
                "-q",
                source.to_string_lossy().as_ref(),
                "-d",
                stage.to_string_lossy().as_ref(),
            ])
            .status()
    } else {
        Command::new("tar")
            .args([
                "-xzf",
                source.to_string_lossy().as_ref(),
                "-C",
                stage.to_string_lossy().as_ref(),
                "--no-same-owner",
                "--no-same-permissions",
            ])
            .status()
    }
    .map_err(|e| e.to_string())?;
    if !status.success() {
        fs::remove_dir_all(&stage).ok();
        return Err("website archive extraction failed".into());
    }
    let content_root = collapse_single_directory(&stage)?;
    let project_root = PathBuf::from(&req.target_root);
    if !project_root.starts_with(&home)
        || project_root
            .components()
            .any(|part| matches!(part, Component::ParentDir))
    {
        fs::remove_dir_all(&stage).ok();
        return Err("website import target is outside its system user home".into());
    }
    fs::create_dir_all(&project_root).map_err(|e| e.to_string())?;

    // A "quick_files" clone archive may carry a "__project_root_extras__"
    // marker directory (.env and other app-root files that live one level
    // above the docroot — see website.rs's is_quick_files branch). Peel it
    // off before the flat copy below, and place it in the target's actual
    // project root instead of the docroot, so .env doesn't end up nested
    // under the target's "public" dir where the app never looks for it.
    let extras_dir = content_root.join("__project_root_extras__");
    if extras_dir.is_dir() {
        if let Some(target_project_root) = req.target_project_root.as_deref() {
            let target_project_root = PathBuf::from(target_project_root);
            if target_project_root.starts_with(&home)
                && !target_project_root
                    .components()
                    .any(|part| matches!(part, Component::ParentDir))
            {
                fs::create_dir_all(&target_project_root).map_err(|e| e.to_string())?;
                let extras_copy_source = format!("{}/.", extras_dir.display());
                let _ = Command::new("cp")
                    .args([
                        "-a",
                        &extras_copy_source,
                        target_project_root.to_string_lossy().as_ref(),
                    ])
                    .status();
            }
        }
        fs::remove_dir_all(&extras_dir).ok();
    }

    let copy_source = format!("{}/.", content_root.display());
    if !Command::new("cp")
        .args(["-a", &copy_source, project_root.to_string_lossy().as_ref()])
        .status()
        .map_err(|e| e.to_string())?
        .success()
    {
        fs::remove_dir_all(&stage).ok();
        return Err("cannot copy website files".into());
    }
    let mut database_backup = None;
    let mut wordpress_url = serde_json::Value::Null;
    let saved_database = if let Some(sql) = req.sql_path.as_deref() {
        let sql = archive_under_root(sql, &[".sql"])?;
        if req.overwrite_database {
            database_backup = Some(backup_generic_database(req, &home)?);
        }
        restore_generic_database(req, &sql)?;
        wordpress_url = rewrite_wordpress_urls(req, &project_root);
        keep_generic_upload(req, &sql, &home)?
    } else {
        None
    };
    let saved_archive = keep_generic_upload(req, &source, &home)?;
    let (framework, config_path, document_root) = detect_generic_application(&project_root);
    let _ = Command::new("chown")
        .args([
            "-R",
            &format!("{}:{}", req.site_owner, req.site_owner),
            home.to_string_lossy().as_ref(),
        ])
        .status();
    crate::filemanager::fix_permissions::run(Some(&req.site_owner), home.to_str(), false)?;
    fs::remove_dir_all(stage).ok();
    Ok(
        serde_json::json!({"project_root":project_root,"document_root":document_root,"framework":framework,"config_path":config_path,"saved_archive":saved_archive,"saved_database":saved_database,"database_backup":database_backup,"wordpress_url":wordpress_url}),
    )
}

fn backup_generic_database(req: &GenericRestoreRequest, home: &Path) -> Result<PathBuf, String> {
    // When the caller set up a .trash batch for this overwrite (Clone/Import's
    // PreOverwriteBackupService), drop the dump alongside the moved files
    // instead of the unrelated migration_uploads folder, so both halves of
    // the backup are restorable together.
    let upload_directory = match req.database_backup_dir.as_deref().map(PathBuf::from) {
        Some(dir) if dir.starts_with(home) && !dir.components().any(|c| matches!(c, Component::ParentDir)) => dir,
        _ => home.join("migration_uploads"),
    };
    fs::create_dir_all(&upload_directory)
        .map_err(|error| format!("cannot create migration upload folder: {error}"))?;
    let timestamp = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|error| error.to_string())?
        .as_nanos();
    let destination = upload_directory.join(format!(
        "database-backup-{}-{timestamp}.sql",
        req.database_name
    ));
    let output_file = fs::File::create(&destination)
        .map_err(|error| format!("cannot create database backup: {error}"))?;
    let local = matches!(req.database_host.as_str(), "127.0.0.1" | "localhost");
    let mut command = Command::new("mysqldump");
    if local {
        command.args(["--protocol=socket"]);
    } else {
        command.env("MYSQL_PWD", &req.database_password).args([
            "--host",
            &req.database_host,
            "--port",
            &req.database_port.to_string(),
            "--user",
            &req.database_user,
        ]);
    }
    let output = command
        .args(["--single-transaction", "--routines", "--triggers"])
        .arg(&req.database_name)
        .stdout(Stdio::from(output_file))
        .stderr(Stdio::piped())
        .output()
        .map_err(|error| {
            fs::remove_file(&destination).ok();
            format!("database backup failed; database was not overwritten: {error}")
        })?;
    if !output.status.success() {
        fs::remove_file(&destination).ok();
        return Err(format!(
            "database backup failed; database was not overwritten: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    Ok(destination)
}

fn default_preserve_uploads() -> bool {
    true
}

fn keep_generic_upload(
    req: &GenericRestoreRequest,
    source: &Path,
    home: &Path,
) -> Result<Option<PathBuf>, String> {
    if !req.preserve_uploads {
        return Ok(None);
    }
    preserve_generic_upload(source, home).map(Some)
}

fn preserve_generic_upload(source: &Path, home: &Path) -> Result<PathBuf, String> {
    let upload_directory = home.join("migration_uploads");
    fs::create_dir_all(&upload_directory)
        .map_err(|error| format!("cannot create migration upload folder: {error}"))?;

    let original_name = source
        .file_name()
        .ok_or_else(|| "uploaded migration filename is missing".to_string())?;
    let mut destination = upload_directory.join(original_name);
    if destination.exists() {
        let original = original_name.to_string_lossy();
        let timestamp = SystemTime::now()
            .duration_since(UNIX_EPOCH)
            .map_err(|error| error.to_string())?
            .as_nanos();
        let (stem, extension) = if original.to_ascii_lowercase().ends_with(".tar.gz") {
            (&original[..original.len() - 7], ".tar.gz")
        } else if let Some((stem, _)) = original.rsplit_once('.') {
            (stem, &original[stem.len()..])
        } else {
            (original.as_ref(), "")
        };
        destination = upload_directory.join(format!("{stem}-{timestamp}{extension}"));
    }

    fs::copy(source, &destination)
        .map_err(|error| format!("cannot preserve uploaded migration archive: {error}"))?;
    Ok(destination)
}

#[cfg(test)]
mod generic_upload_tests {
    use super::preserve_generic_upload;
    use std::fs;

    #[test]
    fn preserves_upload_without_overwriting_an_existing_archive() {
        let root = std::env::temp_dir().join(format!(
            "drust-migration-upload-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        let source_directory = root.join("source");
        let home = root.join("home");
        fs::create_dir_all(&source_directory).unwrap();
        fs::create_dir_all(&home).unwrap();
        let source = source_directory.join("website-project.tar.gz");
        fs::write(&source, b"archive").unwrap();

        let first = preserve_generic_upload(&source, &home).unwrap();
        let second = preserve_generic_upload(&source, &home).unwrap();

        assert_eq!(first.file_name().unwrap(), "website-project.tar.gz");
        assert_ne!(first, second);
        assert_eq!(fs::read(first).unwrap(), b"archive");
        assert_eq!(fs::read(second).unwrap(), b"archive");
        fs::remove_dir_all(root).unwrap();
    }
}

fn archive_under_root(value: &str, extensions: &[&str]) -> Result<PathBuf, String> {
    let root = Path::new(ROOT)
        .canonicalize()
        .map_err(|_| "migration storage is unavailable")?;
    let path = Path::new(value)
        .canonicalize()
        .map_err(|_| "uploaded migration file was not found")?;
    let name = path
        .file_name()
        .and_then(|v| v.to_str())
        .unwrap_or("")
        .to_ascii_lowercase();
    if !path.starts_with(root)
        || !path.is_file()
        || !extensions.iter().any(|ext| name.ends_with(ext))
    {
        return Err("invalid migration file".into());
    }
    Ok(path)
}

fn generic_archive_listing(path: &Path) -> Result<Vec<String>, String> {
    let zip = path
        .to_string_lossy()
        .to_ascii_lowercase()
        .ends_with(".zip");
    let out = if zip {
        Command::new("unzip")
            .args(["-Z1", path.to_string_lossy().as_ref()])
            .output()
    } else {
        Command::new("tar")
            .args(["-tzf", path.to_string_lossy().as_ref()])
            .output()
    }
    .map_err(|e| e.to_string())?;
    if !out.status.success() {
        return Err("invalid website archive".into());
    }
    let verbose = if zip {
        Command::new("zipinfo")
            .args(["-l", path.to_string_lossy().as_ref()])
            .output()
    } else {
        Command::new("tar")
            .args(["-tvzf", path.to_string_lossy().as_ref()])
            .output()
    }
    .map_err(|e| e.to_string())?;
    if !verbose.status.success()
        || String::from_utf8_lossy(&verbose.stdout)
            .lines()
            .any(|line| {
                matches!(
                    line.as_bytes().first(),
                    Some(b'l' | b'b' | b'c' | b'p' | b's')
                )
            })
    {
        return Err("archive contains links or unsupported special files".into());
    }
    Ok(String::from_utf8_lossy(&out.stdout)
        .lines()
        .map(str::to_string)
        .collect())
}

fn collapse_single_directory(stage: &Path) -> Result<PathBuf, String> {
    let entries: Vec<PathBuf> = fs::read_dir(stage)
        .map_err(|e| e.to_string())?
        .filter_map(Result::ok)
        .map(|e| e.path())
        .collect();
    Ok(if entries.len() == 1 && entries[0].is_dir() {
        entries[0].clone()
    } else {
        stage.to_path_buf()
    })
}

pub(super) fn sql_escape(value: &str) -> String {
    value.replace('\\', "\\\\").replace('\'', "''")
}
fn restore_generic_database(req: &GenericRestoreRequest, sql: &Path) -> Result<(), String> {
    let local = matches!(req.database_host.as_str(), "127.0.0.1" | "localhost");
    if local {
        let password = sql_escape(&req.database_password);
        let reset = if req.overwrite_database {
            format!("DROP DATABASE IF EXISTS `{}`; ", req.database_name)
        } else {
            String::new()
        };
        let statement = format!(
            "{}CREATE DATABASE IF NOT EXISTS `{}` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '{}'@'localhost' IDENTIFIED BY '{}'; ALTER USER '{}'@'localhost' IDENTIFIED BY '{}'; GRANT ALL PRIVILEGES ON `{}`.* TO '{}'@'localhost'; FLUSH PRIVILEGES;",
            reset,
            req.database_name,
            req.database_user,
            password,
            req.database_user,
            password,
            req.database_name,
            req.database_user
        );
        if !Command::new("mysql")
            .args(["--protocol=socket", "--execute", &statement])
            .status()
            .map_err(|e| e.to_string())?
            .success()
        {
            return Err("cannot provision local database".into());
        }
    }
    let bytes = fs::read(sql).map_err(|e| e.to_string())?;
    let bytes = remap_unsupported_collations(bytes);
    let bytes = strip_privileged_statements(bytes)?;
    let mut child = Command::new("mysql")
        .env("MYSQL_PWD", &req.database_password)
        .args([
            "--host",
            &req.database_host,
            "--port",
            &req.database_port.to_string(),
            "--user",
            &req.database_user,
            &req.database_name,
        ])
        .stdin(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| e.to_string())?;
    // mysql exiting early (bad SQL, unknown collation, auth, ...) closes its
    // stdin, so this write can fail with a bare "Broken pipe" before we ever
    // see the real reason. Keep going to wait_with_output() either way so the
    // child's actual stderr — not the write error — is what gets reported.
    let write_result = child
        .stdin
        .as_mut()
        .ok_or("database import stdin unavailable")?
        .write_all(&bytes);
    let out = child.wait_with_output().map_err(|e| e.to_string())?;
    let stderr = String::from_utf8_lossy(&out.stderr).trim().to_string();
    if let Err(write_error) = write_result {
        return Err(if stderr.is_empty() {
            format!("database import failed: {write_error}")
        } else {
            format!("database import failed: {stderr}")
        });
    }
    if !out.status.success() {
        return Err(format!(
            "database import failed: {}",
            if stderr.is_empty() { "mysql exited with an error".to_string() } else { stderr }
        ));
    }
    Ok(())
}

/// Point a just-imported WordPress database at its new address: siteurl and
/// home, plus links in post content (plain text, so a REPLACE is safe; the
/// serialized option/meta values are left alone). Returns {from, to}, a
/// {warning} when it could not be done, or null when there was nothing to
/// change. Never fails the import: the site is restored either way.
fn rewrite_wordpress_urls(req: &GenericRestoreRequest, root: &Path) -> serde_json::Value {
    let Some(new_url) = req
        .site_url
        .as_deref()
        .map(|v| v.trim().trim_end_matches('/'))
        .filter(|v| valid_site_url(v))
    else {
        return serde_json::Value::Null;
    };
    let Ok(config) = fs::read_to_string(root.join("wp-config.php")) else {
        return serde_json::Value::Null;
    };
    let prefix = wordpress_table_prefix(&config);
    let query = |sql: &str| -> Result<String, String> {
        let out = Command::new("mysql")
            .env("MYSQL_PWD", &req.database_password)
            .args([
                "--host",
                &req.database_host,
                "--port",
                &req.database_port.to_string(),
                "--user",
                &req.database_user,
                "--batch",
                "--skip-column-names",
                "--execute",
                sql,
                &req.database_name,
            ])
            .output()
            .map_err(|e| e.to_string())?;
        if !out.status.success() {
            return Err(String::from_utf8_lossy(&out.stderr).trim().to_string());
        }
        Ok(String::from_utf8_lossy(&out.stdout).trim().to_string())
    };
    let old_url = match query(&format!(
        "SELECT option_value FROM `{prefix}options` WHERE option_name = 'siteurl' LIMIT 1"
    )) {
        Ok(v) => v.trim_end_matches('/').to_string(),
        Err(e) => {
            return serde_json::json!({"warning": format!("WordPress address was not updated: {e}")});
        }
    };
    if old_url.is_empty() || old_url == new_url {
        return serde_json::Value::Null;
    }
    let (old, new) = (sql_escape(&old_url), sql_escape(new_url));
    match query(&format!(
        "UPDATE `{prefix}options` SET option_value = '{new}' WHERE option_name IN ('siteurl', 'home'); \
         UPDATE `{prefix}posts` SET post_content = REPLACE(post_content, '{old}', '{new}');"
    )) {
        Ok(_) => serde_json::json!({"from": old_url, "to": new_url}),
        Err(e) => serde_json::json!({"warning": format!("WordPress address was not updated: {e}")}),
    }
}

fn valid_site_url(value: &str) -> bool {
    (value.starts_with("http://") || value.starts_with("https://"))
        && value
            .bytes()
            .all(|c| c.is_ascii_alphanumeric() || b".-:/_".contains(&c))
}

/// `$table_prefix = 'wp_';` from wp-config.php; "wp_" when absent or odd.
fn wordpress_table_prefix(config: &str) -> String {
    config
        .lines()
        .map(str::trim)
        .filter(|line| line.starts_with("$table_prefix"))
        .find_map(|line| {
            let value = line.split_once('=')?.1.trim();
            let quote = value.chars().next().filter(|c| *c == '\'' || *c == '"')?;
            let rest = &value[1..];
            Some(rest[..rest.find(quote)?].to_string())
        })
        .filter(|p| !p.is_empty() && p.bytes().all(|c| c.is_ascii_alphanumeric() || c == b'_'))
        .unwrap_or_else(|| "wp_".to_string())
}

// MySQL 8's "utf8mb4_0900_*" collation family (including locale variants like
// utf8mb4_de_pb_0900_ai_ci) doesn't exist on MariaDB below 11.4, so a dump
// taken from MySQL 8 (or a newer MariaDB that added these for compatibility)
// fails immediately with "Unknown collation" on an older MariaDB target.
// Remap them to the closest MariaDB-native equivalent before importing —
// same data, just a different default text sort order for those columns.
pub(super) fn remap_unsupported_collations(bytes: Vec<u8>) -> Vec<u8> {
    const PREFIX: &[u8] = b"utf8mb4_";
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i..].starts_with(PREFIX) {
            let start = i;
            let mut end = i + PREFIX.len();
            while end < bytes.len() && (bytes[end].is_ascii_alphanumeric() || bytes[end] == b'_') {
                end += 1;
            }
            let token = &bytes[start..end];
            if token.windows(4).any(|w| w == b"0900") {
                out.extend_from_slice(if token.ends_with(b"_bin") {
                    b"utf8mb4_bin"
                } else {
                    b"utf8mb4_general_ci"
                });
                i = end;
                continue;
            }
        }
        out.push(bytes[i]);
        i += 1;
    }
    out
}

// The import runs as the site's own database user, which only has rights on
// the target database. Dumps taken with `--databases` / `-B` (or from another
// panel) carry `CREATE DATABASE` + `USE` for the source server's database
// name, so the import tried to switch to e.g. `hstadmin_Katha24webDB` and hit
// "Access denied". Drop database-level statements so everything lands in the
// target database, and strip the parts that need SUPER: DEFINER clauses on
// views/triggers/routines and MySQL's GTID / binlog session settings. Only
// statement lines are touched, never INSERT data. A dump holding several
// databases is refused rather than silently merged into one.
pub(super) fn strip_privileged_statements(bytes: Vec<u8>) -> Result<Vec<u8>, String> {
    let mut out = Vec::with_capacity(bytes.len());
    let mut source_database: Option<Vec<u8>> = None;

    for line in bytes.split_inclusive(|&b| b == b'\n') {
        if line.starts_with(b"INSERT ") || line.starts_with(b"(") {
            out.extend_from_slice(line);
            continue;
        }
        let trimmed = line.trim_ascii_start();
        let upper: Vec<u8> = trimmed.iter().take(64).map(u8::to_ascii_uppercase).collect();

        if upper.starts_with(b"USE ") {
            let name: Vec<u8> = trimmed[4..]
                .iter()
                .copied()
                .filter(|b| !matches!(b, b'`' | b';' | b'\r' | b'\n' | b' '))
                .collect();
            match &source_database {
                Some(existing) if *existing != name => {
                    return Err(format!(
                        "the SQL dump contains more than one database ({} and {}); export only the website's database and import again",
                        String::from_utf8_lossy(existing),
                        String::from_utf8_lossy(&name)
                    ));
                }
                _ => source_database = Some(name),
            }
            continue;
        }
        if upper.starts_with(b"CREATE DATABASE")
            || upper.starts_with(b"CREATE SCHEMA")
            || upper.starts_with(b"DROP DATABASE")
            || upper.starts_with(b"DROP SCHEMA")
            || upper.starts_with(b"/*!40000 DROP DATABASE")
            || upper.starts_with(b"SET @@GLOBAL.GTID_PURGED")
            || upper.starts_with(b"/*!80000 SET @@GLOBAL.GTID_PURGED")
            || upper.starts_with(b"SET @@SESSION.SQL_LOG_BIN")
            || upper.starts_with(b"SET @MYSQLDUMP_TEMP_LOG_BIN")
        {
            continue;
        }
        out.extend_from_slice(&strip_definer(line));
    }
    Ok(out)
}

// Removes `DEFINER=user@host` (quoted or not, including CURRENT_USER) so the
// object is created as the importing user. "SQL SECURITY DEFINER" is kept.
fn strip_definer(line: &[u8]) -> Vec<u8> {
    const KEY: &[u8] = b"DEFINER=";
    let mut out = Vec::with_capacity(line.len());
    let mut i = 0;
    while i < line.len() {
        if line[i..].len() >= KEY.len() && line[i..i + KEY.len()].eq_ignore_ascii_case(KEY) {
            let mut end = i + KEY.len();
            let mut quote: Option<u8> = None;
            while end < line.len() {
                let b = line[end];
                match quote {
                    Some(q) if b == q => quote = None,
                    Some(_) => {}
                    None if matches!(b, b'`' | b'\'' | b'"') => quote = Some(b),
                    None if b.is_ascii_whitespace() || b == b'*' => break,
                    None => {}
                }
                end += 1;
            }
            while end < line.len() && line[end] == b' ' {
                end += 1;
            }
            i = end;
            continue;
        }
        out.push(line[i]);
        i += 1;
    }
    out
}

pub(super) fn detect_generic_application(root: &Path) -> (Option<&'static str>, Option<PathBuf>, PathBuf) {
    if root.join("artisan").is_file() {
        return (
            Some("laravel"),
            Some(root.join(".env")),
            if root.join("public").is_dir() {
                root.join("public")
            } else {
                root.to_path_buf()
            },
        );
    }
    if root.join("wp-config.php").is_file() {
        return (
            Some("wordpress"),
            Some(root.join("wp-config.php")),
            root.to_path_buf(),
        );
    }
    if root.join("spark").is_file() {
        return (
            Some("codeigniter4"),
            Some(root.join(".env")),
            if root.join("public").is_dir() {
                root.join("public")
            } else {
                root.to_path_buf()
            },
        );
    }
    if root.join("application/config/database.php").is_file() {
        let config = if root.join(".env").is_file() {
            root.join(".env")
        } else {
            root.join("application/config/database.php")
        };
        return (Some("codeigniter3"), Some(config), root.to_path_buf());
    }
    (None, None, root.to_path_buf())
}

#[cfg(test)]
mod wordpress_url_tests {
    use super::{valid_site_url, wordpress_table_prefix};

    #[test]
    fn reads_the_table_prefix() {
        assert_eq!(
            wordpress_table_prefix("<?php\n$table_prefix = 'wpx7_';\n"),
            "wpx7_"
        );
        assert_eq!(
            wordpress_table_prefix("$table_prefix  = \"shop_\" ;"),
            "shop_"
        );
        assert_eq!(wordpress_table_prefix("$table_prefix = 'x`; DROP';"), "wp_");
        assert_eq!(wordpress_table_prefix("<?php // none"), "wp_");
    }

    #[test]
    fn only_plain_site_urls_are_used() {
        assert!(valid_site_url("https://shop.example.com"));
        assert!(!valid_site_url("shop.example.com"));
        assert!(!valid_site_url("https://x.com/'; DROP"));
    }
}

#[cfg(test)]
mod sql_sanitize_tests {
    use super::strip_privileged_statements;

    fn run(sql: &str) -> Result<String, String> {
        strip_privileged_statements(sql.as_bytes().to_vec())
            .map(|bytes| String::from_utf8(bytes).unwrap())
    }

    #[test]
    fn drops_database_scoping_from_databases_dumps() {
        let sql = "-- Current Database: `hstadmin_Katha24webDB`\n\
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `hstadmin_Katha24webDB` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;\n\
\n\
USE `hstadmin_Katha24webDB`;\n\
DROP TABLE IF EXISTS `accounts`;\n\
INSERT INTO `accounts` VALUES (1,'USE `x`; CREATE DATABASE y; DEFINER=`a`@`b`');\n";
        let out = run(sql).unwrap();
        assert!(!out.contains("CREATE DATABASE /*"));
        assert!(!out.contains("USE `hstadmin_Katha24webDB`"));
        assert!(out.contains("DROP TABLE IF EXISTS `accounts`;"));
        assert!(out.contains("'USE `x`; CREATE DATABASE y; DEFINER=`a`@`b`'"));
    }

    #[test]
    fn strips_definers_and_super_only_settings() {
        let sql = "/*!50013 DEFINER=`root`@`localhost` SQL SECURITY DEFINER */\n\
CREATE DEFINER=`cpuser`@`%` TRIGGER t BEFORE INSERT ON a FOR EACH ROW SET NEW.x=1;\n\
SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ 'abc:1-5';\n\
SET @@SESSION.SQL_LOG_BIN= 0;\n";
        let out = run(sql).unwrap();
        assert!(!out.contains("DEFINER=`"));
        assert!(out.contains("SQL SECURITY DEFINER"));
        assert!(out.contains("CREATE TRIGGER t"));
        assert!(!out.contains("GTID_PURGED"));
        assert!(!out.contains("SQL_LOG_BIN"));
    }

    #[test]
    fn refuses_dumps_with_several_databases() {
        let error = run("USE `one`;\nCREATE TABLE a (id int);\nUSE `two`;\n").unwrap_err();
        assert!(error.contains("more than one database"));
        assert!(run("USE `one`;\nUSE `one`;\n").is_ok());
    }
}
