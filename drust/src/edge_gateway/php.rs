use std::{
    fs,
    path::{Path, PathBuf},
    process::Stdio,
    sync::{Mutex, OnceLock},
    thread,
    time::Duration,
};

use axum::{
    body::Body,
    http::{HeaderName, HeaderValue, Request, Response, StatusCode, header},
};
use tokio::{
    io::{AsyncReadExt, AsyncWriteExt},
    net::UnixStream,
    time::timeout,
};
use tracing::warn;

pub async fn execute_php_front_controller(
    request: Request<Body>,
    document_root: &Path,
    php_version: Option<&str>,
    site_owner: Option<&str>,
) -> Result<Response<Body>, String> {
    let socket = resolve_fpm_socket(php_version, site_owner).await?;
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
    let is_https = forwarded_request_is_https(&parts.headers);

    let mut params = vec![
        ("GATEWAY_INTERFACE".into(), "CGI/1.1".into()),
        ("SERVER_SOFTWARE".into(), "drust-edge-gateway".into()),
        ("SERVER_PROTOCOL".into(), "HTTP/1.1".into()),
        ("SERVER_NAME".into(), server_name.into()),
        (
            "SERVER_PORT".into(),
            if is_https { "443".into() } else { "80".into() },
        ),
        ("REQUEST_METHOD".into(), parts.method.as_str().into()),
        ("REQUEST_URI".into(), request_uri.into()),
        ("QUERY_STRING".into(), query_string.into()),
        (
            "DOCUMENT_ROOT".into(),
            document_root.to_string_lossy().into_owned(),
        ),
        (
            "SCRIPT_FILENAME".into(),
            script.to_string_lossy().into_owned(),
        ),
        ("SCRIPT_NAME".into(), script_name.clone()),
        ("PHP_SELF".into(), script_name),
        ("REDIRECT_STATUS".into(), "200".into()),
        ("REMOTE_ADDR".into(), "127.0.0.1".into()),
        ("HTTP_HOST".into(), host.into()),
        ("CONTENT_LENGTH".into(), body.len().to_string()),
        ("PHP_VALUE".into(), "zlib.output_compression=0".into()),
        ("PHP_ADMIN_VALUE".into(), "zlib.output_compression=0".into()),
    ];

    if is_https {
        params.push(("HTTPS".into(), "on".into()));
    }

    if let Some(content_type) = parts
        .headers
        .get(header::CONTENT_TYPE)
        .and_then(|value| value.to_str().ok())
    {
        params.push(("CONTENT_TYPE".into(), content_type.into()));
    }
    for (name, value) in &parts.headers {
        // Let the gateway own compression. Forwarding browser encodings to
        // PHP-FPM can produce compressed empty redirects that Firefox rejects.
        if matches!(
            name.as_str(),
            "host" | "content-type" | "content-length" | "accept-encoding"
        ) {
            continue;
        }
        if let Ok(value) = value.to_str() {
            params.push((
                format!("HTTP_{}", name.as_str().replace('-', "_").to_uppercase()),
                value.into(),
            ));
        }
    }
    let (stdout, stderr) = timeout(
        Duration::from_secs(60),
        fastcgi_request(&socket, &params, &body),
    )
    .await
    .map_err(|_| "PHP-FPM request timed out".to_string())??;
    if !stderr.is_empty() {
        warn!(message = %String::from_utf8_lossy(&stderr).trim(), "PHP-FPM stderr");
    }
    parse_cgi_response(&stdout)
}

async fn fastcgi_request(
    socket: &Path,
    params: &[(String, String)],
    body: &[u8],
) -> Result<(Vec<u8>, Vec<u8>), String> {
    let mut stream = UnixStream::connect(socket)
        .await
        .map_err(|e| format!("connect PHP-FPM failed: {e}"))?;
    fastcgi_exchange(&mut stream, params, body).await
}

