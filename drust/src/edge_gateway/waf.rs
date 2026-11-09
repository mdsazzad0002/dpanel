//! The web application firewall in the gateway (backlog S2; spike S1 in
//! `plans/spikes/waf.md`).
//!
//! libmodsecurity is C++ and parses whatever a visitor sends, so it never
//! runs in the gateway's process: `drust waf-worker` processes do, as
//! `nobody` once their rules are loaded, each answering one request at a time
//! over its stdin and stdout. A worker that dies or hangs is replaced, and
//! the request it held goes through: the firewall must never take a site down.
//!
//! Only requests that reach PHP or an app are screened; static files, edge
//! cache hits and ACME never come here. Each site is `off`, `detect` (the
//! default: matches are only logged) or `block` (a match answers 403).
//! Matches go to `<logs>/sites/<domain>/waf.log`, read by `GET /sites/logs`.

use std::{
    io::{self, Read, Write},
    net::SocketAddr,
    path::Path,
    process::Stdio,
    sync::{
        OnceLock,
        atomic::{AtomicUsize, Ordering},
    },
    time::Duration,
};

use axum::{
    body::{Body, Bytes},
    extract::ConnectInfo,
    http::{Request, Response, StatusCode, Version, header},
};
use tokio::{
    io::{AsyncReadExt, AsyncWriteExt, BufReader},
    process::{Child, ChildStdin, ChildStdout, Command},
    sync::Mutex,
};
use tracing::warn;

use crate::platform::waf::{self as engine, Verdict, WafRequest};

/// Bodies up to this size are screened; a bigger one only by its headers.
pub const BODY_LIMIT: usize = 128 * 1024;
/// Waiting for a free worker included. A slow answer lets the request through.
const TIMEOUT: Duration = Duration::from_millis(500);
const MAX_FRAME: usize = BODY_LIMIT + 256 * 1024;

#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub enum WafMode {
    /// Before the panel's migration runs, and when a site turns it off.
    #[default]
    Off,
    Detect,
    Block,
}

impl WafMode {
    pub fn parse(value: &str) -> Self {
        match value.trim() {
            "detect" => Self::Detect,
            "block" => Self::Block,
            _ => Self::Off,
        }
    }
}

// ---- The frames between gateway and worker: a u32 length, then fields. ----

fn put(out: &mut Vec<u8>, field: &[u8]) {
    out.extend_from_slice(&(field.len() as u32).to_le_bytes());
    out.extend_from_slice(field);
}

pub fn encode_request(request: &WafRequest) -> Vec<u8> {
    let mut body = Vec::with_capacity(512 + request.body.len());
    for field in [&request.method, &request.uri, &request.version, &request.client_ip] {
        put(&mut body, field.as_bytes());
    }
    put(&mut body, request.client_port.to_string().as_bytes());
    put(&mut body, request.server_port.to_string().as_bytes());
    put(&mut body, request.headers.len().to_string().as_bytes());
    for (name, value) in &request.headers {
        put(&mut body, name.as_bytes());
        put(&mut body, value.as_bytes());
    }
    put(&mut body, &request.body);
    let mut frame = (body.len() as u32).to_le_bytes().to_vec();
    frame.extend(body);
    frame
}

pub fn decode_request(payload: &[u8]) -> Option<WafRequest> {
    let mut rest = payload;
    let mut field = || -> Option<&[u8]> {
        let length = u32::from_le_bytes(rest.get(..4)?.try_into().ok()?) as usize;
        let value = rest.get(4..4 + length)?;
        rest = &rest[4 + length..];
        Some(value)
    };
    let text = |bytes: &[u8]| String::from_utf8_lossy(bytes).into_owned();
    let mut request = WafRequest {
        method: text(field()?),
        uri: text(field()?),
        version: text(field()?),
        client_ip: text(field()?),
        client_port: text(field()?).parse().ok()?,
        server_port: text(field()?).parse().ok()?,
        ..Default::default()
    };
    let headers: usize = text(field()?).parse().ok()?;
    for _ in 0..headers.min(1000) {
        let name = text(field()?);
        let value = text(field()?);
        request.headers.push((name, value));
    }
    request.body = field()?.to_vec();
    Some(request)
}

fn frame(json: &[u8]) -> Vec<u8> {
    let mut out = (json.len() as u32).to_le_bytes().to_vec();
    out.extend_from_slice(json);
    out
}

// ---- The worker process. ----

