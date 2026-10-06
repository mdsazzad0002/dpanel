use std::{
    pin::Pin,
    task::{Context, Poll},
    time::Duration,
};

use axum::{
    body::{Body, Bytes},
    http::{HeaderName, Method, Request, Response, StatusCode, Uri, header},
};
use futures_util::{Stream, StreamExt};
use http_body::{Frame, SizeHint};
use reqwest::Client;

use super::UpstreamConfig;

#[derive(Clone, Debug)]
pub struct ProxyConfig {
    pub connect_timeout: Duration,
    pub request_timeout: Duration,
    pub preserve_host: bool,
}

impl Default for ProxyConfig {
    fn default() -> Self {
        Self {
            connect_timeout: Duration::from_secs(5),
            request_timeout: Duration::from_secs(30),
            preserve_host: true,
        }
    }
}

/// Bodies at or under this size are read fully before forwarding, which keeps
/// them cacheable and counted exactly; larger ones stream through instead of
/// sitting in gateway memory.
const STREAM_THRESHOLD_BYTES: u64 = 2 * 1024 * 1024;

/// Longest a proxied response may go without sending a byte. Per-site
/// timeouts (capped well below this) bound the wait for the response head.
const IDLE_READ_TIMEOUT: Duration = Duration::from_secs(330);

pub fn build_client(config: &ProxyConfig) -> Result<Client, String> {
    let _preserve_host = config.preserve_host;
    // No total timeout here: it would cut off streamed downloads and
    // server-sent events. proxy_request bounds the response head instead.
    Client::builder()
        .connect_timeout(config.connect_timeout)
        .read_timeout(IDLE_READ_TIMEOUT)
        // A proxy hands redirects to the browser. Following them here would
        // drop the Set-Cookie on the redirect (logins) and fetch the target
        // without the browser's cookies.
        .redirect(reqwest::redirect::Policy::none())
        .build()
        .map_err(|error| format!("proxy client build failed: {error}"))
}

pub async fn proxy_request(
    client: &Client,
    upstream: &UpstreamConfig,
    request: Request<Body>,
) -> Result<Response<Body>, String> {
    proxy_request_with_timeout(client, upstream, request, ProxyConfig::default().request_timeout).await
}

/// Forwards `request` to `upstream`, waiting at most `timeout` for the
/// response head (and for a small body to finish).
pub async fn proxy_request_with_timeout(
    client: &Client,
    upstream: &UpstreamConfig,
    request: Request<Body>,
    timeout: Duration,
) -> Result<Response<Body>, String> {
    let (parts, body) = request.into_parts();
    let uri = parts.uri.clone();
    let method = parts.method.clone();
    let headers = parts.headers.clone();
    let is_head = method == Method::HEAD;
    let request_length = headers
        .get(header::CONTENT_LENGTH)
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.trim().parse::<u64>().ok());

    let target = upstream_uri(upstream, &uri)?;
    let mut builder = client.request(method_to_reqwest(method)?, target);
    // Large uploads stream to the app. The length header is kept, so the
    // upstream sees a normal length-delimited body (WSGI apps read
    // CONTENT_LENGTH and would see an empty chunked body).
    builder = match request_length {
        Some(length) if length > STREAM_THRESHOLD_BYTES => builder
            .header(header::CONTENT_LENGTH, length)
            .body(reqwest::Body::wrap_stream(body.into_data_stream())),
        _ => builder.body(
            axum::body::to_bytes(body, usize::MAX)
                .await
                .map_err(|error| format!("read request body failed: {error}"))?,
        ),
    };

    for (name, value) in headers.iter() {
        if should_skip_header(name) {
            continue;
        }
        builder = builder.header(name, value);
    }

    let forwarded_proto = headers
        .get("x-forwarded-proto")
        .and_then(|value| value.to_str().ok())
        .filter(|value| matches!(value.to_ascii_lowercase().as_str(), "http" | "https"))
        .unwrap_or_else(|| uri.scheme_str().unwrap_or("http"));
    builder = builder.header("x-forwarded-proto", forwarded_proto);
    if let Some(host) = headers.get("host").and_then(|value| value.to_str().ok()) {
        builder = builder.header("x-forwarded-host", host);
    }

    let deadline = tokio::time::Instant::now() + timeout;
    let response = tokio::time::timeout_at(deadline, builder.send())
        .await
        .map_err(|_| format!("upstream did not respond within {}s", timeout.as_secs()))?
        .map_err(|error| format!("upstream request failed: {error}"))?;

    let status = response.status();
    let response_headers = response.headers().clone();
    let event_stream = response_headers
        .get(header::CONTENT_TYPE)
        .and_then(|value| value.to_str().ok())
        .is_some_and(|value| value.trim_start().starts_with("text/event-stream"));
    let response_length = response.content_length();
    let stream = !is_head
        && (event_stream || response_length.is_some_and(|length| length > STREAM_THRESHOLD_BYTES));

    let body = if stream {
        Body::new(UpstreamBody {
            stream: Box::pin(response.bytes_stream()),
            remaining: response_length,
        })
    } else {
        let bytes = tokio::time::timeout_at(deadline, response.bytes())
            .await
            .map_err(|_| format!("upstream did not finish within {}s", timeout.as_secs()))?
            .map_err(|error| format!("read upstream response failed: {error}"))?;
        Body::from(bytes)
    };

    let mut axum_response = Response::new(body);
    *axum_response.status_mut() = StatusCode::from_u16(status.as_u16())
        .map_err(|error| format!("invalid upstream status: {error}"))?;

    let headers_mut = axum_response.headers_mut();
    for (name, value) in response_headers.iter() {
        if should_skip_response_header(name) {
            continue;
        }
        // append, not insert: headers like Set-Cookie repeat, one per cookie.
        headers_mut.append(name, value.clone());
    }

    Ok(axum_response)
}

