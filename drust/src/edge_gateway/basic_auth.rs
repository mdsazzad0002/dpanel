//! Password-protected directories (task S5): a path prefix of a site that
//! asks for a username and password (`WWW-Authenticate: Basic`) before
//! anything else answers, the edge cache and redirects included. The ACME
//! challenge path stays open so certificates still renew.
//!
//! Passwords are stored by the panel as `s256i$<rounds>$<salt hex>$<hex>`:
//! `d0 = sha256(salt || password)`, `d(n+1) = sha256(d(n) || password)`.
//! A header that verified once is remembered, so a page's many assets do not
//! each pay for the rounds.

use std::{
    collections::HashSet,
    sync::{Arc, Mutex, OnceLock},
};

use axum::{
    body::Body,
    http::{HeaderValue, Request, StatusCode, header},
    response::{IntoResponse, Response},
};
use base64::Engine;
use sha2::{Digest, Sha256};

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct ProtectedDirectory {
    /// Normalized, lower case, without a trailing slash; `/` is the whole site.
    pub path_prefix: String,
    pub realm: String,
    /// Username and stored password hash.
    pub users: Arc<[(String, String)]>,
}

impl ProtectedDirectory {
    pub fn new(path_prefix: &str, realm: &str, users: Vec<(String, String)>) -> Self {
        let prefix = canonical_path(path_prefix);
        Self {
            path_prefix: prefix,
            realm: realm.replace(['"', '\\', '\r', '\n'], ""),
            users: users.into(),
        }
    }

    fn covers(&self, path: &str) -> bool {
        self.path_prefix == "/"
            || path == self.path_prefix
            || path.strip_prefix(&self.path_prefix).is_some_and(|rest| rest.starts_with('/'))
    }
}

/// `401` when the request is under a protected directory and does not carry
/// one of its users' credentials.
pub fn challenge_for(site: &super::SiteConfig, request: &Request<Body>) -> Option<Response> {
    if site.guards.protected_directories.is_empty() {
        return None;
    }
    let path = canonical_path(request.uri().path());
    if path.starts_with("/.well-known/acme-challenge/") {
        return None;
    }
    // The longest prefix decides, so a subfolder can have its own users.
    let directory = site
        .guards
        .protected_directories
        .iter()
        .filter(|directory| directory.covers(&path))
        .max_by_key(|directory| directory.path_prefix.len())?;
    let header = request.headers().get(header::AUTHORIZATION).and_then(|value| value.to_str().ok());
    if header.is_some_and(|header| authorized(directory, header)) {
        return None;
    }
    let mut response = (StatusCode::UNAUTHORIZED, "Authentication required").into_response();
    let challenge = format!("Basic realm=\"{}\", charset=\"UTF-8\"", directory.realm);
    if let Ok(value) = HeaderValue::from_str(&challenge) {
        response.headers_mut().insert(header::WWW_AUTHENTICATE, value);
    }
    response.headers_mut().insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    Some(response)
}

/// Whether the request is under a protected directory, so the shared edge
/// cache keeps out of it.
pub fn is_protected(site: &super::SiteConfig, request: &Request<Body>) -> bool {
    if site.guards.protected_directories.is_empty() {
        return false;
    }
    let path = canonical_path(request.uri().path());
    site.guards.protected_directories.iter().any(|directory| directory.covers(&path))
}

fn authorized(directory: &ProtectedDirectory, header: &str) -> bool {
    let Some(encoded) = header.strip_prefix("Basic ").or_else(|| header.strip_prefix("basic ")) else {
        return false;
    };
    let Ok(decoded) = base64::engine::general_purpose::STANDARD.decode(encoded.trim()) else {
        return false;
    };
    let Ok(decoded) = String::from_utf8(decoded) else { return false };
    let Some((username, password)) = decoded.split_once(':') else { return false };
    let Some((_, stored)) = directory.users.iter().find(|(name, _)| name == username) else {
        return false;
    };
    let key = hex(&Sha256::digest(format!("{}\n{stored}\n{password}", directory.path_prefix).as_bytes()));
    if verified().lock().map(|seen| seen.contains(&key)).unwrap_or(false) {
        return true;
    }
    if !verify(stored, password) {
        return false;
    }
    if let Ok(mut seen) = verified().lock() {
        if seen.len() >= 4096 {
            seen.clear();
        }
        seen.insert(key);
    }
    true
}

/// Keyed on the stored hash too, so a changed password is checked again.
fn verified() -> &'static Mutex<HashSet<String>> {
    static VERIFIED: OnceLock<Mutex<HashSet<String>>> = OnceLock::new();
    VERIFIED.get_or_init(|| Mutex::new(HashSet::new()))
}

pub fn verify(stored: &str, password: &str) -> bool {
    let mut parts = stored.split('$');
    let (Some("s256i"), Some(rounds), Some(salt), Some(expected), None) =
        (parts.next(), parts.next(), parts.next(), parts.next(), parts.next())
    else {
        return false;
    };
    let Ok(rounds) = rounds.parse::<u32>() else { return false };
    let Some(salt) = unhex(salt) else { return false };
    if !(1..=1_000_000).contains(&rounds) {
        return false;
    }
    let digest = hash(&salt, password, rounds);
    constant_time_eq(hex(&digest).as_bytes(), expected.to_ascii_lowercase().as_bytes())
}

fn hash(salt: &[u8], password: &str, rounds: u32) -> Vec<u8> {
    let mut digest = Sha256::new().chain_update(salt).chain_update(password.as_bytes()).finalize().to_vec();
    for _ in 1..rounds {
        digest = Sha256::new().chain_update(&digest).chain_update(password.as_bytes()).finalize().to_vec();
    }
    digest
}

fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    a.len() == b.len() && a.iter().zip(b).fold(0u8, |acc, (x, y)| acc | (x ^ y)) == 0
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}

fn unhex(value: &str) -> Option<Vec<u8>> {
    if value.len() % 2 != 0 {
        return None;
    }
    (0..value.len()).step_by(2).map(|i| u8::from_str_radix(value.get(i..i + 2)?, 16).ok()).collect()
}

/// Decoded, so `/%61dmin` is `/admin`; lower case, so a case-insensitive
/// filesystem cannot be reached around a rule; dot segments resolved.
pub fn canonical_path(path: &str) -> String {
    super::normalize_request_path(&percent_decode(path)).to_ascii_lowercase()
}

/// `%XX` to the byte it stands for; anything malformed stays as it is.
pub(super) fn percent_decode(path: &str) -> String {
    let bytes = path.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        if bytes[i] == b'%' {
            if let Some(byte) = path.get(i + 1..i + 3).and_then(|pair| u8::from_str_radix(pair, 16).ok()) {
                out.push(byte);
                i += 3;
                continue;
            }
        }
        out.push(bytes[i]);
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

#[cfg(test)]
pub(super) fn test_site() -> super::SiteConfig {
    super::SiteConfig {
        id: "site".into(),
        scope: "user".into(),
        site_owner: None,
        hostnames: Arc::from(["shop.test".to_string(), "www.shop.test".to_string()]),
        document_root: None,
        php_version: None,
        runtime: "php".into(),
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
        routes: Arc::from([]),
        banned_ips: Arc::from([]),
        allowed_ips: Arc::from([]),
        cache: Default::default(),
        redirects: Arc::from([]),
        guards: Default::default(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    /// The value the panel stores for `secret` with salt `00112233` and 3 rounds.
    fn stored(password: &str) -> String {
        let salt = [0x00, 0x11, 0x22, 0x33];
        format!("s256i$3$00112233${}", hex(&hash(&salt, password, 3)))
    }

    fn site(directories: Vec<ProtectedDirectory>) -> crate::edge_gateway::SiteConfig {
        let mut site = test_site();
        site.guards.protected_directories = directories.into();
        site
    }

    fn request(path: &str, credentials: Option<&str>) -> Request<Body> {
        let mut builder = Request::builder().uri(path);
        if let Some(credentials) = credentials {
            let encoded = base64::engine::general_purpose::STANDARD.encode(credentials);
            builder = builder.header(header::AUTHORIZATION, format!("Basic {encoded}"));
        }
        builder.body(Body::empty()).unwrap()
    }

    fn admin() -> ProtectedDirectory {
        ProtectedDirectory::new("/admin/", "Staff", vec![("alice".into(), stored("secret"))])
    }

    #[test]
    fn asks_for_credentials_under_the_directory_only() {
        let site = site(vec![admin()]);
        let response = challenge_for(&site, &request("/admin/index.php", None)).unwrap();
        assert_eq!(response.status(), StatusCode::UNAUTHORIZED);
        assert_eq!(response.headers()[header::WWW_AUTHENTICATE], "Basic realm=\"Staff\", charset=\"UTF-8\"");
        assert!(challenge_for(&site, &request("/admin", None)).is_some());
        assert!(challenge_for(&site, &request("/administrator", None)).is_none());
        assert!(challenge_for(&site, &request("/", None)).is_none());
    }

    #[test]
    fn the_right_password_lets_the_request_through() {
        let site = site(vec![admin()]);
        assert!(challenge_for(&site, &request("/admin/x", Some("alice:secret"))).is_none());
        assert!(challenge_for(&site, &request("/admin/x", Some("alice:wrong"))).is_some());
        assert!(challenge_for(&site, &request("/admin/x", Some("bob:secret"))).is_some());
    }

    #[test]
    fn encoding_case_and_dot_segments_do_not_get_around_it() {
        let site = site(vec![admin()]);
        for path in ["/%61dmin/x", "/ADMIN/x", "/public/../admin/x", "//admin//x"] {
            assert!(challenge_for(&site, &request(path, None)).is_some(), "{path}");
        }
    }

    #[test]
    fn certificates_still_renew() {
        let site = site(vec![ProtectedDirectory::new("/", "All", vec![("a".into(), stored("b"))])]);
        assert!(challenge_for(&site, &request("/.well-known/acme-challenge/token", None)).is_none());
        assert!(challenge_for(&site, &request("/anything", None)).is_some());
    }

    #[test]
    fn a_subfolder_with_its_own_users_wins() {
        let inner = ProtectedDirectory::new("/admin/reports", "Reports", vec![("bob".into(), stored("pw"))]);
        let site = site(vec![admin(), inner]);
        assert!(challenge_for(&site, &request("/admin/reports/q1", Some("bob:pw"))).is_none());
        assert!(challenge_for(&site, &request("/admin/reports/q1", Some("alice:secret"))).is_some());
    }

    #[test]
    fn checks_the_hash_the_panel_writes() {
        // App\Support\BasicAuthHash with salt 00112233, 3 rounds, password `secret`.
        let panel = "s256i$3$00112233$bbef54be21c1fda457fc9f302c0915d2780ed32cc8537e5410ae4d99fc0d2585";
        assert!(verify(panel, "secret"));
        assert!(!verify(panel, "Secret"));
    }

    #[test]
    fn a_malformed_hash_never_verifies() {
        for stored in ["", "s256i$0$00$00", "s256i$3$zz$00", "plain", "s256i$3$00$00$extra"] {
            assert!(!verify(stored, ""), "{stored}");
        }
    }
}
