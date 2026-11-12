//! Per-site request guards that run before anything else answers (tasks S5
//! and S6): password-protected directories, folders nobody may fetch (an
//! app's private folders, which its .htaccess would deny on Apache), folders
//! where PHP never runs (an upload folder), hotlink protection for media
//! files, and serving only requests that come through Cloudflare.

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
    /// Normalized, lower case path prefixes refused outright: the folder
    /// itself and everything under it.
    pub denied_paths: Arc<[String]>,
    /// File types refused site-wide or under one folder, as an app's
    /// `<FilesMatch>` deny would on Apache.
    pub denied_extensions: Arc<[DeniedExtension]>,
    /// `Some` turns hotlink protection on; the hosts besides the site's own
    /// whose pages may embed its media.
    pub hotlink_allowed_hosts: Option<Arc<[String]>>,
    /// Refuse connections that do not come from Cloudflare's network, so the
    /// origin cannot be reached around Cloudflare's protection.
    pub cloudflare_only: bool,
    /// Whether PHP and app requests pass the firewall first (S2).
    pub waf: super::waf::WafMode,
}

/// A file extension refused under `folder` (empty: the whole site).
#[derive(Clone, Debug, PartialEq, Eq)]
pub struct DeniedExtension {
    pub folder: String,
    pub extension: String,
}

impl DeniedExtension {
    /// `tpl`, `.tpl` and `*.tpl` refuse it site-wide; `/modules/*.tpl` only
    /// under that folder. Anything else is not an entry.
    pub fn parse(entry: &str) -> Option<Self> {
        let entry = entry.trim().to_ascii_lowercase();
        let (folder, extension) = match entry.rsplit_once("/*.") {
            Some((folder, extension)) => (basic_auth::canonical_path(&format!("/{folder}")), extension.to_string()),
            None => (String::new(), entry.trim_start_matches('*').trim_start_matches('.').to_string()),
        };
        let valid = !extension.is_empty()
            && extension.len() <= 16
            && extension.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'-');
        valid.then(|| Self { folder: if folder == "/" { String::new() } else { folder }, extension })
    }
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
    let path = basic_auth::canonical_path(request.uri().path());
    // Every site and runtime: `.env`, `.git/`, `.htaccess`, `.user.ini` and the like.
    if is_hidden(&path) {
        return Some(forbidden("Hidden files are not served"));
    }
    if let Some(response) = basic_auth::challenge_for(site, request) {
        return Some(response);
    }
    let guards = &site.guards;
    if guards.no_php_paths.is_empty()
        && guards.denied_paths.is_empty()
        && guards.denied_extensions.is_empty()
        && guards.hotlink_allowed_hosts.is_none()
        && !guards.cloudflare_only
    {
        return None;
    }
    // Certificate renewal always gets through.
    let acme = path.starts_with("/.well-known/acme-challenge/");
    if !acme && is_denied(guards, &path) {
        return Some(forbidden("Access to this folder is denied"));
    }
    // So does this machine itself (a health check).
    if guards.cloudflare_only && !acme && !peer(request).is_some_and(|ip| ip.is_loopback() || from_cloudflare(ip)) {
        return Some(forbidden("This website only accepts requests through Cloudflare"));
    }
    if runs_php_in_blocked_folder(guards, &path) {
        return Some(forbidden("PHP is turned off in this folder"));
    }
    if has_denied_extension(guards, &path) {
        return Some(forbidden("This file type is not served"));
    }
    if is_hotlinked(site, request, &path) {
        return Some(forbidden("Hotlinking is not allowed"));
    }
    None
}

/// A segment starting with a dot. `/.well-known/` is public by convention
/// (ACME, security.txt, OAuth discovery, app links); a hidden name inside it is not.
fn is_hidden(path: &str) -> bool {
    let rest = match path.strip_prefix("/.well-known") {
        Some(rest) if rest.is_empty() || rest.starts_with('/') => rest,
        _ => path,
    };
    rest.split('/').any(|segment| segment.starts_with('.'))
}

/// The file name's extension, trailing dots and spaces ignored (`x.log.`).
/// `robots.txt` and `/.well-known/` stay public, as upstream rules keep them.
fn has_denied_extension(guards: &SiteGuards, path: &str) -> bool {
    if guards.denied_extensions.is_empty() || path.starts_with("/.well-known/") {
        return false;
    }
    let name = path.rsplit('/').find(|segment| !segment.is_empty()).unwrap_or("");
    let name = name.trim_end_matches(['.', ' ']);
    if name == "robots.txt" {
        return false;
    }
    let Some((stem, extension)) = name.rsplit_once('.') else { return false };
    !stem.is_empty()
        && guards.denied_extensions.iter().any(|denied| {
            denied.extension == extension
                && (denied.folder.is_empty()
                    || path.strip_prefix(denied.folder.as_str()).is_some_and(|rest| rest.starts_with('/')))
        })
}