/// `drust waf-worker <rules>`: answers requests on stdin until it closes.
pub fn run_worker(rules: &Path) -> io::Result<()> {
    // The rules are root's to read; visitors' requests are parsed as nobody.
    let mut engine = engine::load(rules).map_err(|error| io::Error::other(error.to_string()))?;
    crate::platform::process::drop_privileges().map_err(io::Error::other)?;
    let stdin = io::stdin();
    let mut input = stdin.lock();
    let mut output = io::stdout().lock();
    loop {
        let mut length = [0u8; 4];
        match input.read_exact(&mut length) {
            Ok(()) => {}
            Err(error) if error.kind() == io::ErrorKind::UnexpectedEof => return Ok(()),
            Err(error) => return Err(error),
        }
        let length = u32::from_le_bytes(length) as usize;
        if length > MAX_FRAME {
            return Err(io::Error::other("WAF request frame too large"));
        }
        let mut payload = vec![0; length];
        input.read_exact(&mut payload)?;
        let verdict = decode_request(&payload).map(|request| engine.inspect(&request)).unwrap_or(Verdict {
            status: 200,
            messages: vec!["unreadable request frame".into()],
        });
        let json = serde_json::to_vec(&verdict).map_err(io::Error::other)?;
        output.write_all(&frame(&json))?;
        output.flush()?;
    }
}

struct Worker {
    child: Child,
    stdin: ChildStdin,
    stdout: BufReader<ChildStdout>,
}

impl Worker {
    fn spawn() -> io::Result<Self> {
        let program = std::env::current_exe()?;
        let mut command = Command::new(program);
        command
            .arg("waf-worker")
            .arg(engine::rules_file())
            .env_clear()
            .stdin(Stdio::piped())
            .stdout(Stdio::piped())
            .stderr(Stdio::inherit())
            .kill_on_drop(true);
        let mut child = command.spawn()?;
        let stdin = child.stdin.take().ok_or_else(|| io::Error::other("worker has no stdin"))?;
        let stdout = child.stdout.take().ok_or_else(|| io::Error::other("worker has no stdout"))?;
        Ok(Self { child, stdin, stdout: BufReader::new(stdout) })
    }

    async fn inspect(&mut self, frame: &[u8]) -> io::Result<Verdict> {
        self.stdin.write_all(frame).await?;
        self.stdin.flush().await?;
        let length = self.stdout.read_u32_le().await? as usize;
        if length > 1024 * 1024 {
            return Err(io::Error::other("WAF answer too large"));
        }
        let mut json = vec![0; length];
        self.stdout.read_exact(&mut json).await?;
        serde_json::from_slice(&json).map_err(io::Error::other)
    }
}

struct Pool {
    slots: Vec<Mutex<Option<Worker>>>,
    next: AtomicUsize,
}

fn pool() -> Option<&'static Pool> {
    static POOL: OnceLock<Option<Pool>> = OnceLock::new();
    POOL.get_or_init(|| {
        if !engine::available() {
            return None;
        }
        let workers = std::env::var("DRUST_WAF_WORKERS")
            .ok()
            .and_then(|value| value.trim().parse::<usize>().ok())
            .unwrap_or_else(|| std::thread::available_parallelism().map(|n| n.get() / 2).unwrap_or(1))
            .clamp(1, 16);
        Some(Pool { slots: (0..workers).map(|_| Mutex::new(None)).collect(), next: AtomicUsize::new(0) })
    })
    .as_ref()
}

impl Pool {
    async fn inspect(&self, frame: &[u8]) -> Result<Verdict, String> {
        let start = self.next.fetch_add(1, Ordering::Relaxed) % self.slots.len();
        // A free worker if there is one, else wait in turn for this one.
        let mut slot = match (0..self.slots.len()).find_map(|offset| self.slots[(start + offset) % self.slots.len()].try_lock().ok()) {
            Some(slot) => slot,
            None => self.slots[start].lock().await,
        };
        if slot.as_mut().is_some_and(|worker| worker.child.try_wait().ok().flatten().is_some()) {
            *slot = None;
        }
        if slot.is_none() {
            *slot = Some(Worker::spawn().map_err(|error| format!("cannot start a WAF worker: {error}"))?);
        }
        let worker = slot.as_mut().expect("worker started above");
        match worker.inspect(frame).await {
            Ok(verdict) => Ok(verdict),
            Err(error) => {
                // Half a frame may be in the pipe: start over with a new worker.
                *slot = None;
                Err(format!("WAF worker failed: {error}"))
            }
        }
    }
}

// ---- Screening a request. ----

fn version(version: Version) -> &'static str {
    match version {
        Version::HTTP_10 => "1.0",
        Version::HTTP_2 => "2.0",
        Version::HTTP_3 => "3.0",
        _ => "1.1",
    }
}

