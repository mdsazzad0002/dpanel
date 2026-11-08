//! A snapshot of the machine for the dashboard and monitoring pages
//! (backlog D3), so the panel itself never reads `/proc` or runs `systemctl`.
//! Linux reads `/proc`; elsewhere the fields it cannot know stay empty.

use std::{collections::BTreeMap, fs, net::UdpSocket, path::Path};

use serde::Serialize;

use super::Os;

#[derive(Debug, Default, Clone, PartialEq, Serialize)]
pub struct Memory {
    pub total_kb: u64,
    pub available_kb: u64,
    pub swap_total_kb: u64,
    pub swap_free_kb: u64,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct Disk {
    pub path: String,
    pub total_bytes: u64,
    pub free_bytes: u64,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct NetworkInterface {
    pub name: String,
    pub rx_bytes: u64,
    pub tx_bytes: u64,
}

#[derive(Debug, Clone, PartialEq, Serialize)]
pub struct Overview {
    pub os: &'static str,
    pub hostname: String,
    pub primary_ip: Option<String>,
    pub os_name: String,
    pub uptime_seconds: Option<u64>,
    pub cpu_cores: usize,
    pub cpu_model: Option<String>,
    /// 1, 5 and 15 minutes.
    pub load: Option<[f64; 3]>,
    pub memory: Option<Memory>,
    pub disk: Option<Disk>,
    pub process_count: Option<usize>,
    pub network: Vec<NetworkInterface>,
    /// `running`, `down` or `unknown` per name asked for.
    pub services: BTreeMap<String, &'static str>,
}

pub fn parse_meminfo(text: &str) -> Option<Memory> {
    let field = |name: &str| {
        text.lines()
            .find_map(|line| line.strip_prefix(name)?.strip_prefix(':'))
            .and_then(|rest| rest.split_whitespace().next()?.parse::<u64>().ok())
    };
    Some(Memory {
        total_kb: field("MemTotal")?,
        available_kb: field("MemAvailable").or_else(|| field("MemFree"))?,
        swap_total_kb: field("SwapTotal").unwrap_or(0),
        swap_free_kb: field("SwapFree").unwrap_or(0),
    })
}

pub fn parse_loadavg(text: &str) -> Option<[f64; 3]> {
    let mut parts = text.split_whitespace().map(|part| part.parse::<f64>().ok());
    Some([parts.next()??, parts.next()??, parts.next()??])
}

pub fn parse_uptime(text: &str) -> Option<u64> {
    text.split_whitespace().next()?.parse::<f64>().ok().map(|seconds| seconds as u64)
}

pub fn parse_os_release(text: &str) -> Option<String> {
    text.lines()
        .find_map(|line| line.strip_prefix("PRETTY_NAME="))
        .map(|value| value.trim().trim_matches(|c| c == '"' || c == '\'').to_string())
        .filter(|value| !value.is_empty())
}

pub fn parse_cpu_model(text: &str) -> Option<String> {
    text.lines()
        .find(|line| line.starts_with("model name") || line.starts_with("Hardware") || line.starts_with("Model"))
        .and_then(|line| line.split_once(':'))
        .map(|(_, value)| value.trim().to_string())
        .filter(|value| !value.is_empty())
}

/// `/proc/net/dev`, without the loopback.
pub fn parse_net_dev(text: &str) -> Vec<NetworkInterface> {
    text.lines()
        .skip(2)
        .filter_map(|line| {
            let (name, numbers) = line.split_once(':')?;
            let name = name.trim();
            let numbers: Vec<u64> = numbers.split_whitespace().filter_map(|n| n.parse().ok()).collect();
            (name != "lo" && numbers.len() >= 9).then(|| NetworkInterface { name: name.to_string(), rx_bytes: numbers[0], tx_bytes: numbers[8] })
        })
        .collect()
}

/// A unit name a service manager would accept, and nothing more.
pub fn valid_service_name(name: &str) -> bool {
    name.len() <= 64 && name.chars().next().is_some_and(|c| c.is_ascii_alphanumeric()) && name.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.' | '@'))
}

/// The address this machine would send from: a UDP "connect" sends nothing.
fn primary_ip() -> Option<String> {
    let socket = UdpSocket::bind("0.0.0.0:0").ok()?;
    socket.connect("1.1.1.1:80").ok()?;
    let ip = socket.local_addr().ok()?.ip();
    (!ip.is_unspecified()).then(|| ip.to_string())
}

fn hostname(os: Os) -> String {
    let read = |path: &str| fs::read_to_string(path).ok().map(|text| text.trim().to_string()).filter(|text| !text.is_empty());
    let found = match os {
        Os::Linux => read("/proc/sys/kernel/hostname").or_else(|| read("/etc/hostname")),
        Os::Windows => std::env::var("COMPUTERNAME").ok(),
        Os::Macos => std::env::var("HOSTNAME").ok(),
    };
    found.unwrap_or_else(|| "unknown".to_string())
}

fn disk(path: &Path) -> Option<Disk> {
    super::disk_space(path).map(|(total, free)| Disk { path: path.display().to_string(), total_bytes: total, free_bytes: free })
}

pub fn overview(services: &[String], disk_path: &Path) -> Overview {
    let os = super::current().os();
    let proc_text = |name: &str| if os == Os::Linux { fs::read_to_string(format!("/proc/{name}")).ok() } else { None };
    let manager = super::services();
    let services = services
        .iter()
        .filter(|name| valid_service_name(name))
        .map(|name| {
            let state = match manager.status(name) {
                Ok(status) if status.active => "running",
                Ok(_) => "down",
                Err(_) => "unknown",
            };
            (name.clone(), state)
        })
        .collect();
    Overview {
        os: match os {
            Os::Linux => "linux",
            Os::Windows => "windows",
            Os::Macos => "macos",
        },
        hostname: hostname(os),
        primary_ip: primary_ip(),
        os_name: if os == Os::Linux { fs::read_to_string("/etc/os-release").ok().and_then(|text| parse_os_release(&text)) } else { None }
            .unwrap_or_else(|| std::env::consts::OS.to_string()),
        uptime_seconds: proc_text("uptime").as_deref().and_then(parse_uptime),
        cpu_cores: std::thread::available_parallelism().map_or(1, |n| n.get()),
        cpu_model: proc_text("cpuinfo").as_deref().and_then(parse_cpu_model),
        load: proc_text("loadavg").as_deref().and_then(parse_loadavg),
        memory: proc_text("meminfo").as_deref().and_then(parse_meminfo),
        disk: disk(disk_path),
        process_count: (os == Os::Linux).then(|| {
            fs::read_dir("/proc").map_or(0, |entries| {
                entries.flatten().filter(|entry| entry.file_name().to_string_lossy().chars().all(|c| c.is_ascii_digit())).count()
            })
        }),
        network: proc_text("net/dev").as_deref().map(parse_net_dev).unwrap_or_default(),
        services,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn meminfo_gives_total_available_and_swap() {
        let text = "MemTotal:       16314616 kB\nMemFree:  100 kB\nMemAvailable:   12000000 kB\nSwapTotal: 2097148 kB\nSwapFree: 2097000 kB\n";
        assert_eq!(parse_meminfo(text), Some(Memory { total_kb: 16314616, available_kb: 12000000, swap_total_kb: 2097148, swap_free_kb: 2097000 }));
        assert_eq!(parse_meminfo("MemTotal: 10 kB\nMemFree: 4 kB\n").unwrap().available_kb, 4);
        assert_eq!(parse_meminfo("nothing"), None);
    }

    #[test]
    fn small_proc_files_parse() {
        assert_eq!(parse_loadavg("0.52 0.58 0.59 1/389 12345\n"), Some([0.52, 0.58, 0.59]));
        assert_eq!(parse_loadavg("x"), None);
        assert_eq!(parse_uptime("350735.47 234388.90\n"), Some(350735));
        assert_eq!(parse_os_release("NAME=\"Ubuntu\"\nPRETTY_NAME=\"Ubuntu 24.04.1 LTS\"\n").as_deref(), Some("Ubuntu 24.04.1 LTS"));
        assert_eq!(parse_cpu_model("processor\t: 0\nmodel name\t: AMD EPYC 7B13\n").as_deref(), Some("AMD EPYC 7B13"));
    }

    #[test]
    fn net_dev_skips_loopback() {
        let text = "Inter-|   Receive\n face |bytes    packets\n    lo: 100 1 0 0 0 0 0 0 100 1 0 0 0 0 0 0\n  eth0: 5000 10 0 0 0 0 0 0 7000 12 0 0 0 0 0 0\n";
        assert_eq!(parse_net_dev(text), vec![NetworkInterface { name: "eth0".into(), rx_bytes: 5000, tx_bytes: 7000 }]);
    }

    #[test]
    fn service_names_cannot_carry_arguments() {
        assert!(valid_service_name("redis-server"));
        assert!(valid_service_name("php8.3-fpm"));
        assert!(!valid_service_name("--now"));
        assert!(valid_service_name("a@b"));
        assert!(!valid_service_name("a b"));
        assert!(!valid_service_name("a;b"));
    }

    #[test]
    fn an_overview_always_has_cores_and_asked_services() {
        let overview = overview(&["no-such-unit-dpanel".into(), "bad name".into()], &std::env::temp_dir());
        assert!(overview.cpu_cores >= 1);
        assert!(overview.services.contains_key("no-such-unit-dpanel"));
        assert!(!overview.services.contains_key("bad name"));
    }
}
