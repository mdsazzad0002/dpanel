//! How many PHP-FPM workers each site may run (`pm.max_children`), and how
//! many it is running now.
//!
//! The limit is normally sized automatically when a site's pool is created
//! (`site_pool_max_children`). An admin can pin it per site user instead; the
//! pinned value lives in `<state_dir>/php-pool-workers.json`, so a pool that
//! is written again later (a new PHP version, an account's own master) keeps
//! it.
//!
//! Usage is read from `/proc` rather than PHP-FPM's status page: site pools
//! are `ondemand`, and asking a pool with no workers for its status would
//! start one and keep it alive for the idle timeout. A worker waiting in
//! `accept()` is idle; any other worker is serving a request. How often a
//! pool ran out of workers comes from PHP-FPM's own log, which warns each
//! time a pool "reached max_children".

use std::{
    collections::{BTreeMap, BTreeSet, HashMap},
    fs,
    io::{Read, Seek, SeekFrom},
    path::{Path, PathBuf},
    process::Command,
};

use chrono::{Local, NaiveDate, NaiveDateTime};
use serde::Serialize;

use super::php::{provision_lock, reload_fpm_service, site_pool_max_children, validate_php_version};
use crate::platform;

pub const MIN_WORKERS: u16 = 1;
pub const MAX_WORKERS: u16 = 50;

/// How much of each FPM log is read from its end.
const LOG_TAIL_BYTES: u64 = 2 * 1024 * 1024;

#[cfg(target_arch = "x86_64")]
const ACCEPT_SYSCALLS: &[&str] = &["43", "288"];
#[cfg(target_arch = "aarch64")]
const ACCEPT_SYSCALLS: &[&str] = &["202", "242"];
#[cfg(not(any(target_arch = "x86_64", target_arch = "aarch64")))]
const ACCEPT_SYSCALLS: &[&str] = &[];

fn store_path() -> PathBuf {
    platform::paths().state_dir().join("php-pool-workers.json")
}

fn read_store() -> BTreeMap<String, u16> {
    fs::read_to_string(store_path())
        .ok()
        .and_then(|content| serde_json::from_str(&content).ok())
        .unwrap_or_default()
}

fn write_store(store: &BTreeMap<String, u16>) -> Result<(), String> {
    let path = store_path();
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent).map_err(|error| format!("cannot create {}: {error}", parent.display()))?;
    }
    let content = serde_json::to_string_pretty(store).map_err(|error| error.to_string())?;
    let temporary = path.with_extension("json.tmp");
    fs::write(&temporary, content).map_err(|error| format!("cannot write {}: {error}", temporary.display()))?;
    fs::rename(&temporary, &path).map_err(|error| format!("cannot replace {}: {error}", path.display()))
}

fn valid_limit(limit: u16) -> bool {
    (MIN_WORKERS..=MAX_WORKERS).contains(&limit)
}

/// The limit an admin pinned for this site user, if any.
pub(super) fn custom_limit(owner: &str) -> Option<u16> {
    read_store().get(owner).copied().filter(|limit| valid_limit(*limit))
}

/// One managed pool file: in the shared master's `pool.d`, or moved to an
/// account's own master.
#[derive(Debug, Clone)]
struct PoolFile {
    owner: String,
    pool: String,
    version: String,
    path: PathBuf,
    dedicated: bool,
    pm: String,
    max_children: Option<u16>,
}

fn php_versions() -> Vec<String> {
    let Ok(entries) = fs::read_dir(platform::php_fpm::config_root()) else { return Vec::new() };
    let mut versions: Vec<String> = entries
        .filter_map(Result::ok)
        .map(|entry| entry.file_name().to_string_lossy().to_string())
        .filter(|version| validate_php_version(version).is_ok())
        .collect();
    versions.sort();
    versions
}

fn pool_files() -> Vec<PoolFile> {
    let mut pools = Vec::new();
    for version in php_versions() {
        let root = platform::php_fpm::fpm_dir(&version);
        let places = [(root.join("pool.d"), "dpanel-", ".conf", false), (root.join("dpanel-dedicated"), "", ".pool", true)];
        for (directory, prefix, suffix, dedicated) in places {
            let Ok(entries) = fs::read_dir(&directory) else { continue };
            for entry in entries.filter_map(Result::ok) {
                let name = entry.file_name().to_string_lossy().to_string();
                let Some(owner) = name.strip_prefix(prefix).and_then(|rest| rest.strip_suffix(suffix)) else { continue };
                if !crate::app::valid_username(owner) {
                    continue;
                }
                let path = entry.path();
                let Ok(content) = fs::read_to_string(&path) else { continue };
                let pool = pool_name(&content).unwrap_or_else(|| owner.to_string());
                pools.push(PoolFile {
                    owner: owner.to_string(),
                    pool,
                    version: version.clone(),
                    path,
                    dedicated,
                    pm: setting(&content, "pm").unwrap_or_default(),
                    max_children: setting(&content, "pm.max_children").and_then(|value| value.parse().ok()),
                });
            }
        }
    }
    pools.sort_by(|a, b| a.owner.cmp(&b.owner).then_with(|| a.version.cmp(&b.version)));
    pools
}

