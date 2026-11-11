use std::{collections::HashMap, fs, path::Path, path::PathBuf, process::Command, time::Duration};

use super::{
    CacheMode, CachePolicy, RouteAction, RouteConfig, RuntimeSnapshot, SiteCacheConfig, SiteConfig,
    UpstreamConfig,
};
use crate::platform::acme::{self, CertInfo, SslEngine};

#[derive(Clone, Debug)]
pub struct DbSnapshotConfig {
    pub env_path: PathBuf,
    pub ttl: Duration,
}

impl DbSnapshotConfig {
    pub fn new() -> Self {
        Self {
            env_path: crate::platform::paths().panel_root().join(".env"),
            ttl: Duration::from_secs(2),
        }
    }
}

pub fn load_runtime_snapshot(config: &DbSnapshotConfig) -> Result<RuntimeSnapshot, String> {
    let env = read_panel_env(&config.env_path)?;
    let database = env
        .get("DB_DATABASE")
        .cloned()
        .unwrap_or_else(|| "dpanel".to_string());
    let (sites, tls) = load_sites(&env, None)?;
    if sites.is_empty() {
        return Err("database snapshot returned no active websites".to_string());
    }
    // Before the pool's migration runs there are no rules: Host alone decides.
    let bindings = ip_bindings_from(&env).unwrap_or_else(|error| {
        tracing::warn!(%error, "IP pool rules unavailable; matching by Host only");
        super::IpBindings::default()
    });

    Ok(RuntimeSnapshot::new(
        current_version_hint(&database),
        std::sync::Arc::from(sites),
        std::sync::Arc::from(tls),
        CachePolicy {
            enabled: true,
            ttl: config.ttl,
            stale_while_revalidate: Duration::from_secs(1),
        },
    )
    .with_ip_bindings(bindings))
}

/// The IP pool's rules (backlog U5), read on every reload.
pub fn load_ip_bindings(config: &DbSnapshotConfig) -> Result<super::IpBindings, String> {
    ip_bindings_from(&read_panel_env(&config.env_path)?)
}

fn ip_bindings_from(env: &HashMap<String, String>) -> Result<super::IpBindings, String> {
    let addresses = run_mysql(env, IP_ADDRESSES_SQL)?;
    let sites = run_mysql(env, SITE_IPS_SQL)?;
    Ok(parse_ip_bindings(&addresses, &sites))
}

/// `address` is what the gateway sees on a connection (the private address
/// behind NAT); `public_address` only matters to DNS.
const IP_ADDRESSES_SQL: &str = "SELECT address,mode,COALESCE(default_website_id,'') FROM ip_addresses";

/// An alias (and `www.`) answers on its main site's address.
const SITE_IPS_SQL: &str = "SELECT w.id,a.address FROM websites w LEFT JOIN websites p ON p.id=w.parent_id AND w.type IN ('alias','alis') JOIN ip_addresses a ON a.id=COALESCE(p.ip_address_id,w.ip_address_id)";

