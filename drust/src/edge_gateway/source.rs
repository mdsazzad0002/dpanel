use std::{collections::HashMap, fs, path::Path, path::PathBuf, process::Command, time::Duration};

use super::{CachePolicy, RouteAction, RouteConfig, RuntimeSnapshot, SiteConfig};

#[derive(Clone, Debug)]
pub struct DbSnapshotConfig {
    pub env_path: PathBuf,
    pub ttl: Duration,
}

impl DbSnapshotConfig {
    pub fn new() -> Self {
        Self {
            env_path: PathBuf::from("/var/www/dpanel/.env"),
            ttl: Duration::from_secs(2),
        }
    }
}

pub fn load_runtime_snapshot(config: &DbSnapshotConfig) -> Result<RuntimeSnapshot, String> {
    let env = read_env_file(&config.env_path)?;
    let host = env
        .get("DB_HOST")
        .cloned()
        .unwrap_or_else(|| "127.0.0.1".to_string());
    let port = env
        .get("DB_PORT")
        .cloned()
        .unwrap_or_else(|| "3306".to_string());
    let database = env
        .get("DB_DATABASE")
        .cloned()
        .unwrap_or_else(|| "dpanel".to_string());
    let username = env
        .get("DB_USERNAME")
        .cloned()
        .unwrap_or_else(|| "root".to_string());
    let password = env.get("DB_PASSWORD").cloned().unwrap_or_default();

    let mut cmd = Command::new(find_mysql_client());
    cmd.arg("-h")
        .arg(host)
        .arg("-P")
        .arg(port)
        .arg("-u")
        .arg(username)
        .arg("--batch")
        .arg("--raw")
        .arg("--skip-column-names")
        .arg(database.clone())
        .arg("-e")
        .arg("SELECT id,domain,scope,site_owner,root_path,project_root,start_directory,php_version,enable_ssl,status,type FROM websites ORDER BY updated_at DESC");
    if !password.is_empty() {
        cmd.env("MYSQL_PWD", password);
    }
    let output = cmd
        .output()
        .map_err(|error| format!("mysql cli failed: {error}"))?;
    if !output.status.success() {
        return Err(String::from_utf8_lossy(&output.stderr).trim().to_string());
    }

    let mut sites = Vec::new();
    let mut tls = Vec::new();
    for line in String::from_utf8_lossy(&output.stdout).lines() {
        let cols: Vec<&str> = line.split('\t').collect();
        if cols.len() < 11 {
            continue;
        }
        let domain = normalize_domain(cols[1]);
        if domain.is_empty() {
            continue;
        }
        let scope = normalize_scope(cols[2]);
        let site_owner = normalize_site_owner(cols[3]);
        let root_path = cols[4].trim();
        let project_root = cols[5].trim();
        let start_directory = cols[6].trim();
        let php_version = normalize_php_version(cols[7]);
        let enable_ssl = cols[8].trim() == "1";
        let status = cols[9].trim();
        if !matches_status(status) {
            continue;
        }
        let document_root =
            resolve_document_root(root_path, project_root, start_directory, &domain);
        sites.push(SiteConfig {
            id: cols[0].trim().to_string(),
            scope,
            site_owner,
            hostnames: std::sync::Arc::from([domain.clone(), format!("www.{domain}")]),
            document_root,
            php_version,
            enable_ssl,
            spa_fallback: true,
            routes: std::sync::Arc::from([RouteConfig {
                path_prefix: "/".to_string(),
                action: RouteAction::Static,
            }]),
        });
        if enable_ssl {
            tls.push(super::TlsConfig {
                // The configured certificate may not include the www alias.
                // Register only the guaranteed hostname so one single-name
                // certificate cannot prevent the entire TLS listener starting.
                hostnames: std::sync::Arc::from([domain.clone()]),
                cert_path: default_cert_path(&domain),
                key_path: default_key_path(&domain),
            });
        }
    }

    if sites.is_empty() {
        return Err("database snapshot returned no active websites".to_string());
    }

    Ok(RuntimeSnapshot {
        version: current_version_hint(&database),
        sites: std::sync::Arc::from(sites),
        tls: std::sync::Arc::from(tls),
        cache: CachePolicy {
            enabled: true,
            ttl: config.ttl,
            stale_while_revalidate: Duration::from_secs(1),
        },
    })
}

fn read_env_file(path: &Path) -> Result<HashMap<String, String>, String> {
    let contents = fs::read_to_string(path).map_err(|error| format!("read env failed: {error}"))?;
    let mut env = HashMap::new();
    for line in contents.lines() {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') {
            continue;
        }
        if let Some((key, value)) = line.split_once('=') {
            env.insert(key.trim().to_string(), value.trim().to_string());
        }
    }
    Ok(env)
}

fn find_mysql_client() -> &'static str {
    if Path::new("/usr/bin/mariadb").exists() {
        "mariadb"
    } else {
        "mysql"
    }
}

