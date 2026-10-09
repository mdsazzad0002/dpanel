#![allow(dead_code)]

use axum::{
    body::Body,
    http::{HeaderValue, StatusCode, header},
    response::Response,
};
use axum::{extract::ConnectInfo, http::Request};
use std::{
    fs,
    net::{IpAddr, SocketAddr},
    os::unix::fs::PermissionsExt,
    path::Path,
};

use super::{
    RouteAction, StaticAsset, StaticAssetBody, StaticFileConfig,
    ensure_node_process_running, ensure_python_process_running, execute_php_front_controller,
    forget_python_liveness, proxy_request_with_timeout, python_static_file,
    browser_cache_control, load_static_asset, normalize_request_path, proxy_request, resolve_route, resolve_static_path,
};

#[derive(Clone, Debug)]
pub struct DispatchContext {
    pub static_files: Option<StaticFileConfig>,
}

enum StaticPlan {
    Php,
    Asset(Result<StaticAsset, String>),
    Missing,
}

/// Decides how a static-route request is answered. Touches the disk, so it is
/// called from a blocking task.
fn plan_static_request(config: &StaticFileConfig, path: &str) -> StaticPlan {
    let has_front_controller = || config.document_root.join("index.php").is_file();
    // Prefer a PHP front controller for directory requests. This prevents a
    // leftover starter index.html from shadowing a real application
    // index.php in the same document root.
    if (path == "/" || path.ends_with('/')) && has_front_controller() {
        return StaticPlan::Php;
    }
    let exact_static_path = if is_php_path(path) {
        None
    } else {
        resolve_static_path(&config.document_root, path, &config.index_file, false)
    };
    if let Some(path_on_disk) = exact_static_path {
        return StaticPlan::Asset(load_static_asset(&path_on_disk));
    }
    if has_front_controller() {
        return StaticPlan::Php;
    }
    match resolve_static_path(&config.document_root, path, &config.index_file, config.spa_fallback) {
        Some(path_on_disk) => StaticPlan::Asset(load_static_asset(&path_on_disk)),
        None => StaticPlan::Missing,
    }
}

