#![allow(dead_code)]

use axum::{
    body::Body,
    http::{header, HeaderValue, StatusCode},
    response::Response,
};
use axum::http::Request;
use std::time::Duration;

use super::{
    load_static_asset, normalize_request_path, proxy_request, resolve_route, resolve_site,
    resolve_static_path, RouteAction, RuntimeSnapshot, StaticAsset, StaticFileConfig,
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
        return simple_response(StatusCode::NOT_FOUND, "unknown host");
    };
    let Some(route) = resolve_route(site, &path) else {
        return simple_response(StatusCode::NOT_FOUND, "route not found");
    };
    let site_match = site.hostnames.first().map(String::as_str).unwrap_or("");
    let route_match = route.path_prefix.as_str();

    match &route.action {
        RouteAction::Static => {
            let config = if let Some(config) = ctx.static_files.as_ref() {
                config.clone()
            } else if let Some(document_root) = site.document_root.as_ref() {
                StaticFileConfig {
                    document_root: document_root.clone(),
                    index_file: "index.html".to_string(),
                    spa_fallback: site.spa_fallback,
                    cache_ttl: snapshot.cache.ttl,
                }
            } else {
                return simple_response(StatusCode::INTERNAL_SERVER_ERROR, "static root missing");
            };

            let Some(path_on_disk) = resolve_static_path(
                &config.document_root,
                &path,
                &config.index_file,
                config.spa_fallback,
            ) else {
                return simple_response(StatusCode::NOT_FOUND, "file not found");
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
        HeaderValue::from_str(&asset.etag).unwrap_or_else(|_| HeaderValue::from_static("\"invalid\"")),
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
}