fn pool_name(content: &str) -> Option<String> {
    content.lines().find_map(|line| {
        let line = line.trim();
        let name = line.strip_prefix('[')?.strip_suffix(']')?.trim();
        (!name.is_empty() && name != "global").then(|| name.to_string())
    })
}

fn setting(content: &str, key: &str) -> Option<String> {
    content.lines().find_map(|line| {
        let line = line.trim();
        if line.starts_with(';') || line.starts_with('#') {
            return None;
        }
        let (name, value) = line.split_once('=')?;
        (name.trim() == key).then(|| value.trim().to_string())
    })
}

/// The pool file with its `pm.max_children` set to `limit`.
fn with_max_children(content: &str, limit: u16) -> String {
    let wanted = format!("pm.max_children = {limit}");
    let mut replaced = false;
    let mut lines: Vec<String> = content
        .lines()
        .map(|line| {
            let trimmed = line.trim();
            let is_setting = !trimmed.starts_with(';')
                && trimmed.split_once('=').is_some_and(|(name, _)| name.trim() == "pm.max_children");
            if is_setting && !replaced {
                replaced = true;
                wanted.clone()
            } else if is_setting {
                // A second value would override the first; drop it.
                String::new()
            } else {
                line.to_string()
            }
        })
        .collect();
    if !replaced {
        let after = lines
            .iter()
            .position(|line| line.trim().split_once('=').is_some_and(|(name, _)| name.trim() == "pm"));
        match after {
            Some(index) => lines.insert(index + 1, wanted),
            None => lines.push(wanted),
        }
    }
    let mut result = lines.join("\n");
    if content.ends_with('\n') {
        result.push('\n');
    }
    result
}

/// A running PHP-FPM worker.
#[derive(Debug, Default, Clone)]
struct Worker {
    pool: String,
    version: Option<String>,
    busy: bool,
    rss_kb: u64,
}

fn version_in_path(text: &str) -> Option<String> {
    let version = platform::php_fpm::version_in_text(text)?;
    validate_php_version(version).ok().map(|_| version.to_string())
}

fn process_title(pid: &str) -> Option<String> {
    let raw = platform::procfs::read_bytes(pid, "cmdline")?;
    let title = String::from_utf8_lossy(&raw).replace('\0', " ");
    Some(title.trim().to_string())
}

fn parent_pid(pid: &str) -> Option<u32> {
    let stat = platform::procfs::read(pid, "stat")?;
    // "pid (comm) state ppid ..."; comm may itself contain spaces or ')'.
    stat[stat.rfind(')')? + 1..].split_whitespace().nth(1)?.parse().ok()
}

fn resident_kb(pid: &str) -> u64 {
    platform::procfs::read(pid, "status")
        .and_then(|status| {
            status
                .lines()
                .find_map(|line| line.strip_prefix("VmRSS:")?.split_whitespace().next()?.parse().ok())
        })
        .unwrap_or(0)
}

/// Idle workers block in accept(); "running" means on a CPU right now.
fn is_busy(pid: &str) -> bool {
    let Some(syscall) = platform::procfs::read(pid, "syscall") else { return false };
    let number = syscall.split_whitespace().next().unwrap_or("");
    !ACCEPT_SYSCALLS.contains(&number)
}

/// `observer` is the worker asking (the panel's own request): it is busy
/// only with this question, so it counts as idle.
fn running_workers(observer: Option<u32>) -> Vec<Worker> {
    let mut masters: HashMap<u32, String> = HashMap::new();
    let mut found: Vec<(String, Option<u32>, Worker)> = Vec::new();
    for pid in platform::procfs::pids() {
        let Some(title) = process_title(&pid) else { continue };
        if let Some(rest) = title.strip_prefix("php-fpm: master process") {
            if let (Ok(number), Some(version)) = (pid.parse::<u32>(), version_in_path(rest)) {
                masters.insert(number, version);
            }
        } else if let Some(pool) = title.strip_prefix("php-fpm: pool ") {
            let worker = Worker {
                pool: pool.trim().to_string(),
                version: None,
                busy: observer.is_none_or(|observer| pid != observer.to_string()) && is_busy(&pid),
                rss_kb: resident_kb(&pid),
            };
            found.push((pid.clone(), parent_pid(&pid), worker));
        }
    }
    found
        .into_iter()
        .map(|(_, parent, mut worker)| {
            worker.version = parent.and_then(|parent| masters.get(&parent).cloned());
            worker
        })
        .collect()
}