fn parse_ip_bindings(addresses: &str, sites: &str) -> super::IpBindings {
    let ip = |text: &str| text.trim().parse::<std::net::IpAddr>().ok().map(super::ip_binding::canonical);
    let mut bindings = super::IpBindings::default();
    for line in addresses.lines() {
        let mut cols = line.split('\t');
        let (Some(address), mode, default_site) = (cols.next().and_then(ip), cols.next().unwrap_or(""), cols.next().unwrap_or("")) else {
            continue;
        };
        let default_site = optional_string(default_site);
        bindings.addresses.insert(address, super::AddressRule { dedicated: mode.trim() == "dedicated", default_site });
    }
    for line in sites.lines() {
        if let Some((id, address)) = line.split_once('\t')
            && let Some(address) = ip(address)
        {
            bindings.site_ips.insert(id.trim().to_string(), address);
        }
    }
    bindings
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
    let env = read_panel_env(&config.env_path)?;
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
        let domain = if uses_sqlite(env) {
            "LOWER(RTRIM(TRIM(w.domain),'.'))"
        } else {
            "LOWER(TRIM(TRAILING '.' FROM TRIM(w.domain)))"
        };
        format!("{domain} IN ({list})")
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

    // Same again: before the column's migration runs, every site serves.
    let serving_states = match run_mysql(env, &format!("{SERVING_STATE_SQL}{}", site_filter("AND"))) {
        Ok(rows) => parse_serving_states(&rows),
        Err(error) => {
            tracing::warn!(%error, "site serving states unavailable; all sites stay active");
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

    // Optional as well (tasks S5, S6). An alias row takes its main site's guards.
    let mut guards = match run_mysql(env, &format!("{GUARD_SETTINGS_SQL}{}", site_filter("WHERE"))) {
        Ok(rows) => parse_guard_settings(&rows),
        Err(error) => {
            tracing::warn!(%error, "site guard settings unavailable; none applied");
            HashMap::new()
        }
    };
    match run_mysql(env, &format!("{PROTECTED_DIRECTORIES_SQL}{} ORDER BY w.id,d.path,d.id", site_filter("WHERE"))) {
        Ok(rows) => add_protected_directories(&mut guards, &rows),
        Err(error) => tracing::warn!(%error, "protected directories unavailable; none applied"),
    }
    // The WAF mode (S2): a site without a guard row gets the default,
    // `detect`; before the column's migration runs every site is `off`.
    match run_mysql(env, &format!("{WAF_MODE_SQL}{}", site_filter("WHERE"))) {
        Ok(rows) => apply_waf_modes(&mut guards, &rows),
        Err(error) => tracing::warn!(%error, "WAF modes unavailable; the firewall stays off"),
    }

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
            // www.<domain> is its own alias row, never implied by the main site.
            hostnames: std::sync::Arc::from([domain.clone()]),
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
            serving_state: serving_states.get(cols[0].trim()).copied().unwrap_or_default(),
            enable_ssl,
            spa_fallback: true,
            routes,
            banned_ips: parse_ip_list(cols[11]),
            allowed_ips: parse_ip_list(cols[12]),
            cache: cache_settings.remove(cols[0].trim()).unwrap_or_default(),
            redirects: redirects.remove(cols[0].trim()).map(Into::into).unwrap_or_else(|| std::sync::Arc::from([])),
            guards: guards.remove(cols[0].trim()).unwrap_or_default(),
        });
        if enable_ssl {
            tls.push(super::TlsConfig {
                hostnames: std::sync::Arc::from([domain.clone()]),
                cert_path: default_cert_path(&domain),
                key_path: default_key_path(&domain),
            });
        }
    }

    if crate::platform::current().uses_php_cgi_pool() {
        add_panel_site(&mut sites, domains, &panel_domain(), crate::platform::paths().panel_root());
    }

    Ok((sites, tls))
}

pub(super) fn panel_domain() -> String {
    std::env::var("DRUST_PANEL_DOMAIN")
        .ok()
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
        .unwrap_or_else(|| "dpanel.localhost".to_string())
}

/// On Linux the panel is a row of `websites`. A local panel has no row, so it
/// is added here, unless a row has the domain or a reload is for other domains.
fn add_panel_site(sites: &mut Vec<SiteConfig>, wanted: Option<&[String]>, panel_domain: &str, panel_root: &Path) {
    let domain = normalize_domain(panel_domain);
    let is_wanted = wanted.map_or(true, |domains| domains.contains(&domain));
    if is_wanted && !sites.iter().any(|site| site.hostnames.contains(&domain)) {
        sites.push(panel_site(domain, panel_root));
    }
}

/// The static route of any PHP site: files in `public`, the rest to its `index.php`.
fn panel_site(domain: String, panel_root: &Path) -> SiteConfig {
    SiteConfig {
        id: "panel".to_string(),
        scope: "system".to_string(),
        site_owner: None,
        hostnames: std::sync::Arc::from([domain]),
        document_root: Some(panel_root.join("public")),
        php_version: None,
        runtime: "php".to_string(),
        project_root: Some(panel_root.to_path_buf()),
        node_entry_file: None,
        node_start_command: None,
        node_version: None,
        python_entry_file: None,
        python_start_command: None,
        python_version: None,
        python_run: Default::default(),
        serving_state: Default::default(),
        enable_ssl: false,
        spa_fallback: true,
        routes: std::sync::Arc::from([RouteConfig {
            path_prefix: "/".to_string(),
            action: RouteAction::Static,
        }]),
        banned_ips: std::sync::Arc::from([]),
        allowed_ips: std::sync::Arc::from([]),
        cache: Default::default(),
        redirects: std::sync::Arc::from([]),
        guards: Default::default(),
    }
}

