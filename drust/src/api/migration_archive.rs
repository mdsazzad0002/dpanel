//! Account backups from other control panels: cPanel full (cpmove / Backup
//! Wizard), cPanel partial (home directory + MySQL backups), DirectAdmin and
//! Plesk. Each format is read into the same layout — a Linux account, its
//! home directory entries, its domains and their document roots, and its
//! database dumps — so inspect and restore are one code path for all of
//! them.
//!
//! Inspect only lists archives. Restore extracts just the members that
//! were selected, copies them into /home/<account> and imports the dumps
//! over the local MariaDB socket.

use crate::api::database_config::{connect_restored, referenced_database};
use crate::api::migration::{
    ROOT, detect_generic_application, remap_unsupported_collations, sql_escape,
    strip_privileged_statements,
};
use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    Router,
    extract::{Json, Path as UrlPath, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    path::{Component, Path, PathBuf},
    process::{Command, Stdio},
    sync::Arc,
    time::{SystemTime, UNIX_EPOCH},
};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/migration/{provider}/inspect", post(inspect_handle))
        .route("/api/v1/migration/{provider}/restore", post(restore_handle))
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
enum Kind {
    Cpanel,
    CpanelPartial,
    DirectAdmin,
    Plesk,
}

impl Kind {
    fn parse(value: &str) -> Option<Self> {
        match value {
            "cpanel" => Some(Self::Cpanel),
            "cpanel-partial" => Some(Self::CpanelPartial),
            "directadmin" => Some(Self::DirectAdmin),
            "plesk" => Some(Self::Plesk),
            _ => None,
        }
    }

    fn key(self) -> &'static str {
        match self {
            Self::Cpanel => "cpanel",
            Self::CpanelPartial => "cpanel-partial",
            Self::DirectAdmin => "directadmin",
            Self::Plesk => "plesk",
        }
    }

    fn label(self) -> &'static str {
        match self {
            Self::Cpanel | Self::CpanelPartial => "cPanel",
            Self::DirectAdmin => "DirectAdmin",
            Self::Plesk => "Plesk",
        }
    }
}

#[derive(Deserialize, Default)]
struct Options {
    /// cPanel partial only: a home directory backup does not name its
    /// account or domain, so the admin enters them.
    #[serde(default)]
    account: Option<String>,
    #[serde(default)]
    domain: Option<String>,
}

#[derive(Deserialize)]
struct InspectRequest {
    archive_path: String,
    #[serde(default)]
    options: Options,
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
    options: Options,
    #[serde(default)]
    selection: Selection,
    /// Database → the login the panel chose for it. Each restored database
    /// gets that user, and a restored site whose config names the database
    /// is pointed at it.
    #[serde(default)]
    credentials: BTreeMap<String, Credential>,
}

#[derive(Deserialize, Clone)]
struct Credential {
    user: String,
    password: String,
}

async fn inspect_handle(
    State(state): State<Arc<ApiState>>,
    UrlPath(provider): UrlPath<String>,
    headers: HeaderMap,
    Json(req): Json<InspectRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    let Some(kind) = Kind::parse(&provider) else {
        return ApiResponse::error("unknown migration provider").into_response();
    };
    let result = tokio::task::spawn_blocking(move || {
        read_layout(kind, &req.archive_path, &req.options).map(|layout| layout.inventory())
    })
    .await
    .unwrap_or_else(|e| Err(e.to_string()));
    match result {
        Ok(v) => {
            ApiResponse::ok_data(&format!("{} backup inspected", kind.label()), v).into_response()
        }
        Err(e) => ApiResponse::error(&e).into_response(),
    }
}

async fn restore_handle(
    State(state): State<Arc<ApiState>>,
    UrlPath(provider): UrlPath<String>,
    headers: HeaderMap,
    Json(req): Json<RestoreRequest>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    let Some(kind) = Kind::parse(&provider) else {
        return ApiResponse::error("unknown migration provider").into_response();
    };
    let result = tokio::task::spawn_blocking(move || {
        let layout = read_layout(kind, &req.archive_path, &req.options)?;
        restore(&layout, &req.selection, &req.credentials)
    })
    .await
    .unwrap_or_else(|e| Err(e.to_string()));
    match result {
        Ok(v) => ApiResponse::ok_data(&format!("Selected {} resources restored", kind.label()), v)
            .into_response(),
        Err(e) => ApiResponse::error(&e).into_response(),
    }
}

/// Where a home directory entry comes from.
#[derive(Clone, Debug, PartialEq)]
enum FileSource {
    /// A file or directory inside `archive` (a tar), at `member`.
    Member { archive: PathBuf, member: String },
    /// A tar inside `archive` whose contents make up the entry (Plesk keeps
    /// each domain's files in its own nested user-data archive).
    Nested { archive: PathBuf, member: String },
}

#[derive(Clone, Debug, PartialEq)]
enum DumpSource {
    /// A .sql or .sql.gz inside `archive`.
    Member { archive: PathBuf, member: String },
    /// A tar inside `archive` holding the dump (Plesk).
    Nested { archive: PathBuf, member: String },
    /// An uploaded .sql or .sql.gz (cPanel partial).
    File(PathBuf),
}

#[derive(Debug)]
struct Layout {
    kind: Kind,
    account: String,
    /// Home-relative path (the file id) → source.
    files: BTreeMap<String, FileSource>,
    /// Domain → document root, relative to the account's home.
    domains: BTreeMap<String, String>,
    /// Database name → dump.
    databases: BTreeMap<String, DumpSource>,
}

impl Layout {
    fn home(&self) -> PathBuf {
        PathBuf::from(format!("/home/{}", self.account))
    }

