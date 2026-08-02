#![allow(dead_code)]

use axum::http::Request;
use axum::{
    body::Body,
    http::{HeaderValue, StatusCode, header},
    response::Response,
};
use std::path::Path;
use std::time::Duration;

use super::{
    RouteAction, RuntimeSnapshot, StaticAsset, StaticFileConfig, execute_php_front_controller,
    load_static_asset, normalize_request_path, proxy_request, resolve_route, resolve_site,
    resolve_static_path,
};

#[derive(Clone, Debug)]
pub struct DispatchContext {
    pub static_files: Option<StaticFileConfig>,
}

pub async fn dispatch(
    snapshot: &RuntimeSnapshot,
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

    let Some(site) = resolve_site(snapshot, host) else {
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
    if site.scope == "system" && is_system_phpmyadmin_path(&path) {
        let mut response = handle_system_phpmyadmin(
            request,
            &path,
            site.php_version.as_deref(),
            site.hostnames.first().map(String::as_str).unwrap_or(""),
            snapshot.cache.ttl,
        )
        .await;
        // Keep phpMyAdmin responses uncompressed. Its sign-on/logout flow
        // includes empty redirects that must not be transformed by the
        // global compression layer.
        response.headers_mut().remove(header::CONTENT_ENCODING);
        return response;
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
                    cache_ttl: snapshot.cache.ttl,
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

            // Prefer a PHP front controller for directory requests. This
            // prevents a leftover starter index.html from shadowing a real
            // application index.php in the same document root.
            if (path == "/" || path.ends_with('/'))
                && config.document_root.join("index.php").is_file()
            {
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
                return annotated_response(response, site_match, route_match);
            }

            let exact_static_path = if is_php_path(&path) {
                None
            } else {
                resolve_static_path(&config.document_root, &path, &config.index_file, false)
            };
            if let Some(path_on_disk) = exact_static_path {
                let asset = match load_static_asset(&path_on_disk) {
                    Ok(asset) => asset,
                    Err(error) => {
                        return simple_response(StatusCode::INTERNAL_SERVER_ERROR, &error);
                    }
                };
                return annotated_response(
                    static_response(asset, config.cache_ttl),
                    site_match,
                    route_match,
                );
            }

            if config.document_root.join("index.php").is_file() {
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
                return annotated_response(response, site_match, route_match);
            }

            let Some(path_on_disk) = resolve_static_path(
                &config.document_root,
                &path,
                &config.index_file,
                config.spa_fallback,
            ) else {
                if path == "/" {
                    return annotated_response(
                        site_root_error_response(site_match, &config.document_root),
                        site_match,
                        route_match,
                    );
                }
                return annotated_response(
                    not_found_response(
                        "Page not found",
                        "The site matched, but this specific file or page is missing.",
                        host,
                        &path,
                    ),
                    site_match,
                    route_match,
                );
            };

            let asset = match load_static_asset(&path_on_disk) {
                Ok(asset) => asset,
                Err(error) => return simple_response(StatusCode::INTERNAL_SERVER_ERROR, &error),
            };

            return annotated_response(
                static_response(asset, config.cache_ttl),
                site_match,
                route_match,
            );
        }
        RouteAction::Proxy(upstream) => {
            let response = proxy_request(proxy_client, upstream, request)
                .await
                .unwrap_or_else(|error| simple_response(StatusCode::BAD_GATEWAY, &error));
            return annotated_response(response, site_match, route_match);
        }
        RouteAction::Redirect { location, code } => {
            return annotated_response(redirect_response(*code, location), site_match, route_match);
        }
    }
}

fn is_system_phpmyadmin_path(path: &str) -> bool {
    path == "/phpmyadmin" || path.starts_with("/phpmyadmin/")
}

