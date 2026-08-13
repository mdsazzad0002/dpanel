use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;
use serde_json::{Value, json};
use std::{
    fs::{self, File, OpenOptions},
    os::unix::fs::OpenOptionsExt,
    path::{Path, PathBuf},
    process::{Command, Stdio},
    sync::Arc,
    time::{SystemTime, UNIX_EPOCH},
};

const MIGRATION_ROOT: &str = "/var/www/dpanel/storage/app/migrations";

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route(
            "/api/v1/migration/cyberpanel-ssh/discover",
            post(discover_handle),
        )
        .route(
            "/api/v1/migration/cyberpanel-ssh/transfer",
            post(transfer_handle),
        )
}

#[derive(Clone, Deserialize)]
struct Credentials {
    transport_id: String,
    host: String,
    port: u16,
    username: String,
    auth_type: String,
    #[serde(default)]
    password: String,
    #[serde(default)]
    private_key: String,
    #[serde(default)]
    key_passphrase: String,
}

#[derive(Deserialize)]
struct TransferRequest {
    #[serde(flatten)]
    credentials: Credentials,
    #[serde(rename = "type")]
    kind: String,
    source_path: Option<String>,
    database: Option<String>,
    destination: String,
}

async fn discover_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(credentials): Json<Credentials>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(move || discover(&credentials)).await {
        Ok(Ok(data)) => ApiResponse::ok_data("CyberPanel server inspected", data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("SSH worker failed: {error}")).into_response(),
    }
}

async fn transfer_handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<TransferRequest>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(move || transfer(&request)).await {
        Ok(Ok(data)) => ApiResponse::ok_data("Remote source transferred", data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("SSH worker failed: {error}")).into_response(),
    }
}

fn discover(credentials: &Credentials) -> Result<Value, String> {
    validate_credentials(credentials)?;
    let _session = ControlSession(credentials.clone());
    let elevate = needs_elevation(credentials)?;
    match detect_panel(credentials, elevate)?.as_str() {
        "cyberpanel" => discover_cyberpanel(credentials, elevate),
        "hestia" => discover_hestia(credentials, elevate),
        "aapanel" => discover_aapanel(credentials, elevate),
        "plesk" => discover_plesk(credentials, elevate),
        _ => Err("Neither CyberPanel, HestiaCP, aaPanel, nor Plesk was detected on the remote server.".into()),
    }
}

/// Single cheap remote probe that tells `discover()` which panel-specific
/// code path to run — everything past this point (SSH plumbing, elevation,
/// transfer) is identical for every panel; only inventory discovery differs.
fn detect_panel(credentials: &Credentials, elevate: bool) -> Result<String, String> {
    let output = run(
        credentials,
        "if command -v cyberpanel >/dev/null 2>&1 || [ -d /usr/local/CyberCP ]; then printf cyberpanel; elif command -v v-list-users >/dev/null 2>&1 || [ -x /usr/local/hestia/bin/v-list-users ]; then printf hestia; elif [ -d /www/server/panel ] || command -v bt >/dev/null 2>&1; then printf aapanel; elif command -v plesk >/dev/null 2>&1 || [ -d /usr/local/psa ]; then printf plesk; else printf unknown; fi",
        elevate,
    )?;
    Ok(output.trim().to_string())
}

fn remote_databases(credentials: &Credentials, elevate: bool, exclude: &[&str]) -> Result<Vec<String>, String> {
    let mut databases: Vec<String> = run(credentials, "mysql --batch --skip-column-names -e 'SHOW DATABASES' 2>/dev/null || mariadb --batch --skip-column-names -e 'SHOW DATABASES' 2>/dev/null || true", elevate)?
        .lines()
        .map(str::trim)
        .filter(|name| valid_database(name) && !exclude.contains(&name.to_ascii_lowercase().as_str()))
        .map(str::to_string)
        .collect();
    databases.sort();
    databases.dedup();
    Ok(databases)
}

/// Site-root prefixes across every panel this endpoint understands: CyberPanel
/// and HestiaCP both use /home, aaPanel uses /www/wwwroot, Plesk uses
/// /var/www/vhosts. Shared by directory discovery and the transfer path
/// guard so the two never drift apart.
const ALLOWED_SOURCE_ROOTS: [&str; 3] = ["/home/", "/www/wwwroot/", "/var/www/vhosts/"];