    fn inventory(&self) -> serde_json::Value {
        let home = self.home();
        serde_json::json!({
            "provider": self.kind.key(),
            "account": self.account,
            "domains": self.domains.iter().map(|(domain, root)| serde_json::json!({
                "id": domain, "label": domain, "document_root": home.join(root),
            })).collect::<Vec<_>>(),
            "files": self.files.keys().map(|id| serde_json::json!({"id": id, "label": id})).collect::<Vec<_>>(),
            "databases": self.databases.keys().map(|id| serde_json::json!({"id": id, "label": id})).collect::<Vec<_>>(),
        })
    }

    /// The home entry that holds `domain`'s document root: the longest file
    /// id that is a path prefix of it.
    fn file_for_domain(&self, domain: &str) -> Option<&String> {
        let root = Path::new(self.domains.get(domain)?);
        self.files
            .keys()
            .filter(|id| root.starts_with(id.as_str()))
            .max_by_key(|id| id.len())
    }
}

fn read_layout(kind: Kind, archive_path: &str, options: &Options) -> Result<Layout, String> {
    let path = upload_path(archive_path)?;
    match kind {
        Kind::Cpanel => {
            let archive = tar_file(&path, &[".tar.gz", ".tgz"])?;
            cpanel_layout(&archive, &listing(&archive)?)
        }
        Kind::DirectAdmin => {
            let archive = tar_file(&path, &[".tar.gz", ".tgz", ".tar.zst", ".tar"])?;
            directadmin_layout(&archive, &listing(&archive)?)
        }
        Kind::Plesk => {
            let archive = tar_file(&path, &[".tar", ".tar.gz", ".tgz", ".tar.zst"])?;
            plesk_layout(&archive, &listing(&archive)?)
        }
        Kind::CpanelPartial => partial_layout(&path, options),
    }
}

fn upload_path(value: &str) -> Result<PathBuf, String> {
    let root = Path::new(ROOT)
        .canonicalize()
        .map_err(|_| "migration storage is unavailable")?;
    let real = Path::new(value)
        .canonicalize()
        .map_err(|_| "migration archive was not found")?;
    if !real.starts_with(&root) || real == root {
        return Err("invalid migration archive path".into());
    }
    Ok(real)
}

fn tar_file(path: &Path, extensions: &[&str]) -> Result<PathBuf, String> {
    let name = file_name(path);
    if !path.is_file() || !extensions.iter().any(|ext| name.ends_with(ext)) {
        return Err(format!(
            "upload a {} archive",
            extensions.join(", ").trim_start_matches('.')
        ));
    }
    Ok(path.to_path_buf())
}

fn file_name(path: &Path) -> String {
    path.file_name()
        .and_then(|v| v.to_str())
        .unwrap_or("")
        .to_ascii_lowercase()
}

/// Member names of a tar (gzip, zstd or plain; tar detects which), refusing
/// absolute or `..` paths, links and special files.
fn listing(path: &Path) -> Result<Vec<String>, String> {
    let out = Command::new("tar")
        .arg("-tvf")
        .arg(path)
        .output()
        .map_err(|e| e.to_string())?;
    if !out.status.success() {
        return Err(format!(
            "cannot read the backup archive: {}",
            String::from_utf8_lossy(&out.stderr).trim()
        ));
    }
    let verbose = String::from_utf8_lossy(&out.stdout);
    if verbose
        .lines()
        .any(|line| !matches!(line.as_bytes().first(), Some(b'-' | b'd')))
    {
        return Err("the archive contains links or special files, which are not restored".into());
    }
    let out = Command::new("tar")
        .arg("-tf")
        .arg(path)
        .output()
        .map_err(|e| e.to_string())?;
    let names: Vec<String> = String::from_utf8_lossy(&out.stdout)
        .lines()
        .map(|v| v.trim_start_matches("./").trim_end_matches('/').to_string())
        .filter(|v| !v.is_empty() && v != ".")
        .collect();
    if names.iter().any(|v| !safe_relative(v)) {
        return Err("unsafe path in the backup archive".into());
    }
    Ok(names)
}

fn safe_relative(value: &str) -> bool {
    let path = Path::new(value);
    !path.is_absolute()
        && path
            .components()
            .all(|c| matches!(c, Component::Normal(_) | Component::CurDir))
}

/// One member's contents. A member listed as `x` may be stored as `./x`.
fn member(archive: &Path, name: &str) -> Option<String> {
    for candidate in [name.to_string(), format!("./{name}")] {
        let out = Command::new("tar")
            .arg("-xOf")
            .arg(archive)
            .arg(&candidate)
            .stderr(Stdio::null())
            .output()
            .ok()?;
        if out.status.success() {
            return Some(String::from_utf8_lossy(&out.stdout).into_owned());
        }
    }
    None
}

fn valid_account(value: &str) -> bool {
    let mut chars = value.chars();
    matches!(chars.next(), Some(c) if c.is_ascii_lowercase() || c == '_')
        && value.len() <= 32
        && chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-')
}

fn valid_domain(value: &str) -> bool {
    value.contains('.')
        && value.len() <= 253
        && value
            .bytes()
            .all(|c| c.is_ascii_alphanumeric() || c == b'.' || c == b'-')
}

fn valid_database(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && value
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '$')
}

fn dump_name(file: &str) -> Option<&str> {
    file.strip_suffix(".sql.gz")
        .or_else(|| file.strip_suffix(".sql"))
        .filter(|v| !v.is_empty())
}

