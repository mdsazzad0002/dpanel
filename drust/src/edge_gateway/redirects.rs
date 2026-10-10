//! Per-site redirect rules ("Rules → Redirect Rules" in the panel).
//!
//! Rules run before the edge cache, so an http:// or www. request is never
//! answered from a copy stored for the canonical URL. A site without rules
//! pays one empty-slice check.

use axum::{
    body::Body,
    http::{HeaderValue, Request, StatusCode, header},
    response::Response,
};

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum RedirectKind {
    HttpToHttps,
    WwwToRoot,
    /// Send every request to this hostname over HTTPS.
    ToDomain(String),
}

#[derive(Clone, Debug)]
pub struct RedirectRule {
    pub kind: RedirectKind,
    pub status: u16,
    pub preserve_query: bool,
}

impl RedirectRule {
    /// `None` for an unknown kind, so a panel newer than the gateway cannot
    /// make it redirect somewhere unintended.
    pub fn parse(kind: &str, target: &str, status: &str, preserve_query: &str) -> Option<Self> {
        let kind = match kind.trim() {
            "http_to_https" => RedirectKind::HttpToHttps,
            "www_to_root" => RedirectKind::WwwToRoot,
            "to_domain" => {
                let target = target.trim().trim_end_matches('.').to_ascii_lowercase();
                if target.is_empty()
                    || !target
                        .bytes()
                        .all(|byte| byte.is_ascii_alphanumeric() || matches!(byte, b'.' | b'-'))
                {
                    return None;
                }
                RedirectKind::ToDomain(target)
            }
            _ => return None,
        };
        let status = match status.trim().parse::<u16>() {
            Ok(code @ (301 | 302 | 307 | 308)) => code,
            _ => 301,
        };
        Some(Self { kind, status, preserve_query: preserve_query.trim() != "0" })
    }
}

/// The redirect for this request, if any rule matches. HTTP→HTTPS and
/// WWW→root are folded into one hop; the first matching rule sets the code.
pub fn redirect_for(site: &super::SiteConfig, request: &Request<Body>) -> Option<Response> {
    if site.redirects.is_empty() {
        return None;
    }
    let path = request.uri().path();
    // Certificate issuance must reach the site on the hostname it asked for.
    if path.starts_with("/.well-known/acme-challenge/") {
        return None;
    }
    let https = request
        .headers()
        .get("x-forwarded-proto")
        .is_some_and(|value| value.as_bytes().eq_ignore_ascii_case(b"https"));
    let host = request
        .headers()
        .get(header::HOST)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("")
        .split(':')
        .next()
        .unwrap_or("")
        .trim_end_matches('.')
        .to_ascii_lowercase();
    if host.is_empty() {
        return None;
    }

    let mut scheme_https = https;
    let mut target_host = host.clone();
    let mut status = None;
    let mut keep_query = true;
    for rule in site.redirects.iter() {
        let matched = match &rule.kind {
            // Without a certificate the HTTPS hop would land on an error.
            RedirectKind::HttpToHttps if !https && site.enable_ssl => {
                scheme_https = true;
                true
            }
            RedirectKind::WwwToRoot if host.starts_with("www.") => {
                target_host = host["www.".len()..].to_string();
                true
            }
            RedirectKind::ToDomain(domain) if *domain != host => {
                let query = rule.preserve_query.then(|| request.uri().query()).flatten();
                return Some(redirect_response(rule.status, &location("https", domain, path, query)));
            }
            _ => false,
        };
        if matched {
            status.get_or_insert(rule.status);
            keep_query &= rule.preserve_query;
        }
    }
    let status = status?;
    let scheme = if scheme_https { "https" } else { "http" };
    let query = keep_query.then(|| request.uri().query()).flatten();
    Some(redirect_response(status, &location(scheme, &target_host, path, query)))
}

fn location(scheme: &str, host: &str, path: &str, query: Option<&str>) -> String {
    match query {
        Some(query) if !query.is_empty() => format!("{scheme}://{host}{path}?{query}"),
        _ => format!("{scheme}://{host}{path}"),
    }
}

fn redirect_response(code: u16, location: &str) -> Response {
    let mut response = Response::new(Body::empty());
    *response.status_mut() = StatusCode::from_u16(code).unwrap_or(StatusCode::MOVED_PERMANENTLY);
    if let Ok(value) = HeaderValue::from_str(location) {
        response.headers_mut().insert(header::LOCATION, value);
    }
    response.headers_mut().insert("x-redirect-rule", HeaderValue::from_static("1"));
    response
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::edge_gateway::{RouteAction, RouteConfig, SiteConfig};
    use std::sync::Arc;

    fn site(rules: Vec<RedirectRule>, enable_ssl: bool) -> SiteConfig {
        SiteConfig {
            id: "site-1".to_string(),
            scope: "user".to_string(),
            site_owner: None,
            hostnames: Arc::from(["example.com".to_string(), "www.example.com".to_string()]),
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
            enable_ssl,
            spa_fallback: false,
            routes: Arc::from([RouteConfig { path_prefix: "/".to_string(), action: RouteAction::Static }]),
            banned_ips: Arc::from([]),
            allowed_ips: Arc::from([]),
            cache: Default::default(),
            redirects: rules.into(),
        }
    }

    fn rule(kind: &str, target: &str) -> RedirectRule {
        RedirectRule::parse(kind, target, "301", "1").unwrap()
    }

    fn request(host: &str, uri: &str, https: bool) -> Request<Body> {
        let mut builder = Request::builder().uri(uri).header("host", host);
        if https {
            builder = builder.header("x-forwarded-proto", "https");
        }
        builder.body(Body::empty()).unwrap()
    }

    fn location_of(response: Option<Response>) -> Option<String> {
        response.map(|response| response.headers()[header::LOCATION].to_str().unwrap().to_string())
    }

    #[test]
    fn http_and_www_fold_into_one_hop() {
        let site = site(vec![rule("http_to_https", ""), rule("www_to_root", "")], true);
        assert_eq!(
            location_of(redirect_for(&site, &request("www.example.com", "/a?b=1", false))).as_deref(),
            Some("https://example.com/a?b=1"),
        );
        assert!(redirect_for(&site, &request("example.com", "/a", true)).is_none());
    }

    #[test]
    fn https_rule_needs_a_certificate() {
        let site = site(vec![rule("http_to_https", "")], false);
        assert!(redirect_for(&site, &request("example.com", "/", false)).is_none());
    }

    #[test]
    fn domain_rule_skips_acme_and_drops_query_when_asked() {
        let moved = RedirectRule::parse("to_domain", "New.example.org", "302", "0").unwrap();
        let site = site(vec![moved], false);
        let response = redirect_for(&site, &request("example.com", "/x?y=1", false)).unwrap();
        assert_eq!(response.status(), 302);
        assert_eq!(location_of(Some(response)).as_deref(), Some("https://new.example.org/x"));
        assert!(redirect_for(&site, &request("example.com", "/.well-known/acme-challenge/t", false)).is_none());
    }

    #[test]
    fn unknown_or_unsafe_rules_are_dropped() {
        assert!(RedirectRule::parse("regex", "", "301", "1").is_none());
        assert!(RedirectRule::parse("to_domain", "evil.com/path", "301", "1").is_none());
    }
}
