//! Steps a site's PHP worker limit up when it runs out of workers and the
//! server still has room.
//!
//! Every few minutes, each automatic (not admin-pinned) site pool that hit
//! `pm.max_children` recently gets [`STEP`] more workers, up to
//! [`MAX_WORKERS`], provided the server is healthy: after the new workers'
//! memory, enough RAM stays free, and the CPU is not already saturated. Each
//! further step needs a new limit hit after the previous one, so a pool grows
//! only as long as it keeps running out.
//!
//! The raised limit lives in `<state_dir>/php-pool-autoscale.json`, so a pool
//! written again later keeps it. An admin pinning or resetting the limit on
//! the PHP Workers page clears it. Linux only: elsewhere there is no
//! `/proc` to judge health by, and nothing is changed.

use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    path::PathBuf,
    time::Duration,
};

use chrono::{Local, NaiveDateTime};
use serde::{Deserialize, Serialize};

use super::php::provision_lock;
use super::php_workers::{self, MAX_WORKERS};
use crate::platform;

/// Workers added per step.
pub const STEP: u16 = 5;
/// How often pools are checked.
const CHECK_EVERY: Duration = Duration::from_secs(300);
/// A limit hit older than this is not a reason to step up now.
const RECENT_MINUTES: i64 = 10;
/// Share of RAM that must stay available after the new workers.
const MIN_FREE_PERCENT: u64 = 25;
/// The 5-minute load average must stay below this share of the CPU cores.
const MAX_LOAD_PER_CORE: f64 = 0.8;
const TIME_FORMAT: &str = "%Y-%m-%d %H:%M:%S";

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
struct Raised {
    limit: u16,
    /// Local time of the step, as PHP-FPM's log times are local.
    raised_at: String,
}

fn store_path() -> PathBuf {
    platform::paths().state_dir().join("php-pool-autoscale.json")
}

fn read_store() -> BTreeMap<String, Raised> {
    fs::read_to_string(store_path())
        .ok()
        .and_then(|content| serde_json::from_str(&content).ok())
        .unwrap_or_default()
}

fn write_store(store: &BTreeMap<String, Raised>) -> Result<(), String> {
    let path = store_path();
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent).map_err(|error| format!("cannot create {}: {error}", parent.display()))?;
    }
    let content = serde_json::to_string_pretty(store).map_err(|error| error.to_string())?;
    let temporary = path.with_extension("json.tmp");
    fs::write(&temporary, content).map_err(|error| format!("cannot write {}: {error}", temporary.display()))?;
    fs::rename(&temporary, &path).map_err(|error| format!("cannot replace {}: {error}", path.display()))
}

/// The limit this site user was stepped up to, if any.
pub(super) fn raised_limit(owner: &str) -> Option<u16> {
    read_store().get(owner).map(|raised| raised.limit).filter(|limit| (1..=MAX_WORKERS).contains(limit))
}

/// Site users whose limit was stepped up.
pub(super) fn raised_owners() -> BTreeSet<String> {
    read_store().into_keys().collect()
}

/// Drops a step-up; the admin set the limit by hand.
pub(super) fn forget(owner: &str) {
    let mut store = read_store();
    if store.remove(owner).is_some() {
        if let Err(error) = write_store(&store) {
            tracing::warn!(owner, %error, "cannot clear the automatic PHP worker step-up");
        }
    }
}

fn enabled() -> bool {
    !matches!(
        std::env::var("DRUST_PHP_WORKER_AUTOSCALE").ok().as_deref().map(str::trim),
        Some("0" | "false" | "off" | "no")
    )
}

/// Checks the pools every few minutes on the current Tokio runtime.
pub fn spawn() {
    if !cfg!(target_os = "linux") || !enabled() {
        return;
    }
    tokio::spawn(async {
        let mut every = tokio::time::interval(CHECK_EVERY);
        // The first tick is immediate; let the server settle first.
        every.tick().await;
        loop {
            every.tick().await;
            let reloads = tokio::task::spawn_blocking(check_once).await.unwrap_or_default();
            for reload in reloads {
                let result = tokio::task::spawn_blocking({
                    let reload = reload.clone();
                    move || reload.run()
                })
                .await;
                if let Ok(Err(error)) = result {
                    tracing::warn!(?reload, %error, "PHP-FPM reload after an automatic worker step-up failed");
                }
            }
        }
    });
}

#[derive(Debug, Clone, Copy)]
struct Health {
    memory_total_kb: u64,
    memory_available_kb: u64,
    load_5m: f64,
    cores: usize,
}

fn read_health() -> Option<Health> {
    let memory_total_kb = php_workers::meminfo_kb("MemTotal:");
    let memory_available_kb = php_workers::meminfo_kb("MemAvailable:");
    let load = fs::read_to_string("/proc/loadavg").ok().as_deref().and_then(platform::metrics::parse_loadavg)?;
    (memory_total_kb > 0).then_some(Health {
        memory_total_kb,
        memory_available_kb,
        load_5m: load[1],
        cores: std::thread::available_parallelism().map_or(1, |n| n.get()),
    })
}

/// Whether the server can take `extra_kb` more memory and still have room.
fn has_room(health: Health, extra_kb: u64) -> bool {
    let reserve_kb = health.memory_total_kb * MIN_FREE_PERCENT / 100;
    let memory_ok = health.memory_available_kb.saturating_sub(extra_kb) >= reserve_kb;
    let cpu_ok = health.load_5m < health.cores as f64 * MAX_LOAD_PER_CORE;
    memory_ok && cpu_ok
}

