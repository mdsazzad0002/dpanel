//! X-Sendfile for PHP sites, and byte ranges for every file the gateway
//! sends from disk.
//!
//! A PHP app checks who may see a file, then answers with only an
//! `X-Sendfile: /absolute/path` header. The gateway sends the file itself,
//! with byte ranges, so video seeks and large downloads do not hold a PHP
//! worker for the whole transfer. This is what Apache's mod_xsendfile does.
//!
//! A site may only send files inside its owner's home folder that its owner
//! owns. The gateway runs as root, so without that check a site could name
//! any file on the server, through a symlink if not directly.

use std::path::{Path, PathBuf};

use axum::{
    body::Body,
    http::{HeaderMap, HeaderValue, Response, StatusCode, header},
};

use crate::platform;

pub(super) const SENDFILE_HEADER: &str = "x-sendfile";

/// The part of a file a request asks for.
#[derive(Debug, PartialEq, Eq)]
pub(super) enum ByteRange {
    Full,
    /// Inclusive at both ends, as in `Content-Range`.
    Partial { start: u64, end: u64 },
    Unsatisfiable,
}

/// Reads one `Range: bytes=…` request against a file of `size` bytes.
/// Several ranges at once, other units and a stale `If-Range` get the whole
/// file, which HTTP allows.
pub(super) fn requested_range(headers: &HeaderMap, etag: &str, size: u64) -> ByteRange {
    let Some(range) = headers.get(header::RANGE).and_then(|value| value.to_str().ok()) else {
        return ByteRange::Full;
    };
    if let Some(if_range) = headers.get(header::IF_RANGE).and_then(|value| value.to_str().ok()) {
        if if_range.trim() != etag {
            return ByteRange::Full;
        }
    }
    let Some(spec) = range.trim().strip_prefix("bytes=") else {
        return ByteRange::Full;
    };
    if spec.contains(',') {
        return ByteRange::Full;
    }
    let Some((first, last)) = spec.split_once('-') else {
        return ByteRange::Full;
    };
    let (first, last) = (first.trim(), last.trim());
    let parse = |value: &str| value.parse::<u64>().ok();

    let (start, end) = match (first.is_empty(), last.is_empty()) {
        (true, true) => return ByteRange::Full,
        // "bytes=-500": the last 500 bytes.
        (true, false) => {
            let Some(suffix) = parse(last) else { return ByteRange::Full };
            if suffix == 0 || size == 0 {
                return ByteRange::Unsatisfiable;
            }
            (size.saturating_sub(suffix), size - 1)
        }
        // "bytes=500-": from byte 500 to the end.
        (false, true) => {
            let Some(start) = parse(first) else { return ByteRange::Full };
            (start, size.saturating_sub(1))
        }
        (false, false) => {
            let (Some(start), Some(end)) = (parse(first), parse(last)) else { return ByteRange::Full };
            if end < start {
                return ByteRange::Full;
            }
            (start, end.min(size.saturating_sub(1)))
        }
    };
    if start >= size {
        return ByteRange::Unsatisfiable;
    }
    ByteRange::Partial { start, end }
}

/// The file an `X-Sendfile` header names, once it is known to be one the
/// site may send. Touches the disk, so call it from a blocking task.
pub(super) fn allowed_file(requested: &str, owner: &str) -> Option<PathBuf> {
    let requested = Path::new(requested.trim());
    if !requested.is_absolute() {
        return None;
    }
    let home = platform::paths().site_home(owner).canonicalize().ok()?;
    // Resolves "..", and symlinks to wherever they really point.
    let file = requested.canonicalize().ok()?;
    if !file.starts_with(&home) {
        return None;
    }
    let meta = std::fs::metadata(&file).ok()?;
    if !meta.is_file() {
        return None;
    }
    if !owned_like(&file, &home) {
        return None;
    }
    Some(file)
}

/// Files root or another user left in the home stay out of reach, as they
/// would for the site's own PHP.
#[cfg(unix)]
fn owned_like(file: &Path, home: &Path) -> bool {
    use std::os::unix::fs::MetadataExt;
    match (std::fs::metadata(file), std::fs::metadata(home)) {
        (Ok(file), Ok(home)) => file.uid() == home.uid(),
        _ => false,
    }
}

#[cfg(not(unix))]
fn owned_like(_file: &Path, _home: &Path) -> bool {
    true
}