/// Document roots in cPanel userdata are absolute (/home/u/public_html,
/// /home2/u/...): keep the part below the account's home.
fn home_relative(root: &str, account: &str) -> Option<String> {
    let marker = format!("/{account}/");
    let (_, rest) = root.split_once(&marker)?;
    let rest = rest.trim_matches('/');
    (!rest.is_empty() && safe_relative(rest)).then(|| rest.to_string())
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

/// cpmove-USER/ (pkgacct, transfers) or backup-MM.DD.YYYY_HH-MM-SS_USER/
/// (Backup Wizard full backup) → (top directory, account).
fn cpanel_package(names: &[String]) -> Result<(String, String), String> {
    let top = names
        .iter()
        .filter_map(|v| v.split('/').next())
        .find(|v| v.starts_with("cpmove-") || v.starts_with("backup-"))
        .ok_or("this is not a cPanel full backup: the cpmove-USER or backup-..._USER directory is missing")?;
    let account = if let Some(user) = top.strip_prefix("cpmove-") {
        user.to_string()
    } else {
        top.rsplit('_').next().unwrap_or("").to_string()
    };
    if !valid_account(&account) {
        return Err(format!("unsupported cPanel account name \"{account}\""));
    }
    Ok((top.to_string(), account))
}

fn cpanel_layout(archive: &Path, names: &[String]) -> Result<Layout, String> {
    let (top, account) = cpanel_package(names)?;
    let mut domains = BTreeMap::new();
    for name in names.iter().filter(|n| {
        n.starts_with(&format!("{top}/userdata/"))
            && !n.ends_with("/main")
            && !n.ends_with(".json")
            && !n.ends_with(".yaml")
            && !n.ends_with("cache")
            && !n.contains("_SSL")
    }) {
        if let Some(raw) = member(archive, name) {
            if let Some(domain) = yaml_value(&raw, &["servername", "server_name"]) {
                let root = yaml_value(&raw, &["documentroot", "document_root"])
                    .and_then(|r| home_relative(&r, &account))
                    .unwrap_or_else(|| "public_html".into());
                if valid_domain(&domain) {
                    domains.insert(domain.to_ascii_lowercase(), root);
                }
            }
        }
    }
    if domains.is_empty() {
        if let Some(domain) = member(archive, &format!("{top}/cp/{account}"))
            .and_then(|raw| {
                raw.lines()
                    .find_map(|l| l.strip_prefix("DNS=").map(str::to_string))
            })
            .filter(|d| valid_domain(d))
        {
            domains.insert(domain.to_ascii_lowercase(), "public_html".into());
        }
    }
    let home = format!("{top}/homedir/");
    let files = names
        .iter()
        .filter_map(|n| n.strip_prefix(&home))
        .filter_map(|r| r.split('/').next())
        .filter(|v| !v.is_empty())
        .map(|id| {
            (
                id.to_string(),
                FileSource::Member {
                    archive: archive.to_path_buf(),
                    member: format!("{home}{id}"),
                },
            )
        })
        .collect();
    let databases = names
        .iter()
        .filter_map(|n| {
            let file = n.strip_prefix(&format!("{top}/mysql/"))?;
            (!file.contains('/')).then_some((file, n))
        })
        .filter_map(|(file, n)| Some((dump_name(file)?.to_string(), n)))
        .map(|(db, n)| {
            (
                db,
                DumpSource::Member {
                    archive: archive.to_path_buf(),
                    member: n.clone(),
                },
            )
        })
        .collect();
    Ok(Layout {
        kind: Kind::Cpanel,
        account,
        files,
        domains,
        databases,
    })
}

/// DirectAdmin user backup (user.RESELLER.USER.tar.gz or .tar.zst):
/// backup/user.conf, backup/DOMAIN/domain.conf, backup/DB.sql and the
/// home directory's domains/DOMAIN/public_html.
fn directadmin_layout(archive: &Path, names: &[String]) -> Result<Layout, String> {
    if !names.iter().any(|n| n == "backup/user.conf") {
        return Err("this is not a DirectAdmin user backup: backup/user.conf is missing".into());
    }
    let account = member(archive, "backup/user.conf")
        .and_then(|raw| {
            raw.lines()
                .find_map(|l| l.trim().strip_prefix("username=").map(str::to_string))
        })
        .or_else(|| {
            // user.RESELLER.USER.tar.gz
            file_name(archive).split('.').nth(2).map(str::to_string)
        })
        .unwrap_or_default();
    if !valid_account(&account) {
        return Err(format!("unsupported DirectAdmin user name \"{account}\""));
    }
    let mut domains = BTreeMap::new();
    for name in names {
        if let Some(domain) = name
            .strip_prefix("backup/")
            .and_then(|r| r.strip_suffix("/domain.conf"))
            .filter(|d| !d.contains('/') && valid_domain(d))
        {
            let domain = domain.to_ascii_lowercase();
            domains.insert(domain.clone(), format!("domains/{domain}/public_html"));
        }
    }
    let mut files = BTreeMap::new();
    for name in names {
        let mut parts = name.split('/');
        let top = parts.next().unwrap_or("");
        if top.is_empty() || top == "backup" {
            continue;
        }
        // One entry per domain directory, so a domain restores on its own.
        let id = match (top, parts.next()) {
            ("domains", Some(domain)) => format!("domains/{domain}"),
            ("domains", None) => continue,
            _ => top.to_string(),
        };
        files
            .entry(id.clone())
            .or_insert_with(|| FileSource::Member {
                archive: archive.to_path_buf(),
                member: id,
            });
    }
    let databases = names
        .iter()
        .filter_map(|n| {
            let file = n.strip_prefix("backup/")?;
            (!file.contains('/')).then_some((dump_name(file)?, n))
        })
        .map(|(db, n)| {
            (
                db.to_string(),
                DumpSource::Member {
                    archive: archive.to_path_buf(),
                    member: n.clone(),
                },
            )
        })
        .collect();
    Ok(Layout {
        kind: Kind::DirectAdmin,
        account,
        files,
        domains,
        databases,
    })
}

/// Plesk backup exported as one file. Each subscription lives under
/// [resellers/R/][clients/C/]domains/DOMAIN/ with its files in a nested
/// backup_user-data_* archive and each database in
/// databases/NAME_ID/backup_sqldump_*. Files go to
/// /home/ACCOUNT/domains/DOMAIN with the site in httpdocs.
fn plesk_layout(archive: &Path, names: &[String]) -> Result<Layout, String> {
    let domain_of = |name: &str| -> Option<String> {
        let parts: Vec<&str> = name.split('/').collect();
        let at = parts.iter().rposition(|p| *p == "domains")?;
        parts
            .get(at + 1)
            .filter(|d| valid_domain(d) && parts.len() > at + 2)
            .map(|d| d.to_ascii_lowercase())
    };
    let mut files = BTreeMap::new();
    let mut domains = BTreeMap::new();
    let mut databases = BTreeMap::new();
    for name in names {
        let Some(domain) = domain_of(name) else {
            continue;
        };
        let base = name.rsplit('/').next().unwrap_or("");
        let in_databases = name.contains(&format!("{domain}/databases/"));
        if !in_databases && base.contains("user-data") {
            let id = format!("domains/{domain}");
            domains.insert(domain.clone(), format!("{id}/httpdocs"));
            files.insert(
                id,
                FileSource::Nested {
                    archive: archive.to_path_buf(),
                    member: name.clone(),
                },
            );
        } else if in_databases && base.contains("sqldump") {
            // databases/wpdb_3/backup_sqldump_2401011200.tzst → wpdb
            let dir = name.rsplit('/').nth(1).unwrap_or("");
            let db = match dir.rsplit_once('_') {
                Some((head, id)) if !head.is_empty() && id.bytes().all(|c| c.is_ascii_digit()) => {
                    head
                }
                _ => dir,
            };
            if valid_database(db) {
                databases.insert(
                    db.to_string(),
                    DumpSource::Nested {
                        archive: archive.to_path_buf(),
                        member: name.clone(),
                    },
                );
            }
        }
    }
    if files.is_empty() && databases.is_empty() {
        return Err("no Plesk domains were found in this archive. Upload the backup file Plesk exported (Backup Manager → Download), not an encrypted or multi-volume one".into());
    }
    let account = names
        .iter()
        .filter(|n| n.ends_with(".xml") && n.matches('/').count() <= 4)
        .take(20)
        .find_map(|n| member(archive, n).and_then(|raw| plesk_sysuser(&raw)))
        .or_else(|| domains.keys().next().map(|d| account_from_domain(d)))
        .unwrap_or_default();
    if !valid_account(&account) {
        return Err(format!("unsupported Plesk system user \"{account}\""));
    }
    Ok(Layout {
        kind: Kind::Plesk,
        account,
        files,
        domains,
        databases,
    })
}

fn plesk_sysuser(xml: &str) -> Option<String> {
    let at = xml.find("<sysuser")?;
    let tag = &xml[at..at + xml[at..].find('>')?];
    let start = tag.find("name=\"")? + 6;
    let name = &tag[start..start + tag[start..].find('"')?];
    valid_account(name).then(|| name.to_string())
}

fn account_from_domain(domain: &str) -> String {
    let label: String = domain
        .split('.')
        .next()
        .unwrap_or("")
        .to_ascii_lowercase()
        .chars()
        .filter(|c| c.is_ascii_alphanumeric())
        .take(16)
        .collect();
    if label.starts_with(|c: char| c.is_ascii_digit()) {
        format!("u{label}")
    } else {
        label
    }
}

/// cPanel partial: the upload directory holds an optional home directory
/// backup (backup-DOMAIN-DATE.tar.gz) and any number of MySQL backups
/// (DB.sql.gz). Account and main domain come from the admin.
fn partial_layout(dir: &Path, options: &Options) -> Result<Layout, String> {
    if !dir.is_dir() {
        return Err("invalid cPanel partial backup upload".into());
    }
    let account = options.account.as_deref().unwrap_or("").trim().to_string();
    if !valid_account(&account) {
        return Err(
            "enter the account's system user name (lowercase letters, digits, - and _)".into(),
        );
    }
    let domain = options
        .domain
        .as_deref()
        .unwrap_or("")
        .trim()
        .to_ascii_lowercase();
    if !domain.is_empty() && !valid_domain(&domain) {
        return Err("invalid main domain".into());
    }
    let mut entries: Vec<PathBuf> = fs::read_dir(dir)
        .map_err(|e| e.to_string())?
        .filter_map(Result::ok)
        .map(|e| e.path())
        .filter(|p| p.is_file())
        .collect();
    entries.sort();
    let mut files = BTreeMap::new();
    let mut databases = BTreeMap::new();
    for path in entries {
        let name = file_name(&path);
        if let Some(db) = dump_name(&name) {
            if !valid_database(db) {
                return Err(format!("unsupported database name in {name}"));
            }
            databases.insert(db.to_string(), DumpSource::File(path.clone()));
        } else if name.ends_with(".tar.gz") || name.ends_with(".tgz") {
            let names = listing(&path)?;
            let prefix = home_prefix(&names);
            for id in names
                .iter()
                .filter_map(|n| n.strip_prefix(&prefix))
                .filter_map(|r| r.split('/').next())
                .filter(|v| !v.is_empty())
            {
                files.insert(
                    id.to_string(),
                    FileSource::Member {
                        archive: path.clone(),
                        member: format!("{prefix}{id}"),
                    },
                );
            }
        }
    }
    if files.is_empty() && databases.is_empty() {
        return Err("upload a home directory backup (.tar.gz) or a MySQL backup (.sql.gz)".into());
    }
    let mut domains = BTreeMap::new();
    if !domain.is_empty() && files.contains_key("public_html") {
        domains.insert(domain, "public_html".to_string());
    }
    Ok(Layout {
        kind: Kind::CpanelPartial,
        account,
        files,
        domains,
        databases,
    })
}

/// A home directory backup holds the home's entries at the top, or under
/// one wrapping directory (homedir/, backup-.../, backup-.../homedir/).
fn home_prefix(names: &[String]) -> String {
    let tops: BTreeSet<&str> = names.iter().filter_map(|n| n.split('/').next()).collect();
    if tops.len() != 1 {
        return String::new();
    }
    let top = *tops.iter().next().unwrap();
    if top == "homedir" {
        return "homedir/".into();
    }
    if !top.starts_with("backup-") {
        return String::new();
    }
    if names
        .iter()
        .any(|n| n.starts_with(&format!("{top}/homedir/")))
    {
        format!("{top}/homedir/")
    } else {
        format!("{top}/")
    }
}

fn restore(
    layout: &Layout,
    selection: &Selection,
    credentials: &BTreeMap<String, Credential>,
) -> Result<serde_json::Value, String> {
    if let Some((db, _)) = credentials.iter().find(|(_, c)| {
        c.user.len() > 32
            || !valid_database(&c.user)
            || c.user.contains('$')
            || c.password.is_empty()
    }) {
        return Err(format!("invalid database login for {db}"));
    }
    if !selection.full_account
        && selection.domains.is_empty()
        && selection.files.is_empty()
        && selection.databases.is_empty()
    {
        return Err("select at least one domain, file, or database".into());
    }
    if let Some(v) = selection
        .domains
        .iter()
        .find(|v| !layout.domains.contains_key(*v))
    {
        return Err(format!("unknown domain selection: {v}"));
    }
    if let Some(v) = selection
        .files
        .iter()
        .find(|v| !layout.files.contains_key(*v))
    {
        return Err(format!("unknown file selection: {v}"));
    }
    if let Some(v) = selection
        .databases
        .iter()
        .find(|v| !layout.databases.contains_key(*v))
    {
        return Err(format!("unknown database selection: {v}"));
    }
    if let Some(v) = layout.databases.keys().find(|v| !valid_database(v)) {
        return Err(format!("unsafe database name in the backup: {v}"));
    }

    let (domains, files, databases): (Vec<String>, BTreeSet<String>, Vec<String>) =
        if selection.full_account {
            (
                layout.domains.keys().cloned().collect(),
                layout.files.keys().cloned().collect(),
                layout.databases.keys().cloned().collect(),
            )
        } else {
            let mut files: BTreeSet<String> = selection.files.iter().cloned().collect();
            for domain in &selection.domains {
                if let Some(id) = layout.file_for_domain(domain) {
                    files.insert(id.clone());
                }
            }
            (
                selection.domains.clone(),
                files,
                selection.databases.clone(),
            )
        };

    let nonce = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| e.to_string())?
        .as_nanos();
    let stage = PathBuf::from(format!("{ROOT}/.restore-{}-{nonce}", std::process::id()));
    fs::create_dir(&stage).map_err(|e| e.to_string())?;
    let result = restore_into(layout, &stage, &files, &databases, credentials);
    fs::remove_dir_all(&stage).ok();
    let restored_db = result?;
    let (connected, warnings) = connect_sites(layout, &domains, &restored_db, credentials);
    let home = layout.home();
    let _ = Command::new("chown")
        .args(["-R", &format!("{0}:{0}", layout.account)])
        .arg(&home)
        .status();

    Ok(serde_json::json!({
        "account": layout.account,
        "domains": domains,
        "files": files,
        "databases": restored_db,
        "connected": connected,
        "warnings": warnings,
    }))
}