pub async fn dispatch(
    site: Option<&super::SiteConfig>,
    ctx: &DispatchContext,
    request: Request<Body>,
    proxy_client: &reqwest::Client,
) -> Response {
    let host = request
        .headers()
        .get("host")
        .and_then(|value| value.to_str().ok())
        .unwrap_or("");
    let path = normalize_request_path(request.uri().path());

    if path == "/__dpanel/brand-logo.png" {
        return brand_logo_response();
    }
    if path == "/__dpanel/favicon.ico" {
        return favicon_response();
    }
    if path == "/installer.sh" {
        return redirect_response(
            302,
            "https://raw.githubusercontent.com/mdsazzad0002/dpanel/main/installer.sh",
        );
    }

    let Some(site) = site else {
        if let Some(response) = shared_acme_challenge_response(&path) {
            return annotated_response(response, "", "acme-challenge");
        }
        return annotated_response(
            not_found_response(
                "Site not found",
                "We could not find a site that matches this domain.",
                host,
                &path,
            ),
            "",
            "",
        );
    };
    let client_ips = request_client_ips(&request);
    let explicitly_allowed = client_ips.iter().any(|ip| site.allowed_ips.contains(ip));
    let explicitly_banned = client_ips.iter().any(|ip| site.banned_ips.contains(ip));
    if (!site.allowed_ips.is_empty() && !explicitly_allowed)
        || (explicitly_banned && !explicitly_allowed)
    {
        return annotated_response(
            simple_response(StatusCode::FORBIDDEN, "IP address blocked for this website"),
            site.hostnames.first().map(String::as_str).unwrap_or(""),
            "",
        );
    }
    if site.scope == "system" && is_system_phpmyadmin_path(&path) {
        let mut response = handle_system_phpmyadmin(
            request,
            &path,
            site.php_version.as_deref(),
            site.hostnames.first().map(String::as_str).unwrap_or(""),
        )
        .await;
        // Keep phpMyAdmin responses uncompressed. Its sign-on/logout flow
        // includes empty redirects that must not be transformed by the
        // global compression layer.
        response.headers_mut().remove(header::CONTENT_ENCODING);
        response.extensions_mut().insert(super::compression::SkipCompression);
        return response;
    }
    if site.scope == "system" && is_system_pgadmin_path(&path) {
        return handle_system_pgadmin(
            request,
            &path,
            proxy_client,
            site.hostnames.first().map(String::as_str).unwrap_or(""),
        )
        .await;
    }
    // Certbot's webroot authenticator writes its token to the site's root
    // path regardless of runtime. Node/Python/Docker sites route "/" through a
    // reverse proxy, so serve this one path straight off disk or SSL
    // issuance breaks.
    if (site.runtime == "node" || site.runtime == "python" || site.runtime == "docker")
        && path.starts_with("/.well-known/acme-challenge/")
    {
        if let Some(document_root) = site.document_root.as_ref() {
            if let Some(path_on_disk) =
                resolve_static_path(document_root, &path, "index.html", false)
            {
                if let Ok(asset) = load_static_asset(&path_on_disk) {
                    return annotated_response(
                        static_response(asset, request.headers()).await,
                        site.hostnames.first().map(String::as_str).unwrap_or(""),
                        "acme-challenge",
                    );
                }
            }
        }
    }
    let Some(route) = resolve_route(site, &path) else {
        return annotated_response(
            not_found_response(
                "Route not found",
                "The site exists, but this request path does not match any configured route.",
                host,
                &path,
            ),
            site.hostnames.first().map(String::as_str).unwrap_or(""),
            "",
        );
    };
    let site_match = site.hostnames.first().map(String::as_str).unwrap_or("");
    let route_match = route.path_prefix.as_str();

    match &route.action {
        RouteAction::Static => {
            let config = if let Some(document_root) = site.document_root.as_ref() {
                StaticFileConfig {
                    document_root: document_root.clone(),
                    index_file: "index.html".to_string(),
                    spa_fallback: site.spa_fallback,
                }
            } else if let Some(config) = ctx.static_files.as_ref() {
                config.clone()
            } else {
                return simple_response(StatusCode::INTERNAL_SERVER_ERROR, "static root missing");
            };

            if is_blocked_htaccess_path(&path) {
                return annotated_response(
                    simple_response(StatusCode::FORBIDDEN, "forbidden"),
                    site_match,
                    route_match,
                );
            }

            // Disk lookups block, so they run off the async workers: on a slow
            // or busy disk they would otherwise stall every site's requests.
            let plan = {
                let config = config.clone();
                let path = path.clone();
                tokio::task::spawn_blocking(move || plan_static_request(&config, &path)).await
            };
            match plan {
                Ok(StaticPlan::Php) => {
                    let response = execute_php_front_controller(
                        request,
                        &config.document_root,
                        site.php_version.as_deref(),
                        user_pool_owner(site.scope.as_str(), site.site_owner.as_deref()),
                    )
                    .await
                    .unwrap_or_else(|error| {
                        simple_response(
                            StatusCode::BAD_GATEWAY,
                            &format!("PHP application unavailable: {error}"),
                        )
                    });
                    annotated_response(response, site_match, route_match)
                }
                Ok(StaticPlan::Asset(Ok(asset))) => annotated_response(
                    static_response(asset, request.headers()).await,
                    site_match,
                    route_match,
                ),
                Ok(StaticPlan::Asset(Err(error))) => {
                    simple_response(StatusCode::INTERNAL_SERVER_ERROR, &error)
                }
                Ok(StaticPlan::Missing) if path == "/" => annotated_response(
                    site_root_error_response(site_match, &config.document_root),
                    site_match,
                    route_match,
                ),
                Ok(StaticPlan::Missing) => annotated_response(
                    not_found_response(
                        "Page not found",
                        "The site matched, but this specific file or page is missing.",
                        host,
                        &path,
                    ),
                    site_match,
                    route_match,
                ),
                Err(error) => simple_response(
                    StatusCode::INTERNAL_SERVER_ERROR,
                    &format!("static file worker failed: {error}"),
                ),
            }
        }
        RouteAction::Proxy(upstream) => {
            if site.runtime == "node" {
                if let (Some(owner), Some(project_root)) =
                    (site.site_owner.as_deref(), site.project_root.as_deref())
                {
                    if let Err(error) = ensure_node_process_running(
                        &site.id,
                        owner,
                        project_root,
                        site.node_entry_file.as_deref(),
                        site.node_start_command.as_deref(),
                        site.node_version.as_deref(),
                        match upstream {
                            super::UpstreamConfig::Http(addr) => addr.port(),
                            super::UpstreamConfig::Unix(_) => 0,
                        },
                    )
                    .await
                    {
                        return annotated_response(
                            simple_response(
                                StatusCode::BAD_GATEWAY,
                                &format!("Node application unavailable: {error}"),
                            ),
                            site_match,
                            route_match,
                        );
                    }
                }
            } else if site.runtime == "python" {
                if let (Some(owner), Some(project_root)) =
                    (site.site_owner.as_deref(), site.project_root.as_deref())
                {
                    // Static assets come straight off disk; a gunicorn worker
                    // is far too expensive to spend on a CSS file.
                    let static_asset = {
                        let project_root = project_root.to_path_buf();
                        let entry_file = site.python_entry_file.clone();
                        let path = path.clone();
                        tokio::task::spawn_blocking(move || {
                            python_static_file(&project_root, entry_file.as_deref(), &path)
                                .and_then(|file| load_static_asset(&file).ok())
                        })
                        .await
                        .ok()
                        .flatten()
                    };
                    if let Some(asset) = static_asset {
                        return annotated_response(
                            static_response(asset, request.headers()).await,
                            site_match,
                            route_match,
                        );
                    }
                    if let Err(error) = ensure_python_process_running(
                        &site.id,
                        owner,
                        project_root,
                        site.python_entry_file.as_deref(),
                        site.python_start_command.as_deref(),
                        site.python_version.as_deref(),
                        site.python_run,
                        match upstream {
                            super::UpstreamConfig::Http(addr) => addr.port(),
                            super::UpstreamConfig::Unix(_) => 0,
                        },
                    )
                    .await
                    {
                        return annotated_response(
                            simple_response(
                                StatusCode::BAD_GATEWAY,
                                &format!("Python application unavailable: {error}"),
                            ),
                            site_match,
                            route_match,
                        );
                    }
                }
            }
            let timeout = if site.runtime == "python" {
                site.python_run.proxy_timeout()
            } else {
                super::ProxyConfig::default().request_timeout
            };
            let response = proxy_request_with_timeout(proxy_client, upstream, request, timeout)
                .await
                .unwrap_or_else(|error| {
                    // The app may have died since it was last seen up; make
                    // the next request probe and restart it if needed.
                    if site.runtime == "python" || site.runtime == "node" {
                        if let super::UpstreamConfig::Http(addr) = upstream {
                            forget_python_liveness(addr.port());
                        }
                    }
                    if site.runtime == "docker" {
                        return simple_response(
                            StatusCode::BAD_GATEWAY,
                            &format!("Docker application unavailable: the container is stopped or not answering yet ({error})"),
                        );
                    }
                    simple_response(StatusCode::BAD_GATEWAY, &error)
                });
            return annotated_response(response, site_match, route_match);
        }
        RouteAction::Redirect { location, code } => {
            return annotated_response(redirect_response(*code, location), site_match, route_match);
        }
    }
}