async fn fastcgi_exchange(
    stream: &mut UnixStream,
    params: &[(String, String)],
    body: &[u8],
) -> Result<(Vec<u8>, Vec<u8>), String> {
    write_fcgi_record(stream, 1, &[0, 1, 0, 0, 0, 0, 0, 0]).await?;
    let encoded = encode_params(params)?;
    for chunk in encoded.chunks(u16::MAX as usize) {
        write_fcgi_record(stream, 4, chunk).await?;
    }
    write_fcgi_record(stream, 4, &[]).await?;
    for chunk in body.chunks(u16::MAX as usize) {
        write_fcgi_record(stream, 5, chunk).await?;
    }
    write_fcgi_record(stream, 5, &[]).await?;
    stream.flush().await.map_err(|e| e.to_string())?;
    let (mut stdout, mut stderr) = (Vec::new(), Vec::new());
    loop {
        let mut header = [0u8; 8];
        stream
            .read_exact(&mut header)
            .await
            .map_err(|e| format!("read FastCGI header failed: {e}"))?;
        if header[0] != 1 {
            return Err("unsupported FastCGI version".into());
        }
        let length = u16::from_be_bytes([header[4], header[5]]) as usize;
        let padding = header[6] as usize;
        let mut content = vec![0; length];
        stream
            .read_exact(&mut content)
            .await
            .map_err(|e| e.to_string())?;
        if padding > 0 {
            let mut discard = vec![0; padding];
            stream
                .read_exact(&mut discard)
                .await
                .map_err(|e| e.to_string())?;
        }
        match header[1] {
            6 => stdout.extend(content),
            7 => stderr.extend(content),
            3 => break,
            _ => {}
        }
    }
    Ok((stdout, stderr))
}

async fn write_fcgi_record(
    stream: &mut UnixStream,
    kind: u8,
    content: &[u8],
) -> Result<(), String> {
    let padding = (8 - content.len() % 8) % 8;
    let length = content.len() as u16;
    let header = [
        1,
        kind,
        0,
        1,
        (length >> 8) as u8,
        length as u8,
        padding as u8,
        0,
    ];
    stream.write_all(&header).await.map_err(|e| e.to_string())?;
    stream.write_all(content).await.map_err(|e| e.to_string())?;
    if padding > 0 {
        stream
            .write_all(&[0; 8][..padding])
            .await
            .map_err(|e| e.to_string())?;
    }
    Ok(())
}

fn encode_params(params: &[(String, String)]) -> Result<Vec<u8>, String> {
    let mut encoded = Vec::new();
    for (name, value) in params {
        encode_length(&mut encoded, name.len())?;
        encode_length(&mut encoded, value.len())?;
        encoded.extend_from_slice(name.as_bytes());
        encoded.extend_from_slice(value.as_bytes());
    }
    Ok(encoded)
}

fn encode_length(output: &mut Vec<u8>, length: usize) -> Result<(), String> {
    if length < 128 {
        output.push(length as u8);
    } else {
        let value = u32::try_from(length).map_err(|_| "FastCGI parameter too large")? | 0x8000_0000;
        output.extend_from_slice(&value.to_be_bytes());
    }
    Ok(())
}

fn forwarded_request_is_https(headers: &axum::http::HeaderMap) -> bool {
    let forwarded_proto_is_https = headers
        .get("x-forwarded-proto")
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.split(',').next())
        .is_some_and(|value| value.trim().eq_ignore_ascii_case("https"));
    if forwarded_proto_is_https {
        return true;
    }

    headers
        .get("cf-visitor")
        .and_then(|value| value.to_str().ok())
        .is_some_and(|value| {
            serde_json::from_str::<serde_json::Value>(value)
                .ok()
                .and_then(|visitor| visitor.get("scheme")?.as_str().map(str::to_owned))
                .is_some_and(|scheme| scheme.eq_ignore_ascii_case("https"))
        })
}

fn normalize_site_owner(value: &str) -> Option<String> {
    let owner = value.trim().to_ascii_lowercase();
    if owner.is_empty()
        || owner.eq_ignore_ascii_case("null")
        || !owner
            .chars()
            .all(|character| character.is_ascii_alphanumeric() || matches!(character, '_' | '-'))
    {
        None
    } else {
        Some(owner)
    }
}

fn resolve_php_script(
    document_root: &Path,
    request_path: &str,
) -> Result<(PathBuf, String), String> {
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
        return Err(format!(
            "PHP front controller missing: {}",
            script.display()
        ));
    }
    Ok((script, "/index.php".into()))
}

