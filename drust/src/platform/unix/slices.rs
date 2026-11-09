//! Linux `ResourceLimiter`: spec section 6.6, backlog P4. Each limited
//! account gets a systemd slice, `dpanel-<group>.slice` under `dpanel.slice`,
//! with `CPUQuota`, `MemoryMax` and `TasksMax`. Whatever runs in it (the
//! account's own PHP-FPM masters, `edge_gateway::php_fpm_master`) shares
//! those limits, and the kernel enforces them through cgroup v2.

use std::{
    collections::HashMap,
    fs,
    path::{Path, PathBuf},
    sync::{Mutex, OnceLock},
    time::{Duration, Instant},
};

use crate::platform::{
    PlatformError,
    capabilities::Capability,
    limits::{LimitLevel, ResourceLimiter, ResourceProfile, UsageSample, slice_name, valid_group},
};

#[derive(Debug)]
pub struct SystemdSlices {
    /// Where the slice unit files go, normally `/etc/systemd/system`.
    pub unit_dir: PathBuf,
    /// The cgroup v2 mount, normally `/sys/fs/cgroup`.
    pub cgroup_root: PathBuf,
}

fn checked(group: &str) -> Result<(), PlatformError> {
    if valid_group(group) {
        Ok(())
    } else {
        Err(PlatformError::Failed { action: "check account group".into(), detail: format!("invalid account group {group:?}") })
    }
}

/// The `systemctl set-property` form; empty or `infinity` takes a limit away.
fn properties(profile: &ResourceProfile) -> Vec<String> {
    vec![
        format!("CPUQuota={}", if profile.cpu_percent > 0 { format!("{}%", profile.cpu_percent) } else { String::new() }),
        format!("MemoryMax={}", if profile.memory_mb > 0 { format!("{}M", profile.memory_mb) } else { "infinity".into() }),
        format!("TasksMax={}", if profile.max_processes > 0 { profile.max_processes.to_string() } else { "infinity".into() }),
    ]
}

fn unit_file(group: &str, profile: &ResourceProfile) -> String {
    let mut lines = vec![
        "# Managed by dPanel (backlog P4): this account's CPU, memory and process limit.".to_string(),
        "[Unit]".into(),
        format!("Description=dPanel account {group}"),
        "Before=slices.target".into(),
        String::new(),
        "[Slice]".into(),
    ];
    // An empty CPUQuota= is fine for set-property but not worth a line here.
    lines.extend(properties(profile).into_iter().filter(|line| !line.ends_with('=')));
    lines.join("\n") + "\n"
}

/// `usage_usec` from a cgroup's `cpu.stat`: CPU time used, all cores together.
fn cpu_usage_usec(stat: &str) -> Option<u64> {
    stat.lines().find_map(|line| line.strip_prefix("usage_usec ")?.trim().parse().ok())
}

/// One core flat out is 100, the same scale as `CPUQuota`.
fn cpu_percent(used_usec: u64, elapsed: Duration) -> f32 {
    let elapsed_usec = elapsed.as_micros().max(1) as f64;
    (used_usec as f64 / elapsed_usec * 100.0) as f32
}

impl SystemdSlices {
    fn unit_path(&self, group: &str) -> PathBuf {
        self.unit_dir.join(slice_name(group))
    }

    fn cgroup(&self, group: &str) -> PathBuf {
        self.cgroup_root.join("dpanel.slice").join(slice_name(group))
    }

    fn read_number(dir: &Path, file: &str) -> u64 {
        fs::read_to_string(dir.join(file)).ok().and_then(|text| text.trim().parse().ok()).unwrap_or(0)
    }

    fn cpu_usec(dir: &Path) -> Option<u64> {
        fs::read_to_string(dir.join("cpu.stat")).ok().and_then(|text| cpu_usage_usec(&text))
    }
}

/// The last CPU reading of each group, so a usage check measures the time
/// since the one before instead of waiting.
fn last_readings() -> &'static Mutex<HashMap<String, (Instant, u64)>> {
    static LAST: OnceLock<Mutex<HashMap<String, (Instant, u64)>>> = OnceLock::new();
    LAST.get_or_init(|| Mutex::new(HashMap::new()))
}

impl ResourceLimiter for SystemdSlices {
    fn level(&self) -> LimitLevel {
        let systemd = crate::platform::paths().binary(crate::platform::paths::Tool::Systemctl).is_ok();
        if systemd && self.cgroup_root.join("cgroup.controllers").is_file() {
            LimitLevel::Hard
        } else {
            LimitLevel::None
        }
    }

    fn apply(&self, group: &str, profile: &ResourceProfile) -> Result<(), PlatformError> {
        checked(group)?;
        if self.level() == LimitLevel::None {
            return Err(PlatformError::Unsupported { capability: Capability::ResourceLimits });
        }
        let path = self.unit_path(group);
        let content = unit_file(group, profile);
        if fs::read_to_string(&path).ok().as_deref() != Some(content.as_str()) {
            fs::write(&path, content).map_err(|error| PlatformError::from_io("write slice unit", error))?;
        }
        let services = crate::platform::services();
        services.reload_definitions()?;
        let slice = slice_name(group);
        services.start(&slice)?;
        // A running slice keeps its old limits until told; this changes them now.
        services.set_runtime_properties(&slice, &properties(profile))
    }

