use std::{
    path::{Path, PathBuf},
    process::Stdio,
    time::Duration,
};

use axum::{
    body::Body,
    http::{header, HeaderName, HeaderValue, Request, Response, StatusCode},
};
use tokio::{io::AsyncWriteExt, process::Command, time::timeout};

pub async fn execute_php_front_controller(
    request: Request<Body>,
    document_root: &Path,
    php_version: Option<&str>,
) -> Result<Response<Body>, String> {
    let socket = resolve_fpm_socket(document_root, php_version)
        .ok_or_else(|| format!("PHP-FPM socket not found for PHP {}", php_version.unwrap_or("default")))?;
    let (parts, body) = request.into_parts();
    let body = axum::body::to_bytes(body, 64 * 1024 * 1024)
        .await
        .map_err(|error| format!("read PHP request body failed: {error}"))?;
    let host = parts
        .headers
        .get(header::HOST)
        .and_then(|value| value.to_str().ok())
        .unwrap_or("localhost");
    let server_name = host.split(':').next().unwrap_or(host);
    let request_uri = parts
        .uri
        .path_and_query()
        .map(|value| value.as_str())
        .unwrap_or("/");
    let query_string = parts.uri.query().unwrap_or("");
    let (script, script_name) = resolve_php_script(document_root, parts.uri.path())?;

    let mut command = Command::new("/usr/bin/cgi-fcgi");
    command
        .arg("-bind")
        .arg("-connect")
        .arg(&socket)
        .env("GATEWAY_INTERFACE", "CGI/1.1")
        .env("SERVER_SOFTWARE", "drust-edge-gateway")
        .env("SERVER_PROTOCOL", "HTTP/1.1")
        .env("SERVER_NAME", server_name)
        .env("SERVER_PORT", "80")
        .env("REQUEST_METHOD", parts.method.as_str())
        .env("REQUEST_URI", request_uri)
        .env("QUERY_STRING", query_string)
        .env("DOCUMENT_ROOT", document_root)
        .env("SCRIPT_FILENAME", &script)
        .env("SCRIPT_NAME", &script_name)
        .env("PHP_SELF", &script_name)
        .env("REDIRECT_STATUS", "200")
        .env("REMOTE_ADDR", "127.0.0.1")
        .env("HTTP_HOST", host)
        .env("CONTENT_LENGTH", body.len().to_string())
        .env("PHP_VALUE", "zlib.output_compression=0")
        .env("PHP_ADMIN_VALUE", "zlib.output_compression=0")
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());

    if let Some(content_type) = parts
        .headers
        .get(header::CONTENT_TYPE)
        .and_then(|value| value.to_str().ok())
    {
        command.env("CONTENT_TYPE", content_type);
    }
    for (name, value) in &parts.headers {
        // Let the gateway own compression. Forwarding browser encodings to
        // PHP-FPM can produce compressed empty redirects that Firefox rejects.
        if matches!(name.as_str(), "host" | "content-type" | "content-length" | "accept-encoding") {
            continue;
        }
        if let Ok(value) = value.to_str() {
            command.env(
                format!("HTTP_{}", name.as_str().replace('-', "_").to_uppercase()),
                value,
            );
        }
    }

    let mut child = command
        .spawn()
        .map_err(|error| format!("start cgi-fcgi failed: {error}"))?;
    if let Some(mut stdin) = child.stdin.take() {
        stdin
            .write_all(&body)
            .await
            .map_err(|error| format!("write FastCGI body failed: {error}"))?;
    }
    let output = timeout(Duration::from_secs(60), child.wait_with_output())
        .await
        .map_err(|_| "PHP-FPM request timed out".to_string())?
        .map_err(|error| format!("wait for cgi-fcgi failed: {error}"))?;
    if !output.status.success() {
        return Err(format!(
            "PHP-FPM request failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }

    parse_cgi_response(&output.stdout)
}

fn resolve_php_script(document_root: &Path, request_path: &str) -> Result<(PathBuf, String), String> {
    let canonical_root = std::fs::canonicalize(document_root)
        .map_err(|error| format!("PHP document root is unavailable: {error}"))?;
    let requested_name = request_path.trim_start_matches('/');
    if request_path.ends_with('/') && !requested_name.is_empty() {
        let index_name = format!("{requested_name}index.php");
        let requested = document_root.join(&index_name);
        if let Ok(canonical_script) = std::fs::canonicalize(&requested) {
            if canonical_script.is_file() && canonical_script.starts_with(&canonical_root) {
                return Ok((canonical_script, format!("/{index_name}")));
            }
        }
    }
    if request_path.to_ascii_lowercase().ends_with(".php") && !requested_name.is_empty() {
        let requested = document_root.join(requested_name);
        if let Ok(canonical_script) = std::fs::canonicalize(&requested) {
            if canonical_script.is_file() && canonical_script.starts_with(&canonical_root) {
                return Ok((canonical_script, format!("/{requested_name}")));
            }
        }
    }

    let script = canonical_root.join("index.php");
    if !script.is_file() {
        return Err(format!("PHP front controller missing: {}", script.display()));
    }
    Ok((script, "/index.php".into()))
}

fn resolve_fpm_socket(document_root: &Path, php_version: Option<&str>) -> Option<PathBuf> {
    let version = php_version.unwrap_or("8.3");
    let _document_root = document_root;
    let candidates = [
        PathBuf::from(format!("/run/php/php{version}-fpm.sock")),
        PathBuf::from("/run/php/php8.3-fpm.sock"),
        PathBuf::from("/run/php/php8.2-fpm.sock"),
    ];
    candidates.into_iter().find(|path| path.exists())
}

fn parse_cgi_response(output: &[u8]) -> Result<Response<Body>, String> {
    let split = output
        .windows(4)
        .position(|window| window == b"\r\n\r\n")
        .map(|position| (position, 4))
        .or_else(|| {
            output
                .windows(2)
                .position(|window| window == b"\n\n")
                .map(|position| (position, 2))
        })
        .ok_or_else(|| "invalid FastCGI response: headers missing".to_string())?;
    let header_block = String::from_utf8_lossy(&output[..split.0]);
    let body_bytes = &output[split.0 + split.1..];
    let mut response = Response::new(Body::from(body_bytes.to_vec()));

    for line in header_block.lines() {
        let Some((name, value)) = line.trim_end_matches('\r').split_once(':') else {
            continue;
        };
        let value = value.trim();
        if name.eq_ignore_ascii_case("status") {
            let code = value.split_whitespace().next().unwrap_or("200");
            *response.status_mut() = StatusCode::from_bytes(code.as_bytes())
                .map_err(|error| format!("invalid PHP response status: {error}"))?;
            continue;
        }
        let name = HeaderName::from_bytes(name.trim().as_bytes())
            .map_err(|error| format!("invalid PHP response header: {error}"))?;
        let value = HeaderValue::from_str(value)
            .map_err(|error| format!("invalid PHP response header value: {error}"))?;
        response.headers_mut().append(name, value);
    }

    // Some PHP handlers emit gzip metadata on an empty redirect response.
    // Browsers treat that as a corrupt compressed payload.
    if body_bytes.is_empty() {
        response.headers_mut().remove(header::CONTENT_ENCODING);
        response.headers_mut().remove(header::CONTENT_LENGTH);
    }

    Ok(response)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn parses_cgi_status_headers_and_body() {
        let response = parse_cgi_response(
            b"Status: 302 Found\r\nLocation: /login\r\nSet-Cookie: one=1\r\nSet-Cookie: two=2\r\n\r\nbody",
        )
        .unwrap();
        assert_eq!(response.status(), StatusCode::FOUND);
        assert_eq!(response.headers().get(header::LOCATION).unwrap(), "/login");
        assert_eq!(response.headers().get_all(header::SET_COOKIE).iter().count(), 2);
    }
}
