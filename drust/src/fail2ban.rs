//! fail2ban status, unban, and a permanent whitelist for the panel.
//!
//! A wrong SSH password from an admin's own network gets that IP banned, and
//! the panel is then the only way back in (the sshd jail blocks port 22 only).
//! Only fixed fail2ban-client commands run here; IPs and jail names are
//! validated before they reach a command line.

use serde_json::{Value, json};
use std::{fs, net::IpAddr, path::Path, process::Command};

const CLIENT_CANDIDATES: &[&str] = &["/usr/bin/fail2ban-client", "/usr/local/bin/fail2ban-client"];
/// jail.d/*.local is read after jail.local, so this [DEFAULT] ignoreip wins.
const WHITELIST_FILE: &str = "/etc/fail2ban/jail.d/dpanel-whitelist.local";
/// Always ignored; fail2ban's own default.
const LOCAL_IGNORES: &[&str] = &["127.0.0.1/8", "::1"];
/// dPanel's sshd rules. jail.d/*.local files are read in name order, so `zz-`
/// lets this override serverpanel.local, whose `port = ssh` misses a custom port.
const POLICY_FILE: &str = "/etc/fail2ban/jail.d/zz-dpanel-sshd.local";
/// Failed SSH logins allowed before an IP is blocked for good.
pub const DEFAULT_MAX_RETRY: u32 = 3;
const MAX_RETRY_LIMIT: u32 = 10;
const SSHD_CANDIDATES: &[&str] = &["/usr/sbin/sshd", "/usr/local/sbin/sshd"];
const HISTORY_LIMIT: usize = 500;

fn client() -> Option<&'static str> {
    CLIENT_CANDIDATES
        .iter()
        .copied()
        .find(|path| Path::new(path).is_file())
}

fn run(args: &[&str]) -> Result<String, String> {
    let client = client().ok_or("fail2ban is not installed on this server.")?;
    let output = Command::new(client)
        .args(args)
        .output()
        .map_err(|e| format!("Cannot run fail2ban-client: {e}"))?;
    let stdout = String::from_utf8_lossy(&output.stdout).trim().to_string();
    if output.status.success() {
        Ok(stdout)
    } else {
        let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
        Err(if stderr.is_empty() { stdout } else { stderr })
    }
}

/// `fail2ban-client status` output lines look like `|- Key:\tvalue`.
fn field(text: &str, key: &str) -> Option<String> {
    text.lines().find_map(|line| {
        let line = line.trim_start_matches(|c: char| c == '|' || c == '`' || c == '-' || c.is_whitespace());
        let (name, value) = line.split_once(':')?;
        (name.trim() == key).then(|| value.trim().to_string())
    })
}

fn split_list(value: &str) -> Vec<String> {
    value
        .split(|c: char| c == ',' || c.is_whitespace())
        .map(str::trim)
        .filter(|part| !part.is_empty())
        .map(str::to_string)
        .collect()
}

pub(crate) fn parse_jail_list(status: &str) -> Vec<String> {
    field(status, "Jail list").map(|v| split_list(&v)).unwrap_or_default()
}

pub(crate) fn parse_jail(name: &str, status: &str) -> Value {
    let number = |key: &str| {
        field(status, key)
            .and_then(|v| v.parse::<u64>().ok())
            .unwrap_or(0)
    };
    json!({
        "name": name,
        "currently_failed": number("Currently failed"),
        "total_failed": number("Total failed"),
        "currently_banned": number("Currently banned"),
        "total_banned": number("Total banned"),
        "banned_ips": field(status, "Banned IP list").map(|v| split_list(&v)).unwrap_or_default(),
    })
}

/// The IPs dPanel added, read back from its own file.
pub(crate) fn parse_whitelist(file: &str) -> Vec<String> {
    file.lines()
        .find_map(|line| {
            let (key, value) = line.split_once('=')?;
            (key.trim() == "ignoreip").then(|| split_list(value))
        })
        .unwrap_or_default()
        .into_iter()
        .filter(|entry| !LOCAL_IGNORES.contains(&entry.as_str()))
        .collect()
}

