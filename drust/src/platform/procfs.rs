//! Linux `/proc`, for the few callers that read a process's own files
//! (PHP-FPM worker overview). Every reader gives `None` or nothing where
//! there is no `/proc`, so callers need no OS check.

use std::{fs, path::PathBuf};

fn process_file(pid: &str, name: &str) -> PathBuf {
    PathBuf::from("/proc").join(pid).join(name)
}

/// The numeric entries of `/proc`.
pub fn pids() -> Vec<String> {
    let Ok(entries) = fs::read_dir("/proc") else { return Vec::new() };
    entries
        .filter_map(Result::ok)
        .map(|entry| entry.file_name().to_string_lossy().to_string())
        .filter(|name| !name.is_empty() && name.bytes().all(|byte| byte.is_ascii_digit()))
        .collect()
}

/// `/proc/<pid>/<name>` as text.
pub fn read(pid: &str, name: &str) -> Option<String> {
    fs::read_to_string(process_file(pid, name)).ok()
}

/// `/proc/<pid>/<name>` as bytes (`cmdline` holds NULs).
pub fn read_bytes(pid: &str, name: &str) -> Option<Vec<u8>> {
    fs::read(process_file(pid, name)).ok()
}

/// `/proc/meminfo`.
pub fn meminfo() -> Option<String> {
    fs::read_to_string("/proc/meminfo").ok()
}

/// The 1, 5 and 15 minute load averages from `/proc/loadavg`.
pub fn loadavg() -> Option<[f64; 3]> {
    fs::read_to_string("/proc/loadavg").ok().as_deref().and_then(super::metrics::parse_loadavg)
}