fn remote_directories(credentials: &Credentials, elevate: bool) -> Result<Vec<String>, String> {
    // Roots that don't exist on a given host are silently skipped by find
    // (stderr discarded) while it still lists whichever ones do exist.
    let mut directories: Vec<String> = run(credentials, "find /home /www/wwwroot /var/www/vhosts -mindepth 1 -maxdepth 4 -type d -not -path '*/.*' -printf '%p\\n' 2>/dev/null | sort | head -n 5000", elevate)?
        .lines()
        .map(|path| path.trim().trim_end_matches('/').to_string())
        .filter(|path| ALLOWED_SOURCE_ROOTS.iter().any(|root| path.starts_with(root)))
        .collect();
    directories.sort();
    directories.dedup();
    Ok(directories)
}

fn remote_hostname(credentials: &Credentials, elevate: bool) -> Result<String, String> {
    Ok(run(credentials, "hostname -f 2>/dev/null || hostname", elevate)?.trim().to_string())
}

fn discover_cyberpanel(credentials: &Credentials, elevate: bool) -> Result<Value, String> {
    let version = run(
        credentials,
        "cyberpanel --version 2>/dev/null || test -d /usr/local/CyberCP && printf CyberPanel",
        elevate,
    )?;
    if !version.to_ascii_lowercase().contains("cyberpanel")
        && !version.chars().any(|value| value.is_ascii_digit())
    {
        return Err("CyberPanel was not detected on the remote server.".into());
    }
    let website_output = run(
        credentials,
        "cyberpanel listWebsitesJson 2>/dev/null || true",
        elevate,
    )?;
    let payload = decode_json(&website_output).unwrap_or(Value::Array(vec![]));
    let website_rows = rows(&payload, &["data", "websites"]);
    let mut websites = Vec::new();
    for row in website_rows {
        let domain = field(&row, &["domain", "domainName", "website"]).to_ascii_lowercase();
        if !valid_domain(&domain) {
            continue;
        }
        let database_output = run(
            credentials,
            &format!(
                "cyberpanel listDatabasesJson --domainName {} 2>/dev/null || true",
                shell_quote(&domain)
            ),
            elevate,
        )?;
        let database_payload = decode_json(&database_output).unwrap_or(Value::Array(vec![]));
        let databases: Vec<Value> = rows(&database_payload, &["data", "databases"]).into_iter().filter_map(|database| {
            let name = field(&database, &["dbName", "databaseName", "name"]);
            if name.is_empty() { None } else { Some(json!({"name": name, "user": field(&database, &["dbUser", "databaseUser", "user"])})) }
        }).collect();
        websites.push(json!({
            "domain": domain,
            "owner": field(&row, &["adminEmail", "owner", "externalApp"]),
            "php_version": field(&row, &["phpVersion", "php"]),
            "path": nonempty(field(&row, &["path"]), format!("/home/{domain}/public_html")),
            "databases": databases,
        }));
    }
    let vhosts = run(
        credentials,
        r#"for file in /usr/local/lsws/conf/vhosts/*/vhost.conf; do [ -f "$file" ] || continue; domain=$(awk '$1 == "vhDomain" { gsub(/,/, "", $2); print $2; exit }' "$file"); [ -n "$domain" ] || domain=$(basename "$(dirname "$file")"); root=$(awk '$1 == "docRoot" { print $2; exit }' "$file"); [ -n "$domain" ] && printf '__DPANEL_VHOST__\t%s\t%s\n' "$domain" "$root"; done"#,
        elevate,
    )?;
    for line in vhosts
        .lines()
        .filter(|line| line.starts_with("__DPANEL_VHOST__\t"))
    {
        let parts: Vec<&str> = line.splitn(3, '\t').collect();
        let domain = parts
            .get(1)
            .unwrap_or(&"")
            .split(',')
            .next()
            .unwrap_or("")
            .trim()
            .to_ascii_lowercase();
        if !valid_domain(&domain) || websites.iter().any(|item| item["domain"] == domain) {
            continue;
        }
        let path = parts.get(2).unwrap_or(&"").trim();
        websites.push(json!({"domain":domain,"owner":"","php_version":"","path":if path.starts_with("/home/"){path.to_string()}else{format!("/home/{domain}/public_html")},"databases":[],"discovered_via":"openlitespeed-vhost"}));
    }
    websites.sort_by_key(|item| item["domain"].as_str().unwrap_or("").to_string());
    let databases = remote_databases(
        credentials,
        elevate,
        &["information_schema", "mysql", "performance_schema", "sys", "cyberpanel"],
    )?;
    let directories = remote_directories(credentials, elevate)?;
    let hostname = remote_hostname(credentials, elevate)?;
    Ok(
        json!({"panel":"CyberPanel","version":version.trim(),"hostname":hostname,"websites":websites,"databases":databases,"directories":directories}),
    )
}