async fn resolve_fpm_socket(
    php_version: Option<&str>,
    site_owner: Option<&str>,
) -> Result<PathBuf, String> {
    let version = php_version.unwrap_or("8.3");
    let site_pools_enabled = std::env::var("DRUST_SITE_POOLS")
        .map(|value| {
            !matches!(
                value.trim().to_ascii_lowercase().as_str(),
                "0" | "false" | "off" | "no"
            )
        })
        .unwrap_or(true);

    if site_pools_enabled {
        if let Some(raw_owner) = site_owner {
            if let Some(owner) = normalize_site_owner(raw_owner) {
                let socket = PathBuf::from(format!("/run/php/dpanel-{owner}-php{version}.sock"));
                if socket.exists() {
                    return Ok(socket);
                }
                let owner_for_worker = owner.clone();
                let version_for_worker = version.to_string();
                let socket_for_worker = socket.clone();
                let provision_result = tokio::task::spawn_blocking(move || {
                    ensure_user_fpm_pool(&owner_for_worker, &version_for_worker, &socket_for_worker)
                })
                .await
                .map_err(|error| format!("PHP-FPM pool provision worker failed: {error}"))
                .and_then(|result| result);
                match provision_result {
                    Ok(()) if socket.exists() => return Ok(socket),
                    Ok(()) => warn!(
                        site_owner = %owner,
                        php_version = %version,
                        "user PHP-FPM socket is still missing; falling back to shared pool"
                    ),
                    Err(error) => warn!(
                        site_owner = %owner,
                        php_version = %version,
                        error = %error,
                        "user PHP-FPM pool unavailable; falling back to shared pool"
                    ),
                }
            } else {
                warn!(
                    site_owner = %raw_owner,
                    "invalid site owner; falling back to shared PHP-FPM pool"
                );
            }
        }
    }

    resolve_shared_fpm_socket(version)
}

fn resolve_shared_fpm_socket(version: &str) -> Result<PathBuf, String> {
    let candidates = [
        PathBuf::from(format!("/run/php/php{version}-fpm.sock")),
        PathBuf::from("/run/php/php8.3-fpm.sock"),
        PathBuf::from("/run/php/php8.2-fpm.sock"),
    ];
    candidates
        .into_iter()
        .find(|path| path.exists())
        .ok_or_else(|| format!("PHP-FPM socket not found for PHP {version}"))
}

fn ensure_user_fpm_pool(owner: &str, version: &str, socket: &Path) -> Result<(), String> {
    static PROVISION_LOCK: OnceLock<Mutex<()>> = OnceLock::new();
    let _guard = PROVISION_LOCK
        .get_or_init(|| Mutex::new(()))
        .lock()
        .map_err(|_| "PHP-FPM provision lock is poisoned".to_string())?;

    if socket.exists() {
        return Ok(());
    }
    validate_php_version(version)?;
    validate_system_user(owner)?;

    let pool_directory = PathBuf::from(format!("/etc/php/{version}/fpm/pool.d"));
    if !pool_directory.is_dir() {
        return Err(format!(
            "PHP-FPM pool directory is unavailable: {}",
            pool_directory.display()
        ));
    }
    let pool_path = pool_directory.join(format!("dpanel-{owner}.conf"));
    let mut created = false;
    if !pool_path.exists() {
        let max_children = site_pool_max_children();
        let content = format!(
            "; Managed dynamically by drust edge gateway.\n\
             [{owner}]\n\
             user = {owner}\n\
             group = {owner}\n\
             listen = {}\n\
             listen.owner = www-data\n\
             listen.group = www-data\n\
             listen.mode = 0660\n\
             pm = ondemand\n\
             pm.max_children = {max_children}\n\
             pm.process_idle_timeout = 300s\n\
             pm.max_requests = 500\n\
             security.limit_extensions = .php\n",
            socket.display()
        );
        fs::write(&pool_path, content).map_err(|error| {
            format!(
                "cannot create PHP-FPM pool {}: {error}",
                pool_path.display()
            )
        })?;
        created = true;
    }

    if let Err(error) = test_fpm_configuration(version) {
        if created {
            let _ = fs::remove_file(&pool_path);
        }
        return Err(format!(
            "PHP-FPM configuration test failed after provisioning {}: {error}",
            pool_path.display()
        ));
    }
    reload_fpm_service(version)?;

    for _ in 0..40 {
        if socket.exists() {
            return Ok(());
        }
        thread::sleep(Duration::from_millis(50));
    }
    Err(format!(
        "PHP-FPM pool was provisioned but its socket did not start: {}",
        socket.display()
    ))
}

fn validate_php_version(version: &str) -> Result<(), String> {
    let Some((major, minor)) = version.split_once('.') else {
        return Err(format!("invalid PHP version: {version}"));
    };
    if major.is_empty()
        || minor.is_empty()
        || !major.bytes().all(|byte| byte.is_ascii_digit())
        || !minor.bytes().all(|byte| byte.is_ascii_digit())
    {
        return Err(format!("invalid PHP version: {version}"));
    }
    Ok(())
}

fn validate_system_user(owner: &str) -> Result<(), String> {
    let status = std::process::Command::new("id")
        .args(["-u", owner])
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .map_err(|error| format!("cannot validate site owner {owner}: {error}"))?;
    if status.success() {
        Ok(())
    } else {
        Err(format!("site owner does not exist: {owner}"))
    }
}

