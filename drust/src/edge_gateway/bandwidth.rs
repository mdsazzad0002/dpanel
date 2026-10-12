use chrono::Utc;
use serde::{Deserialize, Serialize};
use std::{
    collections::HashMap,
    fs,
    path::{Path, PathBuf},
    sync::{
        Arc, Mutex, RwLock,
        atomic::{AtomicU64, Ordering},
    },
    time::Duration,
};
use tracing::warn;

#[derive(Clone, Debug, Default, Deserialize, Serialize)]
pub struct DomainBandwidth {
    pub upload_bytes: u64,
    pub download_bytes: u64,
    pub requests: u64,
    #[serde(default)]
    pub days: HashMap<String, DailyBandwidth>,
}

#[derive(Clone, Copy, Debug, Default, Deserialize, Serialize)]
pub struct DailyBandwidth {
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
    days: Mutex<HashMap<String, Arc<DayCounters>>>,
}

#[derive(Default)]
struct DayCounters {
    upload_bytes: AtomicU64,
    download_bytes: AtomicU64,
    requests: AtomicU64,
}

impl DayCounters {
    fn usage(&self) -> DailyBandwidth {
        DailyBandwidth {
            upload_bytes: self.upload_bytes.load(Ordering::Relaxed),
            download_bytes: self.download_bytes.load(Ordering::Relaxed),
            requests: self.requests.load(Ordering::Relaxed),
        }
    }
}

impl Counters {
    fn from_usage(usage: DomainBandwidth) -> Self {
        Self {
            upload_bytes: AtomicU64::new(usage.upload_bytes),
            download_bytes: AtomicU64::new(usage.download_bytes),
            requests: AtomicU64::new(usage.requests),
            days: Mutex::new(
                usage.days.into_iter()
                    .map(|(day, usage)| (day, Arc::new(DayCounters {
                        upload_bytes: AtomicU64::new(usage.upload_bytes),
                        download_bytes: AtomicU64::new(usage.download_bytes),
                        requests: AtomicU64::new(usage.requests),
                    })))
                    .collect(),
            ),
        }
    }

    fn usage(&self) -> DomainBandwidth {
        let days = self.days.lock().map(|days| {
            days.iter().map(|(day, counters)| (day.clone(), counters.usage())).collect()
        }).unwrap_or_default();
        DomainBandwidth {
            upload_bytes: self.upload_bytes.load(Ordering::Relaxed),
            download_bytes: self.download_bytes.load(Ordering::Relaxed),
            requests: self.requests.load(Ordering::Relaxed),
            days,
        }
    }

    fn day(&self, day: &str) -> Arc<DayCounters> {
        let Ok(mut days) = self.days.lock() else { return Arc::new(DayCounters::default()); };
        days.entry(day.to_string()).or_default().clone()
    }
}

#[derive(Clone)]
pub struct DomainBandwidthCounter {
    monthly: Arc<Counters>,
    daily: Arc<DayCounters>,
}

impl DomainBandwidthCounter {
    pub fn record_upload(&self, bytes: u64) {
        self.monthly.upload_bytes.fetch_add(bytes, Ordering::Relaxed);
        self.daily.upload_bytes.fetch_add(bytes, Ordering::Relaxed);
    }

    pub fn record_download(&self, bytes: u64) {
        self.monthly.download_bytes.fetch_add(bytes, Ordering::Relaxed);
        self.daily.download_bytes.fetch_add(bytes, Ordering::Relaxed);
    }
}

type CounterMap = HashMap<String, Arc<Counters>>;