/// A streamed upstream body that still reports its exact size when the
/// upstream sent Content-Length, so bandwidth accounting and the edge cache's
/// size checks keep working for streamed responses.
struct UpstreamBody {
    stream: Pin<Box<dyn Stream<Item = Result<Bytes, reqwest::Error>> + Send>>,
    remaining: Option<u64>,
}

impl http_body::Body for UpstreamBody {
    type Data = Bytes;
    type Error = reqwest::Error;

    fn poll_frame(
        mut self: Pin<&mut Self>,
        cx: &mut Context<'_>,
    ) -> Poll<Option<Result<Frame<Bytes>, Self::Error>>> {
        match self.stream.poll_next_unpin(cx) {
            Poll::Ready(Some(Ok(chunk))) => {
                if let Some(remaining) = self.remaining.as_mut() {
                    *remaining = remaining.saturating_sub(chunk.len() as u64);
                }
                Poll::Ready(Some(Ok(Frame::data(chunk))))
            }
            Poll::Ready(Some(Err(error))) => Poll::Ready(Some(Err(error))),
            Poll::Ready(None) => Poll::Ready(None),
            Poll::Pending => Poll::Pending,
        }
    }

    fn size_hint(&self) -> SizeHint {
        match self.remaining {
            Some(remaining) => SizeHint::with_exact(remaining),
            None => SizeHint::default(),
        }
    }
}

pub async fn health_check_upstream(
    client: &Client,
    upstream: &UpstreamConfig,
) -> Result<bool, String> {
    let url = upstream_health_url(upstream)?;
    let response = client
        .get(url)
        .timeout(Duration::from_secs(5))
        .send()
        .await
        .map_err(|error| format!("upstream health request failed: {error}"))?;
    Ok(response.status().is_success())
}

fn upstream_uri(upstream: &UpstreamConfig, uri: &Uri) -> Result<reqwest::Url, String> {
    match upstream {
        UpstreamConfig::Http(addr) => {
            let path_and_query = uri
                .path_and_query()
                .map(|value| value.as_str())
                .unwrap_or("/");
            let url = format!("http://{addr}{path_and_query}");
            reqwest::Url::parse(&url).map_err(|error| format!("invalid upstream url: {error}"))
        }
        UpstreamConfig::Unix(_) => Err("unix socket upstream is not implemented yet".into()),
    }
}

fn upstream_health_url(upstream: &UpstreamConfig) -> Result<reqwest::Url, String> {
    match upstream {
        UpstreamConfig::Http(addr) => {
            let url = format!("http://{addr}/health");
            reqwest::Url::parse(&url)
                .map_err(|error| format!("invalid upstream health url: {error}"))
        }
        UpstreamConfig::Unix(_) => Err("unix socket upstream health not implemented yet".into()),
    }
}

fn method_to_reqwest(method: Method) -> Result<reqwest::Method, String> {
    reqwest::Method::from_bytes(method.as_str().as_bytes())
        .map_err(|error| format!("invalid method: {error}"))
}

fn should_skip_header(name: &HeaderName) -> bool {
    matches!(
        name.as_str(),
        "host" | "connection" | "content-length" | "transfer-encoding" | "accept-encoding"
    )
}

fn should_skip_response_header(name: &HeaderName) -> bool {
    matches!(name.as_str(), "connection" | "transfer-encoding")
}

#[cfg(test)]
mod tests {
    use super::*;
    use axum::body::Body;
    use http_body::Body as _;

    #[test]
    fn skip_hop_by_hop_headers() {
        assert!(should_skip_header(&HeaderName::from_static("host")));
        assert!(!should_skip_header(&HeaderName::from_static("x-custom")));
    }

