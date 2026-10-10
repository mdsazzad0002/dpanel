use std::{collections::HashMap, fs, path::Path, path::PathBuf, process::Command, time::Duration};

use super::{
    CacheMode, CachePolicy, RouteAction, RouteConfig, RuntimeSnapshot, SiteCacheConfig, SiteConfig,
    UpstreamConfig,
};

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
    let database = env
        .get("DB_DATABASE")
        .cloned()
        .unwrap_or_else(|| "dpanel".to_string());
    let (sites, tls) = load_sites(&env, None)?;
    if sites.is_empty() {
        return Err("database snapshot returned no active websites".to_string());
    }

    Ok(RuntimeSnapshot::new(
        current_version_hint(&database),
        std::sync::Arc::from(sites),
        std::sync::Arc::from(tls),
        CachePolicy {
            enabled: true,
            ttl: config.ttl,
            stale_while_revalidate: Duration::from_secs(1),
        },
    ))
}

/// The sites (and certificates) of just these domains, for a reload after
/// one site changed: the queries are filtered in SQL instead of reading
/// every website. A domain with no active site comes back absent, which is
/// how a deleted or disabled site leaves the gateway.
pub fn load_domain_sites(
    config: &DbSnapshotConfig,
    domains: &[String],
) -> Result<(Vec<SiteConfig>, Vec<super::TlsConfig>), String> {
    // Only plain hostnames go into the SQL; anything else cannot be a site.
    let domains = domains
        .iter()
        .map(|domain| normalize_domain(domain))
        .filter(|domain| {
            !domain.is_empty()
                && domain
                    .bytes()
                    .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'-' | b'_'))
        })
        .collect::<Vec<_>>();
    if domains.is_empty() {
        return Ok((Vec::new(), Vec::new()));
    }
    let env = read_env_file(&config.env_path)?;
    load_sites(&env, Some(&domains))
}