#[derive(Debug, Default, Clone, PartialEq, Eq)]
struct LimitHits {
    last_24h: u32,
    last: Option<NaiveDateTime>,
}

fn read_tail(path: &Path) -> String {
    let Ok(mut file) = fs::File::open(path) else { return String::new() };
    let length = file.metadata().map(|meta| meta.len()).unwrap_or(0);
    if length > LOG_TAIL_BYTES {
        let _ = file.seek(SeekFrom::Start(length - LOG_TAIL_BYTES));
    }
    let mut bytes = Vec::new();
    let _ = file.read_to_end(&mut bytes);
    String::from_utf8_lossy(&bytes).into_owned()
}

/// "16-Nov-2026 11:01:23", as PHP-FPM writes its log.
fn parse_log_time(text: &str) -> Option<NaiveDateTime> {
    let (date, time) = text.trim().split_once(' ')?;
    let mut date = date.split('-');
    let day: u32 = date.next()?.parse().ok()?;
    let month = match date.next()? {
        "Jan" => 1, "Feb" => 2, "Mar" => 3, "Apr" => 4, "May" => 5, "Jun" => 6,
        "Jul" => 7, "Aug" => 8, "Sep" => 9, "Oct" => 10, "Nov" => 11, "Dec" => 12,
        _ => return None,
    };
    let year: i32 = date.next()?.parse().ok()?;
    let mut time = time.split(':');
    let hour: u32 = time.next()?.parse().ok()?;
    let minute: u32 = time.next()?.parse().ok()?;
    let second: u32 = time.next()?.split('.').next()?.parse().ok()?;
    NaiveDate::from_ymd_opt(year, month, day)?.and_hms_opt(hour, minute, second)
}

/// Per pool name: how often it ran out of workers in the day before `now`.
fn limit_hits_in(log: &str, now: NaiveDateTime, hits: &mut HashMap<String, LimitHits>) {
    let since = now - chrono::Duration::hours(24);
    for line in log.lines() {
        if !line.contains("max_children") || !line.contains("server reached") {
            continue;
        }
        let Some(time) = line.strip_prefix('[').and_then(|rest| rest.split_once(']')).and_then(|(time, _)| parse_log_time(time)) else {
            continue;
        };
        let Some(pool) = line.split("[pool ").nth(1).and_then(|rest| rest.split(']').next()) else { continue };
        let entry = hits.entry(pool.trim().to_string()).or_default();
        if time >= since {
            entry.last_24h += 1;
        }
        if entry.last.is_none_or(|last| time > last) {
            entry.last = Some(time);
        }
    }
}

fn limit_hits(log: &Path, now: NaiveDateTime) -> HashMap<String, LimitHits> {
    let mut hits = HashMap::new();
    let mut rotated = log.as_os_str().to_owned();
    rotated.push(".1");
    for path in [PathBuf::from(rotated), log.to_path_buf()] {
        limit_hits_in(&read_tail(&path), now, &mut hits);
    }
    hits
}

fn shared_log(version: &str) -> PathBuf {
    platform::php_fpm::shared_log(version)
}

fn dedicated_log(owner: &str, version: &str) -> PathBuf {
    platform::php_fpm::dedicated_log(owner, version)
}

fn meminfo_kb(key: &str) -> u64 {
    platform::procfs::meminfo()
        .and_then(|content| {
            content.lines().find_map(|line| line.strip_prefix(key)?.split_whitespace().next()?.parse().ok())
        })
        .unwrap_or(0)
}

#[derive(Debug, Serialize)]
pub struct PoolUsage {
    pub owner: String,
    pub pool: String,
    pub version: String,
    pub dedicated: bool,
    pub pm: String,
    pub max_children: Option<u16>,
    pub custom: bool,
    pub running: usize,
    pub active: usize,
    pub idle: usize,
    pub memory_kb: u64,
    pub limit_hits_24h: u32,
    pub last_limit_hit: Option<String>,
}