fn valid_username(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 32
        && value
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-'))
}

fn discover_hestia(credentials: &Credentials, elevate: bool) -> Result<Value, String> {
    let version = run(
        credentials,
        "grep -m1 '^VERSION' /usr/local/hestia/conf/hestia.conf 2>/dev/null | cut -d= -f2 | tr -d '\"' || true",
        elevate,
    )?;

    let users_output = run(
        credentials,
        "v-list-users json 2>/dev/null || /usr/local/hestia/bin/v-list-users json 2>/dev/null || true",
        elevate,
    )?;
    let users_payload = decode_json(&users_output).unwrap_or(Value::Object(Default::default()));
    let usernames: Vec<String> = users_payload
        .as_object()
        .map(|map| map.keys().cloned().collect())
        .unwrap_or_default();

    let mut websites = Vec::new();
    for user in &usernames {
        if !valid_username(user) {
            continue;
        }
        let domains_output = run(
            credentials,
            &format!(
                "v-list-web-domains {0} json 2>/dev/null || /usr/local/hestia/bin/v-list-web-domains {0} json 2>/dev/null || true",
                shell_quote(user)
            ),
            elevate,
        )?;
        let domains_payload = decode_json(&domains_output).unwrap_or(Value::Object(Default::default()));
        let Some(domains) = domains_payload.as_object() else {
            continue;
        };
        if domains.is_empty() {
            continue;
        }

        let databases_output = run(
            credentials,
            &format!(
                "v-list-databases {0} json 2>/dev/null || /usr/local/hestia/bin/v-list-databases {0} json 2>/dev/null || true",
                shell_quote(user)
            ),
            elevate,
        )?;
        let databases_payload = decode_json(&databases_output).unwrap_or(Value::Object(Default::default()));
        let databases: Vec<Value> = databases_payload
            .as_object()
            .map(|map| {
                map.iter()
                    .map(|(name, info)| json!({"name": name, "user": field(info, &["USER", "DBUSER"])}))
                    .collect()
            })
            .unwrap_or_default();

        for (domain, info) in domains {
            let domain_lower = domain.to_ascii_lowercase();
            if !valid_domain(&domain_lower) {
                continue;
            }
            let php_version = field(info, &["BACKEND", "PHP"])
                .trim_start_matches("php")
                .replace('_', ".");
            let php_version = if !php_version.is_empty() && php_version.chars().all(|c| c.is_ascii_digit() || c == '.') {
                php_version
            } else {
                String::new()
            };
            websites.push(json!({
                "domain": domain_lower,
                "owner": user,
                "php_version": php_version,
                "path": format!("/home/{user}/web/{domain_lower}/public_html"),
                "databases": databases.clone(),
            }));
        }
    }
    websites.sort_by_key(|item| item["domain"].as_str().unwrap_or("").to_string());

    let databases = remote_databases(
        credentials,
        elevate,
        &["information_schema", "mysql", "performance_schema", "sys"],
    )?;
    let directories = remote_directories(credentials, elevate)?;
    let hostname = remote_hostname(credentials, elevate)?;
    Ok(
        json!({"panel":"HestiaCP","version":nonempty(version.trim().to_string(), "Hestia".into()),"hostname":hostname,"websites":websites,"databases":databases,"directories":directories}),
    )
}