/// PHP headers the gateway's own file response must keep: the ones the app
/// sets for the download (type, name, caching, cookies). Length, range and
/// validator headers describe the file, so the gateway writes those.
pub(super) fn carry_app_headers(app: &HeaderMap, response: &mut Response<Body>) {
    for (name, value) in app {
        if matches!(
            name.as_str(),
            SENDFILE_HEADER
                | "content-length"
                | "content-range"
                | "accept-ranges"
                | "etag"
                | "last-modified"
                | "transfer-encoding"
                | "content-encoding"
                | "status"
        ) {
            continue;
        }
        if name == header::SET_COOKIE {
            response.headers_mut().append(name.clone(), value.clone());
        } else {
            response.headers_mut().insert(name.clone(), value.clone());
        }
    }
}

pub(super) fn unsatisfiable(size: u64) -> Response<Body> {
    let mut response = Response::new(Body::empty());
    *response.status_mut() = StatusCode::RANGE_NOT_SATISFIABLE;
    if let Ok(value) = HeaderValue::from_str(&format!("bytes */{size}")) {
        response.headers_mut().insert(header::CONTENT_RANGE, value);
    }
    response
}

#[cfg(test)]
mod tests {
    use super::*;

    fn range(value: &str, size: u64) -> ByteRange {
        let mut headers = HeaderMap::new();
        headers.insert(header::RANGE, HeaderValue::from_str(value).unwrap());
        requested_range(&headers, "\"tag\"", size)
    }

    #[test]
    fn reads_the_three_single_range_forms() {
        assert_eq!(range("bytes=0-99", 1000), ByteRange::Partial { start: 0, end: 99 });
        assert_eq!(range("bytes=900-", 1000), ByteRange::Partial { start: 900, end: 999 });
        assert_eq!(range("bytes=-100", 1000), ByteRange::Partial { start: 900, end: 999 });
    }

    #[test]
    fn clamps_an_end_past_the_file() {
        assert_eq!(range("bytes=500-5000", 1000), ByteRange::Partial { start: 500, end: 999 });
        assert_eq!(range("bytes=-5000", 1000), ByteRange::Partial { start: 0, end: 999 });
    }

    #[test]
    fn a_start_past_the_end_cannot_be_served() {
        assert_eq!(range("bytes=1000-", 1000), ByteRange::Unsatisfiable);
        assert_eq!(range("bytes=-0", 1000), ByteRange::Unsatisfiable);
    }

    #[test]
    fn anything_else_gets_the_whole_file() {
        assert_eq!(range("bytes=0-1,5-9", 1000), ByteRange::Full);
        assert_eq!(range("items=0-9", 1000), ByteRange::Full);
        assert_eq!(range("bytes=9-1", 1000), ByteRange::Full);
        assert_eq!(requested_range(&HeaderMap::new(), "\"tag\"", 1000), ByteRange::Full);
    }

    #[test]
    fn a_stale_if_range_gets_the_whole_file() {
        let mut headers = HeaderMap::new();
        headers.insert(header::RANGE, HeaderValue::from_static("bytes=0-9"));
        headers.insert(header::IF_RANGE, HeaderValue::from_static("\"old\""));
        assert_eq!(requested_range(&headers, "\"tag\"", 1000), ByteRange::Full);
        headers.insert(header::IF_RANGE, HeaderValue::from_static("\"tag\""));
        assert_eq!(requested_range(&headers, "\"tag\"", 1000), ByteRange::Partial { start: 0, end: 9 });
    }

    #[test]
    fn a_relative_path_is_never_sent() {
        assert_eq!(allowed_file("storage/video.mp4", "anyone"), None);
    }

    #[test]
    fn app_headers_keep_the_download_name_but_not_the_length() {
        let mut app = HeaderMap::new();
        app.insert(header::CONTENT_DISPOSITION, HeaderValue::from_static("attachment; filename=\"a.mp4\""));
        app.insert(header::CONTENT_LENGTH, HeaderValue::from_static("0"));
        app.insert(SENDFILE_HEADER, HeaderValue::from_static("/home/a/v.mp4"));
        let mut response = Response::new(Body::empty());
        carry_app_headers(&app, &mut response);
        assert!(response.headers().contains_key(header::CONTENT_DISPOSITION));
        assert!(!response.headers().contains_key(header::CONTENT_LENGTH));
        assert!(!response.headers().contains_key(SENDFILE_HEADER));
    }
}
