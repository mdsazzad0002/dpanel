use super::{RouteConfig, RuntimeSnapshot, SiteConfig};

pub fn resolve_site<'a>(snapshot: &'a RuntimeSnapshot, host: &str) -> Option<&'a SiteConfig> {
    let normalized = host
        .split(':')
        .next()
        .unwrap_or(host)
        .trim()
        .trim_end_matches('.')
        .to_ascii_lowercase();
    if let Some(index) = snapshot.hostname_index.get(&normalized) {
        return snapshot.sites.get(*index);
    }
    // A site that serves its subdomains lists "*.<domain>" as a host name.
    // The closest such parent wins, and a subdomain with a site of its own
    // was already matched exactly above.
    let mut rest = normalized.as_str();
    while let Some((_, parent)) = rest.split_once('.') {
        if !parent.contains('.') {
            break;
        }
        if let Some(index) = snapshot.hostname_index.get(&format!("*.{parent}")) {
            return snapshot.sites.get(*index);
        }
        rest = parent;
    }
    None
}

pub fn resolve_route<'a>(site: &'a SiteConfig, path: &str) -> Option<&'a RouteConfig> {
    site.routes
        .iter()
        .filter(|route| prefix_matches(route.path_prefix.as_str(), path))
        .max_by_key(|route| route.path_prefix.len())
}

/// Segment-aware: "/api" (or "/api/") covers "/api" and "/api/x" but not
/// "/apix", so a shared path never swallows a sibling page.
fn prefix_matches(prefix: &str, path: &str) -> bool {
    let prefix = prefix.trim_end_matches('/');
    prefix.is_empty()
        || path == prefix
        || (path.starts_with(prefix) && path[prefix.len()..].starts_with('/'))
}

pub fn normalize_request_path(path: &str) -> String {
    let mut parts = Vec::new();
    for segment in path.split('/') {
        match segment {
            "" | "." => {}
            ".." => {
                let _ = parts.pop();
            }
            other => parts.push(other),
        }
    }

    if parts.is_empty() {
        "/".to_string()
    } else {
        format!("/{}", parts.join("/"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::edge_gateway::{CachePolicy, RouteAction, RouteConfig, UpstreamConfig};
    use std::{path::PathBuf, sync::Arc, time::Duration};

    #[test]
    fn wildcard_sites_answer_subdomains_but_exact_sites_win() {
        let site = |id: &str, names: &[&str]| SiteConfig {
            id: id.to_string(),
            hostnames: Arc::from(names.iter().map(|n| n.to_string()).collect::<Vec<_>>()),
            ..crate::edge_gateway::source::panel_site(Vec::new(), std::path::Path::new("/tmp"))
        };
        let snapshot = RuntimeSnapshot::new(
            1,
            Arc::from([
                site("second", &["second.example.com", "*.second.example.com"]),
                site("own", &["own.second.example.com"]),
            ]),
            Arc::from([]),
            CachePolicy { enabled: false, ttl: Duration::from_secs(60), stale_while_revalidate: Duration::from_secs(30) },
        );
        let id = |host: &str| resolve_site(&snapshot, host).map(|site| site.id.clone());
        assert_eq!(id("second.example.com").as_deref(), Some("second"));
        assert_eq!(id("a.second.example.com:443").as_deref(), Some("second"));
        assert_eq!(id("x.y.second.example.com").as_deref(), Some("second"));
        assert_eq!(id("own.second.example.com").as_deref(), Some("own"));
        assert_eq!(id("example.com"), None);
        assert_eq!(id("other.example.com"), None);
    }

    #[test]
    fn resolve_site_matches_host() {
        let snapshot = RuntimeSnapshot::new(
            1,
            Arc::from([SiteConfig {
                id: "site-1".to_string(),
                scope: "user".to_string(),
                site_owner: Some("example".to_string()),
                hostnames: Arc::from(["example.com".to_string(), "www.example.com".to_string()]),
                document_root: Some(PathBuf::from("/var/www/example/public")),
                php_version: None,
                runtime: "php".to_string(),
                project_root: None,
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
                routes: Arc::from([RouteConfig {
                    path_prefix: "/".to_string(),
                    action: RouteAction::Static,
                }]),
                banned_ips: Arc::from([]),
                allowed_ips: Arc::from([]),
                cache: Default::default(),
                redirects: Arc::from([]),
                guards: Default::default(),
            }]),
            Arc::from([]),
            CachePolicy {
                enabled: true,
                ttl: Duration::from_secs(60),
                stale_while_revalidate: Duration::from_secs(30),
            },
        );

        assert!(resolve_site(&snapshot, "example.com").is_some());
        assert!(resolve_site(&snapshot, "www.example.com").is_some());
        assert!(resolve_site(&snapshot, "missing.example.com").is_none());
        assert!(resolve_site(&snapshot, "a.www.example.com").is_none(), "no wildcard without *.");
    }

    #[test]
    fn route_resolution_prefers_longest_prefix() {
        let site = SiteConfig {
            id: "site-1".to_string(),
            scope: "user".to_string(),
            site_owner: Some("example".to_string()),
            hostnames: Arc::from(["example.com".to_string()]),
            document_root: None,
            php_version: None,
            runtime: "php".to_string(),
            project_root: None,
            node_entry_file: None,
            node_start_command: None,
            node_version: None,
            python_entry_file: None,
            python_start_command: None,
            python_version: None,
            python_run: Default::default(),
            serving_state: Default::default(),
            enable_ssl: false,
            spa_fallback: false,
            routes: Arc::from([
                RouteConfig {
                    path_prefix: "/".to_string(),
                    action: RouteAction::Static,
                },
                RouteConfig {
                    path_prefix: "/api/".to_string(),
                    action: RouteAction::Proxy(UpstreamConfig::Http(
                        "127.0.0.1:9000".parse().unwrap(),
                    )),
                },
            ]),
            banned_ips: Arc::from([]),
            allowed_ips: Arc::from([]),
            cache: Default::default(),
            redirects: Arc::from([]),
            guards: Default::default(),
        };

        let route = resolve_route(&site, "/api/users").unwrap();
        assert_eq!(route.path_prefix, "/api/");
        assert_eq!(resolve_route(&site, "/api").unwrap().path_prefix, "/api/");
        assert_eq!(resolve_route(&site, "/apix").unwrap().path_prefix, "/");
    }

    #[test]
    fn normalize_path_rejects_traversal_shape() {
        assert_eq!(normalize_request_path("/a/b/../c"), "/a/c");
        assert_eq!(normalize_request_path("/"), "/");
    }
}