fn restore_into(
    layout: &Layout,
    stage: &Path,
    files: &BTreeSet<String>,
    databases: &[String],
    credentials: &BTreeMap<String, Credential>,
) -> Result<Vec<String>, String> {
    let home = layout.home();
    ensure_account(&layout.account, &home)?;

    for (index, id) in files.iter().enumerate() {
        let work = stage.join(format!("f{index}"));
        fs::create_dir(&work).map_err(|e| e.to_string())?;
        let from = match &layout.files[id] {
            FileSource::Member { archive, member } => {
                extract(archive, member, &work)?;
                work.join(member)
            }
            FileSource::Nested { archive, member } => {
                extract(archive, member, &work)?;
                let content = work.join("__content");
                fs::create_dir(&content).map_err(|e| e.to_string())?;
                let nested = work.join(member);
                listing(&nested)?;
                untar(&nested, &content)?;
                content
            }
        };
        copy_into(&from, &home.join(id))?;
        fs::remove_dir_all(&work).ok();
    }

    let mut restored = Vec::new();
    for (index, db) in databases.iter().enumerate() {
        let work = stage.join(format!("d{index}"));
        fs::create_dir(&work).map_err(|e| e.to_string())?;
        let dump = match &layout.databases[db] {
            DumpSource::Member { archive, member } => {
                extract(archive, member, &work)?;
                plain_sql(&work.join(member), &work)?
            }
            DumpSource::Nested { archive, member } => {
                extract(archive, member, &work)?;
                let nested = work.join(member);
                let content = work.join("__content");
                fs::create_dir(&content).map_err(|e| e.to_string())?;
                listing(&nested)?;
                untar(&nested, &content)?;
                let file = largest_file(&content).ok_or(format!("the dump for {db} is empty"))?;
                plain_sql(&file, &work)?
            }
            DumpSource::File(path) => plain_sql(path, &work)?,
        };
        import_database(db, &dump)?;
        fs::remove_dir_all(&work).ok();
        if let Some(login) = credentials.get(db) {
            grant(db, login)?;
        }
        restored.push(db.clone());
    }

    let _ = Command::new("chown")
        .args(["-R", &format!("{0}:{0}", layout.account)])
        .arg(&home)
        .status();
    crate::filemanager::fix_permissions::run(Some(&layout.account), home.to_str(), false)?;
    Ok(restored)
}