fn site_pool_max_children() -> u16 {
    if let Some(configured) = std::env::var("DRUST_SITE_POOL_MAX_CHILDREN")
        .ok()
        .and_then(|value| value.trim().parse::<u16>().ok())
        .map(|value| value.clamp(1, 50))
    {
        return configured;
    }

    let total_memory_kb = fs::read_to_string("/proc/meminfo")
        .ok()
        .and_then(|contents| {
            contents.lines().find_map(|line| {
                line.strip_prefix("MemTotal:")?
                    .split_whitespace()
                    .next()?
                    .parse::<u64>()
                    .ok()
            })
        })
        .unwrap_or(4 * 1024 * 1024);
    let pool_count = Path::new("/etc/php")
        .read_dir()
        .ok()
        .into_iter()
        .flatten()
        .filter_map(Result::ok)
        .map(|entry| entry.path().join("fpm/pool.d"))
        .filter_map(|directory| directory.read_dir().ok())
        .flat_map(|entries| entries.filter_map(Result::ok))
        .filter(|entry| {
            entry.file_name().to_string_lossy().starts_with("dpanel-")
                && entry.path().extension().and_then(|value| value.to_str()) == Some("conf")
        })
        .count()
        .max(1);
    let estimated_child_mb = std::env::var("DRUST_SITE_POOL_CHILD_MEMORY_MB")
        .ok()
        .and_then(|value| value.trim().parse::<u64>().ok())
        .unwrap_or(96)
        .clamp(32, 512);

    automatic_site_pool_max_children(total_memory_kb, pool_count, estimated_child_mb)
}

fn automatic_site_pool_max_children(
    total_memory_kb: u64,
    pool_count: usize,
    estimated_child_mb: u64,
) -> u16 {
    // Site pools are ondemand. Reserve 70% for the OS, database, Redis and
    // non-PHP services, then share the bounded PHP budget across all sites.
    let php_budget_mb = total_memory_kb.saturating_div(1024).saturating_mul(30) / 100;
    let per_pool = php_budget_mb
        .saturating_div(pool_count.max(1) as u64)
        .saturating_div(estimated_child_mb.max(1));

    per_pool.clamp(2, 12) as u16
}

fn test_fpm_configuration(version: &str) -> Result<(), String> {
    let binary = format!("/usr/sbin/php-fpm{version}");
    let output = std::process::Command::new(&binary)
        .arg("-t")
        .output()
        .map_err(|error| format!("cannot run {binary}: {error}"))?;
    if output.status.success() {
        Ok(())
    } else {
        Err(String::from_utf8_lossy(&output.stderr).trim().to_string())
    }
}

fn reload_fpm_service(version: &str) -> Result<(), String> {
    let service = format!("php{version}-fpm");
    let output = std::process::Command::new("systemctl")
        .args(["reload-or-restart", &service])
        .output()
        .map_err(|error| format!("cannot reload {service}: {error}"))?;
    if output.status.success() {
        Ok(())
    } else {
        Err(format!(
            "cannot reload {service}: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ))
    }
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
        assert_eq!(
            response
                .headers()
                .get_all(header::SET_COOKIE)
                .iter()
                .count(),
            2
        );
    }

    #[test]
    fn accepts_safe_site_owner_names() {
        assert_eq!(
            normalize_site_owner(" Account_User "),
            Some("account_user".into())
        );
        assert_eq!(normalize_site_owner("../www-data"), None);
        assert_eq!(normalize_site_owner("bad/name"), None);
    }

    #[test]
    fn detects_forwarded_https_scheme() {
        let mut headers = axum::http::HeaderMap::new();
        headers.insert("x-forwarded-proto", HeaderValue::from_static("https"));
        assert!(forwarded_request_is_https(&headers));

        headers.clear();
        headers.insert(
            "cf-visitor",
            HeaderValue::from_static(r#"{"scheme":"https"}"#),
        );
        assert!(forwarded_request_is_https(&headers));

        headers.clear();
        headers.insert("x-forwarded-proto", HeaderValue::from_static("http"));
        assert!(!forwarded_request_is_https(&headers));
    }

    #[test]
    fn sizes_site_pools_from_a_bounded_shared_memory_budget() {
        assert_eq!(automatic_site_pool_max_children(16 * 1024 * 1024, 8, 96), 6);
        assert_eq!(
            automatic_site_pool_max_children(2 * 1024 * 1024, 50, 128),
            2
        );
        assert_eq!(
            automatic_site_pool_max_children(128 * 1024 * 1024, 1, 32),
            12
        );
    }
}
