use std::{time::Duration};

use axum::{
    body::Body,
    http::{HeaderName, Method, Request, Response, StatusCode, Uri},
};
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

pub fn build_client(config: &ProxyConfig) -> Result<Client, String> {
    let _preserve_host = config.preserve_host;
    Client::builder()
        .connect_timeout(config.connect_timeout)
        .timeout(config.request_timeout)
        .build()
        .map_err(|error| format!("proxy client build failed: {error}"))
}

pub async fn proxy_request(
    client: &Client,
    upstream: &UpstreamConfig,
    request: Request<Body>,
) -> Result<Response<Body>, String> {
    let (parts, body) = request.into_parts();
    let uri = parts.uri.clone();
    let method = parts.method.clone();
    let headers = parts.headers.clone();
    let body_bytes = axum::body::to_bytes(body, usize::MAX)
        .await
        .map_err(|error| format!("read request body failed: {error}"))?;

    let target = upstream_uri(upstream, &uri)?;
    let mut builder = client.request(method_to_reqwest(method)?, target);

    for (name, value) in headers.iter() {
        if should_skip_header(name) {
            continue;
        }
        builder = builder.header(name, value);
    }

    builder = builder.header("x-forwarded-proto", uri.scheme_str().unwrap_or("http"));
    if let Some(host) = headers.get("host").and_then(|value| value.to_str().ok()) {
        builder = builder.header("x-forwarded-host", host);
    }

    let response = builder
        .body(body_bytes)
        .send()
        .await
        .map_err(|error| format!("upstream request failed: {error}"))?;

    let status = response.status();
    let response_headers = response.headers().clone();
    let bytes = response
        .bytes()
        .await
        .map_err(|error| format!("read upstream response failed: {error}"))?;

    let mut axum_response = Response::new(Body::from(bytes));
    *axum_response.status_mut() = StatusCode::from_u16(status.as_u16())
        .map_err(|error| format!("invalid upstream status: {error}"))?;

    let headers_mut = axum_response.headers_mut();
    for (name, value) in response_headers.iter() {
        if should_skip_response_header(name) {
            continue;
        }
        headers_mut.insert(name, value.clone());
    }

    Ok(axum_response)
}

pub async fn health_check_upstream(client: &Client, upstream: &UpstreamConfig) -> Result<bool, String> {
    let url = upstream_health_url(upstream)?;
    let response = client
        .get(url)
        .send()
        .await
        .map_err(|error| format!("upstream health request failed: {error}"))?;
    Ok(response.status().is_success())
}

fn upstream_uri(upstream: &UpstreamConfig, uri: &Uri) -> Result<reqwest::Url, String> {
    match upstream {
        UpstreamConfig::Http(addr) => {
            let path_and_query = uri.path_and_query().map(|value| value.as_str()).unwrap_or("/");
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
            reqwest::Url::parse(&url).map_err(|error| format!("invalid upstream health url: {error}"))
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
}
