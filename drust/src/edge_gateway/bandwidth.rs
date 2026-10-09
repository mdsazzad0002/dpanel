use chrono::Utc;
use serde::{Deserialize, Serialize};
use std::{
    collections::HashMap,
    fs,
    path::{Path, PathBuf},
    sync::{
        Arc, RwLock,
        atomic::{AtomicU64, Ordering},
    },
    time::Duration,
};
use tracing::warn;

#[derive(Clone, Copy, Debug, Default, Deserialize, Serialize)]
pub struct DomainBandwidth {
    pub upload_bytes: u64,
    pub download_bytes: u64,
    pub requests: u64,
}

/// Bumped on every request without taking a write lock.
#[derive(Default)]
struct Counters {
    upload_bytes: AtomicU64,
    download_bytes: AtomicU64,
    requests: AtomicU64,
}

impl Counters {
    fn from_usage(usage: DomainBandwidth) -> Self {
        Self {
            upload_bytes: AtomicU64::new(usage.upload_bytes),
            download_bytes: AtomicU64::new(usage.download_bytes),
            requests: AtomicU64::new(usage.requests),
        }
    }

    fn usage(&self) -> DomainBandwidth {
        DomainBandwidth {
            upload_bytes: self.upload_bytes.load(Ordering::Relaxed),
            download_bytes: self.download_bytes.load(Ordering::Relaxed),
            requests: self.requests.load(Ordering::Relaxed),
        }
    }
}

type CounterMap = HashMap<String, Arc<Counters>>;

#[derive(Clone)]
pub struct BandwidthTracker {
    directory: Arc<PathBuf>,
    counters: Arc<RwLock<CounterMap>>,
}

fn counter_map(usage: HashMap<String, DomainBandwidth>) -> CounterMap {
    usage
        .into_iter()
        .map(|(domain, usage)| (domain, Arc::new(Counters::from_usage(usage))))
        .collect()
}

impl BandwidthTracker {
    pub fn from_env() -> Self {
        let directory = std::env::var("DRUST_BANDWIDTH_DIR")
            .map(PathBuf::from)
            .unwrap_or_else(|_| PathBuf::from("/var/lib/drust/bandwidth"));
        Self::new(directory)
    }

    pub fn new(directory: PathBuf) -> Self {
        if let Err(error) = fs::create_dir_all(&directory) {
            warn!(path = %directory.display(), error = %error, "bandwidth directory creation failed");
        }
        let counters = read_month(&month_path(&directory)).unwrap_or_default();
        Self {
            directory: Arc::new(directory),
            counters: Arc::new(RwLock::new(counter_map(counters))),
        }
    }

    pub fn record(&self, domain: &str, upload_bytes: u64, download_bytes: u64) {
        let domain = domain.trim().trim_end_matches('.').to_lowercase();
        if domain.is_empty() {
            return;
        }
        let existing = self.counters.read().ok().and_then(|counters| counters.get(&domain).cloned());
        let usage = match existing {
            Some(usage) => usage,
            // Only a domain's first request takes the write lock.
            None => match self.counters.write() {
                Ok(mut counters) => counters.entry(domain).or_default().clone(),
                Err(_) => return,
            },
        };
        usage.upload_bytes.fetch_add(upload_bytes, Ordering::Relaxed);
        usage.download_bytes.fetch_add(download_bytes, Ordering::Relaxed);
        usage.requests.fetch_add(1, Ordering::Relaxed);
    }

    pub fn spawn_periodic_flush(&self) {
        let tracker = self.clone();
        let interval = std::env::var("DRUST_BANDWIDTH_FLUSH_SECONDS")
            .ok()
            .and_then(|value| value.parse::<u64>().ok())
            .unwrap_or(30)
            .max(5);
        tokio::spawn(async move {
            let mut ticker = tokio::time::interval(Duration::from_secs(interval));
            ticker.tick().await;
            loop {
                ticker.tick().await;
                if let Err(error) = tracker.flush() {
                    warn!(error = %error, "bandwidth counter flush failed");
                }
            }
        });
    }

    pub fn flush(&self) -> Result<(), String> {
        fs::create_dir_all(self.directory.as_ref())
            .map_err(|error| format!("create bandwidth directory failed: {error}"))?;
        let snapshot = self.usage()?;
        let path = month_path(self.directory.as_ref());
        let temporary = path.with_extension("json.tmp");
        let payload = serde_json::to_vec(&snapshot)
            .map_err(|error| format!("serialize bandwidth counters failed: {error}"))?;
        fs::write(&temporary, payload)
            .map_err(|error| format!("write bandwidth counters failed: {error}"))?;
        fs::rename(&temporary, &path)
            .map_err(|error| format!("commit bandwidth counters failed: {error}"))
    }
}

impl BandwidthTracker {
    fn usage(&self) -> Result<HashMap<String, DomainBandwidth>, String> {
        Ok(self
            .counters
            .read()
            .map_err(|_| "bandwidth counter lock poisoned".to_string())?
            .iter()
            .map(|(domain, counters)| (domain.clone(), counters.usage()))
            .collect())
    }
}

fn month_path(directory: &Path) -> PathBuf {
    directory.join(format!("{}.json", Utc::now().format("%Y-%m")))
}

fn read_month(path: &Path) -> Result<HashMap<String, DomainBandwidth>, String> {
    if !path.is_file() {
        return Ok(HashMap::new());
    }
    let payload =
        fs::read(path).map_err(|error| format!("read bandwidth counters failed: {error}"))?;
    serde_json::from_slice(&payload)
        .map_err(|error| format!("parse bandwidth counters failed: {error}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn aggregates_domain_usage_case_insensitively() {
        let tracker = BandwidthTracker {
            directory: Arc::new(PathBuf::from("/tmp")),
            counters: Arc::new(RwLock::new(HashMap::new())),
        };
        tracker.record("Example.COM.", 10, 20);
        tracker.record("example.com", 5, 7);
        let counters = tracker.usage().unwrap();
        let usage = counters.get("example.com").unwrap();
        assert_eq!(usage.upload_bytes, 15);
        assert_eq!(usage.download_bytes, 27);
        assert_eq!(usage.requests, 2);
    }
}
