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

pub fn status() -> Result<Value, String> {
    if client().is_none() {
        return Ok(json!({ "installed": false, "running": false, "jails": [], "whitelist": [] }));
    }
    let whitelist = read_whitelist();
    let Ok(overview) = run(&["status"]) else {
        return Ok(json!({ "installed": true, "running": false, "jails": [], "whitelist": whitelist }));
    };
    let jails: Vec<Value> = parse_jail_list(&overview)
        .iter()
        .filter_map(|name| run(&["status", name]).ok().map(|text| parse_jail(name, &text)))
        .collect();
    Ok(json!({ "installed": true, "running": true, "jails": jails, "whitelist": whitelist }))
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