/// An IP or a CIDR that is not so wide it would switch fail2ban off.
pub(crate) fn validate_entry(entry: &str) -> Result<String, String> {
    let (address, prefix) = match entry.split_once('/') {
        Some((address, prefix)) => (address, Some(prefix)),
        None => (entry, None),
    };
    let ip: IpAddr = address
        .trim()
        .parse()
        .map_err(|_| format!("'{entry}' is not a valid IP address."))?;
    let Some(prefix) = prefix else {
        return Ok(ip.to_string());
    };
    let prefix: u8 = prefix
        .parse()
        .map_err(|_| format!("'{entry}' has an invalid prefix."))?;
    let (min, max) = if ip.is_ipv4() { (16, 32) } else { (48, 128) };
    if prefix < min || prefix > max {
        return Err(format!(
            "'{entry}' is too wide; use /{min} or narrower so fail2ban still protects the server."
        ));
    }
    Ok(format!("{ip}/{prefix}"))
}

fn validate_ip(ip: &str) -> Result<String, String> {
    ip.trim()
        .parse::<IpAddr>()
        .map(|ip| ip.to_string())
        .map_err(|_| format!("'{ip}' is not a valid IP address."))
}

fn read_whitelist() -> Vec<String> {
    fs::read_to_string(WHITELIST_FILE)
        .map(|text| parse_whitelist(&text))
        .unwrap_or_default()
}

fn write_whitelist(entries: &[String]) -> Result<(), String> {
    let mut all: Vec<String> = LOCAL_IGNORES.iter().map(|s| s.to_string()).collect();
    all.extend(entries.iter().cloned());
    let content = format!(
        "# Managed by dPanel (Security > Fail2ban). Changes here are overwritten.\n[DEFAULT]\nignoreip = {}\n",
        all.join(" ")
    );
    if let Some(parent) = Path::new(WHITELIST_FILE).parent() {
        fs::create_dir_all(parent).map_err(|e| format!("Cannot create {}: {e}", parent.display()))?;
    }
    fs::write(WHITELIST_FILE, content).map_err(|e| format!("Cannot write {WHITELIST_FILE}: {e}"))
}

/// The ports sshd listens on, so a ban covers a custom port and not just 22.
pub(crate) fn parse_sshd_ports(config: &str) -> Vec<String> {
    let mut ports: Vec<String> = Vec::new();
    for line in config.lines() {
        if let Some(("port", value)) = line.trim().split_once(' ') {
            let value = value.trim();
            if value.parse::<u16>().is_ok() && !ports.iter().any(|port| port == value) {
                ports.push(value.to_string());
            }
        }
    }
    ports
}

fn sshd_ports() -> Vec<String> {
    let ports = SSHD_CANDIDATES
        .iter()
        .find(|path| Path::new(path).is_file())
        .and_then(|sshd| Command::new(sshd).arg("-T").output().ok())
        .filter(|output| output.status.success())
        .map(|output| parse_sshd_ports(&String::from_utf8_lossy(&output.stdout)))
        .unwrap_or_default();
    if ports.is_empty() { vec!["ssh".to_string()] } else { ports }
}

/// Every jail bans for good: a ban only ends when it is removed in the panel.
pub(crate) fn policy_content(max_retry: u32, ports: &[String]) -> String {
    format!(
        "# Managed by dPanel (Security > Fail2ban). Changes here are overwritten.\n\
         # Bans from every jail last until they are removed in the panel.\n\
         [DEFAULT]\nbantime = -1\n\n\
         # {max_retry} failed SSH logins within a day block the IP.\n\
         [sshd]\nenabled = true\nport = {}\nmaxretry = {max_retry}\nfindtime = 1d\nbantime = -1\n",
        ports.join(",")
    )
}

/// Policy files written before every jail was made permanent only covered sshd.
pub(crate) fn policy_is_current(file: &str) -> bool {
    file.contains("[DEFAULT]\nbantime = -1\n")
}

pub(crate) fn parse_policy_max_retry(file: &str) -> Option<u32> {
    file.lines().find_map(|line| {
        let (key, value) = line.split_once('=')?;
        (key.trim() == "maxretry").then(|| value.trim().parse().ok())?
    })
}