/// The prefix itself or anything under it; `/var` never matches `/various`.
fn is_denied(guards: &SiteGuards, path: &str) -> bool {
    guards.denied_paths.iter().any(|prefix| {
        prefix != "/" && path.strip_prefix(prefix.as_str()).is_some_and(|rest| rest.is_empty() || rest.starts_with('/'))
    })
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

    fn denied() -> SiteGuards {
        let paths = ["/var", "/images/deleted", "/occ"].map(String::from);
        SiteGuards { denied_paths: Arc::from(paths), ..Default::default() }
    }

    #[test]
    fn a_denied_folder_is_refused_however_it_is_spelled() {
        let site = site(denied());
        for path in [
            "/var",
            "/var/",
            "/var/logs/prod.log",
            "/VAR/Logs/x",
            "/%76ar/logs/x",
            "/%2Fvar/logs",
            "/var%2flogs",
            "//var//logs",
            "/./var/logs",
            "/img/../var/logs",
            "/x/..%2Fvar/logs",
            "/images/deleted/a/b/file.png",
            "/Images/Deleted/",
            "/occ",
        ] {
            let response = check(&site, &request(path, None)).unwrap_or_else(|| panic!("{path} got through"));
            assert_eq!(response.status(), StatusCode::FORBIDDEN, "{path}");
        }
    }

    #[test]
    fn neighbours_of_a_denied_folder_and_acme_get_through() {
        let site = site(denied());
        for path in ["/", "/various", "/var.php", "/images/photo.png", "/images/deleted-old/x", "/occasion", "/index.php/var", "/.well-known/acme-challenge/token"] {
            assert!(check(&site, &request(path, None)).is_none(), "{path} was refused");
        }
        let everything = site_with_root_denied();
        assert!(check(&everything, &request("/anything", None)).is_none(), "a denied `/` would take the site down");
    }

    fn site_with_root_denied() -> super::super::SiteConfig {
        site(SiteGuards { denied_paths: Arc::from(["/".to_string()]), ..Default::default() })
    }

    #[test]
    fn acme_is_exempt_even_inside_a_denied_folder() {
        let site = site(SiteGuards { denied_paths: Arc::from(["/.well-known".to_string()]), ..Default::default() });
        assert!(check(&site, &request("/.well-known/acme-challenge/t", None)).is_none());
        assert!(check(&site, &request("/.well-known/security.txt", None)).is_some());
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
    fn hidden_files_are_refused_on_every_site_however_spelled() {
        let site = site(SiteGuards::default());
        for path in [
            "/.env",
            "/.ENV",
            "/.git/config",
            "/app/.htaccess",
            "/.user.ini",
            "/%2eenv",
            "/%2Egit/HEAD",
            "/x/%2e%2e/.env",
            "/img/../.git/config",
            "/.env.",
            "/...",
            "/.well-known/x/.env",
            "/.well-known/../.env",
            "/.well-knownx/a",
        ] {
            let response = check(&site, &request(path, None)).unwrap_or_else(|| panic!("{path} got through"));
            assert_eq!(response.status(), StatusCode::FORBIDDEN, "{path}");
        }
    }

    #[test]
    fn well_known_and_ordinary_dotted_names_are_served() {
        let site = site(SiteGuards::default());
        for path in [
            "/",
            "/.well-known/acme-challenge/t",
            "/.well-known/security.txt",
            "/.well-known/assetlinks.json",
            "/.WELL-KNOWN/openid-configuration",
            "/.well-known",
            "/index.php",
            "/a.b/c.d",
            "/file.",
        ] {
            assert!(check(&site, &request(path, None)).is_none(), "{path} was refused");
        }
    }

    fn extensions(entries: &[&str]) -> SiteGuards {
        let list: Vec<_> = entries.iter().filter_map(|entry| DeniedExtension::parse(entry)).collect();
        assert_eq!(list.len(), entries.len(), "an entry did not parse");
        SiteGuards { denied_extensions: list.into(), ..Default::default() }
    }

    #[test]
    fn a_denied_file_type_is_refused_however_spelled() {
        let site = site(extensions(&["log", ".TPL", "*.txt", "/modules/*.yml", "/themes/*.twig"]));
        for path in [
            "/var/logs/x.log",
            "/X.LOG",
            "/x.Tpl",
            "/x%2elog",
            "/x.log.",
            "/x.log%20",
            "/x.log...",
            "/a/../x.log",
            "/x.log/",
            "/notes.txt",
            "/modules/a/config.yml",
            "/Modules/A/B.YML",
            "/%6dodules/a.yml",
            "/x/..%2fmodules/a.yml",
            "/themes/classic/templates/page.twig",
        ] {
            let response = check(&site, &request(path, None)).unwrap_or_else(|| panic!("{path} got through"));
            assert_eq!(response.status(), StatusCode::FORBIDDEN, "{path}");
        }
    }

    #[test]
    fn other_types_folders_and_public_files_get_through() {
        let site = site(extensions(&["log", "txt", "/modules/*.yml"]));
        for path in [
            "/x.logs",
            "/log",
            "/catalog",
            "/x.log.php",
            "/robots.txt",
            "/.well-known/security.txt",
            "/config.yml",
            "/modulesx/a.yml",
            "/modules.yml",
        ] {
            assert!(check(&site, &request(path, None)).is_none(), "{path} was refused");
        }
    }

    #[test]
    fn extension_entries_parse_and_reject_junk() {
        let parsed = DeniedExtension::parse(" /Modules/Sub/*.YML ").unwrap();
        assert_eq!(parsed, DeniedExtension { folder: "/modules/sub".into(), extension: "yml".into() });
        assert_eq!(DeniedExtension::parse("/*.tpl").unwrap().folder, "");
        assert_eq!(DeniedExtension::parse("*.tpl").unwrap(), DeniedExtension { folder: String::new(), extension: "tpl".into() });
        for junk in ["", ".", "*.", "/a/*.", "a b", "x/y", "*.t/pl", "abcdefghijklmnopq"] {
            assert!(DeniedExtension::parse(junk).is_none(), "{junk:?} parsed");
        }
    }

    #[test]
    fn hotlinking_is_allowed_unless_turned_on() {
        let site = site(SiteGuards::default());
        assert!(check(&site, &request("/img/logo.png", Some("https://evil.test/"))).is_none());
    }
}
