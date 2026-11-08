//! Per-site request guards that run before anything else answers (tasks S5
//! and S6): password-protected directories, folders where PHP never runs
//! (an upload folder), hotlink protection for media files, and serving only
//! requests that come through Cloudflare.

use std::{
    net::{IpAddr, SocketAddr},
    sync::Arc,
};

use axum::{
    body::Body,
    http::{HeaderValue, Request, StatusCode, header},
    response::{IntoResponse, Response},
};

use super::basic_auth::{self, ProtectedDirectory};

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct SiteGuards {
    pub protected_directories: Arc<[ProtectedDirectory]>,
    /// Normalized, lower case path prefixes where a PHP file is refused.
    pub no_php_paths: Arc<[String]>,
    /// `Some` turns hotlink protection on; the hosts besides the site's own
    /// whose pages may embed its media.
    pub hotlink_allowed_hosts: Option<Arc<[String]>>,
    /// Refuse connections that do not come from Cloudflare's network, so the
    /// origin cannot be reached around Cloudflare's protection.
    pub cloudflare_only: bool,
}

/// Cloudflare's published ranges (cloudflare.com/ips), which change rarely.
const CLOUDFLARE_RANGES: [&str; 22] = [
    "173.245.48.0/20", "103.21.244.0/22", "103.22.200.0/22", "103.31.4.0/22", "141.101.64.0/18",
    "108.162.192.0/18", "190.93.240.0/20", "188.114.96.0/20", "197.234.240.0/22", "198.41.128.0/17",
    "162.158.0.0/15", "104.16.0.0/13", "104.24.0.0/14", "172.64.0.0/13", "131.0.72.0/22",
    "2400:cb00::/32", "2606:4700::/32", "2803:f800::/32", "2405:b500::/32", "2405:8100::/32",
    "2a06:98c0::/29", "2c0f:f248::/32",
];

fn in_range(ip: IpAddr, range: &str) -> bool {
    let Ok(net) = range.parse::<crate::platform::firewall::IpNet>() else { return false };
    let bits = |ip: IpAddr| -> u128 {
        match ip {
            IpAddr::V4(v4) => u128::from(u32::from(v4)) << 96,
            IpAddr::V6(v6) => u128::from(v6),
        }
    };
    if ip.is_ipv4() != net.addr.is_ipv4() {
        return false;
    }
    // IPv4 sits in the top 32 bits, so one mask works for both families.
    let prefix = u32::from(net.prefix);
    let mask = if prefix == 0 { 0 } else { u128::MAX << (128 - prefix.min(128)) };
    bits(ip) & mask == bits(net.addr) & mask
}

/// The connection's own address: headers such as `CF-Connecting-IP` are set
/// by whoever sends the request, so they prove nothing.
fn peer(request: &Request<Body>) -> Option<IpAddr> {
    request
        .extensions()
        .get::<axum::extract::ConnectInfo<SocketAddr>>()
        .map(|info| info.0.ip())
        .or_else(|| request.extensions().get::<SocketAddr>().map(SocketAddr::ip))
        .map(|ip| match ip {
            IpAddr::V6(v6) => v6.to_ipv4_mapped().map_or(ip, IpAddr::V4),
            other => other,
        })
}

pub fn from_cloudflare(ip: IpAddr) -> bool {
    CLOUDFLARE_RANGES.iter().any(|range| in_range(ip, range))
}

const PHP_EXTENSIONS: [&str; 5] = [".php", ".phtml", ".phar", ".php5", ".php7"];
const MEDIA_EXTENSIONS: [&str; 14] = [
    ".jpg", ".jpeg", ".png", ".gif", ".webp", ".avif", ".svg", ".bmp", ".ico", ".mp4", ".webm", ".mp3", ".ogg", ".pdf",
];

/// The guard's answer when one refuses the request; `None` lets it through.
pub fn check(site: &super::SiteConfig, request: &Request<Body>) -> Option<Response> {
    if let Some(response) = basic_auth::challenge_for(site, request) {
        return Some(response);
    }
    let guards = &site.guards;
    if guards.no_php_paths.is_empty() && guards.hotlink_allowed_hosts.is_none() && !guards.cloudflare_only {
        return None;
    }
    let path = basic_auth::canonical_path(request.uri().path());
    // This machine itself (a health check) and certificate renewal still get through.
    if guards.cloudflare_only
        && !path.starts_with("/.well-known/acme-challenge/")
        && !peer(request).is_some_and(|ip| ip.is_loopback() || from_cloudflare(ip))
    {
        return Some(forbidden("This website only accepts requests through Cloudflare"));
    }
    if runs_php_in_blocked_folder(guards, &path) {
        return Some(forbidden("PHP is turned off in this folder"));
    }
    if is_hotlinked(site, request, &path) {
        return Some(forbidden("Hotlinking is not allowed"));
    }
    None
}

/// Any segment, not just the last, so `/uploads/x.php/info` is caught too.
fn runs_php_in_blocked_folder(guards: &SiteGuards, path: &str) -> bool {
    let under = guards
        .no_php_paths
        .iter()
        .any(|prefix| prefix == "/" || path.strip_prefix(prefix.as_str()).is_some_and(|rest| rest.starts_with('/')));
    under && path.split('/').any(|segment| PHP_EXTENSIONS.iter().any(|ext| segment.ends_with(ext)))
}