/// Create (or re-password) the database's login with access to it alone.
fn grant(db: &str, login: &Credential) -> Result<(), String> {
    let (user, password) = (&login.user, sql_escape(&login.password));
    let statement = format!(
        "CREATE USER IF NOT EXISTS '{user}'@'localhost' IDENTIFIED BY '{password}'; \
         ALTER USER '{user}'@'localhost' IDENTIFIED BY '{password}'; \
         GRANT ALL PRIVILEGES ON `{db}`.* TO '{user}'@'localhost'; FLUSH PRIVILEGES;"
    );
    let out = Command::new("mysql")
        .args(["--protocol=socket", "--execute", &statement])
        .output()
        .map_err(|e| e.to_string())?;
    if !out.status.success() {
        return Err(format!(
            "cannot create the database user {user}: {}",
            String::from_utf8_lossy(&out.stderr).trim()
        ));
    }
    Ok(())
}

/// For each restored domain whose site is WordPress, Laravel or
/// CodeIgniter, rewrite its config to the restored database's new login.
/// The database is the one the config already names, or the only one
/// restored. Problems are reported, not fatal: files and data are in place.
fn connect_sites(
    layout: &Layout,
    domains: &[String],
    restored: &[String],
    credentials: &BTreeMap<String, Credential>,
) -> (Vec<serde_json::Value>, Vec<String>) {
    let (mut connected, mut warnings) = (Vec::new(), Vec::new());
    let home = layout.home();
    for domain in domains {
        let Some(root) = layout.domains.get(domain) else {
            continue;
        };
        let docroot = home.join(root);
        let candidates = [Some(docroot.as_path()), docroot.parent()];
        let Some((framework, config)) = candidates.into_iter().flatten().find_map(|dir| {
            match detect_generic_application(dir) {
                (Some(framework), Some(config), _) if config.is_file() => Some((framework, config)),
                _ => None,
            }
        }) else {
            continue;
        };
        let named = fs::read_to_string(&config)
            .ok()
            .and_then(|text| referenced_database(framework, &text));
        let database = named
            .clone()
            .filter(|db| restored.contains(db))
            .or_else(|| (restored.len() == 1).then(|| restored[0].clone()));
        let Some(database) = database else {
            if named.is_some() || !restored.is_empty() {
                warnings.push(format!(
                    "{domain}: {framework} site uses database {}, which was not restored; connect it on the Databases page",
                    named.unwrap_or_else(|| "(unknown)".into())
                ));
            }
            continue;
        };
        let Some(login) = credentials.get(&database) else {
            continue;
        };
        match connect_restored(
            &layout.account,
            framework,
            &config,
            &database,
            &login.user,
            &login.password,
        ) {
            Ok(_) => connected.push(serde_json::json!({
                "domain": domain, "database": database, "framework": framework, "config": config,
            })),
            Err(e) => warnings.push(format!(
                "{domain}: could not update {}: {e}",
                config.display()
            )),
        }
    }
    (connected, warnings)
}