/// `domains` must already be validated (see `load_domain_sites`).
fn load_sites(
    env: &HashMap<String, String>,
    domains: Option<&[String]>,
) -> Result<(Vec<SiteConfig>, Vec<super::TlsConfig>), String> {
    let domain_filter = domains.map(|domains| {
        let list = domains
            .iter()
            .map(|domain| format!("'{domain}'"))
            .collect::<Vec<_>>()
            .join(",");
        format!("LOWER(TRIM(TRAILING '.' FROM TRIM(w.domain))) IN ({list})")
    });
    let site_filter = |prefix: &str| {
        domain_filter.as_ref().map_or(String::new(), |filter| format!(" {prefix} {filter}"))
    };
    let stdout = run_mysql(env, &format!("SELECT w.id,w.domain,w.scope,w.site_owner,w.root_path,w.project_root,w.start_directory,w.php_version,w.enable_ssl,w.status,w.type,COALESCE(GROUP_CONCAT(CASE WHEN r.rule_type='ban' THEN r.ip_address END),''),COALESCE(GROUP_CONCAT(CASE WHEN r.rule_type='allow' THEN r.ip_address END),''),COALESCE(w.runtime,'php'),w.node_port,COALESCE(w.node_entry_file,''),COALESCE(w.node_start_command,''),COALESCE(w.node_version,''),COALESCE(w.node_process_status,''),w.python_port,COALESCE(w.python_entry_file,''),COALESCE(w.python_start_command,''),COALESCE(w.python_version,''),COALESCE(w.python_process_status,'') FROM websites w LEFT JOIN website_ip_rules r ON r.website_id=w.id{} GROUP BY w.id,w.domain,w.scope,w.site_owner,w.root_path,w.project_root,w.start_directory,w.php_version,w.enable_ssl,w.status,w.type,w.updated_at,w.runtime,w.node_port,w.node_entry_file,w.node_start_command,w.node_version,w.node_process_status,w.python_port,w.python_entry_file,w.python_start_command,w.python_version,w.python_process_status ORDER BY w.updated_at DESC", site_filter("WHERE")))?;
    // Cache settings live in their own table and are optional: a panel that
    // has not migrated yet must not take every website down with it.
    let cache_sql = match &domain_filter {
        Some(filter) => format!("{CACHE_SETTINGS_SQL} WHERE website_id IN (SELECT w.id FROM websites w WHERE {filter})"),
        None => CACHE_SETTINGS_SQL.to_string(),
    };
    let mut cache_settings = match run_mysql(env, &cache_sql) {
        Ok(rows) => parse_cache_settings(&rows),
        Err(error) => {
            tracing::warn!(%error, "edge cache settings unavailable; caching stays off");
            HashMap::new()
        }
    };
    // Queried separately for the same reason: before the column's migration
    // runs, sites just fall back to the default worker count.
    let python_run = match run_mysql(env, &format!("{PYTHON_RUN_SQL}{}", site_filter("AND"))) {
        Ok(rows) => parse_python_run(&rows),
        Err(error) => {
            tracing::warn!(%error, "python worker settings unavailable; using defaults");
            HashMap::new()
        }
    };

    // Same again for Docker sites: before their migration runs, none exist.
    let docker_ports = match run_mysql(env, &format!("{DOCKER_PORT_SQL}{}", site_filter("AND"))) {
        Ok(rows) => parse_docker_ports(&rows),
        Err(error) => {
            tracing::warn!(%error, "docker site ports unavailable");
            HashMap::new()
        }
    };

    // Optional like the cache settings: no table yet means no redirects.
    let redirect_sql = match &domain_filter {
        Some(filter) => format!("{REDIRECT_RULES_SQL} AND website_id IN (SELECT w.id FROM websites w WHERE {filter}) ORDER BY website_id,position,id"),
        None => format!("{REDIRECT_RULES_SQL} ORDER BY website_id,position,id"),
    };
    let mut redirects = match run_mysql(env, &redirect_sql) {
        Ok(rows) => parse_redirect_rules(&rows),
        Err(error) => {
            tracing::warn!(%error, "redirect rules unavailable; none applied");
            HashMap::new()
        }
    };

    // Optional too: before the port_shares migration, no site has any.
    let share_sql = match &domain_filter {
        Some(filter) => format!("{PORT_SHARES_SQL} AND website_id IN (SELECT w.id FROM websites w WHERE {filter})"),
        None => PORT_SHARES_SQL.to_string(),
    };
    let mut port_shares = match run_mysql(env, &share_sql) {
        Ok(rows) => parse_port_shares(&rows),
        Err(error) => {
            tracing::warn!(%error, "port shares unavailable; none applied");
            HashMap::new()
        }
    };

    let mut sites = Vec::new();
    let mut tls = Vec::new();
    for line in stdout.lines() {
        let cols: Vec<&str> = line.split('\t').collect();
        if cols.len() < 24 {
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
        let document_root = resolve_document_root(
            root_path,
            project_root,
            start_directory,
            &domain,
            scope == "system",
            site_owner.as_deref(),
        );
        let runtime = cols[13].trim().to_ascii_lowercase();
        let node_port = cols[14].trim().parse::<u16>().ok();
        let node_entry_file = optional_string(cols[15]);
        let node_start_command = optional_string(cols[16]);
        let node_version = optional_string(cols[17]);
        let node_process_status = cols[18].trim().to_ascii_lowercase();
        let python_port = cols[19].trim().parse::<u16>().ok();
        let python_entry_file = optional_string(cols[20]);
        let python_start_command = optional_string(cols[21]);
        let python_version = optional_string(cols[22]);
        let python_process_status = cols[23].trim().to_ascii_lowercase();
        let proxy_route = |port: Option<u16>| -> std::sync::Arc<[RouteConfig]> {
            match port {
                Some(port) => std::sync::Arc::from([RouteConfig {
                    path_prefix: "/".to_string(),
                    action: RouteAction::Proxy(UpstreamConfig::Http(std::net::SocketAddr::from((
                        [127, 0, 0, 1],
                        port,
                    )))),
                }]),
                // No port allocated yet (e.g. mid-provisioning): fall back to
                // static so the site at least resolves instead of 502ing.
                None => std::sync::Arc::from([RouteConfig {
                    path_prefix: "/".to_string(),
                    action: RouteAction::Static,
                }]),
            }
        };
        let static_route = || -> std::sync::Arc<[RouteConfig]> {
            std::sync::Arc::from([RouteConfig {
                path_prefix: "/".to_string(),
                action: RouteAction::Static,
            }])
        };
        let routes = match runtime.as_str() {
            "node" if node_process_status != "stopped" => proxy_route(node_port),
            "python" if python_process_status != "stopped" => proxy_route(python_port),
            // Always proxied, even while stopped: the static fallback would
            // serve the mounted folder's raw files (configs, .env) instead.
            "docker" => match docker_ports.get(cols[0].trim()) {
                Some(port) => proxy_route(Some(*port)),
                None => std::sync::Arc::from([]),
            },
            _ => static_route(),
        };
        let routes = match port_shares.remove(cols[0].trim()) {
            Some(shares) => merge_port_shares(&routes, shares),
            None => routes,
        };
        sites.push(SiteConfig {
            id: cols[0].trim().to_string(),
            scope,
            site_owner,
            hostnames: std::sync::Arc::from([domain.clone(), format!("www.{domain}")]),
            document_root,
            php_version,
            runtime,
            project_root: optional_path(project_root),
            node_entry_file,
            node_start_command,
            node_version,
            python_entry_file,
            python_start_command,
            python_version,
            python_run: python_run.get(cols[0].trim()).copied().unwrap_or_default(),
            enable_ssl,
            spa_fallback: true,
            routes,
            banned_ips: parse_ip_list(cols[11]),
            allowed_ips: parse_ip_list(cols[12]),
            cache: cache_settings.remove(cols[0].trim()).unwrap_or_default(),
            redirects: redirects.remove(cols[0].trim()).map(Into::into).unwrap_or_else(|| std::sync::Arc::from([])),
        });
        if enable_ssl {
            tls.push(super::TlsConfig {
                // The www alias is registered best-effort: the resolver skips
                // any hostname the certificate does not actually cover.
                hostnames: if domain.starts_with("www.") {
                    std::sync::Arc::from([domain.clone()])
                } else {
                    std::sync::Arc::from([domain.clone(), format!("www.{domain}")])
                },
                cert_path: default_cert_path(&domain),
                key_path: default_key_path(&domain),
            });
        }
    }

    Ok((sites, tls))
}

fn run_mysql(env: &HashMap<String, String>, sql: &str) -> Result<String, String> {
    let setting = |key: &str, default: &str| env.get(key).cloned().unwrap_or_else(|| default.to_string());
    let mut cmd = Command::new(find_mysql_client());
    cmd.arg("-h")
        .arg(setting("DB_HOST", "127.0.0.1"))
        .arg("-P")
        .arg(setting("DB_PORT", "3306"))
        .arg("-u")
        .arg(setting("DB_USERNAME", "root"))
        .arg("--batch")
        .arg("--raw")
        .arg("--skip-column-names")
        .arg(setting("DB_DATABASE", "dpanel"))
        .arg("-e")
        .arg(sql);
    let password = setting("DB_PASSWORD", "");
    if !password.is_empty() {
        cmd.env("MYSQL_PWD", password);
    }
    let output = cmd
        .output()
        .map_err(|error| format!("mysql cli failed: {error}"))?;
    if !output.status.success() {
        return Err(String::from_utf8_lossy(&output.stderr).trim().to_string());
    }
    Ok(String::from_utf8_lossy(&output.stdout).into_owned())
}

/// Lists become space-separated so each row stays on one line.
const CACHE_SETTINGS_SQL: &str = "SELECT website_id,mode,edge_ttl,COALESCE(browser_ttl,0),REPLACE(REPLACE(COALESCE(bypass_paths,''),CHAR(13),' '),CHAR(10),' '),REPLACE(REPLACE(COALESCE(bypass_cookies,''),CHAR(13),' '),CHAR(10),' '),ignore_query_string,serve_stale,COALESCE(development_mode_until,0) FROM website_edge_cache";

const REDIRECT_RULES_SQL: &str = "SELECT website_id,kind,COALESCE(target,''),status_code,preserve_query FROM website_redirect_rules WHERE enabled=1";

fn parse_redirect_rules(rows: &str) -> HashMap<String, Vec<super::RedirectRule>> {
    let mut rules: HashMap<String, Vec<super::RedirectRule>> = HashMap::new();
    for line in rows.lines() {
        let cols: Vec<&str> = line.split('\t').collect();
        if cols.len() < 5 {
            continue;
        }
        if let Some(rule) = super::RedirectRule::parse(cols[1], cols[2], cols[3], cols[4]) {
            rules.entry(cols[0].trim().to_string()).or_default().push(rule);
        }
    }
    rules
}

const PORT_SHARES_SQL: &str = "SELECT website_id,path_prefix,target_port,strip_prefix FROM port_shares WHERE enabled=1";

fn parse_port_shares(rows: &str) -> HashMap<String, Vec<RouteConfig>> {
    let mut shares: HashMap<String, Vec<RouteConfig>> = HashMap::new();
    for line in rows.lines() {
        let cols: Vec<&str> = line.split('\t').collect();
        if cols.len() < 4 {
            continue;
        }
        let prefix = cols[1].trim();
        let Ok(port) = cols[2].trim().parse::<u16>() else {
            continue;
        };
        if !prefix.starts_with('/') || port == 0 {
            continue;
        }
        shares.entry(cols[0].trim().to_string()).or_default().push(RouteConfig {
            path_prefix: prefix.to_string(),
            action: RouteAction::PortShare {
                port,
                strip_prefix: cols[3].trim() == "1",
            },
        });
    }
    shares
}

/// A share on the same path as a runtime route (e.g. "/") replaces it.
fn merge_port_shares(base: &[RouteConfig], shares: Vec<RouteConfig>) -> std::sync::Arc<[RouteConfig]> {
    let key = |prefix: &str| prefix.trim_end_matches('/').to_string();
    let mut routes: Vec<RouteConfig> = base
        .iter()
        .filter(|route| !shares.iter().any(|share| key(&share.path_prefix) == key(&route.path_prefix)))
        .cloned()
        .collect();
    routes.extend(shares);
    std::sync::Arc::from(routes)
}

const DOCKER_PORT_SQL: &str = "SELECT w.id,COALESCE(w.docker_port,0) FROM websites w WHERE w.runtime='docker'";

fn parse_docker_ports(rows: &str) -> HashMap<String, u16> {
    rows.lines()
        .filter_map(|line| {
            let mut cols = line.split('\t');
            let id = cols.next()?.trim().to_string();
            let port = cols.next()?.trim().parse::<u16>().ok().filter(|port| *port > 0)?;
            Some((id, port))
        })
        .collect()
}

const PYTHON_RUN_SQL: &str = "SELECT w.id,COALESCE(w.python_workers,0),COALESCE(w.python_mode,''),COALESCE(w.python_timeout,0) FROM websites w WHERE w.runtime='python'";

fn parse_python_run(rows: &str) -> HashMap<String, super::PythonRunOptions> {
    rows.lines()
        .filter_map(|line| {
            let mut cols = line.split('\t');
            let id = cols.next()?.trim().to_string();
            let workers = cols.next()?.trim().parse::<u16>().ok().filter(|count| *count > 0);
            let development = cols.next()?.trim().eq_ignore_ascii_case("development");
            let timeout = cols.next()?.trim().parse::<u16>().ok().filter(|seconds| *seconds > 0);
            Some((id, super::PythonRunOptions { workers, development, timeout }))
        })
        .collect()
}

fn parse_cache_settings(rows: &str) -> HashMap<String, SiteCacheConfig> {
    let words = |value: &str| -> std::sync::Arc<[String]> {
        value
            .split_whitespace()
            .filter(|word| !word.eq_ignore_ascii_case("null"))
            .map(str::to_string)
            .collect::<Vec<_>>()
            .into()
    };
    let number = |value: &str| value.trim().parse::<u64>().unwrap_or(0);
    rows.lines()
        .filter_map(|line| {
            let cols: Vec<&str> = line.split('\t').collect();
            if cols.len() < 9 {
                return None;
            }
            let browser_ttl = number(cols[3]);
            Some((
                cols[0].trim().to_string(),
                SiteCacheConfig {
                    mode: CacheMode::parse(cols[1]),
                    edge_ttl: Duration::from_secs(number(cols[2])),
                    browser_ttl: (browser_ttl > 0).then(|| Duration::from_secs(browser_ttl)),
                    bypass_paths: words(cols[4]),
                    bypass_cookies: words(cols[5]),
                    ignore_query: cols[6].trim() == "1",
                    serve_stale: cols[7].trim() == "1",
                    development_mode_until: number(cols[8]),
                },
            ))
        })
        .collect()
}

fn parse_ip_list(value: &str) -> std::sync::Arc<[std::net::IpAddr]> {
    value
        .split(',')
        .filter_map(|item| item.trim().parse().ok())
        .collect::<Vec<_>>()
        .into()
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
    is_system_site: bool,
    site_owner: Option<&str>,
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
    let project_is_owned = site_owner
        .map(|owner| {
            project
                .as_ref()
                .is_some_and(|path| path.starts_with(format!("/home/{owner}")))
        })
        .unwrap_or(false);
    if is_system_site || project_is_owned {
        if let Some(project) = project.as_ref() {
            push_candidate(
                &mut candidates,
                join_start_directory(project, start.as_deref()),
            );
            push_candidate(&mut candidates, project.clone());
        }
    }
    push_candidate(&mut candidates, default_document_root(domain));

    candidates
        .iter()
        .find(|path| path.join("index.html").is_file())
        .or_else(|| candidates.iter().find(|path| path.is_dir()))
        .cloned()
        .or_else(|| candidates.into_iter().next())
}

fn optional_string(value: &str) -> Option<String> {
    let trimmed = value.trim();
    if trimmed.is_empty() || trimmed.eq_ignore_ascii_case("null") {
        None
    } else {
        Some(trimmed.to_string())
    }
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
    fn parses_python_run_mode_and_workers() {
        let rows = parse_python_run("a\t8\tdevelopment\t120\nb\t0\tproduction\t0\nc\t0\t\t0\n");
        assert_eq!(rows["a"].workers, Some(8));
        assert_eq!(rows["a"].timeout, Some(120));
        assert_eq!(rows["b"].timeout, None);
        assert!(rows["a"].development);
        assert_eq!(rows["b"].workers, None);
        assert!(!rows["b"].development);
        assert!(!rows["c"].development);
    }

    #[test]
    fn port_shares_replace_the_same_path_and_add_others() {
        let shares = parse_port_shares("site-1\t/\t3000\t0\nsite-1\t/api\t4000\t1\nsite-2\tbad\t1\t0\n");
        assert!(!shares.contains_key("site-2"));
        let base = [RouteConfig { path_prefix: "/".to_string(), action: RouteAction::Static }];
        let routes = merge_port_shares(&base, shares["site-1"].clone());
        assert_eq!(routes.len(), 2);
        assert!(routes.iter().all(|route| matches!(route.action, RouteAction::PortShare { .. })));
        assert!(routes.iter().any(|route| matches!(route.action, RouteAction::PortShare { port: 4000, strip_prefix: true })));
    }

    #[test]
    fn parses_docker_ports_and_skips_unassigned() {
        let ports = parse_docker_ports("a\t50000\nb\t0\nc\tnope\n");
        assert_eq!(ports.get("a"), Some(&50000));
        assert!(!ports.contains_key("b"));
        assert!(!ports.contains_key("c"));
    }

    #[test]
    fn reads_cache_settings_rows() {
        let settings = parse_cache_settings(
            "abc\teverything\t3600\t0\t/wp-admin /cart\twordpress_logged_in_\t1\t1\t0\n\
             def\tbogus\t60\t300\t\t\t0\t0\t1700000000\n\
             short\trow\n",
        );
        let abc = &settings["abc"];
        assert_eq!(abc.mode, CacheMode::Everything);
        assert_eq!(abc.edge_ttl, Duration::from_secs(3600));
        assert_eq!(abc.browser_ttl, None);
        assert_eq!(&*abc.bypass_paths, ["/wp-admin".to_string(), "/cart".to_string()]);
        assert!(abc.ignore_query && abc.serve_stale);
        let def = &settings["def"];
        assert_eq!(def.mode, CacheMode::Off);
        assert_eq!(def.browser_ttl, Some(Duration::from_secs(300)));
        assert_eq!(def.development_mode_until, 1_700_000_000);
        assert!(!settings.contains_key("short"));
    }

    #[test]
    fn combines_root_path_with_start_directory() {
        let root = resolve_document_root(
            "/home/example/public_html",
            "/home/example",
            "public",
            "example.test",
            false,
            Some("example"),
        )
        .unwrap();
        assert_eq!(root, PathBuf::from("/home/example/public_html/public"));
    }

    #[test]
    fn does_not_duplicate_start_directory() {
        let root = resolve_document_root(
            "/srv/example/public",
            "",
            "public",
            "example.test",
            false,
            Some("example"),
        )
        .unwrap();
        assert_eq!(root, PathBuf::from("/srv/example/public"));
    }

    #[test]
    fn ignores_database_null_paths() {
        let root =
            resolve_document_root("NULL", "/srv/example", "public", "example.test", true, None)
                .unwrap();
        assert_eq!(root, PathBuf::from("/srv/example/public"));
    }
}