/// A body we may read in full first: its length is known and small.
fn buffered_length(request: &Request<Body>) -> Option<usize> {
    if request.headers().contains_key(header::TRANSFER_ENCODING) {
        return None;
    }
    let length = request.headers().get(header::CONTENT_LENGTH)?.to_str().ok()?.trim().parse::<usize>().ok()?;
    (length <= BODY_LIMIT).then_some(length)
}

pub fn facts(request: &Request<Body>, body: Vec<u8>) -> WafRequest {
    let peer = request.extensions().get::<ConnectInfo<SocketAddr>>().map(|info| info.0)
        .or_else(|| request.extensions().get::<SocketAddr>().copied());
    WafRequest {
        method: request.method().as_str().to_string(),
        uri: request.uri().path_and_query().map(|value| value.as_str().to_string()).unwrap_or_else(|| "/".into()),
        version: version(request.version()).into(),
        client_ip: peer.map(|addr| addr.ip().to_string()).unwrap_or_else(|| "0.0.0.0".into()),
        client_port: peer.map(|addr| addr.port()).unwrap_or(0),
        server_port: 0,
        headers: request
            .headers()
            .iter()
            .map(|(name, value)| (name.as_str().to_string(), String::from_utf8_lossy(value.as_bytes()).into_owned()))
            .collect(),
        body,
    }
}

/// The request to carry on with, or the answer that stops it.
pub async fn screen(site: &super::SiteConfig, request: Request<Body>) -> Result<Request<Body>, Response<Body>> {
    let mode = site.guards.waf;
    // The panel's own sites are not customers' to protect, and must stay reachable.
    if mode == WafMode::Off || site.scope == "system" {
        return Ok(request);
    }
    let Some(pool) = pool() else { return Ok(request) };

    // Read a small body now (and hand it on afterwards); a big or streamed
    // one is screened by its headers only, so uploads keep streaming.
    let (request, body) = match buffered_length(&request) {
        Some(length) if length > 0 => {
            let (parts, body) = request.into_parts();
            match axum::body::to_bytes(body, BODY_LIMIT).await {
                Ok(bytes) => (Request::from_parts(parts, Body::from(bytes.clone())), bytes),
                Err(_) => return Err(simple(StatusCode::BAD_REQUEST, "The request body could not be read.")),
            }
        }
        _ => (request, Bytes::new()),
    };
    let facts = facts(&request, body.to_vec());
    let frame = encode_request(&facts);

    let verdict = match tokio::time::timeout(TIMEOUT, pool.inspect(&frame)).await {
        Ok(Ok(verdict)) => verdict,
        Ok(Err(error)) => {
            warn!(%error, "WAF unavailable; request let through");
            return Ok(request);
        }
        Err(_) => {
            warn!("WAF did not answer in time; request let through");
            return Ok(request);
        }
    };

    let blocked = mode == WafMode::Block && verdict.status != 200;
    if verdict.status != 200 || !verdict.messages.is_empty() {
        if let Some(domain) = site.hostnames.first() {
            log_match(domain, &facts, &verdict, blocked);
        }
    }
    if blocked {
        return Err(simple(
            StatusCode::from_u16(verdict.status).ok().filter(|code| code.is_client_error()).unwrap_or(StatusCode::FORBIDDEN),
            "This request was blocked by the website's firewall.",
        ));
    }
    Ok(request)
}

fn simple(status: StatusCode, message: &str) -> Response<Body> {
    Response::builder()
        .status(status)
        .header(header::CONTENT_TYPE, "text/plain; charset=utf-8")
        .header(header::CACHE_CONTROL, "no-store")
        .body(Body::from(message.to_string()))
        .unwrap_or_default()
}

// ---- The site's WAF log. ----

/// `[id "942100"] [msg "SQL Injection …"] [data "Matched Data: …"]` in short.
pub fn summarize(message: &str) -> String {
    let field = |name: &str| -> Option<String> {
        let start = message.find(&format!("[{name} \""))? + name.len() + 3;
        let end = message[start..].find("\"]")? + start;
        Some(message[start..end].to_string())
    };
    let id = field("id").unwrap_or_default();
    let msg = field("msg").unwrap_or_else(|| message.chars().take(120).collect());
    match field("data") {
        Some(data) if !data.is_empty() => {
            let data: String = data.chars().take(160).collect();
            format!("{id} {msg} ({data})").trim().to_string()
        }
        _ => format!("{id} {msg}").trim().to_string(),
    }
}