    fn usage(&self, group: &str) -> Result<UsageSample, PlatformError> {
        checked(group)?;
        let dir = self.cgroup(group);
        if !dir.is_dir() {
            // Nothing of the account's runs right now.
            return Ok(UsageSample { cpu_percent: 0.0, memory_mb: 0, processes: 0 });
        }

        let now = Instant::now();
        let used = Self::cpu_usec(&dir).unwrap_or(0);
        let previous = last_readings().lock().ok().and_then(|map| map.get(group).copied());
        let cpu_percent = match previous {
            Some((at, before)) if now.duration_since(at) >= Duration::from_secs(1)
                && now.duration_since(at) <= Duration::from_secs(3600)
                && used >= before =>
            {
                cpu_percent(used - before, now.duration_since(at))
            }
            _ => {
                let wait = Duration::from_millis(500);
                std::thread::sleep(wait);
                let after = Self::cpu_usec(&dir).unwrap_or(used);
                cpu_percent(after.saturating_sub(used), wait)
            }
        };
        if let Ok(mut map) = last_readings().lock() {
            map.insert(group.to_string(), (Instant::now(), Self::cpu_usec(&dir).unwrap_or(used)));
        }

        Ok(UsageSample {
            cpu_percent,
            memory_mb: Self::read_number(&dir, "memory.current") / (1024 * 1024),
            processes: Self::read_number(&dir, "pids.current") as u32,
        })
    }

    fn clear(&self, group: &str) -> Result<(), PlatformError> {
        checked(group)?;
        let services = crate::platform::services();
        let slice = slice_name(group);
        // The runtime settings outlive the unit file until the next boot.
        let none = ResourceProfile { cpu_percent: 0, memory_mb: 0, max_processes: 0 };
        let _ = services.set_runtime_properties(&slice, &properties(&none));
        match fs::remove_file(self.unit_path(group)) {
            Ok(()) => services.reload_definitions(),
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
            Err(error) => Err(PlatformError::from_io("remove slice unit", error)),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_unit_file_has_only_the_limits_that_are_set() {
        let all = unit_file("a12", &ResourceProfile { cpu_percent: 150, memory_mb: 1024, max_processes: 200 });
        assert!(all.contains("[Slice]\nCPUQuota=150%\nMemoryMax=1024M\nTasksMax=200\n"));
        assert!(all.contains("Description=dPanel account a12"));

        let memory_only = unit_file("a12", &ResourceProfile { cpu_percent: 0, memory_mb: 512, max_processes: 0 });
        assert!(!memory_only.contains("CPUQuota"));
        assert!(memory_only.contains("MemoryMax=512M\nTasksMax=infinity\n"));
    }

    #[test]
    fn runtime_properties_take_an_unset_limit_away() {
        let none = properties(&ResourceProfile { cpu_percent: 0, memory_mb: 0, max_processes: 0 });
        assert_eq!(none, ["CPUQuota=", "MemoryMax=infinity", "TasksMax=infinity"]);
    }

    #[test]
    fn cpu_time_becomes_a_share_of_one_core() {
        assert_eq!(cpu_usage_usec("usage_usec 4200\nuser_usec 4000\nsystem_usec 200\n"), Some(4200));
        assert_eq!(cpu_usage_usec("nr_periods 0\n"), None);
        assert!((cpu_percent(1_500_000, Duration::from_secs(1)) - 150.0).abs() < 0.01);
    }

    #[test]
    fn usage_of_an_account_with_nothing_running_is_zero() {
        let slices = SystemdSlices { unit_dir: "/nonexistent".into(), cgroup_root: "/nonexistent".into() };
        let sample = slices.usage("a5").unwrap();
        assert_eq!((sample.memory_mb, sample.processes), (0, 0));
        assert!(slices.usage("../a5").is_err());
    }

    #[test]
    fn usage_reads_the_slice_cgroup() {
        let root = std::env::temp_dir().join(format!("drust-slices-{}", std::process::id()));
        let dir = root.join("dpanel.slice").join("dpanel-a7.slice");
        fs::create_dir_all(&dir).unwrap();
        fs::write(dir.join("memory.current"), "268435456\n").unwrap();
        fs::write(dir.join("pids.current"), "9\n").unwrap();
        fs::write(dir.join("cpu.stat"), "usage_usec 100\n").unwrap();
        let slices = SystemdSlices { unit_dir: root.clone(), cgroup_root: root.clone() };
        let sample = slices.usage("a7").unwrap();
        assert_eq!((sample.memory_mb, sample.processes), (256, 9));
        assert_eq!(sample.cpu_percent, 0.0);
        fs::remove_dir_all(root).unwrap();
    }
}