/// aaPanel (bt.cn) has no scriptable JSON CLI like CyberPanel/Hestia, so
/// inventory comes straight from its own SQLite store at
/// `/www/server/panel/data/default.db` (`sites` and `databases` tables,
/// `databases.pid` referencing `sites.id`) — read-only, same as the other
/// two panels never write anything remote.
fn discover_aapanel(credentials: &Credentials, elevate: bool) -> Result<Value, String> {
    const DB_PATH: &str = "/www/server/panel/data/default.db";

    let version = run(
        credentials,
        "cat /www/server/panel/class/public.py 2>/dev/null | grep -m1 \"^version\" | cut -d\"'\" -f2 || true",
        elevate,
    )?;

    let sites_output = run(
        credentials,
        &format!(
            "sqlite3 {} \"SELECT '__DPANEL_SITE__' || char(9) || id || char(9) || name || char(9) || path FROM sites;\" 2>/dev/null || true",
            shell_quote(DB_PATH)
        ),
        elevate,
    )?;
    let databases_output = run(
        credentials,
        &format!(
            "sqlite3 {} \"SELECT '__DPANEL_DB__' || char(9) || pid || char(9) || name || char(9) || IFNULL(username,'') FROM databases;\" 2>/dev/null || true",
            shell_quote(DB_PATH)
        ),
        elevate,
    )?;

    let mut databases_by_site: std::collections::HashMap<String, Vec<Value>> = std::collections::HashMap::new();
    for line in databases_output.lines().filter(|line| line.starts_with("__DPANEL_DB__\t")) {
        let parts: Vec<&str> = line.splitn(4, '\t').collect();
        let (Some(site_id), Some(name)) = (parts.get(1), parts.get(2)) else {
            continue;
        };
        if name.is_empty() {
            continue;
        }
        databases_by_site
            .entry(site_id.to_string())
            .or_default()
            .push(json!({"name": name, "user": parts.get(3).unwrap_or(&"")}));
    }

    let mut websites = Vec::new();
    for line in sites_output.lines().filter(|line| line.starts_with("__DPANEL_SITE__\t")) {
        let parts: Vec<&str> = line.splitn(4, '\t').collect();
        let (Some(site_id), Some(domain), Some(path)) = (parts.get(1), parts.get(2), parts.get(3)) else {
            continue;
        };
        let domain_lower = domain.to_ascii_lowercase();
        if !valid_domain(&domain_lower) {
            continue;
        }
        websites.push(json!({
            "domain": domain_lower,
            "owner": "",
            "php_version": "",
            "path": nonempty((*path).to_string(), format!("/www/wwwroot/{domain_lower}")),
            "databases": databases_by_site.get(*site_id).cloned().unwrap_or_default(),
        }));
    }
    websites.sort_by_key(|item| item["domain"].as_str().unwrap_or("").to_string());

    let databases = remote_databases(
        credentials,
        elevate,
        &["information_schema", "mysql", "performance_schema", "sys"],
    )?;
    let directories = remote_directories(credentials, elevate)?;
    let hostname = remote_hostname(credentials, elevate)?;
    Ok(
        json!({"panel":"aaPanel","version":nonempty(version.trim().to_string(), "aaPanel".into()),"hostname":hostname,"websites":websites,"databases":databases,"directories":directories}),
    )
}

/// Plesk keeps everything (domains, hosting roots, databases) in its own
/// internal `psa` MySQL database. There's no read-only CLI for this like
/// CyberPanel/Hestia's JSON commands, so — same trick Plesk's own backup
/// tooling and countless migration scripts use — connect straight to `psa`
/// with the admin credential Plesk itself stores at `/etc/psa/.psa.shadow`.
fn plesk_db_query(credentials: &Credentials, elevate: bool, sql: &str) -> Result<String, String> {
    run(
        credentials,
        &format!(
            "mysql -uadmin -p\"$(cat /etc/psa/.psa.shadow 2>/dev/null)\" -Ne {} psa 2>/dev/null || true",
            shell_quote(sql)
        ),
        elevate,
    )
}

