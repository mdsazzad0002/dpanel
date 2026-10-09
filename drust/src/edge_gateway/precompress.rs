//! Compressed copies of responses the gateway already holds in memory (edge
//! cache entries, cached static files). The compression layer would otherwise
//! gzip or brotli the same bytes again on every hit; here each encoding is made
//! once per copy and reused. Responses sent this way carry Content-Encoding, so
//! the layer leaves them alone.

use std::{
    io::Write,
    sync::{Arc, OnceLock},
};

use axum::{
    body::Body,
    http::{HeaderMap, HeaderValue, Version, header},
    response::Response,
};
use bytes::Bytes;

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Encoding {
    Brotli,
    Gzip,
}

impl Encoding {
    fn name(self) -> &'static str {
        match self {
            Self::Brotli => "br",
            Self::Gzip => "gzip",
        }
    }

    fn index(self) -> usize {
        match self {
            Self::Brotli => 0,
            Self::Gzip => 1,
        }
    }
}

/// The encoding to send for this Accept-Encoding, preferring brotli on a tie.
pub fn negotiate(request_headers: &HeaderMap) -> Option<Encoding> {
    let (mut brotli, mut gzip, mut any) = (None, None, None);
    for value in request_headers.get_all(header::ACCEPT_ENCODING) {
        let Ok(value) = value.to_str() else { continue };
        for item in value.split(',') {
            let mut parts = item.split(';');
            let name = parts.next().unwrap_or("").trim().to_ascii_lowercase();
            let quality = parts
                .filter_map(|part| part.trim().strip_prefix("q="))
                .find_map(|q| q.trim().parse::<f32>().ok())
                .unwrap_or(1.0);
            match name.as_str() {
                "br" => brotli = Some(quality),
                "gzip" | "x-gzip" => gzip = Some(quality),
                "*" => any = Some(quality),
                _ => {}
            }
        }
    }
    let brotli = brotli.or(any).unwrap_or(0.0);
    let gzip = gzip.or(any).unwrap_or(0.0);
    if brotli > 0.0 && brotli >= gzip {
        Some(Encoding::Brotli)
    } else if gzip > 0.0 {
        Some(Encoding::Gzip)
    } else {
        None
    }
}

/// One lazily made copy per encoding.
#[derive(Debug, Default)]
pub struct Variants([OnceLock<Bytes>; 2]);

/// Derived from the body, so it never makes two copies differ.
impl PartialEq for Variants {
    fn eq(&self, _: &Self) -> bool {
        true
    }
}

impl Eq for Variants {}

fn compress(encoding: Encoding, body: &[u8]) -> Bytes {
    // Made once per cached copy, so a better ratio than the per-response
    // layer (level 4) is worth its CPU.
    let output = match encoding {
        Encoding::Brotli => {
            let mut writer = brotli::CompressorWriter::new(Vec::new(), 4096, 6, 22);
            let _ = writer.write_all(body);
            writer.into_inner()
        }
        Encoding::Gzip => {
            let mut writer = flate2::write::GzEncoder::new(Vec::new(), flate2::Compression::new(6));
            let _ = writer.write_all(body);
            writer.finish().unwrap_or_default()
        }
    };
    Bytes::from(output)
}

async fn compressed(variants: &Arc<Variants>, encoding: Encoding, body: &Bytes) -> Option<Bytes> {
    let slot = encoding.index();
    if let Some(copy) = variants.0[slot].get() {
        return Some(copy.clone());
    }
    let (variants, body) = (variants.clone(), body.clone());
    tokio::task::spawn_blocking(move || variants.0[slot].get_or_init(|| compress(encoding, &body)).clone())
        .await
        .ok()
}

