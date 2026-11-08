//! Per-site access log (backlog S4). The gateway appends one line per
//! request to `<data_dir>/logs/sites/<domain>/access.log`, in the combined
//! format plus the time taken. A writer thread does the file work, so a
//! slow disk never holds a request: when its queue is full, lines are dropped.
//!
//! A file over `MAX_BYTES` becomes `access.log.1` (the older ones shift up,
//! `KEEP` are kept). PHP's own error log per account is rotated the same way.

use std::{
    collections::HashMap,
    fs::{self, File, OpenOptions},
    io::{self, Read, Seek, SeekFrom, Write},
    net::SocketAddr,
    path::{Path, PathBuf},
    sync::{OnceLock, mpsc},
    time::{Duration, Instant},
};

use axum::{
    body::Body,
    extract::ConnectInfo,
    http::{Request, Response, header},
};
use chrono::{DateTime, Utc};

pub const MAX_BYTES: u64 = 10 * 1024 * 1024;
pub const KEEP: usize = 5;
const QUEUE: usize = 10_000;

/// Where every site's access log lives.
pub fn logs_root() -> PathBuf {
    crate::platform::paths().data_dir().join("logs").join("sites")
}

/// PHP's error log for an account: its pool writes here (`php.rs`).
pub fn php_error_log(owner: &str) -> PathBuf {
    crate::platform::paths().site_home(owner).join("logs").join("php-error.log")
}

/// A host name safe to use as a folder: letters, digits, `-`, `.`, no `..`.
pub fn safe_domain(domain: &str) -> Option<String> {
    let domain = domain.trim().trim_end_matches('.').to_ascii_lowercase();
    let fine = !domain.is_empty()
        && domain.len() <= 253
        && !domain.starts_with('.')
        && !domain.contains("..")
        && domain.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '.');
    fine.then_some(domain)
}

pub fn access_log_path(root: &Path, domain: &str) -> Option<PathBuf> {
    safe_domain(domain).map(|domain| root.join(domain).join("access.log"))
}

#[derive(Debug, Clone)]
pub struct AccessEntry {
    pub domain: String,
    pub client: Option<std::net::IpAddr>,
    pub method: String,
    pub target: String,
    pub version: String,
    pub status: u16,
    pub bytes: Option<u64>,
    pub referer: Option<String>,
    pub user_agent: Option<String>,
    pub duration: Duration,
    pub time: DateTime<Utc>,
}

/// What the request says about itself, taken before it is handled.
pub struct RequestFacts {
    client: Option<std::net::IpAddr>,
    method: String,
    target: String,
    version: String,
    referer: Option<String>,
    user_agent: Option<String>,
    started: Instant,
    time: DateTime<Utc>,
}

impl RequestFacts {
    pub fn of(request: &Request<Body>) -> Self {
        let header_text = |name: header::HeaderName| request.headers().get(name).and_then(|v| v.to_str().ok()).map(str::to_string);
        Self {
            client: request.extensions().get::<ConnectInfo<SocketAddr>>().map(|info| info.0.ip()),
            method: request.method().to_string(),
            target: request.uri().path_and_query().map_or_else(|| request.uri().path().to_string(), |pq| pq.to_string()),
            version: format!("{:?}", request.version()),
            referer: header_text(header::REFERER),
            user_agent: header_text(header::USER_AGENT),
            started: Instant::now(),
            time: Utc::now(),
        }
    }

    pub fn finish(self, domain: String, response: &Response<Body>) -> AccessEntry {
        let bytes = response
            .headers()
            .get(header::CONTENT_LENGTH)
            .and_then(|v| v.to_str().ok())
            .and_then(|v| v.parse().ok())
            .or_else(|| axum::body::HttpBody::size_hint(response.body()).exact());
        AccessEntry {
            domain,
            client: self.client,
            method: self.method,
            target: self.target,
            version: self.version,
            status: response.status().as_u16(),
            bytes,
            referer: self.referer,
            user_agent: self.user_agent,
            duration: self.started.elapsed(),
            time: self.time,
        }
    }
}

/// A quoted field: `"` and control characters cannot break the line.
fn quoted(value: Option<&str>) -> String {
    let value = value.unwrap_or("-");
    let clean: String = value
        .chars()
        .map(|c| if c == '"' { '\'' } else if c.is_control() { ' ' } else { c })
        .take(2048)
        .collect();
    format!("\"{clean}\"")
}