fn normalize_scope(value: &str) -> String {
    match value.trim().to_ascii_lowercase().as_str() {
        "system" => "system".to_string(),
        _ => "user".to_string(),
    }
}

fn normalize_site_owner(value: &str) -> Option<String> {
    let owner = value.trim().to_ascii_lowercase();
    if owner.is_empty() || owner.eq_ignore_ascii_case("null") {
        return None;
    }

    Some(owner)
}

fn normalize_domain(domain: &str) -> String {
    domain.trim().trim_end_matches('.').to_lowercase()
}

fn default_document_root(domain: &str) -> PathBuf {
    PathBuf::from(format!("/var/www/{domain}/public"))
}

fn default_cert_path(domain: &str) -> PathBuf {
    let certbot_path = PathBuf::from(format!("/etc/letsencrypt/live/{domain}/fullchain.pem"));
    if certbot_path.is_file() {
        return certbot_path;
    }
    PathBuf::from(format!("/etc/drust/tls/{domain}.crt"))
}

fn default_key_path(domain: &str) -> PathBuf {
    let certbot_path = PathBuf::from(format!("/etc/letsencrypt/live/{domain}/privkey.pem"));
    if certbot_path.is_file() {
        return certbot_path;
    }
    PathBuf::from(format!("/etc/drust/tls/{domain}.key"))
}

fn resolve_document_root(
    root_path: &str,
    project_root: &str,
    start_directory: &str,
    domain: &str,
) -> Option<PathBuf> {
    let root = optional_path(root_path);
    let project = optional_path(project_root);
    let start = normalize_site_directory(start_directory);
    let mut candidates = Vec::new();

    // DPanel stores the account web root and the app's public entry directory separately.
    if let Some(root) = root.as_ref() {
        push_candidate(
            &mut candidates,
            join_start_directory(root, start.as_deref()),
        );
        push_candidate(&mut candidates, root.clone());
    }
    if let Some(project) = project.as_ref() {
        push_candidate(
            &mut candidates,
            join_start_directory(project, start.as_deref()),
        );
        push_candidate(&mut candidates, project.clone());
    }
    push_candidate(&mut candidates, default_document_root(domain));

    candidates
        .iter()
        .find(|path| path.join("index.html").is_file())
        .or_else(|| candidates.iter().find(|path| path.is_dir()))
        .cloned()
        .or_else(|| candidates.into_iter().next())
}

fn optional_path(path: &str) -> Option<PathBuf> {
    let normalized = path.trim().replace('\\', "/");
    let normalized = normalized.trim_end_matches('/');
    if normalized.is_empty() || normalized.eq_ignore_ascii_case("null") {
        return None;
    }
    Some(PathBuf::from(normalized))
}

fn normalize_site_directory(value: &str) -> Option<String> {
    let trimmed = value.trim().trim_matches('/');
    if trimmed.is_empty() || trimmed == "." || trimmed.eq_ignore_ascii_case("null") {
        return None;
    }
    Some(trimmed.replace('\\', "/"))
}

fn join_start_directory(root: &Path, start_directory: Option<&str>) -> PathBuf {
    let Some(start_directory) = start_directory else {
        return root.to_path_buf();
    };
    if root.ends_with(start_directory) {
        root.to_path_buf()
    } else {
        root.join(start_directory)
    }
}

fn push_candidate(candidates: &mut Vec<PathBuf>, candidate: PathBuf) {
    if !candidates.contains(&candidate) {
        candidates.push(candidate);
    }
}

fn matches_status(status: &str) -> bool {
    matches!(
        status.trim().to_lowercase().as_str(),
        "live" | "active" | "published" | "enabled"
    )
}

fn normalize_php_version(value: &str) -> Option<String> {
    let value = value.trim();
    if value.eq_ignore_ascii_case("null")
        || !value
            .bytes()
            .all(|byte| byte.is_ascii_digit() || byte == b'.')
    {
        return None;
    }
    let (major, minor) = value.split_once('.')?;
    if major.is_empty() || minor.is_empty() {
        return None;
    }
    Some(format!("{major}.{minor}"))
}

fn current_version_hint(database: &str) -> u64 {
    let mut hash = 1469598103934665603u64;
    for byte in database.as_bytes() {
        hash ^= u64::from(*byte);
        hash = hash.wrapping_mul(1099511628211);
    }
    hash
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn combines_root_path_with_start_directory() {
        let root = resolve_document_root(
            "/home/example/public_html",
            "/home/example",
            "public",
            "example.test",
        )
        .unwrap();
        assert_eq!(root, PathBuf::from("/home/example/public_html/public"));
    }

    #[test]
    fn does_not_duplicate_start_directory() {
        let root =
            resolve_document_root("/srv/example/public", "", "public", "example.test").unwrap();
        assert_eq!(root, PathBuf::from("/srv/example/public"));
    }

    #[test]
    fn ignores_database_null_paths() {
        let root = resolve_document_root("NULL", "/srv/example", "public", "example.test").unwrap();
        assert_eq!(root, PathBuf::from("/srv/example/public"));
    }
}