/// Sends `response` (whose body is `body`) compressed from `variants` when
/// the client accepts it and the compression layer would have compressed it.
pub async fn encode(
    mut response: Response,
    request_headers: &HeaderMap,
    body: &Bytes,
    variants: &Arc<Variants>,
) -> Response {
    let Some(encoding) = negotiate(request_headers) else {
        return response;
    };
    if (body.len() as u64) < super::compression::MIN_BYTES
        || !super::compression::should_compress(
            response.status(),
            Version::HTTP_11,
            response.headers(),
            response.extensions(),
        )
    {
        return response;
    }
    let Some(copy) = compressed(variants, encoding, body).await else {
        return response;
    };
    if copy.len() >= body.len() {
        return response;
    }
    let headers = response.headers_mut();
    headers.insert(header::CONTENT_ENCODING, HeaderValue::from_static(encoding.name()));
    headers.remove(header::CONTENT_LENGTH);
    let varies = headers
        .get_all(header::VARY)
        .iter()
        .filter_map(|value| value.to_str().ok())
        .any(|value| value.to_ascii_lowercase().contains("accept-encoding"));
    if !varies {
        headers.append(header::VARY, HeaderValue::from_static("accept-encoding"));
    }
    *response.body_mut() = Body::from(copy);
    response
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::http::StatusCode;
    use std::io::Read;

    fn accept(value: &'static str) -> HeaderMap {
        let mut headers = HeaderMap::new();
        headers.insert(header::ACCEPT_ENCODING, HeaderValue::from_static(value));
        headers
    }

    #[test]
    fn negotiates_like_browsers_send() {
        assert_eq!(negotiate(&accept("gzip, deflate, br, zstd")), Some(Encoding::Brotli));
        assert_eq!(negotiate(&accept("gzip, deflate")), Some(Encoding::Gzip));
        assert_eq!(negotiate(&accept("br;q=0, gzip")), Some(Encoding::Gzip));
        assert_eq!(negotiate(&accept("br;q=0.5, gzip;q=0.9")), Some(Encoding::Gzip));
        assert_eq!(negotiate(&accept("identity")), None);
        assert_eq!(negotiate(&accept("*")), Some(Encoding::Brotli));
        assert_eq!(negotiate(&HeaderMap::new()), None);
    }

    fn page() -> (Response, Bytes) {
        let body = Bytes::from("<p>hello</p>".repeat(500));
        let mut response = Response::new(Body::from(body.clone()));
        *response.status_mut() = StatusCode::OK;
        response.headers_mut().insert(header::CONTENT_TYPE, HeaderValue::from_static("text/html"));
        (response, body)
    }

    #[tokio::test]
    async fn compresses_once_and_reuses_the_copy() {
        let variants = Arc::new(Variants::default());
        let (response, body) = page();
        let response = encode(response, &accept("gzip"), &body, &variants).await;
        assert_eq!(response.headers()[header::CONTENT_ENCODING], "gzip");
        assert_eq!(response.headers()[header::VARY], "accept-encoding");
        let sent = axum::body::to_bytes(response.into_body(), usize::MAX).await.unwrap();
        let mut decoded = String::new();
        flate2::read::GzDecoder::new(&sent[..]).read_to_string(&mut decoded).unwrap();
        assert_eq!(decoded.as_bytes(), &body[..]);

        let stored = variants.0[Encoding::Gzip.index()].get().unwrap().clone();
        let (again, _) = page();
        let again = encode(again, &accept("gzip"), &body, &variants).await;
        let resent = axum::body::to_bytes(again.into_body(), usize::MAX).await.unwrap();
        assert_eq!(resent, stored);
    }

    #[tokio::test]
    async fn leaves_small_or_binary_bodies_alone() {
        let variants = Arc::new(Variants::default());
        let small = Bytes::from_static(b"tiny");
        let response = encode(Response::new(Body::from(small.clone())), &accept("br"), &small, &variants).await;
        assert!(response.headers().get(header::CONTENT_ENCODING).is_none());

        let (mut response, body) = page();
        response.headers_mut().insert(header::CONTENT_TYPE, HeaderValue::from_static("image/png"));
        let response = encode(response, &accept("br"), &body, &variants).await;
        assert!(response.headers().get(header::CONTENT_ENCODING).is_none());
        assert!(variants.0.iter().all(|copy| copy.get().is_none()));
    }
}