fn request_client_ips(request: &Request<Body>) -> Vec<IpAddr> {
    let mut ips = Vec::new();
    if let Some(peer) = request.extensions().get::<ConnectInfo<SocketAddr>>() {
        ips.push(peer.0.ip());
    }
    if let Some(peer) = request.extensions().get::<SocketAddr>() {
        ips.push(peer.ip());
    }
    for header in ["cf-connecting-ip", "x-real-ip"] {
        if let Some(ip) = request
            .headers()
            .get(header)
            .and_then(|value| value.to_str().ok())
            .and_then(|value| value.trim().parse().ok())
        {
            ips.push(ip);
        }
    }
    if let Some(ip) = request
        .headers()
        .get("x-forwarded-for")
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.split(',').next())
        .and_then(|value| value.trim().parse().ok())
    {
        ips.push(ip);
    }
    ips.sort_unstable();
    ips.dedup();
    ips
}

fn is_system_phpmyadmin_path(path: &str) -> bool {
    path == "/phpmyadmin" || path.starts_with("/phpmyadmin/")
}

async fn handle_system_phpmyadmin(
    request: Request<Body>,
    path: &str,
    php_version: Option<&str>,
    site_match: &str,
) -> Response {
    // System paths are intentionally fixed. The hostname is database-driven,
    // while the service path remains stable across panel domain migrations.
    let root = Path::new("/var/www/phpmyadmin");
    if let Err(error) = repair_phpmyadmin_sensitive_permissions(root) {
        return simple_response(
            StatusCode::INTERNAL_SERVER_ERROR,
            &format!("phpMyAdmin configuration permission repair failed: {error}"),
        );
    }
    let relative = path.strip_prefix("/phpmyadmin").unwrap_or("/");
    let relative = if relative.is_empty() { "/" } else { relative };
    let local_path = normalize_request_path(relative);

    // phpMyAdmin's logout route normally emits a compressed 302 to the
    // sign-on helper. Serve the helper directly to avoid browser-specific
    // corrupt-content handling on the first logout request.
    if local_path == "/index.php" && request.uri().query() == Some("route=/logout") {
        let response = simple_response(
            StatusCode::OK,
            r#"<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>phpMyAdmin Logged Out</title></head><body><p>Logged out from phpMyAdmin.</p><p>Start login again from ServerPanel.</p><p>You will be redirected to the panel in <span id="countdown">10</span> seconds.</p><script>let seconds=10;const countdown=document.getElementById('countdown');const timer=setInterval(()=>{seconds-=1;countdown.textContent=String(seconds);if(seconds<=0){clearInterval(timer);window.location.href='/';}},1000);</script></body></html>"#,
        );
        return annotated_response(response, site_match, "/phpmyadmin");
    }

    // Directory requests must execute index.php, never expose it as a
    // downloadable static asset.
    if (local_path == "/" || local_path.ends_with('/')) {
        if root.join("index.php").is_file() {
            let (mut parts, body) = request.into_parts();
            let query = parts
                .uri
                .query()
                .map(|value| format!("?{value}"))
                .unwrap_or_default();
            let Ok(uri) = format!("/{query}").parse() else {
                return simple_response(StatusCode::BAD_REQUEST, "Invalid phpMyAdmin path");
            };
            parts.uri = uri;
            let response = execute_php_front_controller(
                Request::from_parts(parts, body),
                root,
                php_version,
                None,
            )
            .await
            .unwrap_or_else(|error| {
                simple_response(
                    StatusCode::BAD_GATEWAY,
                    &format!("phpMyAdmin unavailable: {error}"),
                )
            });
            return annotated_response(response, site_match, "/phpmyadmin");
        }
    }
    let path_on_disk = if is_php_path(&local_path) {
        None
    } else {
        resolve_static_path(root, &local_path, "index.php", false)
    };

    if let Some(path_on_disk) = path_on_disk {
        return annotated_response(
            match load_static_asset(&path_on_disk) {
                Ok(asset) => static_response(asset, request.headers()).await,
                Err(error) => simple_response(StatusCode::INTERNAL_SERVER_ERROR, &error),
            },
            site_match,
            "/phpmyadmin",
        );
    }

    let (mut parts, body) = request.into_parts();
    let query = parts
        .uri
        .query()
        .map(|value| format!("?{value}"))
        .unwrap_or_default();
    let Ok(uri) = format!("{local_path}{query}").parse() else {
        return simple_response(StatusCode::BAD_REQUEST, "Invalid phpMyAdmin path");
    };
    parts.uri = uri;
    let response =
        execute_php_front_controller(Request::from_parts(parts, body), root, php_version, None)
            .await
            .unwrap_or_else(|error| {
                simple_response(
                    StatusCode::BAD_GATEWAY,
                    &format!("phpMyAdmin unavailable: {error}"),
                )
            });
    annotated_response(response, site_match, "/phpmyadmin")
}