fn uses_sqlite(env: &HashMap<String, String>) -> bool {
    env.get("DB_CONNECTION").is_some_and(|value| value == "sqlite")
}

/// Prints rows like `mysql --batch`. A local panel keeps its data in SQLite,
/// and PHP is the one SQLite reader such a machine is sure to have.
const SQLITE_ROWS_PHP: &str = "$db=new PDO('sqlite:'.$argv[1],null,null,[PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);\
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);\
foreach($db->query($argv[2],PDO::FETCH_NUM) as $row){\
echo implode(chr(9),array_map(fn($v)=>$v===null?'NULL':str_replace([chr(9),chr(10),chr(13)],' ',(string)$v),$row)),chr(10);}";

fn run_sqlite(env: &HashMap<String, String>, sql: &str) -> Result<String, String> {
    let database = env.get("DB_DATABASE").ok_or("DB_DATABASE is not set")?;
    let output = Command::new(crate::platform::paths().php_cli(None))
        .args(["-d", "display_errors=stderr", "-r", SQLITE_ROWS_PHP, "--", database, sql])
        .output()
        .map_err(|error| format!("php cli failed: {error}"))?;
    if !output.status.success() {
        return Err(String::from_utf8_lossy(&output.stderr).trim().to_string());
    }
    Ok(String::from_utf8_lossy(&output.stdout).into_owned())
}