fn write_policy(max_retry: u32) -> Result<(), String> {
    if !(1..=MAX_RETRY_LIMIT).contains(&max_retry) {
        return Err(format!("Allowed failed logins must be between 1 and {MAX_RETRY_LIMIT}."));
    }
    if let Some(parent) = Path::new(POLICY_FILE).parent() {
        fs::create_dir_all(parent).map_err(|e| format!("Cannot create {}: {e}", parent.display()))?;
    }
    fs::write(POLICY_FILE, policy_content(max_retry, &sshd_ports()))
        .map_err(|e| format!("Cannot write {POLICY_FILE}: {e}"))
}

/// Runs once when drust starts, so every server blocks SSH brute force and
/// keeps every ban by default. An existing policy keeps the admin's maxretry
/// and is only rewritten when it predates permanent bans for all jails.
pub fn ensure_default_policy() {
    if client().is_none() {
        return;
    }
    let existing = fs::read_to_string(POLICY_FILE).ok();
    if existing.as_deref().is_some_and(policy_is_current) {
        return;
    }
    let max_retry = existing
        .as_deref()
        .and_then(parse_policy_max_retry)
        .filter(|n| (1..=MAX_RETRY_LIMIT).contains(n))
        .unwrap_or(DEFAULT_MAX_RETRY);
    match write_policy(max_retry).and_then(|()| run(&["reload"])) {
        Ok(_) => println!("[INFO] fail2ban: bans are permanent; SSH blocks an IP after {max_retry} failures."),
        Err(error) => eprintln!("[WARN] fail2ban: could not apply the default policy: {error}"),
    }
}

/// What the running jails enforce, read back from fail2ban itself.
fn policy(jails: &[String]) -> Value {
    let live = |key: &str| run(&["get", "sshd", key]).ok().and_then(|v| v.trim().parse::<i64>().ok());
    let temporary: Vec<&String> = jails
        .iter()
        .filter(|jail| run(&["get", jail, "bantime"]).ok().and_then(|v| v.trim().parse::<i64>().ok()) != Some(-1))
        .collect();
    json!({
        "managed": Path::new(POLICY_FILE).exists(),
        "max_retry": live("maxretry")
            .or_else(|| fs::read_to_string(POLICY_FILE).ok().and_then(|f| parse_policy_max_retry(&f).map(i64::from))),
        "permanent": live("bantime") == Some(-1),
        "temporary_jails": temporary,
        "ports": sshd_ports(),
        "default_max_retry": DEFAULT_MAX_RETRY,
    })
}

pub fn status() -> Result<Value, String> {
    if client().is_none() {
        return Ok(json!({ "installed": false, "running": false, "jails": [], "whitelist": [], "policy": null }));
    }
    let whitelist = read_whitelist();
    let Ok(overview) = run(&["status"]) else {
        return Ok(json!({ "installed": true, "running": false, "jails": [], "whitelist": whitelist, "policy": null }));
    };
    let names = parse_jail_list(&overview);
    let jails: Vec<Value> = names
        .iter()
        .filter_map(|name| run(&["status", name]).ok().map(|text| parse_jail(name, &text)))
        .collect();
    Ok(json!({ "installed": true, "running": true, "jails": jails, "whitelist": whitelist, "policy": policy(&names) }))
}

pub fn set_policy(max_retry: u32) -> Result<String, String> {
    write_policy(max_retry)?;
    run(&["reload"])?;
    Ok(format!("SSH now blocks an IP after {max_retry} failed logins. Bans last until you remove them."))
}

/// Blocks an IP from SSH until it is unblocked in the panel.
pub fn ban(ip: &str) -> Result<String, String> {
    let ip = validate_ip(ip)?;
    if read_whitelist().contains(&ip) {
        return Err(format!("{ip} is whitelisted. Remove it from the whitelist first."));
    }
    run(&["set", "sshd", "banip", &ip])?;
    Ok(format!("{ip} blocked from SSH."))
}