// pgAdmin runs as its own gunicorn service (dpanel-pgadmin, installed by the
// dscript postgresql module) with SCRIPT_NAME=/pgadmin4, so the full request
// path is forwarded unchanged.
const PGADMIN_PATH: &str = "/pgadmin4";
const PGADMIN_UPSTREAM: ([u8; 4], u16) = ([127, 0, 0, 1], 5050);

fn is_system_pgadmin_path(path: &str) -> bool {
    path == PGADMIN_PATH || path.starts_with("/pgadmin4/")
}

async fn handle_system_pgadmin(
    mut request: Request<Body>,
    path: &str,
    proxy_client: &reqwest::Client,
    site_match: &str,
) -> Response {
    // pgAdmin logs in whoever these headers name (see crate::pgadmin_sso), so a
    // browser must never be able to send them. Only the SSO request below adds
    // them, after checking a one-time ticket.
    strip_pgadmin_identity_headers(request.headers_mut());

    // Check the raw path: `path` is normalized, which strips the trailing
    // slash, so "/pgadmin4/" would otherwise redirect to itself forever.
    if path == PGADMIN_PATH && request.uri().path() == PGADMIN_PATH {
        return annotated_response(
            redirect_response(301, "/pgadmin4/"),
            site_match,
            PGADMIN_PATH,
        );
    }
    if path == crate::pgadmin_sso::SSO_PATH {
        return annotated_response(
            pgadmin_sso_login(request, proxy_client).await,
            site_match,
            PGADMIN_PATH,
        );
    }
    if is_anonymous_pgadmin_entry(&request, path) {
        return annotated_response(
            pgadmin_notice_response(
                StatusCode::OK,
                "Open pgAdmin from dPanel",
                "pgAdmin signs you in through dPanel. Use <strong>DB Login</strong> on a PostgreSQL database, or <strong>Open pgAdmin</strong> on Database Management &rarr; PostgreSQL.",
            ),
            site_match,
            PGADMIN_PATH,
        );
    }
    let upstream = super::UpstreamConfig::Http(SocketAddr::from(PGADMIN_UPSTREAM));
    let response = match proxy_request(proxy_client, &upstream, request).await {
        Ok(response) => response,
        // The service is off by default; a refused connection means an admin
        // has not turned it on yet.
        Err(_) => pgadmin_unavailable_response(),
    };
    annotated_response(response, site_match, PGADMIN_PATH)
}

/// WSGI maps `X-Foo-Bar` and `X_Foo_Bar` to the same variable, so match both.
fn strip_pgadmin_identity_headers(headers: &mut axum::http::HeaderMap) {
    let blocked: Vec<axum::http::HeaderName> = headers
        .keys()
        .filter(|name| {
            let normalized = name.as_str().replace('_', "-");
            normalized == crate::pgadmin_sso::USER_HEADER
                || normalized == crate::pgadmin_sso::SECRET_HEADER
        })
        .cloned()
        .collect();
    for name in blocked {
        headers.remove(name);
    }
}

/// pgAdmin's own login page can't sign anyone in (webserver auth only), so a
/// visitor without a session gets a pointer back to dPanel instead.
fn is_anonymous_pgadmin_entry(request: &Request<Body>, path: &str) -> bool {
    if request.method() != axum::http::Method::GET {
        return false;
    }
    if path == "/pgadmin4/login" {
        return true;
    }
    let has_session = request
        .headers()
        .get_all(header::COOKIE)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .flat_map(|value| value.split(';'))
        .any(|pair| pair.trim_start().starts_with("pga4_session="));
    path == PGADMIN_PATH && !has_session
}