fn discover_plesk(credentials: &Credentials, elevate: bool) -> Result<Value, String> {
    let version = run(
        credentials,
        "awk '{print $1; exit}' /usr/local/psa/version 2>/dev/null || true",
        elevate,
    )?;

    let sites_output = plesk_db_query(
        credentials,
        elevate,
        "SELECT d.id, d.name, IFNULL(h.www_root,'') FROM domains d LEFT JOIN hosting h ON h.dom_id = d.id;",
    )?;
    let databases_output = plesk_db_query(credentials, elevate, "SELECT dom_id, name FROM data_bases;")?;

    let mut databases_by_site: std::collections::HashMap<String, Vec<Value>> = std::collections::HashMap::new();
    for line in databases_output.lines() {
        let parts: Vec<&str> = line.splitn(2, '\t').collect();
        let (Some(site_id), Some(name)) = (parts.first(), parts.get(1)) else {
            continue;
        };
        if name.is_empty() || **name == *"NULL" {
            continue;
        }
        databases_by_site
            .entry((*site_id).to_string())
            .or_default()
            .push(json!({"name": name, "user": ""}));
    }

    let mut websites = Vec::new();
    for line in sites_output.lines() {
        let parts: Vec<&str> = line.splitn(3, '\t').collect();
        let (Some(site_id), Some(domain), Some(www_root)) = (parts.first(), parts.get(1), parts.get(2)) else {
            continue;
        };
        let domain_lower = domain.to_ascii_lowercase();
        if !valid_domain(&domain_lower) {
            continue;
        }
        let path = if www_root.starts_with('/') {
            (*www_root).to_string()
        } else {
            let subdirectory = if www_root.is_empty() || **www_root == *"NULL" { "httpdocs" } else { www_root };
            format!("/var/www/vhosts/{domain_lower}/{subdirectory}")
        };
        websites.push(json!({
            "domain": domain_lower,
            "owner": "",
            "php_version": "",
            "path": path,
            "databases": databases_by_site.get(*site_id).cloned().unwrap_or_default(),
        }));
    }
    websites.sort_by_key(|item| item["domain"].as_str().unwrap_or("").to_string());

    let databases = remote_databases(
        credentials,
        elevate,
        &["information_schema", "mysql", "performance_schema", "sys", "psa", "apsc", "roundcube"],
    )?;
    let directories = remote_directories(credentials, elevate)?;
    let hostname = remote_hostname(credentials, elevate)?;
    Ok(
        json!({"panel":"Plesk","version":nonempty(version.trim().to_string(), "Plesk".into()),"hostname":hostname,"websites":websites,"databases":databases,"directories":directories}),
    )
}

fn transfer(request: &TransferRequest) -> Result<Value, String> {
    validate_credentials(&request.credentials)?;
    let _session = ControlSession(request.credentials.clone());
    let elevate = needs_elevation(&request.credentials)?;
    let destination = safe_destination(&request.destination)?;
    let (command, name, mime) = if request.kind == "files" {
        let source = request
            .source_path
            .as_deref()
            .unwrap_or("")
            .trim_end_matches('/');
        let under_allowed_root = ALLOWED_SOURCE_ROOTS.iter().any(|root| source.starts_with(root));
        if !under_allowed_root || source.split('/').any(|part| part == "..") {
            return Err("Select a valid source directory inside /home, /www/wwwroot, or /var/www/vhosts.".into());
        }
        let path = Path::new(source);
        let parent = path
            .parent()
            .and_then(Path::to_str)
            .ok_or("Invalid source directory")?;
        let base = path
            .file_name()
            .and_then(|value| value.to_str())
            .ok_or("Invalid source directory")?;
        (
            format!(
                "tar -C {} -czf - -- {}",
                shell_quote(parent),
                shell_quote(base)
            ),
            format!("{base}.tar.gz"),
            "application/gzip",
        )
    } else if request.kind == "database" {
        let database = request.database.as_deref().unwrap_or("");
        if !valid_database(database) {
            return Err("Select a valid source database.".into());
        }
        (
            format!(
                "if command -v mariadb-dump >/dev/null 2>&1; then mariadb-dump --single-transaction --routines --triggers {} 2>/dev/null; else mysqldump --single-transaction --routines --triggers {} 2>/dev/null; fi",
                shell_quote(database),
                shell_quote(database)
            ),
            format!("{database}.sql"),
            "application/sql",
        )
    } else {
        return Err("Unsupported transfer type.".into());
    };
    let file = OpenOptions::new()
        .create(true)
        .truncate(true)
        .write(true)
        .mode(0o600)
        .custom_flags(0o400000)
        .open(&destination)
        .map_err(|error| format!("Cannot create migration output: {error}"))?;
    if let Err(error) = run_to_file(&request.credentials, &command, file, elevate) {
        let _ = fs::remove_file(&destination);
        return Err(error);
    }
    let size = fs::metadata(&destination)
        .map_err(|error| error.to_string())?
        .len();
    if size == 0 {
        let _ = fs::remove_file(&destination);
        return Err("Remote transfer produced an empty file.".into());
    }
    Ok(json!({"path":destination,"name":name,"mime":mime,"size":size}))
}

/// Checks whether the authenticated SSH user is non-root; if so, subsequent
/// commands must be routed through `sudo -S` (fed the SSH password over
/// stdin) since CyberPanel/mysql/`/home` access on a migrated box normally
/// requires root. Only attempted for password auth — there's no separate
/// sudo credential, and key passphrases aren't account login passwords.
fn needs_elevation(credentials: &Credentials) -> Result<bool, String> {
    if credentials.auth_type != "password" || credentials.username == "root" {
        return Ok(false);
    }
    let uid = run(credentials, "id -u", false)?;
    Ok(uid.trim() != "0")
}