fn ensure_account(account: &str, home: &Path) -> Result<(), String> {
    if home.exists() {
        return Ok(());
    }
    let _ = Command::new("groupadd").args(["--force", account]).status();
    let created = Command::new("useradd")
        .args([
            "--create-home",
            "--gid",
            account,
            "--shell",
            "/usr/sbin/nologin",
            account,
        ])
        .status()
        .map_err(|e| e.to_string())?;
    if !created.success() {
        return Err(format!("cannot create the system user {account}"));
    }
    Ok(())
}

fn extract(archive: &Path, member: &str, into: &Path) -> Result<(), String> {
    let out = Command::new("tar")
        .arg("-xf")
        .arg(archive)
        .arg("-C")
        .arg(into)
        .args(["--no-same-owner", "--no-same-permissions", "--"])
        .arg(member)
        .output()
        .map_err(|e| e.to_string())?;
    if out.status.success() {
        return Ok(());
    }
    // Some panels store members as ./name.
    let retry = Command::new("tar")
        .arg("-xf")
        .arg(archive)
        .arg("-C")
        .arg(into)
        .args(["--no-same-owner", "--no-same-permissions", "--"])
        .arg(format!("./{member}"))
        .output()
        .map_err(|e| e.to_string())?;
    if retry.status.success() {
        return Ok(());
    }
    Err(format!(
        "cannot extract {member}: {}",
        String::from_utf8_lossy(&out.stderr).trim()
    ))
}

fn untar(archive: &Path, into: &Path) -> Result<(), String> {
    let out = Command::new("tar")
        .arg("-xf")
        .arg(archive)
        .arg("-C")
        .arg(into)
        .args(["--no-same-owner", "--no-same-permissions"])
        .output()
        .map_err(|e| e.to_string())?;
    if !out.status.success() {
        return Err(format!(
            "cannot extract {}: {}",
            file_name(archive),
            String::from_utf8_lossy(&out.stderr).trim()
        ));
    }
    Ok(())
}