/// Turns a one-time dPanel ticket into a pgAdmin login: forwards a fresh
/// (cookie-less) login request carrying the identity and shared secret, and
/// hands pgAdmin's session cookie and redirect back to the browser.
async fn pgadmin_sso_login(request: Request<Body>, proxy_client: &reqwest::Client) -> Response {
    let ticket = request
        .uri()
        .query()
        .unwrap_or("")
        .split('&')
        .find_map(|pair| pair.strip_prefix("ticket="))
        .unwrap_or("");
    let Some(identity) = crate::pgadmin_sso::consume(ticket) else {
        return pgadmin_notice_response(
            StatusCode::FORBIDDEN,
            "pgAdmin link expired",
            "This sign-in link has already been used or is older than a minute. Open pgAdmin again from dPanel.",
        );
    };
    let Some(secret) = crate::pgadmin_sso::shared_secret() else {
        return pgadmin_notice_response(
            StatusCode::SERVICE_UNAVAILABLE,
            "pgAdmin sign-on is not configured",
            "Run <code>sudo dpanel postgresql configure</code> on the server.",
        );
    };
    let (Ok(identity), Ok(secret)) = (
        HeaderValue::from_str(&identity),
        HeaderValue::from_str(&secret),
    ) else {
        return pgadmin_unavailable_response();
    };

    let mut login = Request::builder()
        .method(axum::http::Method::GET)
        .uri("/pgadmin4/login")
        .body(Body::empty())
        .expect("static pgAdmin login request is valid");
    for (name, value) in request.headers() {
        // Drop the old pgAdmin session so the login starts clean.
        if name != header::COOKIE {
            login.headers_mut().append(name.clone(), value.clone());
        }
    }
    login
        .headers_mut()
        .insert(crate::pgadmin_sso::USER_HEADER, identity);
    login
        .headers_mut()
        .insert(crate::pgadmin_sso::SECRET_HEADER, secret);

    let upstream = super::UpstreamConfig::Http(SocketAddr::from(PGADMIN_UPSTREAM));
    let mut response = match proxy_request(proxy_client, &upstream, login).await {
        Ok(response) => response,
        Err(_) => return pgadmin_unavailable_response(),
    };
    response
        .headers_mut()
        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response
}

fn pgadmin_notice_response(status: StatusCode, title: &str, message: &str) -> Response {
    let mut response = simple_response(
        status,
        &format!(
            r#"<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{title}</title></head><body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem"><h1>{title}</h1><p>{message}</p></body></html>"#
        ),
    );
    response.headers_mut().insert(
        header::CONTENT_TYPE,
        HeaderValue::from_static("text/html; charset=utf-8"),
    );
    response
        .headers_mut()
        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response
}

fn pgadmin_unavailable_response() -> Response {
    let mut response = simple_response(
        StatusCode::SERVICE_UNAVAILABLE,
        r#"<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>pgAdmin is turned off</title></head><body style="font-family:system-ui,sans-serif;max-width:40rem;margin:4rem auto;padding:0 1rem"><h1>pgAdmin is turned off</h1><p>PostgreSQL and pgAdmin are installed but not running.</p><p>An admin can turn them on from <strong>dPanel &rarr; Database Management &rarr; PostgreSQL</strong>, or on the server with <code>sudo dpanel postgresql start</code>.</p></body></html>"#,
    );
    response.headers_mut().insert(
        header::CONTENT_TYPE,
        HeaderValue::from_static("text/html; charset=utf-8"),
    );
    response
        .headers_mut()
        .insert(header::CACHE_CONTROL, HeaderValue::from_static("no-store"));
    response
}

fn repair_phpmyadmin_sensitive_permissions(root: &Path) -> Result<(), String> {
    for (name, mode) in [("config.inc.php", 0o640), ("phpmyadminsignin.php", 0o640)] {
        let path = root.join(name);
        let metadata =
            fs::metadata(&path).map_err(|error| format!("{}: {error}", path.display()))?;
        let current_mode = metadata.permissions().mode() & 0o777;
        if current_mode == mode {
            continue;
        }

        crate::app::run_status("chown", &["root:www-data", path.to_string_lossy().as_ref()])?;
        fs::set_permissions(&path, fs::Permissions::from_mode(mode))
            .map_err(|error| format!("failed to chmod {}: {error}", path.display()))?;
        crate::app::info(&format!(
            "repaired phpMyAdmin permission: {} {:o} -> {:o}",
            path.display(),
            current_mode,
            mode
        ));
    }
    Ok(())
}

fn user_pool_owner<'a>(scope: &str, site_owner: Option<&'a str>) -> Option<&'a str> {
    if scope.eq_ignore_ascii_case("system") {
        None
    } else {
        site_owner
    }
}

fn annotated_response(mut response: Response, site_match: &str, route_match: &str) -> Response {
    if let Ok(value) = HeaderValue::from_str(site_match) {
        response.headers_mut().insert("x-site-match", value);
    }
    if let Ok(value) = HeaderValue::from_str(route_match) {
        response.headers_mut().insert("x-route-match", value);
    }
    response
}