fn run(credentials: &Credentials, command: &str, elevate: bool) -> Result<String, String> {
    let (mut command_process, temporary_key) =
        ssh_command(credentials, command, Stdio::piped(), elevate)?;
    let output = spawn_with_password(&mut command_process, credentials, elevate)
        .map_err(|error| format!("Could not start SSH: {error}"));
    if let Some(path) = temporary_key {
        let _ = fs::remove_file(path);
    }
    let output = output?;
    if !output.status.success() {
        return Err(nonempty(
            String::from_utf8_lossy(&output.stderr).trim().to_string(),
            "Remote CyberPanel command failed.".into(),
        ));
    }
    Ok(String::from_utf8_lossy(&output.stdout).trim().to_string())
}

fn run_to_file(
    credentials: &Credentials,
    command: &str,
    file: File,
    elevate: bool,
) -> Result<(), String> {
    let (mut command_process, temporary_key) =
        ssh_command(credentials, command, Stdio::from(file), elevate)?;
    let output = spawn_with_password(&mut command_process, credentials, elevate)
        .map_err(|error| format!("Could not start SSH transfer: {error}"));
    if let Some(path) = temporary_key {
        let _ = fs::remove_file(path);
    }
    let output = output?;
    if !output.status.success() {
        return Err(nonempty(
            String::from_utf8_lossy(&output.stderr).trim().to_string(),
            "Remote transfer failed.".into(),
        ));
    }
    Ok(())
}

/// Spawns `command`, and when `elevate` is set, writes the account password
/// followed by a newline to its stdin so the remote `sudo -S` can read it,
/// then closes stdin so the wrapped command sees EOF as usual.
fn spawn_with_password(
    command: &mut Command,
    credentials: &Credentials,
    elevate: bool,
) -> std::io::Result<std::process::Output> {
    let mut child = command.spawn()?;
    if elevate {
        use std::io::Write;
        if let Some(mut stdin) = child.stdin.take() {
            let _ = writeln!(stdin, "{}", credentials.password);
        }
    }
    child.wait_with_output()
}

fn ssh_command(
    credentials: &Credentials,
    remote: &str,
    stdout: Stdio,
    elevate: bool,
) -> Result<(Command, Option<PathBuf>), String> {
    let askpass = if Path::new("/usr/local/libexec/drust-ssh-askpass").is_file() {
        "/usr/local/libexec/drust-ssh-askpass"
    } else {
        "/var/www/drust/deploy/drust-ssh-askpass"
    };
    let mut command = Command::new("ssh");
    command.args([
        "-o",
        "BatchMode=no",
        "-o",
        "ConnectTimeout=20",
        "-o",
        "ServerAliveInterval=30",
        "-o",
        "StrictHostKeyChecking=accept-new",
        "-o",
        "ControlMaster=auto",
        "-o",
        "ControlPersist=15",
        "-o",
        &format!("ControlPath={}", control_path(credentials)),
        "-p",
        &credentials.port.to_string(),
    ]);
    let mut temporary_key = None;
    if credentials.auth_type == "key" {
        let key = temp_file("key")?;
        fs::write(&key, &credentials.private_key)
            .map_err(|error| format!("Cannot prepare SSH key: {error}"))?;
        temporary_key = Some(key);
        if !credentials.key_passphrase.is_empty() {
            command
                .env("SSH_ASKPASS", askpass)
                .env("SSH_ASKPASS_REQUIRE", "force")
                .env("DISPLAY", "dpanel:0")
                .env("DPANEL_SSH_PASSWORD", &credentials.key_passphrase);
        }
    } else {
        command
            .env("SSH_ASKPASS", askpass)
            .env("SSH_ASKPASS_REQUIRE", "force")
            .env("DISPLAY", "dpanel:0")
            .env("DPANEL_SSH_PASSWORD", &credentials.password);
    }
    if let Some(key) = &temporary_key {
        command.args(["-i", key.to_str().unwrap_or("")]);
    }
    let remote_invocation = if elevate {
        format!("sudo -S -p '' bash -lc {}", shell_quote(remote))
    } else {
        format!("bash -lc {}", shell_quote(remote))
    };
    command
        .arg(format!("{}@{}", credentials.username, credentials.host))
        .arg(remote_invocation)
        .stdin(if elevate { Stdio::piped() } else { Stdio::null() })
        .stdout(stdout)
        .stderr(Stdio::piped());
    Ok((command, temporary_key))
}