/// Copy `from` (a file or directory) to `to`, merging into an existing
/// directory instead of nesting inside it.
fn copy_into(from: &Path, to: &Path) -> Result<(), String> {
    if !from.exists() {
        return Ok(());
    }
    if let Some(parent) = to.parent() {
        fs::create_dir_all(parent).map_err(|e| e.to_string())?;
    }
    let source = if from.is_dir() {
        fs::create_dir_all(to).map_err(|e| e.to_string())?;
        from.join(".")
    } else {
        from.to_path_buf()
    };
    let out = Command::new("cp")
        .arg("-a")
        .arg(&source)
        .arg(to)
        .output()
        .map_err(|e| e.to_string())?;
    if !out.status.success() {
        return Err(String::from_utf8_lossy(&out.stderr).trim().to_string());
    }
    Ok(())
}

fn largest_file(dir: &Path) -> Option<PathBuf> {
    let mut best: Option<(u64, PathBuf)> = None;
    let mut stack = vec![dir.to_path_buf()];
    while let Some(current) = stack.pop() {
        for entry in fs::read_dir(&current).ok()?.filter_map(Result::ok) {
            let path = entry.path();
            let Ok(meta) = fs::symlink_metadata(&path) else {
                continue;
            };
            if meta.is_dir() {
                stack.push(path);
            } else if meta.is_file() && best.as_ref().is_none_or(|(size, _)| meta.len() > *size) {
                best = Some((meta.len(), path));
            }
        }
    }
    best.map(|(_, path)| path)
}

/// A plain .sql path for `dump`, gunzipping into `work` when it is
/// compressed (checked by magic bytes, so the name does not matter).
fn plain_sql(dump: &Path, work: &Path) -> Result<PathBuf, String> {
    let mut magic = [0u8; 4];
    let read = {
        use std::io::Read;
        fs::File::open(dump)
            .and_then(|mut f| f.read(&mut magic))
            .map_err(|e| e.to_string())?
    };
    let tool = match &magic[..read] {
        [0x1f, 0x8b, ..] => "gzip",
        [0x28, 0xb5, 0x2f, 0xfd] => "zstd",
        _ => return Ok(dump.to_path_buf()),
    };
    let target = work.join("dump.sql");
    let output = fs::File::create(&target).map_err(|e| e.to_string())?;
    let status = Command::new(tool)
        .arg("-dc")
        .arg(dump)
        .stdout(output)
        .status()
        .map_err(|e| format!("{tool}: {e}"))?;
    if !status.success() {
        return Err(format!("cannot decompress {}", file_name(dump)));
    }
    Ok(target)
}