#[derive(Debug, Serialize)]
pub struct Overview {
    pub pools: Vec<PoolUsage>,
    pub automatic_limit: u16,
    pub min_limit: u16,
    pub max_limit: u16,
    pub average_worker_kb: u64,
    pub memory_total_kb: u64,
    pub memory_available_kb: u64,
}

/// Every managed site pool with its limit and what it is using now.
pub fn overview(observer: Option<u32>) -> Overview {
    let store = read_store();
    let workers = running_workers(observer);
    let now = Local::now().naive_local();
    let mut logs: HashMap<PathBuf, HashMap<String, LimitHits>> = HashMap::new();

    let pools = pool_files()
        .into_iter()
        .map(|file| {
            let mine: Vec<&Worker> = workers
                .iter()
                .filter(|worker| worker.pool == file.pool && worker.version.as_deref() == Some(file.version.as_str()))
                .collect();
            let active = mine.iter().filter(|worker| worker.busy).count();
            let log = if file.dedicated { dedicated_log(&file.owner, &file.version) } else { shared_log(&file.version) };
            let hits = logs
                .entry(log.clone())
                .or_insert_with(|| limit_hits(&log, now))
                .get(&file.pool)
                .cloned()
                .unwrap_or_default();
            PoolUsage {
                custom: store.contains_key(&file.owner),
                running: mine.len(),
                active,
                idle: mine.len() - active,
                memory_kb: mine.iter().map(|worker| worker.rss_kb).sum(),
                limit_hits_24h: hits.last_24h,
                last_limit_hit: hits.last.map(|time| time.format("%Y-%m-%d %H:%M:%S").to_string()),
                owner: file.owner,
                pool: file.pool,
                version: file.version,
                dedicated: file.dedicated,
                pm: file.pm,
                max_children: file.max_children,
            }
        })
        .collect();

    let site_workers: Vec<&Worker> = workers.iter().filter(|worker| worker.pool != "www").collect();
    let average_worker_kb = if site_workers.is_empty() {
        0
    } else {
        site_workers.iter().map(|worker| worker.rss_kb).sum::<u64>() / site_workers.len() as u64
    };

    Overview {
        pools,
        automatic_limit: site_pool_max_children(),
        min_limit: MIN_WORKERS,
        max_limit: MAX_WORKERS,
        average_worker_kb,
        memory_total_kb: meminfo_kb("MemTotal:"),
        memory_available_kb: meminfo_kb("MemAvailable:"),
    }
}

/// A PHP-FPM master to reload after its pool files changed.
#[derive(Debug, Clone, PartialEq, Eq, PartialOrd, Ord)]
pub enum Reload {
    Shared(String),
    Dedicated { owner: String, version: String },
}

impl Reload {
    pub fn run(&self) -> Result<(), String> {
        match self {
            Reload::Shared(version) => reload_fpm_service(version),
            Reload::Dedicated { owner, version } => {
                let unit = super::php_fpm_master::unit_name(owner, version);
                platform::services()
                    .reload_or_restart(&unit)
                    .map_err(|error| format!("cannot reload {unit}: {error}"))
            }
        }
    }
}

#[derive(Debug)]
pub struct Change {
    pub limit: u16,
    pub custom: bool,
    pub reloads: Vec<Reload>,
}

fn test_configuration(file: &PoolFile) -> Result<(), String> {
    let binary = platform::php_fpm::binary(&file.version);
    let mut command = Command::new(&binary);
    command.arg("-t");
    if file.dedicated {
        command.arg("--fpm-config").arg(file.path.with_extension("conf"));
    }
    let output = command.output().map_err(|error| format!("cannot run {}: {error}", binary.display()))?;
    if output.status.success() {
        Ok(())
    } else {
        Err(String::from_utf8_lossy(&output.stderr).trim().to_string())
    }
}