async fn static_response(
    asset: StaticAsset,
    request_headers: &axum::http::HeaderMap,
) -> Response {
    if request_headers
        .get(header::IF_NONE_MATCH)
        .and_then(|v| v.to_str().ok())
        .is_some_and(|v| v.split(',').any(|tag| tag.trim() == asset.etag))
    {
        let mut response = Response::new(Body::empty());
        *response.status_mut() = StatusCode::NOT_MODIFIED;
        if let Ok(value) = HeaderValue::from_str(&asset.etag) {
            response.headers_mut().insert(header::ETAG, value);
        }
        response.extensions_mut().insert(super::edge_cache::StaticFileResponse);
        return response;
    }
    let memory_body = match &asset.body {
        StaticAssetBody::Memory(body) => Some(body.clone()),
        StaticAssetBody::Stream(_) => None,
    };
    let body = match asset.body {
        StaticAssetBody::Memory(body) => Body::from(body),
        StaticAssetBody::Stream(path) => match tokio::fs::File::open(path).await {
            Ok(file) => Body::from_stream(tokio_util::io::ReaderStream::new(file)),
            Err(_) => return simple_response(StatusCode::NOT_FOUND, "static file unavailable"),
        },
    };
    let mut response = Response::new(body);
    *response.status_mut() = StatusCode::OK;
    let headers = response.headers_mut();
    headers.insert(
        header::CONTENT_TYPE,
        HeaderValue::from_str(&asset.content_type)
            .unwrap_or_else(|_| HeaderValue::from_static("application/octet-stream")),
    );
    headers.insert(
        header::ETAG,
        HeaderValue::from_str(&asset.etag)
            .unwrap_or_else(|_| HeaderValue::from_static("\"invalid\"")),
    );
    headers.insert(
        header::LAST_MODIFIED,
        HeaderValue::from_str(&format_http_date(asset.last_modified))
            .unwrap_or_else(|_| HeaderValue::from_static("Thu, 01 Jan 1970 00:00:00 GMT")),
    );
    headers.insert(header::ACCEPT_RANGES, HeaderValue::from_static("bytes"));
    headers.insert(
        header::CACHE_CONTROL,
        HeaderValue::from_static(browser_cache_control(&asset.path, &asset.content_type)),
    );
    response.extensions_mut().insert(super::edge_cache::StaticFileResponse);
    match memory_body {
        Some(body) => super::precompress::encode(response, request_headers, &body, &asset.variants).await,
        None => response,
    }
}

fn redirect_response(code: u16, location: &str) -> Response {
    let mut response = Response::new(Body::empty());
    *response.status_mut() = StatusCode::from_u16(code).unwrap_or(StatusCode::FOUND);
    if let Ok(value) = HeaderValue::from_str(location) {
        response
            .headers_mut()
            .insert(axum::http::header::LOCATION, value);
    }
    response
}

pub fn snapshot_reload_response(version: u64) -> Response {
    let mut response = Response::new(Body::from(format!("snapshot reloaded: {version}")));
    *response.status_mut() = StatusCode::OK;
    response
}

pub fn upstream_health_response(body: String) -> Response {
    let mut response = Response::new(Body::from(body));
    *response.status_mut() = StatusCode::OK;
    response
}

/// Hostnames that are not websites (the mail hostname) still need HTTP-01
/// certificates, so certbot writes their tokens to this one shared webroot.
const SHARED_ACME_ROOT: &str = "/var/lib/dpanel/acme";

fn shared_acme_challenge_response(path: &str) -> Option<Response> {
    let token = path.strip_prefix("/.well-known/acme-challenge/")?;
    if token.is_empty()
        || !token
            .bytes()
            .all(|byte| byte.is_ascii_alphanumeric() || byte == b'-' || byte == b'_')
    {
        return None;
    }
    let body = fs::read(
        Path::new(SHARED_ACME_ROOT)
            .join(".well-known/acme-challenge")
            .join(token),
    )
    .ok()?;
    let mut response = Response::new(Body::from(body));
    response
        .headers_mut()
        .insert(header::CONTENT_TYPE, HeaderValue::from_static("text/plain"));
    Some(response)
}

fn simple_response(status: StatusCode, message: &str) -> Response {
    let mut response = Response::new(Body::from(message.to_string()));
    *response.status_mut() = status;
    response
}

fn brand_logo_response() -> Response {
    let mut response = Response::new(Body::from(
        include_bytes!("../../../dpanel/public/dpanel_logo.png").as_slice(),
    ));
    *response.status_mut() = StatusCode::OK;
    response
        .headers_mut()
        .insert(header::CONTENT_TYPE, HeaderValue::from_static("image/png"));
    response.headers_mut().insert(
        header::CACHE_CONTROL,
        HeaderValue::from_static("public, max-age=86400"),
    );
    response
}

fn favicon_response() -> Response {
    let mut response = Response::new(Body::from(
        include_bytes!("../../../dpanel/public/favicon.ico").as_slice(),
    ));
    *response.status_mut() = StatusCode::OK;
    response.headers_mut().insert(
        header::CONTENT_TYPE,
        HeaderValue::from_static("image/x-icon"),
    );
    response.headers_mut().insert(
        header::CACHE_CONTROL,
        HeaderValue::from_static("public, max-age=86400"),
    );
    response
}

