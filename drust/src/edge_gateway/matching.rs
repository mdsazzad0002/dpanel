use super::{RouteConfig, RuntimeSnapshot, SiteConfig};

pub fn resolve_site<'a>(snapshot: &'a RuntimeSnapshot, host: &str) -> Option<&'a SiteConfig> {
    snapshot.sites.iter().find(|site| {
        site.hostnames
            .iter()
            .any(|candidate| candidate.eq_ignore_ascii_case(host))
    })
}

pub fn resolve_route<'a>(site: &'a SiteConfig, path: &str) -> Option<&'a RouteConfig> {
    site.routes
        .iter()
        .filter(|route| path.starts_with(route.path_prefix.as_str()))
        .max_by_key(|route| route.path_prefix.len())
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
    fn resolve_site_matches_host() {
        let snapshot = RuntimeSnapshot {
            version: 1,
            sites: Arc::from([SiteConfig {
                id: "site-1".to_string(),
                scope: "user".to_string(),
                site_owner: Some("example".to_string()),
                hostnames: Arc::from(["example.com".to_string(), "www.example.com".to_string()]),
                document_root: Some(PathBuf::from("/var/www/example/public")),
                php_version: None,
                enable_ssl: false,
                spa_fallback: true,
                routes: Arc::from([RouteConfig {
                    path_prefix: "/".to_string(),
                    action: RouteAction::Static,
                }]),
            }]),
            tls: Arc::from([]),
            cache: CachePolicy {
                enabled: true,
                ttl: Duration::from_secs(60),
                stale_while_revalidate: Duration::from_secs(30),
            },
        };

        assert!(resolve_site(&snapshot, "example.com").is_some());
        assert!(resolve_site(&snapshot, "www.example.com").is_some());
        assert!(resolve_site(&snapshot, "missing.example.com").is_none());
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
        };

        let route = resolve_route(&site, "/api/users").unwrap();
        assert_eq!(route.path_prefix, "/api/");
    }

    #[test]
    fn normalize_path_rejects_traversal_shape() {
        assert_eq!(normalize_request_path("/a/b/../c"), "/a/c");
        assert_eq!(normalize_request_path("/"), "/");
    }
}