/// The next limit for a pool at `current`, or `None` at the ceiling.
fn next_limit(current: u16) -> Option<u16> {
    (current < MAX_WORKERS).then(|| current.saturating_add(STEP).min(MAX_WORKERS))
}

/// Whether a hit at `last_hit` calls for a step: recent, and after the last step.
fn hit_counts(last_hit: NaiveDateTime, now: NaiveDateTime, raised_at: Option<NaiveDateTime>) -> bool {
    last_hit >= now - chrono::Duration::minutes(RECENT_MINUTES) && raised_at.is_none_or(|raised| last_hit > raised)
}

fn estimated_worker_kb() -> u64 {
    std::env::var("DRUST_SITE_POOL_CHILD_MEMORY_MB")
        .ok()
        .and_then(|value| value.trim().parse::<u64>().ok())
        .unwrap_or(96)
        .clamp(32, 512)
        * 1024
}

/// One pass over the pools. Returns the masters to reload.
fn check_once() -> Vec<php_workers::Reload> {
    let Some(mut health) = read_health() else { return Vec::new() };
    let Ok(_guard) = provision_lock() else { return Vec::new() };

    let files = php_workers::pool_files();
    let workers = php_workers::running_workers(None);
    let site_workers: Vec<_> = workers.iter().filter(|worker| worker.pool != "www").collect();
    let average_kb = if site_workers.is_empty() {
        estimated_worker_kb()
    } else {
        (site_workers.iter().map(|worker| worker.rss_kb).sum::<u64>() / site_workers.len() as u64)
            .max(estimated_worker_kb() / 2)
    };

    let now = Local::now().naive_local();
    let mut logs = BTreeMap::new();
    // Per site user: the newest limit hit and the highest current limit.
    let mut owners: BTreeMap<String, (Option<NaiveDateTime>, u16)> = BTreeMap::new();
    for file in files {
        if file.pm != "ondemand" || php_workers::custom_limit(&file.owner).is_some() {
            continue;
        }
        let log = if file.dedicated {
            php_workers::dedicated_log(&file.owner, &file.version)
        } else {
            php_workers::shared_log(&file.version)
        };
        let last = logs
            .entry(log.clone())
            .or_insert_with(|| php_workers::limit_hits(&log, now))
            .get(&file.pool)
            .and_then(|hits| hits.last);
        let entry = owners.entry(file.owner).or_insert((None, 0));
        entry.0 = entry.0.max(last);
        entry.1 = entry.1.max(file.max_children.unwrap_or(0));
    }

    let mut store = read_store();
    let mut changed = false;
    let mut reloads = BTreeSet::new();
    for (owner, (last_hit, current)) in owners {
        let Some(last_hit) = last_hit else { continue };
        let raised_at = store
            .get(&owner)
            .and_then(|raised| NaiveDateTime::parse_from_str(&raised.raised_at, TIME_FORMAT).ok());
        if !hit_counts(last_hit, now, raised_at) {
            continue;
        }
        let current = current.max(1);
        let Some(next) = next_limit(current) else { continue };
        let extra_kb = u64::from(next - current) * average_kb;
        if !has_room(health, extra_kb) {
            tracing::info!(owner, current, "PHP pool ran out of workers; no room on the server to add more");
            continue;
        }
        match php_workers::write_limit(&owner, next) {
            Ok((written, _)) => {
                tracing::info!(owner, from = current, to = next, "PHP worker limit stepped up after the pool ran out");
                reloads.extend(written);
                store.insert(owner, Raised { limit: next, raised_at: now.format(TIME_FORMAT).to_string() });
                changed = true;
                // The next pool must fit beside this one's new workers.
                health.memory_available_kb = health.memory_available_kb.saturating_sub(extra_kb);
            }
            Err(error) => tracing::warn!(owner, %error, "automatic PHP worker step-up failed"),
        }
    }
    if changed {
        if let Err(error) = write_store(&store) {
            tracing::warn!(%error, "cannot save automatic PHP worker step-ups");
        }
    }
    reloads.into_iter().collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn time(text: &str) -> NaiveDateTime {
        NaiveDateTime::parse_from_str(text, TIME_FORMAT).unwrap()
    }

    fn health(total_gb: u64, available_gb: u64, load_5m: f64, cores: usize) -> Health {
        Health {
            memory_total_kb: total_gb * 1024 * 1024,
            memory_available_kb: available_gb * 1024 * 1024,
            load_5m,
            cores,
        }
    }

    #[test]
    fn steps_by_five_up_to_the_ceiling() {
        assert_eq!(next_limit(6), Some(11));
        assert_eq!(next_limit(47), Some(50));
        assert_eq!(next_limit(50), None);
    }

    #[test]
    fn needs_free_memory_and_cpu() {
        let five_workers = 5 * 128 * 1024;
        assert!(has_room(health(8, 4, 1.0, 4), five_workers));
        // Below a quarter of RAM free once the workers start.
        assert!(!has_room(health(8, 2, 1.0, 4), five_workers));
        // CPU already busy.
        assert!(!has_room(health(8, 4, 3.5, 4), five_workers));
    }

    #[test]
    fn only_a_recent_hit_after_the_last_step_counts() {
        let now = time("2026-11-20 12:00:00");
        assert!(hit_counts(time("2026-11-20 11:55:00"), now, None));
        assert!(!hit_counts(time("2026-11-20 11:30:00"), now, None));
        assert!(!hit_counts(time("2026-11-20 11:55:00"), now, Some(time("2026-11-20 11:56:00"))));
        assert!(hit_counts(time("2026-11-20 11:58:00"), now, Some(time("2026-11-20 11:56:00"))));
    }
}
