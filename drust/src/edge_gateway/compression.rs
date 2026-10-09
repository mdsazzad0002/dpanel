//! Response compression for every listener (HTTP/1, HTTP/2, HTTP/3).
//!
//! Only complete 200 responses with a text-like body are compressed. Redirects,
//! empty bodies, partial content, upgrades, downloads and event streams pass
//! through untouched: an earlier global layer compressed phpMyAdmin's empty
//! redirects and broke its sign-on flow.

use axum::http::{Extensions, HeaderMap, StatusCode, Version, header};
use tower_http::compression::{CompressionLayer, CompressionLevel};

/// Smaller bodies gain nothing from compression.
pub(crate) const MIN_BYTES: u64 = 1024;

/// Put in a response's extensions to send it uncompressed.
#[derive(Clone, Copy, Debug)]
pub struct SkipCompression;

pub fn layer() -> CompressionLayer<fn(StatusCode, Version, &HeaderMap, &Extensions) -> bool> {
    // Level 4 is close to the best ratio for gzip and brotli at a fraction of
    // the CPU; brotli's own default (11) is far too slow per request.
    CompressionLayer::new()
        .quality(CompressionLevel::Precise(4))
        .compress_when(should_compress as fn(StatusCode, Version, &HeaderMap, &Extensions) -> bool)
}

pub(crate) fn should_compress(status: StatusCode, _: Version, headers: &HeaderMap, extensions: &Extensions) -> bool {
    if status != StatusCode::OK || extensions.get::<SkipCompression>().is_some() {
        return false;
    }
    if headers.contains_key(header::CONTENT_ENCODING) || headers.contains_key(header::CONTENT_DISPOSITION) {
        return false;
    }
    // A response that asks to be streamed as-is (nginx's convention, also
    // sent by Laravel/Symfony streamed responses) or not to be transformed.
    let value = |name| headers.get(name).and_then(|v| v.to_str().ok()).unwrap_or("").to_ascii_lowercase();
    if value("x-accel-buffering") == "no" || value(header::CACHE_CONTROL.as_str()).contains("no-transform") {
        return false;
    }
    if let Some(length) = headers.get(header::CONTENT_LENGTH).and_then(|v| v.to_str().ok()).and_then(|v| v.parse::<u64>().ok()) {
        if length < MIN_BYTES {
            return false;
        }
    }
    is_compressible(&value(header::CONTENT_TYPE.as_str()))
}

fn is_compressible(content_type: &str) -> bool {
    let mime = content_type.split(';').next().unwrap_or("").trim();
    if mime == "text/event-stream" {
        return false;
    }
    mime.starts_with("text/")
        || matches!(
            mime,
            "application/javascript"
                | "application/x-javascript"
                | "application/json"
                | "application/ld+json"
                | "application/manifest+json"
                | "application/xml"
                | "application/rss+xml"
                | "application/atom+xml"
                | "application/xhtml+xml"
                | "application/wasm"
                | "image/svg+xml"
                | "image/x-icon"
                | "font/ttf"
                | "font/otf"
                | "application/vnd.ms-fontobject"
        )
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::http::HeaderValue;

    fn headers(pairs: &[(&'static str, &'static str)]) -> HeaderMap {
        let mut map = HeaderMap::new();
        for (name, value) in pairs {
            map.insert(*name, HeaderValue::from_static(value));
        }
        map
    }

    fn check(status: StatusCode, pairs: &[(&'static str, &'static str)]) -> bool {
        should_compress(status, Version::HTTP_11, &headers(pairs), &Extensions::new())
    }

    #[test]
    fn compresses_text_assets() {
        assert!(check(StatusCode::OK, &[("content-type", "text/html; charset=utf-8")]));
        assert!(check(StatusCode::OK, &[("content-type", "application/javascript"), ("content-length", "477551")]));
        assert!(check(StatusCode::OK, &[("content-type", "image/svg+xml")]));
    }

    #[test]
    fn leaves_everything_else_alone() {
        assert!(!check(StatusCode::FOUND, &[("content-type", "text/html")]));
        assert!(!check(StatusCode::NOT_MODIFIED, &[("content-type", "text/html")]));
        assert!(!check(StatusCode::PARTIAL_CONTENT, &[("content-type", "text/css")]));
        assert!(!check(StatusCode::OK, &[("content-type", "text/html"), ("content-length", "200")]));
        assert!(!check(StatusCode::OK, &[("content-type", "image/png")]));
        assert!(!check(StatusCode::OK, &[("content-type", "application/zip")]));
        assert!(!check(StatusCode::OK, &[("content-type", "text/event-stream")]));
        assert!(!check(StatusCode::OK, &[("content-type", "text/html"), ("content-encoding", "gzip")]));
        assert!(!check(StatusCode::OK, &[("content-type", "text/csv"), ("content-disposition", "attachment")]));
        assert!(!check(StatusCode::OK, &[("content-type", "text/html"), ("x-accel-buffering", "no")]));
        assert!(!check(StatusCode::OK, &[("content-type", "text/html"), ("cache-control", "no-cache, no-transform")]));

        let mut skip = Extensions::new();
        skip.insert(SkipCompression);
        assert!(!should_compress(StatusCode::OK, Version::HTTP_11, &headers(&[("content-type", "text/html")]), &skip));
    }
}