    #[tokio::test]
    async fn build_client_works() {
        let client = build_client(&ProxyConfig::default()).unwrap();
        let _ = client;
    }

    async fn spawn_upstream() -> std::net::SocketAddr {
        use axum::{Router, routing::{get, post}};
        let app = Router::new()
            .route(
                "/echo",
                post(|headers: axum::http::HeaderMap, body: Bytes| async move {
                    let length = headers
                        .get(header::CONTENT_LENGTH)
                        .and_then(|value| value.to_str().ok())
                        .unwrap_or("none")
                        .to_string();
                    let chunked = headers.contains_key(header::TRANSFER_ENCODING);
                    format!("{}|{}|{}", body.len(), length, chunked)
                }),
            )
            .route("/big", get(|| async { vec![b'x'; 5 * 1024 * 1024] }))
            .route("/small", get(|| async { "hello" }))
            .route(
                "/slow",
                get(|| async {
                    tokio::time::sleep(Duration::from_secs(3)).await;
                    "late"
                }),
            )
            .route(
                "/events",
                get(|| async {
                    let events = futures_util::stream::unfold(0, |count| async move {
                        if count == 2 {
                            return None;
                        }
                        if count == 1 {
                            tokio::time::sleep(Duration::from_secs(2)).await;
                        }
                        Some((Ok::<_, std::io::Error>(Bytes::from(format!("data: {count}\n\n"))), count + 1))
                    });
                    Response::builder()
                        .header(header::CONTENT_TYPE, "text/event-stream")
                        .body(Body::from_stream(events))
                        .unwrap()
                }),
            )
            .layer(axum::extract::DefaultBodyLimit::disable());
        let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let addr = listener.local_addr().unwrap();
        tokio::spawn(async move { axum::serve(listener, app).await.unwrap() });
        addr
    }

    fn get_request(path: &str) -> Request<Body> {
        Request::builder().uri(path).body(Body::empty()).unwrap()
    }

    #[tokio::test]
    async fn small_responses_stay_buffered_and_large_ones_stream_with_exact_size() {
        let upstream = UpstreamConfig::Http(spawn_upstream().await);
        let client = build_client(&ProxyConfig::default()).unwrap();
        let timeout = Duration::from_secs(10);

        let small = proxy_request_with_timeout(&client, &upstream, get_request("/small"), timeout)
            .await
            .unwrap();
        assert_eq!(small.body().size_hint().exact(), Some(5));

        let big = proxy_request_with_timeout(&client, &upstream, get_request("/big"), timeout)
            .await
            .unwrap();
        assert_eq!(big.body().size_hint().exact(), Some(5 * 1024 * 1024));
        let body = axum::body::to_bytes(big.into_body(), usize::MAX).await.unwrap();
        assert_eq!(body.len(), 5 * 1024 * 1024);
    }

    #[tokio::test]
    async fn large_uploads_stream_with_their_content_length() {
        let upstream = UpstreamConfig::Http(spawn_upstream().await);
        let client = build_client(&ProxyConfig::default()).unwrap();
        let payload = vec![b'u'; 3 * 1024 * 1024];
        let request = Request::builder()
            .method(Method::POST)
            .uri("/echo")
            .header(header::CONTENT_LENGTH, payload.len())
            .body(Body::from(payload))
            .unwrap();
        let response = proxy_request_with_timeout(&client, &upstream, request, Duration::from_secs(10))
            .await
            .unwrap();
        let body = axum::body::to_bytes(response.into_body(), usize::MAX).await.unwrap();
        assert_eq!(body, format!("{0}|{0}|false", 3 * 1024 * 1024).as_bytes());
    }

    #[tokio::test]
    async fn event_streams_deliver_the_first_event_before_the_stream_ends() {
        let upstream = UpstreamConfig::Http(spawn_upstream().await);
        let client = build_client(&ProxyConfig::default()).unwrap();
        let started = std::time::Instant::now();
        let response = proxy_request_with_timeout(&client, &upstream, get_request("/events"), Duration::from_secs(10))
            .await
            .unwrap();
        let mut frames = response.into_body().into_data_stream();
        let first = frames.next().await.unwrap().unwrap();
        assert_eq!(first, Bytes::from_static(b"data: 0\n\n"));
        assert!(started.elapsed() < Duration::from_secs(1), "first event was held back");
    }

    #[tokio::test]
    async fn slow_upstreams_hit_the_per_site_timeout() {
        let upstream = UpstreamConfig::Http(spawn_upstream().await);
        let client = build_client(&ProxyConfig::default()).unwrap();
        let error = proxy_request_with_timeout(&client, &upstream, get_request("/slow"), Duration::from_secs(1))
            .await
            .unwrap_err();
        assert!(error.contains("within 1s"), "{error}");
    }
}