/// One SSH login attempt from the journal.
pub(crate) fn parse_login(message: &str) -> Option<(&'static str, String, String, String)> {
    // "<user> from <ip> port <n>" -> (user, ip); the user may be empty or contain spaces.
    let user_ip = |rest: &str| {
        let (user, after) = rest.rsplit_once(" from ")?;
        let ip = after.split_whitespace().next()?;
        ip.parse::<IpAddr>().ok()?;
        Some((user.trim().to_string(), ip.to_string()))
    };
    if let Some(rest) = message.strip_prefix("Accepted ") {
        let (method, rest) = rest.split_once(" for ")?;
        let (user, ip) = user_ip(rest)?;
        return Some(("accepted", method.to_string(), user, ip));
    }
    if let Some(rest) = message.strip_prefix("Failed ") {
        let (method, rest) = rest.split_once(" for ")?;
        let (rest, method) = match rest.strip_prefix("invalid user ") {
            Some(rest) => (rest, format!("{method} (invalid user)")),
            None => (rest, method.to_string()),
        };
        let (user, ip) = user_ip(rest)?;
        return Some(("failed", method, user, ip));
    }
    let (user, ip) = user_ip(message.strip_prefix("Invalid user ")?)?;
    Some(("failed", "invalid user".to_string(), user, ip))
}

/// Recent SSH logins, newest first, both accepted and failed.
pub fn ssh_history() -> Result<Value, String> {
    let limit = HISTORY_LIMIT.to_string();
    let output = Command::new("journalctl")
        .args([
            "-u", "ssh.service", "-u", "sshd.service", "--no-pager", "-r", "-n", &limit,
            "-o", "json", "--output-fields=MESSAGE,_PID",
            "-g", "^(Accepted|Failed|Invalid user) ",
        ])
        .output()
        .map_err(|e| format!("Cannot run journalctl: {e}"))?;
    // journalctl exits 1 when --grep matches nothing.
    let stdout = String::from_utf8_lossy(&output.stdout);
    let entries: Vec<(String, i64, String)> = stdout
        .lines()
        .filter_map(|line| serde_json::from_str::<Value>(line).ok())
        .filter_map(|entry| {
            let message = entry["MESSAGE"].as_str()?.to_string();
            let micros = entry["__REALTIME_TIMESTAMP"].as_str()?.parse::<i64>().ok()?;
            let pid = entry["_PID"].as_str().unwrap_or_default().to_string();
            Some((message, micros / 1_000_000, pid))
        })
        .collect();
    // sshd logs "Invalid user x" and then "Failed password for invalid user x"
    // for the same attempt; keep only the second.
    let failed_invalid: Vec<&str> = entries
        .iter()
        .filter(|(message, ..)| message.starts_with("Failed ") && message.contains(" for invalid user "))
        .map(|(_, _, pid)| pid.as_str())
        .collect();
    let events: Vec<Value> = entries
        .iter()
        .filter(|(message, _, pid)| !(message.starts_with("Invalid user ") && failed_invalid.contains(&pid.as_str())))
        .filter_map(|(message, time, _)| {
            let (result, method, user, ip) = parse_login(message)?;
            Some(json!({ "time": time, "result": result, "method": method, "user": user, "ip": ip }))
        })
        .collect();
    Ok(json!({ "events": events, "limit": HISTORY_LIMIT }))
}

/// Removes the ban from every jail at once, which is what a locked-out admin wants.
pub fn unban(ip: &str) -> Result<String, String> {
    let ip = validate_ip(ip)?;
    run(&["unban", &ip])?;
    Ok(format!("{ip} unblocked."))
}

pub fn whitelist_add(entry: &str) -> Result<String, String> {
    let entry = validate_entry(entry)?;
    let mut entries = read_whitelist();
    if !entries.contains(&entry) {
        entries.push(entry.clone());
        write_whitelist(&entries)?;
        run(&["reload"])?;
    }
    // A plain IP that is banned right now should be let in immediately too.
    if !entry.contains('/') {
        let _ = run(&["unban", &entry]);
    }
    Ok(format!("{entry} will never be blocked."))
}

pub fn whitelist_remove(entry: &str) -> Result<String, String> {
    let entry = validate_entry(entry)?;
    let mut entries = read_whitelist();
    let before = entries.len();
    entries.retain(|existing| existing != &entry);
    if entries.len() != before {
        write_whitelist(&entries)?;
        run(&["reload"])?;
    }
    Ok(format!("{entry} removed from the whitelist."))
}