/// Pins (`Some`) or unpins (`None`, back to automatic sizing) this site
/// user's worker limit and rewrites its pools. The masters still have to be
/// reloaded with [`Reload::run`] for the change to take effect; the caller
/// does that after answering, as the panel itself may run on one of them.
pub fn set_limit(owner: &str, limit: Option<u16>) -> Result<Change, String> {
    if !crate::app::valid_username(owner) {
        return Err(format!("invalid site user: {owner}"));
    }
    if let Some(limit) = limit {
        if !valid_limit(limit) {
            return Err(format!("worker limit must be between {MIN_WORKERS} and {MAX_WORKERS}"));
        }
    }
    let _guard = provision_lock()?;

    let files: Vec<PoolFile> = pool_files().into_iter().filter(|file| file.owner == owner).collect();
    let effective = limit.unwrap_or_else(site_pool_max_children);

    let mut written: Vec<(PathBuf, String)> = Vec::new();
    let mut reloads = BTreeSet::new();
    let restore = |written: &[(PathBuf, String)]| {
        for (path, original) in written {
            let _ = fs::write(path, original);
        }
    };
    for file in &files {
        let original = fs::read_to_string(&file.path).map_err(|error| format!("cannot read {}: {error}", file.path.display()))?;
        let updated = with_max_children(&original, effective);
        if updated == original {
            continue;
        }
        if let Err(error) = fs::write(&file.path, &updated) {
            restore(&written);
            return Err(format!("cannot write {}: {error}", file.path.display()));
        }
        written.push((file.path.clone(), original));
        reloads.insert(if file.dedicated {
            Reload::Dedicated { owner: owner.to_string(), version: file.version.clone() }
        } else {
            Reload::Shared(file.version.clone())
        });
    }

    for file in files.iter().filter(|file| written.iter().any(|(path, _)| path == &file.path)) {
        if let Err(error) = test_configuration(file) {
            restore(&written);
            return Err(format!("PHP {} configuration test failed: {error}", file.version));
        }
    }

    let mut store = read_store();
    match limit {
        Some(limit) => store.insert(owner.to_string(), limit),
        None => store.remove(owner),
    };
    if let Err(error) = write_store(&store) {
        restore(&written);
        return Err(error);
    }

    Ok(Change { limit: effective, custom: limit.is_some(), reloads: reloads.into_iter().collect() })
}

#[cfg(test)]
mod tests {
    use super::*;

    const POOL: &str = "; Managed dynamically by drust edge gateway.\n[shop]\nuser = shop\npm = ondemand\npm.max_children = 2\npm.max_requests = 500\n";

    #[test]
    fn replaces_the_worker_limit_and_keeps_everything_else() {
        let updated = with_max_children(POOL, 8);
        assert_eq!(updated, POOL.replace("pm.max_children = 2", "pm.max_children = 8"));
        assert_eq!(with_max_children(&updated, 8), updated);
    }

    #[test]
    fn adds_a_missing_limit_after_the_process_manager() {
        let updated = with_max_children("[shop]\npm = ondemand\npm.max_requests = 500\n", 4);
        assert_eq!(updated, "[shop]\npm = ondemand\npm.max_children = 4\npm.max_requests = 500\n");
    }

    #[test]
    fn ignores_commented_limits() {
        let updated = with_max_children("[shop]\n;pm.max_children = 9\npm = ondemand\n", 3);
        assert!(updated.contains(";pm.max_children = 9\n"));
        assert!(updated.contains("pm = ondemand\npm.max_children = 3\n"));
    }

    #[test]
    fn reads_pool_settings() {
        assert_eq!(pool_name(POOL).as_deref(), Some("shop"));
        assert_eq!(setting(POOL, "pm").as_deref(), Some("ondemand"));
        assert_eq!(setting(POOL, "pm.max_children").as_deref(), Some("2"));
    }

    #[test]
    fn finds_the_php_version_of_a_master() {
        assert_eq!(version_in_path(" (/etc/php/8.3/fpm/php-fpm.conf)").as_deref(), Some("8.3"));
        assert_eq!(version_in_path(" (/etc/php/8.4/fpm/dpanel-dedicated/shop.conf)").as_deref(), Some("8.4"));
        assert_eq!(version_in_path(" (/opt/php.conf)"), None);
    }

    #[test]
    fn counts_recent_limit_warnings_per_pool() {
        let log = "\
[14-Nov-2026 09:00:00] WARNING: [pool shop] server reached max_children setting (2), consider raising it\n\
[16-Nov-2026 10:00:00] WARNING: [pool shop] server reached max_children setting (2), consider raising it\n\
[16-Nov-2026 10:30:00] WARNING: [pool www] server reached pm.max_children setting (5), consider raising it\n\
[16-Nov-2026 11:00:00] NOTICE: [pool shop] child 123 started\n\
[16-Nov-2026 11:01:23] WARNING: [pool shop] server reached max_children setting (2), consider raising it\n";
        let now = parse_log_time("16-Nov-2026 12:00:00").unwrap();
        let mut hits = HashMap::new();
        limit_hits_in(log, now, &mut hits);
        assert_eq!(hits["shop"].last_24h, 2);
        assert_eq!(hits["shop"].last, parse_log_time("16-Nov-2026 11:01:23"));
        assert_eq!(hits["www"].last_24h, 1);
    }
}