fn import_database(db: &str, dump: &Path) -> Result<(), String> {
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
    let bytes = fs::read(dump).map_err(|e| e.to_string())?;
    let bytes = strip_privileged_statements(remap_unsupported_collations(bytes))?;
    let mut child = Command::new("mysql")
        .args(["--protocol=socket", db])
        .stdin(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| e.to_string())?;
    let written = {
        use std::io::Write;
        child
            .stdin
            .take()
            .ok_or("mysql stdin unavailable")?
            .write_all(&bytes)
    };
    let out = child.wait_with_output().map_err(|e| e.to_string())?;
    if !out.status.success() || written.is_err() {
        return Err(format!(
            "database restore failed for {db}: {}",
            String::from_utf8_lossy(&out.stderr).trim()
        ));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    fn names(list: &[&str]) -> Vec<String> {
        list.iter().map(|s| s.to_string()).collect()
    }

    #[test]
    fn provider_routes_do_not_clash_with_the_fixed_migration_routes() {
        // axum panics on overlapping routes when they are merged.
        let _ = crate::api::migration::routes()
            .merge(crate::api::migration_ssh::routes())
            .merge(routes());
    }

    #[test]
    fn cpanel_package_reads_both_full_backup_names() {
        assert_eq!(
            cpanel_package(&names(&["cpmove-bob/homedir/public_html"])).unwrap(),
            ("cpmove-bob".into(), "bob".into())
        );
        assert_eq!(
            cpanel_package(&names(&[
                "backup-1.2.2024_10-11-12_alice/mysql/alice_wp.sql"
            ]))
            .unwrap(),
            ("backup-1.2.2024_10-11-12_alice".into(), "alice".into())
        );
        assert!(cpanel_package(&names(&["public_html/index.php"])).is_err());
    }

    #[test]
    fn document_roots_become_home_relative() {
        assert_eq!(
            home_relative("/home/bob/public_html", "bob").as_deref(),
            Some("public_html")
        );
        assert_eq!(
            home_relative("/home2/bob/sites/shop/", "bob").as_deref(),
            Some("sites/shop")
        );
        assert_eq!(home_relative("/var/www/html", "bob"), None);
        assert_eq!(home_relative("/home/bob/../etc", "bob"), None);
    }

    #[test]
    fn directadmin_layout_lists_domains_files_and_dumps() {
        let archive = Path::new("/nonexistent/user.admin.bob.tar.gz");
        let layout = directadmin_layout(
            archive,
            &names(&[
                "backup/user.conf",
                "backup/example.com/domain.conf",
                "backup/shop.example.net/domain.conf",
                "backup/bob_wp.sql",
                "backup/bob_wp.conf",
                "domains/example.com/public_html/index.php",
                "domains/shop.example.net/public_html/index.php",
                "imap/example.com/info/Maildir/cur",
            ]),
        )
        .unwrap();
        // user.conf is unreadable here, so the file name supplies the user.
        assert_eq!(layout.account, "bob");
        assert_eq!(
            layout.domains["example.com"],
            "domains/example.com/public_html"
        );
        assert_eq!(
            layout.files.keys().collect::<Vec<_>>(),
            ["domains/example.com", "domains/shop.example.net", "imap"]
        );
        assert_eq!(layout.databases.keys().collect::<Vec<_>>(), ["bob_wp"]);
        assert_eq!(
            layout.file_for_domain("shop.example.net").unwrap(),
            "domains/shop.example.net"
        );
    }

    #[test]
    fn plesk_layout_finds_nested_user_data_and_dumps() {
        let archive = Path::new("/nonexistent/backup.tar");
        let layout = plesk_layout(
            archive,
            &names(&[
                "backup_info_2401011200.xml",
                "clients/jdoe/domains/example.com/backup_user-data_2401011200.tzst",
                "clients/jdoe/domains/example.com/databases/wpdb_3/backup_sqldump_2401011200.tzst",
                "domains/admin-site.org/backup_user-data_2401011200.tgz",
            ]),
        )
        .unwrap();
        assert_eq!(layout.account, "adminsite");
        assert_eq!(
            layout.domains["example.com"],
            "domains/example.com/httpdocs"
        );
        assert_eq!(
            layout.domains["admin-site.org"],
            "domains/admin-site.org/httpdocs"
        );
        assert!(matches!(
            layout.files["domains/example.com"],
            FileSource::Nested { .. }
        ));
        assert_eq!(layout.databases.keys().collect::<Vec<_>>(), ["wpdb"]);
        assert!(plesk_layout(archive, &names(&["random/file.txt"])).is_err());
    }

    #[test]
    fn plesk_sysuser_is_read_from_xml() {
        assert_eq!(
            plesk_sysuser(r#"<phosting><sysuser name="jdoe_ftp" quota="-1"><password/></sysuser>"#)
                .as_deref(),
            Some("jdoe_ftp")
        );
        assert_eq!(plesk_sysuser(r#"<sysuser name="Bad User">"#), None);
    }

    #[test]
    fn home_prefix_unwraps_one_directory() {
        assert_eq!(home_prefix(&names(&["public_html/a", "etc/b"])), "");
        assert_eq!(home_prefix(&names(&["homedir/public_html/a"])), "homedir/");
        assert_eq!(
            home_prefix(&names(&["backup-ex.com-1-2-2024/public_html/a"])),
            "backup-ex.com-1-2-2024/"
        );
        assert_eq!(home_prefix(&names(&["public_html/a"])), "");
    }

    #[test]
    fn partial_layout_combines_home_and_dumps() {
        let dir = std::env::temp_dir().join(format!("dpanel-partial-{}", std::process::id()));
        fs::create_dir_all(dir.join("src/public_html")).unwrap();
        fs::write(dir.join("src/public_html/index.php"), "<?php").unwrap();
        let upload = dir.join("upload");
        fs::create_dir_all(&upload).unwrap();
        assert!(
            Command::new("tar")
                .arg("-czf")
                .arg(upload.join("backup-example.com-1-2-2024.tar.gz"))
                .arg("-C")
                .arg(dir.join("src"))
                .arg("public_html")
                .status()
                .unwrap()
                .success()
        );
        fs::write(upload.join("bob_wp.sql.gz"), "x").unwrap();
        let options = Options {
            account: Some("bob".into()),
            domain: Some("Example.com".into()),
        };
        let layout = partial_layout(&upload, &options).unwrap();
        assert_eq!(layout.files.keys().collect::<Vec<_>>(), ["public_html"]);
        assert_eq!(layout.domains["example.com"], "public_html");
        assert_eq!(layout.databases.keys().collect::<Vec<_>>(), ["bob_wp"]);
        assert!(
            partial_layout(
                &upload,
                &Options {
                    account: Some("Bad".into()),
                    domain: None
                }
            )
            .is_err()
        );
        fs::remove_dir_all(&dir).ok();
    }

    #[test]
    fn nested_archives_extract_and_merge_into_existing_directories() {
        let dir = std::env::temp_dir().join(format!("dpanel-nested-{}", std::process::id()));
        let tar = |args: &[&str], cwd: &Path| {
            assert!(
                Command::new("tar")
                    .args(args)
                    .current_dir(cwd)
                    .status()
                    .unwrap()
                    .success()
            );
        };
        fs::create_dir_all(dir.join("site/httpdocs")).unwrap();
        fs::write(dir.join("site/httpdocs/index.php"), "new").unwrap();
        fs::create_dir_all(dir.join("outer/domains/example.com")).unwrap();
        tar(
            &[
                "-czf",
                "../outer/domains/example.com/backup_user-data_1.tgz",
                "httpdocs",
            ],
            &dir.join("site"),
        );
        tar(&["-cf", "../backup.tar", "domains"], &dir.join("outer"));
        let archive = dir.join("backup.tar");
        let layout = plesk_layout(&archive, &listing(&archive).unwrap()).unwrap();
        let FileSource::Nested { member, .. } = &layout.files["domains/example.com"] else {
            panic!("expected a nested source");
        };

        let work = dir.join("work");
        fs::create_dir_all(work.join("__content")).unwrap();
        extract(&archive, member, &work).unwrap();
        untar(&work.join(member), &work.join("__content")).unwrap();

        let home = dir.join("home/domains/example.com");
        fs::create_dir_all(home.join("httpdocs")).unwrap();
        fs::write(home.join("httpdocs/keep.txt"), "old").unwrap();
        copy_into(&work.join("__content"), &home).unwrap();
        assert_eq!(
            fs::read_to_string(home.join("httpdocs/index.php")).unwrap(),
            "new"
        );
        assert!(home.join("httpdocs/keep.txt").is_file());
        assert!(!home.join("httpdocs/httpdocs").exists());
        fs::remove_dir_all(&dir).ok();
    }
}