fn run_mysql(env: &HashMap<String, String>, sql: &str) -> Result<String, String> {
    if uses_sqlite(env) {
        return run_sqlite(env, sql);
    }
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

/// Lists are comma-separated; the panel never stores a comma inside an entry.
/// The last column is the main site and its aliases, whose pages may always embed the media.
const GUARD_SETTINGS_SQL: &str = "SELECT w.id,COALESCE(g.no_php_paths,''),g.hotlink_protection,COALESCE(g.hotlink_allowed_hosts,''),COALESCE((SELECT GROUP_CONCAT(a.domain) FROM websites a WHERE a.id=g.website_id OR a.parent_id=g.website_id),''),g.cloudflare_only FROM websites w JOIN website_guard_settings g ON g.website_id=COALESCE(w.parent_id,w.id)";

const PROTECTED_DIRECTORIES_SQL: &str = "SELECT w.id,d.path,COALESCE(d.realm,''),d.username,d.password_hash FROM websites w JOIN website_protected_directories d ON d.website_id=COALESCE(w.parent_id,w.id)";

fn comma_list(value: &str) -> impl Iterator<Item = &str> {
    value.split(',').map(str::trim).filter(|item| !item.is_empty() && *item != "NULL")
}

fn parse_guard_settings(rows: &str) -> HashMap<String, super::SiteGuards> {
    let mut guards = HashMap::new();
    for line in rows.lines() {
        let cols: Vec<&str> = line.split('\t').collect();
        if cols.len() < 4 {
            continue;
        }
        let no_php_paths: Vec<String> = comma_list(cols[1])
            .map(super::basic_auth::canonical_path)
            .collect();
        let own_sites = cols.get(4).copied().unwrap_or("");
        let hotlink_allowed_hosts = matches!(cols[2].trim(), "1" | "true").then(|| {
            comma_list(cols[3]).chain(comma_list(own_sites)).map(str::to_ascii_lowercase).collect::<Vec<_>>().into()
        });
        guards.insert(
            cols[0].trim().to_string(),
            super::SiteGuards {
                no_php_paths: no_php_paths.into(),
                hotlink_allowed_hosts,
                cloudflare_only: cols.get(5).is_some_and(|value| matches!(value.trim(), "1" | "true")),
                ..Default::default()
            },
        );
    }
    guards
}

const WAF_MODE_SQL: &str = "SELECT w.id,COALESCE(g.waf_mode,'detect') FROM websites w LEFT JOIN website_guard_settings g ON g.website_id=COALESCE(w.parent_id,w.id)";

fn apply_waf_modes(guards: &mut HashMap<String, super::SiteGuards>, rows: &str) {
    for line in rows.lines() {
        if let Some((site, mode)) = line.split_once('\t') {
            guards.entry(site.trim().to_string()).or_default().waf = super::WafMode::parse(mode);
        }
    }
}

/// One row per user of a folder; the folder's realm is its first row's.
fn add_protected_directories(guards: &mut HashMap<String, super::SiteGuards>, rows: &str) {
    let mut folders: HashMap<String, Vec<(String, String, Vec<(String, String)>)>> = HashMap::new();
    for line in rows.lines() {
        let cols: Vec<&str> = line.split('\t').collect();
        if cols.len() < 5 || cols[3].trim().is_empty() || cols[4].trim().is_empty() {
            continue;
        }
        let site = folders.entry(cols[0].trim().to_string()).or_default();
        let user = (cols[3].trim().to_string(), cols[4].trim().to_string());
        match site.iter_mut().find(|(path, _, _)| path == cols[1].trim()) {
            Some((_, _, users)) => users.push(user),
            None => site.push((cols[1].trim().to_string(), cols[2].trim().to_string(), vec![user])),
        }
    }
    for (site, list) in folders {
        let directories: Vec<_> = list
            .into_iter()
            .map(|(path, realm, users)| {
                let realm = if realm.is_empty() || realm == "NULL" { "Restricted".to_string() } else { realm };
                super::basic_auth::ProtectedDirectory::new(&path, &realm, users)
            })
            .collect();
        guards.entry(site).or_default().protected_directories = directories.into();
    }
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

const SERVING_STATE_SQL: &str = "SELECT w.id,w.serving_state FROM websites w WHERE w.serving_state<>'active'";

fn parse_serving_states(rows: &str) -> HashMap<String, super::SiteState> {
    rows.lines()
        .filter_map(|line| {
            let (id, state) = line.split_once('\t')?;
            Some((id.trim().to_string(), super::SiteState::parse(state)))
        })
        .collect()
}

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

/// The panel's `.env`, with a relative SQLite path made absolute like Laravel does.
fn read_panel_env(path: &Path) -> Result<HashMap<String, String>, String> {
    let mut env = read_env_file(path)?;
    if uses_sqlite(&env) {
        let panel = path.parent().unwrap_or(Path::new(""));
        let database = panel.join(env.get("DB_DATABASE").map_or("database/database.sqlite", String::as_str));
        env.insert("DB_DATABASE".into(), database.to_string_lossy().into_owned());
    }
    Ok(env)
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
    if crate::platform::paths().binary(crate::platform::paths::Tool::Mariadb).is_ok() {
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

/// The certificate and key files serving `domain`.
///
/// A certificate the customer uploaded always wins. Then: a certificate from drust's own ACME client (in `tls_dir`) wins while that
/// engine is on, and a wildcard from it on every engine (certbot never
/// issues one here); otherwise certbot's, then whatever else is in
/// `tls_dir`. A name with no certificate of its own uses its parent's
/// wildcard (task M4). A www alias falls back to its main domain's, which
/// may cover www; the SNI resolver skips it if it does not.
fn certificate_files(
    domain: &str,
    engine: SslEngine,
    tls_dir: &Path,
    certbot: impl Fn(&str) -> Option<(PathBuf, PathBuf)>,
) -> (PathBuf, PathBuf) {
    let in_tls_dir = |name: &str| (tls_dir.join(format!("{name}.crt")), tls_dir.join(format!("{name}.key")));
    let wildcard_of = |files: &(PathBuf, PathBuf), name: &str| {
        files.0.is_file() && CertInfo::read(&files.0).is_some_and(|info| info.names.contains(&format!("*.{name}")))
    };
    if let Some(uploaded) = acme::uploaded_certificate(tls_dir, domain) {
        return uploaded;
    }
    let own = in_tls_dir(domain);
    if own.0.is_file() && (engine == SslEngine::Acme || wildcard_of(&own, domain)) {
        return own;
    }
    if let Some(files) = certbot(domain) {
        return files;
    }
    if own.0.is_file() {
        return own;
    }
    if let Some((_, parent)) = domain.split_once('.').filter(|(_, parent)| parent.contains('.')) {
        let of_parent = in_tls_dir(parent);
        if wildcard_of(&of_parent, parent) {
            return of_parent;
        }
    }
    if let Some(main) = domain.strip_prefix("www.") {
        let of_main = in_tls_dir(main);
        if engine == SslEngine::Acme && of_main.0.is_file() {
            return of_main;
        }
        if let Some(files) = certbot(main) {
            return files;
        }
    }
    own
}

fn default_certificate_files(domain: &str) -> (PathBuf, PathBuf) {
    certificate_files(domain, acme::engine(), &crate::platform::paths().tls_dir(), acme::certbot_lineage)
}

fn default_cert_path(domain: &str) -> PathBuf {
    default_certificate_files(domain).0
}

fn default_key_path(domain: &str) -> PathBuf {
    default_certificate_files(domain).1
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
                .is_some_and(|path| path.starts_with(crate::platform::paths().site_home(owner)))
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
    push_candidate(&mut candidates, crate::platform::paths().default_document_root(domain));

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
    fn the_acme_certificate_wins_only_while_that_engine_is_on() {
        let folder = tempfile::tempdir().unwrap();
        let tls = folder.path();
        let certbot = |name: &str| {
            (name == "both.example.com" || name == "old.example.com")
                .then(|| (PathBuf::from(format!("/live/{name}/fullchain.pem")), PathBuf::from(format!("/live/{name}/privkey.pem"))))
        };
        let own = |name: &str| (tls.join(format!("{name}.crt")), tls.join(format!("{name}.key")));
        let lineage = |name: &str| certbot(name).unwrap();
        for name in ["both.example.com", "new.example.com"] {
            fs::write(own(name).0, "").unwrap();
        }

        // Both exist: the engine decides, and turning it off goes back to certbot.
        assert_eq!(certificate_files("both.example.com", SslEngine::Acme, tls, certbot), own("both.example.com"));
        assert_eq!(certificate_files("both.example.com", SslEngine::Certbot, tls, certbot), lineage("both.example.com"));
        // Only one exists: that one, whatever the engine.
        for engine in [SslEngine::Acme, SslEngine::Certbot] {
            assert_eq!(certificate_files("old.example.com", engine, tls, certbot), lineage("old.example.com"));
            assert_eq!(certificate_files("new.example.com", engine, tls, certbot), own("new.example.com"));
            assert_eq!(certificate_files("none.example.com", engine, tls, certbot), own("none.example.com"));
        }
        // A www alias borrows its main domain's certificate, from the engine's side first.
        assert_eq!(certificate_files("www.old.example.com", SslEngine::Certbot, tls, certbot), lineage("old.example.com"));
        assert_eq!(certificate_files("www.both.example.com", SslEngine::Acme, tls, certbot), own("both.example.com"));
        assert_eq!(certificate_files("www.both.example.com", SslEngine::Certbot, tls, certbot), lineage("both.example.com"));
        assert_eq!(certificate_files("www.new.example.com", SslEngine::Acme, tls, certbot), own("new.example.com"));
        assert_eq!(certificate_files("www.new.example.com", SslEngine::Certbot, tls, certbot), own("www.new.example.com"));
    }

    #[test]
    fn a_wildcard_serves_its_subdomains_and_beats_certbot() {
        let folder = tempfile::tempdir().unwrap();
        let tls = folder.path();
        // certbot has the main domains; a www alias has no lineage of its own.
        let certbot = |name: &str| {
            (!name.starts_with("www.")).then(|| (PathBuf::from(format!("/live/{name}/fullchain.pem")), PathBuf::from("/live/privkey.pem")))
        };
        let no_certbot = |_: &str| None;
        let own = |name: &str| (tls.join(format!("{name}.crt")), tls.join(format!("{name}.key")));
        let write = |name: &str, names: &[&str]| {
            let names: Vec<String> = names.iter().map(|name| name.to_string()).collect();
            fs::write(own(name).0, rcgen::generate_simple_self_signed(names).unwrap().cert.pem()).unwrap();
        };
        write("example.com", &["example.com", "*.example.com"]);
        write("plain.com", &["plain.com", "www.plain.com"]);

        for engine in [SslEngine::Acme, SslEngine::Certbot] {
            // The wildcard site itself, even with a certbot lineage left over.
            assert_eq!(certificate_files("example.com", engine, tls, certbot), own("example.com"));
            // A subdomain site or alias without its own certificate.
            assert_eq!(certificate_files("shop.example.com", engine, tls, no_certbot), own("example.com"));
            assert_eq!(certificate_files("www.example.com", engine, tls, certbot), own("example.com"));
            // One label only, and never another domain's.
            assert_eq!(certificate_files("a.b.example.com", engine, tls, no_certbot), own("a.b.example.com"));
            assert_eq!(certificate_files("shop.plain.com", engine, tls, no_certbot), own("shop.plain.com"));
        }
        // A subdomain with a certificate of its own keeps it.
        assert_eq!(certificate_files("shop.example.com", SslEngine::Certbot, tls, certbot), certbot("shop.example.com").unwrap());
        // Not a wildcard: certbot's lineage still wins on the certbot engine.
        assert_eq!(certificate_files("plain.com", SslEngine::Certbot, tls, certbot), certbot("plain.com").unwrap());
    }

    #[test]
    fn an_uploaded_certificate_wins_over_every_engine() {
        let folder = tempfile::tempdir().unwrap();
        let tls = folder.path();
        let certbot = |name: &str| (!name.starts_with("www.")).then(|| (PathBuf::from("/live/fullchain.pem"), PathBuf::from("/live/privkey.pem")));
        let upload = |domain: &str, names: &[&str]| {
            let files = acme::custom_certificate_files(tls, domain);
            fs::create_dir_all(files.0.parent().unwrap()).unwrap();
            let names: Vec<String> = names.iter().map(|name| name.to_string()).collect();
            fs::write(&files.0, rcgen::generate_simple_self_signed(names).unwrap().cert.pem()).unwrap();
            files
        };
        let both = upload("both.example.com", &["both.example.com", "www.both.example.com"]);
        let bare = upload("bare.example.com", &["bare.example.com"]);

        for engine in [SslEngine::Acme, SslEngine::Certbot, SslEngine::LocalCa] {
            assert_eq!(certificate_files("both.example.com", engine, tls, certbot), both);
            assert_eq!(certificate_files("bare.example.com", engine, tls, certbot), bare);
            // A www alias uses the upload only when the upload names it.
            assert_eq!(certificate_files("www.both.example.com", engine, tls, certbot), both);
            assert_eq!(certificate_files("www.bare.example.com", engine, tls, certbot), certbot("bare.example.com").unwrap());
        }

        // Taking it away uncovers what the engine issued.
        fs::remove_file(&both.0).unwrap();
        assert_eq!(certificate_files("both.example.com", SslEngine::Certbot, tls, certbot), certbot("both.example.com").unwrap());
    }

    #[test]
    fn panel_entry_serves_public_as_a_system_php_site_on_the_panel_host() {
        let mut sites = Vec::new();
        add_panel_site(&mut sites, None, " P.localhost. ", Path::new("panel"));
        let snapshot = RuntimeSnapshot::new(
            1,
            sites.into(),
            std::sync::Arc::from([]),
            CachePolicy { enabled: false, ttl: Duration::ZERO, stale_while_revalidate: Duration::ZERO },
        );

        let site = crate::edge_gateway::resolve_site(&snapshot, "p.localhost:80").expect("panel host");
        assert_eq!(site.document_root.as_deref(), Some(Path::new("panel").join("public").as_path()));
        assert_eq!((site.scope.as_str(), site.runtime.as_str()), ("system", "php"));
        assert_eq!(site.site_owner, None);
        assert!(matches!(
            site.routes.as_ref(),
            [RouteConfig { path_prefix, action: RouteAction::Static }] if path_prefix == "/"
        ));
    }

    #[test]
    fn panel_entry_yields_to_a_row_and_to_a_reload_of_other_domains() {
        let root = Path::new("panel");
        let mut sites = vec![panel_site("p.localhost".to_string(), Path::new("row"))];
        add_panel_site(&mut sites, None, "p.localhost", root);
        assert_eq!(sites.len(), 1);
        assert_eq!(sites[0].document_root.as_deref(), Some(Path::new("row").join("public").as_path()));

        let mut sites = Vec::new();
        add_panel_site(&mut sites, Some(&["mysite.test".to_string()]), "p.localhost", root);
        assert!(sites.is_empty());
        add_panel_site(&mut sites, Some(&["p.localhost".to_string()]), "p.localhost", root);
        assert_eq!(sites.len(), 1);
    }

    #[test]
    fn reads_guard_settings_and_groups_protected_users_by_folder() {
        let mut guards = parse_guard_settings("a\t/wp-content/uploads, /files\t1\tcdn.test\tshop.test,brand.test\t1\nb\t\t0\tNULL\t\t0\n");
        add_protected_directories(&mut guards, "a\t/admin\tStaff\talice\th1\na\t/admin\tStaff\tbob\th2\nc\t/\t\tcarol\th3\n");
        assert_eq!(&*guards["a"].no_php_paths, ["/wp-content/uploads".to_string(), "/files".to_string()]);
        let hosts = ["cdn.test", "shop.test", "brand.test"].map(String::from);
        assert_eq!(guards["a"].hotlink_allowed_hosts.as_deref(), Some(&hosts[..]));
        assert_eq!(guards["a"].protected_directories.len(), 1);
        assert_eq!(guards["a"].protected_directories[0].users.len(), 2);
        assert!(guards["b"].hotlink_allowed_hosts.is_none());
        assert!(guards["a"].cloudflare_only && !guards["b"].cloudflare_only);
        assert_eq!(guards["c"].protected_directories[0].realm, "Restricted");
        apply_waf_modes(&mut guards, "a\tblock\nd\tdetect\ne\tNULL\n");
        assert_eq!(guards["a"].waf, super::super::WafMode::Block);
        assert_eq!(guards["d"].waf, super::super::WafMode::Detect);
        assert_eq!(guards["e"].waf, super::super::WafMode::Off);
        assert!(guards["a"].cloudflare_only, "a WAF mode keeps the other guards");
    }

    #[test]
    fn parses_the_ip_pool_rules() {
        let addresses = "10.0.0.5\tshared\t\n10.0.0.6\tdedicated\tsite-own\n::ffff:10.0.0.7\tdedicated\tNULL\nnot-an-ip\tshared\t\n";
        let sites = "site-own\t10.0.0.6\nsite-www\t10.0.0.6\nsite-bad\tnope\n";
        let bindings = parse_ip_bindings(addresses, sites);
        assert_eq!(bindings.addresses.len(), 3);
        let own = &bindings.addresses[&"10.0.0.6".parse().unwrap()];
        assert!(own.dedicated);
        assert_eq!(own.default_site.as_deref(), Some("site-own"));
        assert!(!bindings.addresses[&"10.0.0.5".parse().unwrap()].dedicated);
        assert_eq!(bindings.addresses[&"10.0.0.7".parse().unwrap()].default_site, None);
        assert_eq!(bindings.site_ips.len(), 2);
        assert_eq!(bindings.site_ips["site-www"], "10.0.0.6".parse::<std::net::IpAddr>().unwrap());
    }

    #[test]
    fn parses_only_the_states_it_knows() {
        let states = parse_serving_states("a\tsuspended\nb\tover_limit\nc\tnonsense\n");
        assert_eq!(states["a"], crate::edge_gateway::SiteState::Suspended);
        assert_eq!(states["b"], crate::edge_gateway::SiteState::OverLimit);
        assert_eq!(states["c"], crate::edge_gateway::SiteState::Active);
    }

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