fn is_php_path(path: &str) -> bool {
    path.rsplit('/').next().is_some_and(|name| {
        name.rsplit_once('.')
            .is_some_and(|(_, extension)| extension.eq_ignore_ascii_case("php"))
    })
}

fn is_blocked_htaccess_path(path: &str) -> bool {
    // Certbot's webroot authenticator must expose its temporary token through
    // this standardized dot-directory. Keep every other hidden path blocked.
    if path.starts_with("/.well-known/acme-challenge/") {
        return false;
    }

    if path
        .split('/')
        .filter(|segment| !segment.is_empty())
        .any(|segment| segment.starts_with('.'))
    {
        return true;
    }
    let extension = path
        .rsplit('/')
        .next()
        .and_then(|name| name.rsplit_once('.'))
        .map(|(_, extension)| extension.to_ascii_lowercase());
    matches!(
        extension.as_deref(),
        Some(
            "sh" | "bash"
                | "env"
                | "ini"
                | "conf"
                | "sql"
                | "sqlite"
                | "yml"
                | "yaml"
                | "json"
                | "md"
        )
    )
}

fn not_found_response(title: &str, message: &str, host: &str, path: &str) -> Response {
    let body = format!(
        "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><link rel=\"icon\" type=\"image/x-icon\" href=\"/__dpanel/favicon.ico\"><link rel=\"shortcut icon\" href=\"/__dpanel/favicon.ico\"><title>{title} · dPanel</title><style>\
        :root{{color-scheme:dark;--text:#f8fafc;--muted:#cbd5e1;--accent:#fca5a5;--accent-strong:#fb7185;--border:rgba(255,255,255,.10)}}\
        *{{box-sizing:border-box}} body{{margin:0;min-height:100vh;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;color:var(--text);background-color:#03060f;background-image:linear-gradient(rgba(255,255,255,.03) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.03) 1px,transparent 1px),radial-gradient(circle at top left,rgba(248,113,113,.13),transparent 35%),radial-gradient(circle at bottom right,rgba(251,113,133,.09),transparent 30%),linear-gradient(180deg,#040814 0%,#02050a 100%);background-size:72px 72px,72px 72px,100% 100%,100% 100%,100% 100%;display:grid;place-items:center;padding:24px}}\
        .wrap{{width:min(896px,100%);position:relative}} .glow{{position:absolute;inset:-70px auto auto -70px;width:220px;height:220px;border-radius:50%;background:rgba(248,113,113,.16);filter:blur(42px)}}\
        .card{{position:relative;overflow:hidden;border:1px solid var(--border);border-radius:32px;background:rgba(255,255,255,.04);box-shadow:0 30px 100px rgba(0,0,0,.45);backdrop-filter:blur(20px);padding:clamp(28px,6vw,56px)}}\
        .brand-logo{{display:none}}\
        .badge{{display:inline-flex;align-items:center;gap:10px;padding:8px 14px;border-radius:999px;background:rgba(248,113,113,.10);border:1px solid rgba(252,165,165,.28);color:var(--accent);font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;font-weight:700;letter-spacing:.16em;text-transform:uppercase}}\
        h1{{margin:18px 0 10px;font-size:clamp(32px,5vw,52px);line-height:1.04;letter-spacing:-.04em}} p{{margin:0;color:var(--muted);font-size:16px;line-height:1.65;max-width:62ch}}\
        .meta{{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:28px 0}} .item{{padding:16px 18px;border-radius:16px;background:rgba(0,0,0,.22);border:1px solid var(--border)}}\
        .label{{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.14em;color:#94a3b8;margin-bottom:8px}} .value{{font-size:14px;word-break:break-word;color:#e2e8f0}}\
        .actions{{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}} a,button{{appearance:none;border:none;cursor:pointer;text-decoration:none;font:inherit}}\
        .primary{{background:linear-gradient(135deg,var(--accent),var(--accent-strong));color:#2b080b;font-weight:800;padding:12px 18px;border-radius:14px;box-shadow:0 10px 30px rgba(248,113,113,.16)}}\
        .secondary{{background:transparent;color:#fecaca;border:1px solid rgba(252,165,165,.24);padding:12px 18px;border-radius:14px}}\
        .primary:hover{{filter:brightness(1.06)}} .secondary:hover{{background:rgba(248,113,113,.08)}} .footer{{margin-top:22px;font-size:13px;color:#94a3b8}} code{{padding:.18rem .42rem;border-radius:8px;background:rgba(255,255,255,.06);color:#fecaca}}\
        @media (min-width:1024px){{.brand-logo{{display:block;position:absolute;right:46px;width:270px;height:auto;object-fit:contain;opacity:.92}} .card{{padding-top:72px}}}}\
        @media (max-width:640px){{.card{{padding:24px}} .meta{{grid-template-columns:1fr}} h1{{font-size:34px}} p{{font-size:16px}}}}\
        </style></head><body><main class=\"wrap\"><div class=\"glow\"></div><section class=\"card\"><img class=\"brand-logo\" src=\"/__dpanel/brand-logo.png\" alt=\"dPanel\"><div class=\"badge\">HTTP 404 · Not Found</div><h1>{title}</h1><p>{message}</p><div class=\"meta\"><div class=\"item\"><span class=\"label\">Host</span><span class=\"value\">{host_value}</span></div><div class=\"item\"><span class=\"label\">Path</span><span class=\"value\">{path_value}</span></div></div><div class=\"actions\"><a class=\"primary\" href=\"/\">Go to home</a><a class=\"secondary\" href=\"javascript:history.back()\">Go back</a></div><div class=\"footer\">If this should exist, check the site entry, route rules, and whether the gateway snapshot has been reloaded.</div></section></main></body></html>",
        title = escape_html(title),
        message = escape_html(message),
        host_value = escape_html(if host.is_empty() { "-" } else { host }),
        path_value = escape_html(if path.is_empty() { "/" } else { path }),
    );
    let mut response = Response::new(Body::from(body));
    *response.status_mut() = StatusCode::NOT_FOUND;
    response.headers_mut().insert(
        header::CONTENT_TYPE,
        HeaderValue::from_static("text/html; charset=utf-8"),
    );
    response
}