fn clean(value: &str) -> String {
    value.chars().map(|c| if c.is_control() { ' ' } else { c }).collect()
}

pub fn log_line(time: &str, request: &WafRequest, verdict: &Verdict, blocked: bool) -> String {
    let action = if blocked { "blocked" } else if verdict.status != 200 { "would-block" } else { "matched" };
    let rules: Vec<String> = verdict.messages.iter().map(|message| summarize(message)).filter(|rule| !rule.is_empty()).collect();
    clean(&format!(
        "{time} {} {} {} {action} {} | {}",
        request.client_ip,
        request.method,
        request.uri.chars().take(500).collect::<String>(),
        verdict.status,
        rules.join(" ; "),
    ))
}

fn log_match(domain: &str, request: &WafRequest, verdict: &Verdict, blocked: bool) {
    let Some(path) = super::site_logs::safe_domain(domain).map(|domain| super::site_logs::logs_root().join(domain).join("waf.log")) else {
        return;
    };
    let line = log_line(&chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs, true), request, verdict, blocked);
    tokio::task::spawn_blocking(move || {
        let write = || -> io::Result<()> {
            if let Some(dir) = path.parent() {
                std::fs::create_dir_all(dir)?;
            }
            super::site_logs::rotate_if_large(&path, super::site_logs::MAX_BYTES, super::site_logs::KEEP)?;
            let mut file = std::fs::OpenOptions::new().create(true).append(true).open(&path)?;
            writeln!(file, "{line}")
        };
        if let Err(error) = write() {
            warn!(%error, "cannot write the WAF log");
        }
    });
}

#[cfg(test)]
mod tests {
    use super::*;

    fn sample() -> WafRequest {
        WafRequest {
            method: "POST".into(),
            uri: "/wp-comments-post.php?x=1".into(),
            version: "1.1".into(),
            client_ip: "203.0.113.7".into(),
            client_port: 51234,
            server_port: 443,
            headers: vec![("host".into(), "shop.test".into()), ("content-type".into(), "application/x-www-form-urlencoded".into())],
            body: b"comment=<script>alert(1)</script>".to_vec(),
        }
    }

    #[test]
    fn a_request_survives_the_frame() {
        let frame = encode_request(&sample());
        let length = u32::from_le_bytes(frame[..4].try_into().unwrap()) as usize;
        assert_eq!(length, frame.len() - 4);
        assert_eq!(decode_request(&frame[4..]), Some(sample()));
        assert_eq!(decode_request(&frame[4..frame.len() - 3]), None);
    }

    #[test]
    fn the_mode_defaults_to_off_for_anything_unknown() {
        assert_eq!(WafMode::parse("detect"), WafMode::Detect);
        assert_eq!(WafMode::parse("block"), WafMode::Block);
        assert_eq!(WafMode::parse(""), WafMode::Off);
        assert_eq!(WafMode::parse("NULL"), WafMode::Off);
    }

    #[test]
    fn a_rule_match_becomes_one_short_line() {
        let message = r#"ModSecurity: Warning. detected SQLi using libinjection. [file "/x/REQUEST-942.conf"] [line "46"] [id "942100"] [rev ""] [msg "SQL Injection Attack Detected via libinjection"] [data "Matched Data: s&sos found within ARGS:id: 1' OR '1'='1"] [severity "2"]"#;
        assert_eq!(summarize(message), "942100 SQL Injection Attack Detected via libinjection (Matched Data: s&sos found within ARGS:id: 1' OR '1'='1)");
        let verdict = Verdict { status: 403, messages: vec![message.into()] };
        let line = log_line("2026-11-09T10:00:00Z", &sample(), &verdict, false);
        assert!(line.starts_with("2026-11-09T10:00:00Z 203.0.113.7 POST /wp-comments-post.php?x=1 would-block 403 | 942100 "), "{line}");
        assert!(!line.contains('\n'));
    }

    #[test]
    fn only_a_small_known_body_is_read_first() {
        let request = |headers: &[(&str, &str)]| {
            let mut builder = Request::builder().method("POST").uri("/");
            for (name, value) in headers {
                builder = builder.header(*name, *value);
            }
            builder.body(Body::empty()).unwrap()
        };
        assert_eq!(buffered_length(&request(&[("content-length", "2048")])), Some(2048));
        assert_eq!(buffered_length(&request(&[("content-length", "999999999")])), None);
        assert_eq!(buffered_length(&request(&[("transfer-encoding", "chunked")])), None);
        assert_eq!(buffered_length(&request(&[])), None);
    }
}