pub fn format_line(entry: &AccessEntry) -> String {
    let request = format!("{} {} {}", entry.method, entry.target, entry.version);
    format!(
        "{} - - [{}] {} {} {} {} {} {}\n",
        entry.client.map_or("-".to_string(), |ip| ip.to_string()),
        entry.time.format("%d/%b/%Y:%H:%M:%S %z"),
        quoted(Some(&request)),
        entry.status,
        entry.bytes.map_or("-".to_string(), |b| b.to_string()),
        quoted(entry.referer.as_deref()),
        quoted(entry.user_agent.as_deref()),
        entry.duration.as_millis(),
    )
}

/// `log` becomes `log.1`, `log.1` becomes `log.2` …; the oldest past `keep` goes.
pub fn rotate(path: &Path, keep: usize) -> io::Result<()> {
    let numbered = |n: usize| PathBuf::from(format!("{}.{n}", path.display()));
    let _ = fs::remove_file(numbered(keep));
    for n in (1..keep).rev() {
        if numbered(n).exists() {
            fs::rename(numbered(n), numbered(n + 1))?;
        }
    }
    fs::rename(path, numbered(1))
}

pub fn rotate_if_large(path: &Path, max_bytes: u64, keep: usize) -> io::Result<bool> {
    match fs::metadata(path) {
        Ok(meta) if meta.len() > max_bytes => rotate(path, keep).map(|_| true),
        _ => Ok(false),
    }
}

/// The last `lines` lines, reading at most `max_bytes` from the end.
pub fn tail(path: &Path, lines: usize, max_bytes: u64) -> io::Result<Vec<String>> {
    let mut file = File::open(path)?;
    let len = file.metadata()?.len();
    let start = len.saturating_sub(max_bytes);
    file.seek(SeekFrom::Start(start))?;
    let mut buffer = Vec::new();
    file.take(max_bytes).read_to_end(&mut buffer)?;
    let text = String::from_utf8_lossy(&buffer);
    let mut all: Vec<&str> = text.lines().collect();
    if start > 0 && !all.is_empty() {
        all.remove(0); // cut in the middle
    }
    let skip = all.len().saturating_sub(lines);
    Ok(all[skip..].iter().map(|line| line.to_string()).collect())
}

struct OpenLog {
    file: File,
    size: u64,
}

/// Owns the open files; runs on its own thread.
pub struct Writer {
    root: PathBuf,
    max_bytes: u64,
    keep: usize,
    open: HashMap<String, OpenLog>,
}

impl Writer {
    pub fn new(root: PathBuf, max_bytes: u64, keep: usize) -> Self {
        Self { root, max_bytes, keep, open: HashMap::new() }
    }

    pub fn write(&mut self, entry: &AccessEntry) -> io::Result<()> {
        let Some(path) = access_log_path(&self.root, &entry.domain) else { return Ok(()) };
        let line = format_line(entry);
        if !self.open.contains_key(&entry.domain) {
            if let Some(dir) = path.parent() {
                fs::create_dir_all(dir)?;
            }
            let file = OpenOptions::new().create(true).append(true).open(&path)?;
            let size = file.metadata()?.len();
            self.open.insert(entry.domain.clone(), OpenLog { file, size });
        }
        let log = self.open.get_mut(&entry.domain).expect("opened above");
        log.file.write_all(line.as_bytes())?;
        log.size += line.len() as u64;
        if log.size > self.max_bytes {
            self.open.remove(&entry.domain);
            rotate(&path, self.keep)?;
        }
        Ok(())
    }
}

static SENDER: OnceLock<mpsc::SyncSender<AccessEntry>> = OnceLock::new();

/// Starts the writer thread; before this, `record` does nothing.
pub fn start(root: PathBuf) {
    if SENDER.get().is_some() {
        return;
    }
    let (sender, receiver) = mpsc::sync_channel::<AccessEntry>(QUEUE);
    if SENDER.set(sender).is_err() {
        return;
    }
    std::thread::Builder::new()
        .name("site-access-log".into())
        .spawn(move || {
            let mut writer = Writer::new(root, MAX_BYTES, KEEP);
            for entry in receiver {
                if let Err(error) = writer.write(&entry) {
                    tracing::debug!(domain = %entry.domain, %error, "access log write failed");
                }
            }
        })
        .map(|_| ())
        .unwrap_or_else(|error| tracing::warn!(%error, "access log writer did not start"));
}