#[cfg(test)]
mod tests {
    use super::*;

    const OVERVIEW: &str = "Status\n|- Number of jail:\t2\n`- Jail list:\tsshd, recidive\n";
    const SSHD: &str = "Status for the jail: sshd\n|- Filter\n|  |- Currently failed:\t3\n|  |- Total failed:\t41\n|  `- Journal matches:\t_SYSTEMD_UNIT=sshd.service + _COMM=sshd\n`- Actions\n   |- Currently banned:\t2\n   |- Total banned:\t9\n   `- Banned IP list:\t203.0.113.7 2001:db8::1\n";

    #[test]
    fn parses_jail_list() {
        assert_eq!(parse_jail_list(OVERVIEW), vec!["sshd", "recidive"]);
        assert!(parse_jail_list("Status\n|- Number of jail:\t0\n`- Jail list:\t\n").is_empty());
    }

    #[test]
    fn parses_jail_status() {
        let jail = parse_jail("sshd", SSHD);
        assert_eq!(jail["currently_failed"], 3);
        assert_eq!(jail["currently_banned"], 2);
        assert_eq!(jail["total_banned"], 9);
        assert_eq!(jail["banned_ips"], json!(["203.0.113.7", "2001:db8::1"]));
    }

    #[test]
    fn whitelist_hides_local_defaults() {
        let file = "# Managed\n[DEFAULT]\nignoreip = 127.0.0.1/8 ::1 198.51.100.4 10.1.0.0/16\n";
        assert_eq!(parse_whitelist(file), vec!["198.51.100.4", "10.1.0.0/16"]);
        assert!(parse_whitelist("").is_empty());
    }

    #[test]
    fn reads_sshd_ports() {
        let config = "port 2002\nport 22\nport 2002\npermitrootlogin yes\nlistenaddress 0.0.0.0:2002\n";
        assert_eq!(parse_sshd_ports(config), vec!["2002", "22"]);
        assert!(parse_sshd_ports("").is_empty());
    }

    #[test]
    fn policy_round_trips() {
        let file = policy_content(3, &["2002".to_string()]);
        assert!(file.contains("port = 2002\n"));
        assert!(file.contains("bantime = -1\n"));
        assert_eq!(parse_policy_max_retry(&file), Some(3));
        assert!(policy_is_current(&file));

        let old = "[sshd]\nenabled = true\nport = ssh\nmaxretry = 5\nfindtime = 1d\nbantime = -1\n";
        assert!(!policy_is_current(old));
        assert_eq!(parse_policy_max_retry(old), Some(5));
    }

    #[test]
    fn parses_login_lines() {
        assert_eq!(
            parse_login("Accepted password for sazzad from 103.78.226.196 port 58874 ssh2"),
            Some(("accepted", "password".into(), "sazzad".into(), "103.78.226.196".into()))
        );
        assert_eq!(
            parse_login("Failed password for root from 2001:db8::1 port 59492 ssh2"),
            Some(("failed", "password".into(), "root".into(), "2001:db8::1".into()))
        );
        assert_eq!(
            parse_login("Failed password for invalid user admin from 77.83.39.1 port 1 ssh2"),
            Some(("failed", "password (invalid user)".into(), "admin".into(), "77.83.39.1".into()))
        );
        assert_eq!(
            parse_login("Invalid user  from 77.83.39.99 port 42624"),
            Some(("failed", "invalid user".into(), "".into(), "77.83.39.99".into()))
        );
        assert_eq!(parse_login("Server listening on 0.0.0.0 port 2002."), None);
    }

    #[test]
    fn validates_entries() {
        assert_eq!(validate_entry("198.51.100.4").unwrap(), "198.51.100.4");
        assert_eq!(validate_entry("10.1.0.0/16").unwrap(), "10.1.0.0/16");
        assert!(validate_entry("0.0.0.0/0").is_err());
        assert!(validate_entry("10.0.0.0/8").is_err());
        assert!(validate_entry("2001:db8::/32").is_err());
        assert!(validate_entry("2001:db8::/64").is_ok());
        assert!(validate_entry("1.2.3.4; rm -rf /").is_err());
        assert!(validate_entry("example.com").is_err());
    }
}