#[derive(Clone)]
pub struct BandwidthTracker {
    directory: Arc<PathBuf>,
    counters: Arc<RwLock<CounterMap>>,
    month: Arc<RwLock<String>>,
    previous_months: Arc<RwLock<HashMap<String, CounterMap>>>,
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
        let month = current_month();
        let counters = read_month(&month_path_for(&directory, &month)).unwrap_or_default();
        Self {
            directory: Arc::new(directory),
            counters: Arc::new(RwLock::new(counter_map(counters))),
            month: Arc::new(RwLock::new(month)),
            previous_months: Arc::new(RwLock::new(HashMap::new())),
        }
    }

    #[cfg(test)]
    pub fn record(&self, domain: &str, upload_bytes: u64, download_bytes: u64) {
        self.rollover_if_needed();
        let Some(usage) = self.counter_for_domain(domain) else { return; };
        let daily = usage.day(&Utc::now().format("%Y-%m-%d").to_string());
        usage.upload_bytes.fetch_add(upload_bytes, Ordering::Relaxed);
        usage.download_bytes.fetch_add(download_bytes, Ordering::Relaxed);
        usage.requests.fetch_add(1, Ordering::Relaxed);
        daily.upload_bytes.fetch_add(upload_bytes, Ordering::Relaxed);
        daily.download_bytes.fetch_add(download_bytes, Ordering::Relaxed);
        daily.requests.fetch_add(1, Ordering::Relaxed);
    }

    pub fn begin_request(&self, domain: &str) -> Option<DomainBandwidthCounter> {
        self.rollover_if_needed();
        let usage = self.counter_for_domain(domain)?;
        usage.requests.fetch_add(1, Ordering::Relaxed);
        let day = Utc::now().format("%Y-%m-%d").to_string();
        let daily = usage.day(&day);
        daily.requests.fetch_add(1, Ordering::Relaxed);
        Some(DomainBandwidthCounter { monthly: usage, daily })
    }

    fn counter_for_domain(&self, domain: &str) -> Option<Arc<Counters>> {
        let domain = domain.trim().trim_end_matches('.').to_lowercase();
        if domain.is_empty() { return None; }
        let existing = self.counters.read().ok().and_then(|counters| counters.get(&domain).cloned());
        match existing {
            Some(usage) => Some(usage),
            // Only a domain's first request takes the write lock.
            None => self.counters.write().ok().map(|mut counters| counters.entry(domain).or_default().clone()),
        }
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
        self.rollover_if_needed();
        let (current_month, current, previous) = {
            let month = self.month.read()
                .map_err(|_| "bandwidth month lock poisoned".to_string())?;
            let current = self.usage()?;
            let previous = self.previous_months.read()
                .map_err(|_| "bandwidth archive lock poisoned".to_string())?
                .iter()
                .map(|(month, counters)| (month.clone(), usage_from(counters)))
                .collect::<HashMap<_, _>>();
            (month.clone(), current, previous)
        };
        write_month(self.directory.as_ref(), &current_month, &current)?;
        for (month, usage) in previous {
            write_month(self.directory.as_ref(), &month, &usage)?;
        }
        Ok(())
    }

    fn rollover_if_needed(&self) {
        let next_month = current_month();
        let Ok(mut current_month) = self.month.write() else { return; };
        if *current_month == next_month { return; }

        let Ok(mut current_counters) = self.counters.write() else { return; };
        let next = counter_map(read_month(&month_path_for(self.directory.as_ref(), &next_month)).unwrap_or_default());
        let previous = std::mem::replace(&mut *current_counters, next);
        drop(current_counters);
        if let Ok(mut archived) = self.previous_months.write() {
            archived.insert(current_month.clone(), previous);
            while archived.len() > 2 {
                if let Some(oldest) = archived.keys().min().cloned() {
                    archived.remove(&oldest);
                }
            }
        }
        *current_month = next_month;
    }
}

impl BandwidthTracker {
    fn usage(&self) -> Result<HashMap<String, DomainBandwidth>, String> {
        let counters = self.counters.read()
            .map_err(|_| "bandwidth counter lock poisoned".to_string())?;
        Ok(usage_from(&counters))
    }
}

fn usage_from(counters: &CounterMap) -> HashMap<String, DomainBandwidth> {
    counters.iter().map(|(domain, counters)| (domain.clone(), counters.usage())).collect()
}

fn current_month() -> String {
    Utc::now().format("%Y-%m").to_string()
}

fn month_path_for(directory: &Path, month: &str) -> PathBuf {
    directory.join(format!("{month}.json"))
}

fn write_month(directory: &Path, month: &str, snapshot: &HashMap<String, DomainBandwidth>) -> Result<(), String> {
    let path = month_path_for(directory, month);
    let temporary = path.with_extension("json.tmp");
    let payload = serde_json::to_vec(snapshot)
        .map_err(|error| format!("serialize bandwidth counters failed: {error}"))?;
    fs::write(&temporary, payload)
        .map_err(|error| format!("write bandwidth counters failed: {error}"))?;
    fs::rename(&temporary, &path)
        .map_err(|error| format!("commit bandwidth counters failed: {error}"))
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
            month: Arc::new(RwLock::new(current_month())),
            previous_months: Arc::new(RwLock::new(HashMap::new())),
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