fn validate_credentials(value: &Credentials) -> Result<(), String> {
    if value.transport_id.len() < 16
        || value.transport_id.len() > 64
        || !value
            .transport_id
            .chars()
            .all(|c| c.is_ascii_hexdigit() || c == '-')
    {
        return Err("Invalid SSH transport ID.".into());
    }
    if value.host.is_empty()
        || value.host.len() > 253
        || !value
            .host
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-' | ':'))
    {
        return Err("Invalid SSH host.".into());
    }
    if value.username.is_empty()
        || value.username.len() > 64
        || !value
            .username
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-'))
    {
        return Err("Invalid SSH username.".into());
    }
    if value.port == 0 {
        return Err("Invalid SSH port.".into());
    }
    match value.auth_type.as_str() {
        "password" if !value.password.is_empty() => Ok(()),
        "key" if !value.private_key.is_empty() => Ok(()),
        _ => Err("SSH credentials are incomplete.".into()),
    }
}

struct ControlSession(Credentials);

impl Drop for ControlSession {
    fn drop(&mut self) {
        let path = control_path(&self.0);
        let _ = Command::new("ssh")
            .args([
                "-S",
                &path,
                "-O",
                "exit",
                &format!("{}@{}", self.0.username, self.0.host),
            ])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status();
        let _ = fs::remove_file(path);
    }
}

fn control_path(credentials: &Credentials) -> String {
    format!("/tmp/drust-ssh-control-{}", credentials.transport_id)
}

fn safe_destination(value: &str) -> Result<PathBuf, String> {
    let path = PathBuf::from(value);
    let parent = path
        .parent()
        .ok_or("Invalid migration destination")?
        .canonicalize()
        .map_err(|_| "Migration destination is unavailable")?;
    let root = Path::new(MIGRATION_ROOT)
        .canonicalize()
        .map_err(|_| "Migration storage is unavailable")?;
    if !parent.starts_with(root)
        || path.exists()
            && path
                .symlink_metadata()
                .map(|meta| meta.file_type().is_symlink())
                .unwrap_or(true)
    {
        return Err("Invalid migration destination.".into());
    }
    Ok(path)
}

fn temp_file(kind: &str) -> Result<PathBuf, String> {
    let nonce = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|error| error.to_string())?
        .as_nanos();
    let path = PathBuf::from(format!(
        "/tmp/drust-ssh-{kind}-{}-{nonce}",
        std::process::id()
    ));
    OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(&path)
        .map_err(|error| format!("Cannot create SSH temporary file: {error}"))?;
    Ok(path)
}
fn shell_quote(value: &str) -> String {
    format!("'{}'", value.replace('\'', "'\\''"))
}
fn valid_database(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && value
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '$' | '-'))
}
fn valid_domain(value: &str) -> bool {
    value.len() > 3
        && value.len() <= 253
        && value.contains('.')
        && value
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-'))
}
fn nonempty(value: String, fallback: String) -> String {
    if value.is_empty() { fallback } else { value }
}
fn field(value: &Value, keys: &[&str]) -> String {
    keys.iter()
        .find_map(|key| value.get(key))
        .and_then(Value::as_str)
        .unwrap_or("")
        .trim()
        .to_string()
}
fn rows(value: &Value, keys: &[&str]) -> Vec<Value> {
    for key in keys {
        if let Some(candidate) = value.get(key) {
            if let Some(array) = candidate.as_array() {
                return array.clone();
            }
            if let Some(raw) = candidate.as_str() {
                if let Ok(decoded) = serde_json::from_str::<Value>(raw) {
                    if let Some(array) = decoded.as_array() {
                        return array.clone();
                    }
                }
            }
        }
    }
    value.as_array().cloned().unwrap_or_default()
}
fn decode_json(output: &str) -> Option<Value> {
    let start = output
        .char_indices()
        .find(|(_, value)| *value == '{' || *value == '[')?
        .0;
    let mut value: Value = serde_json::from_str(&output[start..]).ok()?;
    if let Some(raw) = value.as_str() {
        value = serde_json::from_str(raw).ok()?;
    }
    Some(value)
}