/// A media file requested from another site's page. No referrer is allowed:
/// browsers drop it often, and blocking it would break direct visits.
fn is_hotlinked(site: &super::SiteConfig, request: &Request<Body>, path: &str) -> bool {
    let Some(allowed) = &site.guards.hotlink_allowed_hosts else { return false };
    if !MEDIA_EXTENSIONS.iter().any(|ext| path.ends_with(ext)) {
        return false;
    }
    let Some(referer) = request.headers().get(header::REFERER).and_then(|value| value.to_str().ok()) else {
        return false;
    };
    let Some(host) = referer_host(referer) else { return false };
    let own = site.hostnames.iter().chain(allowed.iter());
    !own.into_iter().any(|allowed| {
        let allowed = allowed.trim().to_ascii_lowercase();
        host == allowed || host.strip_suffix(&allowed).is_some_and(|rest| rest.ends_with('.'))
    })
}

fn referer_host(referer: &str) -> Option<String> {
    let rest = referer.split_once("://")?.1;
    let authority = rest.split(['/', '?', '#']).next()?;
    let host = authority.rsplit('@').next()?;
    let host = if host.starts_with('[') { host.split(']').next()?.trim_start_matches('[') } else { host.split(':').next()? };
    (!host.is_empty()).then(|| host.to_ascii_lowercase())
}

fn forbidden(message: &'static str) -> Response {
    let mut response = (StatusCode::FORBIDDEN, message).into_response();
    response.headers_mut().insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response
}

#[cfg(test)]
mod tests {
    use super::*;

    fn site(guards: SiteGuards) -> super::super::SiteConfig {
        let mut site = super::super::basic_auth::test_site();
        site.guards = guards;
        site
    }

    fn request(path: &str, referer: Option<&str>) -> Request<Body> {
        let mut builder = Request::builder().uri(path);
        if let Some(referer) = referer {
            builder = builder.header(header::REFERER, referer);
        }
        builder.body(Body::empty()).unwrap()
    }

    fn no_php() -> SiteGuards {
        SiteGuards { no_php_paths: Arc::from(["/wp-content/uploads".to_string()]), ..Default::default() }
    }

    #[test]
    fn php_in_an_upload_folder_is_refused_however_it_is_spelled() {
        let site = site(no_php());
        for path in ["/wp-content/uploads/shell.php", "/wp-content/uploads/2026/x.PHP", "/wp-content/%75ploads/x.phtml", "/wp-content/uploads/x.php/info"] {
            let response = check(&site, &request(path, None)).unwrap_or_else(|| panic!("{path} got through"));
            assert_eq!(response.status(), StatusCode::FORBIDDEN);
        }
    }

    #[test]
    fn other_files_and_other_folders_are_untouched() {
        let site = site(no_php());
        assert!(check(&site, &request("/wp-content/uploads/photo.jpg", None)).is_none());
        assert!(check(&site, &request("/wp-admin/index.php", None)).is_none());
        assert!(check(&site, &request("/wp-content/uploads-old/x.php", None)).is_none());
    }

    #[test]
    fn media_embedded_by_another_site_is_refused() {
        let site = site(SiteGuards { hotlink_allowed_hosts: Some(Arc::from(["partner.test".to_string()])), ..Default::default() });
        let image = "/img/logo.png";
        assert!(check(&site, &request(image, Some("https://evil.test/page"))).is_some());
        assert!(check(&site, &request(image, Some("https://shop.test.evil.test/"))).is_some());
        assert!(check(&site, &request(image, Some("https://shop.test/about"))).is_none());
        assert!(check(&site, &request(image, Some("https://www.shop.test:8443/"))).is_none());
        assert!(check(&site, &request(image, Some("https://cdn.partner.test/"))).is_none());
        assert!(check(&site, &request(image, None)).is_none());
        assert!(check(&site, &request("/page.html", Some("https://evil.test/"))).is_none());
    }

    fn from(ip: &str, path: &str) -> Request<Body> {
        let mut request = request(path, None);
        let addr: SocketAddr = format!("{ip}:443").parse().unwrap_or_else(|_| format!("[{ip}]:443").parse().unwrap());
        request.extensions_mut().insert(axum::extract::ConnectInfo(addr));
        request
    }

    #[test]
    fn cloudflare_only_refuses_everyone_else_whatever_their_headers_say() {
        let site = site(SiteGuards { cloudflare_only: true, ..Default::default() });
        assert!(check(&site, &from("104.16.1.1", "/")).is_none());
        assert!(check(&site, &from("2606:4700::1", "/")).is_none());
        assert!(check(&site, &from("::ffff:162.158.1.1", "/")).is_none());
        assert!(check(&site, &from("127.0.0.1", "/")).is_none());
        let mut spoofed = from("203.0.113.9", "/");
        spoofed.headers_mut().insert("cf-connecting-ip", HeaderValue::from_static("104.16.1.1"));
        assert_eq!(check(&site, &spoofed).unwrap().status(), StatusCode::FORBIDDEN);
        assert!(check(&site, &request("/", None)).is_some(), "no peer address is no proof either");
        assert!(check(&site, &from("203.0.113.9", "/.well-known/acme-challenge/t")).is_none());
    }

    #[test]
    fn range_edges() {
        assert!(from_cloudflare("173.245.63.255".parse().unwrap()));
        assert!(!from_cloudflare("173.245.64.0".parse().unwrap()));
        assert!(from_cloudflare("2a06:98c7:ffff::1".parse().unwrap()));
        assert!(!from_cloudflare("2a06:98c8::1".parse().unwrap()));
    }

    #[test]
    fn hotlinking_is_allowed_unless_turned_on() {
        let site = site(SiteGuards::default());
        assert!(check(&site, &request("/img/logo.png", Some("https://evil.test/"))).is_none());
    }
}