async fn handle_system_phpmyadmin(
    request: Request<Body>,
    path: &str,
    php_version: Option<&str>,
    site_match: &str,
    cache_ttl: Duration,
) -> Response {
    // System paths are intentionally fixed. The hostname is database-driven,
    // while the service path remains stable across panel domain migrations.
    let root = Path::new("/var/www/phpmyadmin");
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
                Ok(asset) => static_response(asset, cache_ttl),
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

fn static_response(asset: StaticAsset, cache_ttl: Duration) -> Response {
    let mut response = Response::new(Body::from(asset.body));
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
        HeaderValue::from_str(&format!(
            "public, max-age={}, stale-while-revalidate={}",
            cache_ttl.as_secs(),
            cache_ttl.as_secs().saturating_div(2)
        ))
        .unwrap_or_else(|_| HeaderValue::from_static("public, max-age=60")),
    );
    response
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

fn simple_response(status: StatusCode, message: &str) -> Response {
    let mut response = Response::new(Body::from(message.to_string()));
    *response.status_mut() = status;
    response
}

fn is_php_path(path: &str) -> bool {
    path.rsplit('/').next().is_some_and(|name| {
        name.rsplit_once('.')
            .is_some_and(|(_, extension)| extension.eq_ignore_ascii_case("php"))
    })
}

fn is_blocked_htaccess_path(path: &str) -> bool {
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
        "<!doctype html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"><title>{title} · Drust</title><style>\
        :root{{color-scheme:dark;--bg1:#07111f;--bg2:#0f1d33;--card:#0f172a;--card2:#111c30;--text:#e5eefb;--muted:#9fb1cb;--accent:#6ee7ff;--accent2:#8b5cf6;--border:rgba(255,255,255,.10)}}\
        *{{box-sizing:border-box}} body{{margin:0;min-height:100vh;font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,\"Segoe UI\",sans-serif;color:var(--text);background:radial-gradient(circle at top left,#17345c 0,#07111f 40%,#050b14 100%);display:grid;place-items:center;padding:24px}}\
        .wrap{{width:min(760px,100%);position:relative}} .glow{{position:absolute;inset:-80px auto auto -80px;width:180px;height:180px;border-radius:50%;background:rgba(110,231,255,.14);filter:blur(18px)}}\
        .card{{position:relative;overflow:hidden;border:1px solid var(--border);border-radius:24px;background:linear-gradient(180deg,rgba(17,28,48,.92),rgba(9,15,28,.96));box-shadow:0 24px 80px rgba(0,0,0,.42);padding:34px}}\
        .badge{{display:inline-flex;align-items:center;gap:10px;padding:8px 14px;border-radius:999px;background:rgba(110,231,255,.09);border:1px solid rgba(110,231,255,.22);color:var(--accent);font-size:13px;font-weight:700;letter-spacing:.04em;text-transform:uppercase}}\
        h1{{margin:18px 0 10px;font-size:clamp(32px,5vw,56px);line-height:1.02;letter-spacing:-.04em}} p{{margin:0;color:var(--muted);font-size:18px;line-height:1.65;max-width:62ch}}\
        .meta{{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;margin:24px 0}} .item{{padding:16px 18px;border-radius:18px;background:rgba(255,255,255,.03);border:1px solid var(--border)}}\
        .label{{display:block;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;margin-bottom:8px}} .value{{font-size:15px;word-break:break-word;color:#f8fbff}}\
        .actions{{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}} a,button{{appearance:none;border:none;cursor:pointer;text-decoration:none;font:inherit}}\
        .primary{{background:linear-gradient(135deg,var(--accent),#7dd3fc);color:#04111c;font-weight:800;padding:12px 18px;border-radius:14px}}\
        .secondary{{background:transparent;color:var(--text);border:1px solid var(--border);padding:12px 18px;border-radius:14px}}\
        .footer{{margin-top:22px;font-size:13px;color:#7f92ae}} code{{padding:.18rem .42rem;border-radius:8px;background:rgba(255,255,255,.06);color:#dbeafe}}\
        @media (max-width:640px){{.card{{padding:24px}} .meta{{grid-template-columns:1fr}} h1{{font-size:34px}} p{{font-size:16px}}}}\
        </style></head><body><main class=\"wrap\"><div class=\"glow\"></div><section class=\"card\"><div class=\"badge\">HTTP 404 · Not Found</div><h1>{title}</h1><p>{message}</p><div class=\"meta\"><div class=\"item\"><span class=\"label\">Host</span><span class=\"value\">{host_value}</span></div><div class=\"item\"><span class=\"label\">Path</span><span class=\"value\">{path_value}</span></div></div><div class=\"actions\"><a class=\"primary\" href=\"/\">Go to home</a><a class=\"secondary\" href=\"javascript:history.back()\">Go back</a></div><div class=\"footer\">If this should exist, check the site entry, route rules, and whether the gateway snapshot has been reloaded.</div></section></main></body></html>",
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
}