pub fn record(entry: AccessEntry) {
    if let Some(sender) = SENDER.get() {
        let _ = sender.try_send(entry);
    }
}

/// Rotates every account's PHP error log that grew past the limit.
pub fn rotate_php_error_logs(sites_root: &Path) {
    let Ok(homes) = fs::read_dir(sites_root) else { return };
    for home in homes.flatten() {
        let log = home.path().join("logs").join("php-error.log");
        if let Err(error) = rotate_if_large(&log, MAX_BYTES, KEEP) {
            tracing::debug!(path = %log.display(), %error, "PHP error log rotation failed");
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn entry(domain: &str) -> AccessEntry {
        AccessEntry {
            domain: domain.into(),
            client: Some("203.0.113.9".parse().unwrap()),
            method: "GET".into(),
            target: "/shop?q=1".into(),
            version: "HTTP/1.1".into(),
            status: 200,
            bytes: Some(512),
            referer: None,
            user_agent: Some("curl/8 \"evil\"\nnext".into()),
            duration: Duration::from_millis(12),
            time: DateTime::parse_from_rfc3339("2026-11-08T10:00:00Z").unwrap().with_timezone(&Utc),
        }
    }

    fn temp(name: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!("site-logs-{name}-{}", std::process::id()));
        let _ = fs::remove_dir_all(&dir);
        fs::create_dir_all(&dir).unwrap();
        dir
    }

    #[test]
    fn a_line_is_combined_format_and_a_header_cannot_break_it() {
        let line = format_line(&entry("shop.test"));
        assert_eq!(
            line,
            "203.0.113.9 - - [08/Nov/2026:10:00:00 +0000] \"GET /shop?q=1 HTTP/1.1\" 200 512 \"-\" \"curl/8 'evil' next\" 12\n"
        );
        assert_eq!(line.matches('\n').count(), 1);
    }

    #[test]
    fn a_domain_cannot_leave_the_logs_folder() {
        let root = Path::new("/logs");
        assert_eq!(access_log_path(root, "Shop.Test.").unwrap(), root.join("shop.test/access.log"));
        assert!(access_log_path(root, "../etc").is_none());
        assert!(access_log_path(root, "a/b").is_none());
        assert!(access_log_path(root, "").is_none());
    }

    #[test]
    fn the_writer_rotates_and_keeps_only_so_many() {
        let root = temp("rotate");
        let mut writer = Writer::new(root.clone(), 200, 2);
        for _ in 0..20 {
            writer.write(&entry("shop.test")).unwrap();
        }
        let dir = root.join("shop.test");
        assert!(dir.join("access.log.1").exists());
        assert!(dir.join("access.log.2").exists());
        assert!(!dir.join("access.log.3").exists());
        assert!(fs::metadata(dir.join("access.log.1")).unwrap().len() > 200);
        let _ = fs::remove_dir_all(root);
    }

    #[test]
    fn tail_gives_the_last_whole_lines() {
        let root = temp("tail");
        let path = root.join("log");
        fs::write(&path, (1..=100).map(|n| format!("line {n}\n")).collect::<String>()).unwrap();
        assert_eq!(tail(&path, 3, 1 << 20).unwrap(), vec!["line 98", "line 99", "line 100"]);
        // Reading only the end drops the half line it starts in.
        let short = tail(&path, 100, 30).unwrap();
        assert_eq!(short.last().unwrap(), "line 100");
        assert!(short.iter().all(|line| line.starts_with("line ")));
        let _ = fs::remove_dir_all(root);
    }

    #[test]
    fn a_small_php_log_is_left_alone() {
        let root = temp("php");
        let logs = root.join("site1").join("logs");
        fs::create_dir_all(&logs).unwrap();
        fs::write(logs.join("php-error.log"), "x").unwrap();
        rotate_php_error_logs(&root);
        assert!(logs.join("php-error.log").exists());
        assert!(!logs.join("php-error.log.1").exists());
        let _ = fs::remove_dir_all(root);
    }
}