fn escape_html(input: &str) -> String {
    input
        .replace('&', "&amp;")
        .replace('<', "&lt;")
        .replace('>', "&gt;")
        .replace('"', "&quot;")
        .replace('\'', "&#39;")
}

fn site_root_error_response(site_domain: &str, document_root: &std::path::Path) -> Response {
    let body = format!(
        "<!doctype html><html><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>Website root not ready</title></head><body><main><h1>Website root not ready</h1><p>The domain is configured, but no <code>index.html</code> or <code>index.php</code> front controller was found in its document root.</p><dl><dt>Domain</dt><dd>{site_domain}</dd><dt>Document root</dt><dd>{document_root}</dd></dl></main></body></html>",
        site_domain = escape_html(site_domain),
        document_root = escape_html(&document_root.display().to_string()),
    );
    let mut response = Response::new(Body::from(body));
    *response.status_mut() = StatusCode::INTERNAL_SERVER_ERROR;
    response.headers_mut().insert(
        header::CONTENT_TYPE,
        HeaderValue::from_static("text/html; charset=utf-8"),
    );
    response
}

fn format_http_date(_value: std::time::SystemTime) -> String {
    "Thu, 01 Jan 1970 00:00:00 GMT".to_string()
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Arc;
    use std::time::Duration;

    #[test]
    fn build_simple_response() {
        let response = simple_response(StatusCode::NOT_FOUND, "missing");
        assert_eq!(response.status(), StatusCode::NOT_FOUND);
    }

    #[test]
    fn system_sites_never_select_a_user_pool() {
        assert_eq!(user_pool_owner("system", Some("dpanel_localhost")), None);
        assert_eq!(user_pool_owner("SYSTEM", Some("root")), None);
        assert_eq!(
            user_pool_owner("user", Some("account_user")),
            Some("account_user")
        );
    }

    #[test]
    fn permits_acme_challenges_without_exposing_other_hidden_paths() {
        assert!(!is_blocked_htaccess_path(
            "/.well-known/acme-challenge/example-token"
        ));
        assert!(is_blocked_htaccess_path("/.env"));
        assert!(is_blocked_htaccess_path("/.well-known/private.txt"));
        assert!(is_blocked_htaccess_path("/.well-known/acme-challenge"));
    }

    #[test]
    fn browsers_cannot_send_pgadmin_identity_headers() {
        let mut headers = axum::http::HeaderMap::new();
        headers.insert("x-dpanel-pgadmin-user", HeaderValue::from_static("dpanel-admin"));
        headers.insert("x_dpanel_pgadmin_user", HeaderValue::from_static("dpanel-admin"));
        headers.insert("x-pgadmin-webserver-secret", HeaderValue::from_static("guess"));
        headers.insert("x_pgadmin_webserver_secret", HeaderValue::from_static("guess"));
        headers.insert("accept", HeaderValue::from_static("text/html"));
        strip_pgadmin_identity_headers(&mut headers);
        assert_eq!(headers.len(), 1);
        assert!(headers.contains_key("accept"));
    }

    #[test]
    fn only_sessionless_pgadmin_entry_pages_are_intercepted() {
        let request = |uri: &str, cookie: Option<&str>| {
            let mut builder = Request::builder().method("GET").uri(uri);
            if let Some(cookie) = cookie {
                builder = builder.header(header::COOKIE, cookie);
            }
            builder.body(Body::empty()).unwrap()
        };
        assert!(is_anonymous_pgadmin_entry(&request("/pgadmin4/", None), "/pgadmin4"));
        assert!(is_anonymous_pgadmin_entry(&request("/pgadmin4/login", Some("pga4_session=x")), "/pgadmin4/login"));
        assert!(!is_anonymous_pgadmin_entry(
            &request("/pgadmin4/", Some("PGADMIN_LANGUAGE=en; pga4_session=abc")),
            "/pgadmin4"
        ));
        assert!(!is_anonymous_pgadmin_entry(&request("/pgadmin4/browser/", None), "/pgadmin4/browser"));
    }
}
