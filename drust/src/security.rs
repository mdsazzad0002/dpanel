use serde_json::{Value, json};
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    net::IpAddr,
    path::Path,
    process::Command,
};

fn executable(program: &str) -> Option<&str> {
    let candidates: &[&str] = match program {
        "sshd" => &["/usr/sbin/sshd", "/usr/local/sbin/sshd"],
        "ufw" => &["/usr/sbin/ufw", "/usr/bin/ufw"],
        "ss" => &["/usr/bin/ss", "/usr/sbin/ss"],
        "systemctl" => &["/usr/bin/systemctl", "/bin/systemctl"],
        _ => &[],
    };
    candidates
        .iter()
        .copied()
        .find(|candidate| Path::new(candidate).is_file())
}

fn command(program: &str, args: &[&str]) -> Result<String, String> {
    let executable =
        executable(program).ok_or_else(|| format!("{program} is not installed on this server"))?;
    let output = Command::new(executable)
        .args(args)
        .output()
        .map_err(|e| e.to_string())?;
    if !output.status.success() {
        let error = String::from_utf8_lossy(&output.stderr).trim().to_string();
        return Err(if error.is_empty() {
            format!("{program} exited unsuccessfully")
        } else {
            error
        });
    }
    Ok(String::from_utf8_lossy(&output.stdout).trim().to_string())
}

fn effective_value(text: &str, key: &str, fallback: &str) -> String {
    text.lines()
        .find_map(|line| {
            let mut parts = line.split_whitespace();
            (parts.next()? == key).then(|| parts.next().unwrap_or(fallback).to_string())
        })
        .unwrap_or_else(|| fallback.to_string())
}

pub fn status() -> Result<Value, String> {
    let ssh_installed = executable("sshd").is_some();
    let effective_result = command("sshd", &["-T"]);
    let effective = effective_result.as_deref().unwrap_or("");
    let port: u16 = effective_value(&effective, "port", "22")
        .parse()
        .unwrap_or(22);
    let service_active = command("systemctl", &["is-active", "ssh"])
        .map(|v| v == "active")
        .unwrap_or(false);
    let service_enabled = command("systemctl", &["is-enabled", "ssh"])
        .map(|v| v == "enabled")
        .unwrap_or(false);
    let sockets = command("ss", &["-ltnH"]).unwrap_or_default();
    let suffix = format!(":{port}");
    let listen_addresses: Vec<String> = sockets
        .lines()
        .filter_map(|line| {
            let address = line.split_whitespace().nth(3)?;
            address.ends_with(&suffix).then(|| {
                address
                    .trim_end_matches(&suffix)
                    .trim_matches(['[', ']'])
                    .to_string()
            })
        })
        .collect();
    let ufw = command("ufw", &["status"]).unwrap_or_default();
    let firewall_ports: BTreeSet<u16> = ufw
        .lines()
        .filter_map(|line| {
            let columns: Vec<&str> = line.split_whitespace().collect();
            if columns.len() >= 3 && columns[1] == "ALLOW" {
                columns[0].split('/').next()?.parse().ok()
            } else {
                None
            }
        })
        .collect();
    let allowed_ips: Vec<String> = ufw
        .lines()
        .filter_map(|line| {
            let columns: Vec<&str> = line.split_whitespace().collect();
            if columns.len() >= 3
                && columns[0].trim_end_matches("/tcp") == port.to_string()
                && columns[1] == "ALLOW"
            {
                Some(columns[2..].join(" ").trim_end_matches(" (v6)").to_string())
            } else {
                None
            }
        })
        .collect();
    let mut listener_map: BTreeMap<u16, (BTreeSet<String>, BTreeSet<String>)> = BTreeMap::new();
    for line in command("ss", &["-ltnpH"]).unwrap_or_default().lines() {
        let columns: Vec<&str> = line.split_whitespace().collect();
        let Some(local) = columns.get(3) else {
            continue;
        };
        let Some((address, raw_port)) = local.rsplit_once(':') else {
            continue;
        };
        let Ok(port) = raw_port.parse::<u16>() else {
            continue;
        };
        let entry = listener_map.entry(port).or_default();
        entry.0.insert(address.trim_matches(['[', ']']).to_string());
        if let Some(process) = columns.get(5) {
            let name = process.split('"').nth(1).unwrap_or(process).to_string();
            entry.1.insert(name);
        }
    }
    for port in &firewall_ports {
        listener_map.entry(*port).or_default();
    }

    let ports: Vec<Value> = listener_map
        .into_iter()
        .map(|(port, (addresses, processes))| {
            json!({
                "port": port, "protocol": "tcp", "listening": !addresses.is_empty(),
                "firewall_allowed": firewall_ports.contains(&port),
                "addresses": addresses.into_iter().collect::<Vec<_>>(),
                "processes": processes.into_iter().collect::<Vec<_>>(),
                "service": service_name(port)
            })
        })
        .collect();

    Ok(json!({
        "checked_at": chrono_free_timestamp(),
        "ssh": {
            "port": port,
            "password_authentication": on_off(&effective_value(&effective, "passwordauthentication", "no")),
            "permit_root_login": effective_value(&effective, "permitrootlogin", "no"),
            "pubkey_authentication": on_off(&effective_value(&effective, "pubkeyauthentication", "yes")),
            "service_active": service_active, "service_enabled": service_enabled,
            "listening": !listen_addresses.is_empty(), "listen_addresses": listen_addresses,
            "installed": ssh_installed,
            "config_valid": effective_result.is_ok(),
            "status_message": if ssh_installed { Value::Null } else { json!("OpenSSH server is not installed.") }
        },
        "firewall": { "enabled": ufw.to_lowercase().contains("status: active"), "ssh_allowed_ips": allowed_ips },
        "ports": ports
    }))
}

fn service_name(port: u16) -> &'static str {
    match port {
        20 | 21 => "FTP",
        22 => "SSH",
        25 => "SMTP",
        53 => "DNS",
        80 => "HTTP",
        110 => "POP3",
        143 => "IMAP",
        443 => "HTTPS",
        445 => "SMB",
        465 => "SMTPS",
        587 => "Mail submission",
        631 => "Printing",
        993 => "IMAPS",
        995 => "POP3S",
        3306 => "MySQL",
        6379 => "Redis",
        9500 => "drust API",
        _ => "Custom service",
    }
}

fn chrono_free_timestamp() -> u64 {
    std::time::SystemTime::now()
        .duration_since(std::time::UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

fn on_off(value: &str) -> &'static str {
    if matches!(value.to_lowercase().as_str(), "yes" | "on" | "true" | "1") {
        "On"
    } else {
        "Off"
    }
}

pub fn apply_ssh_config(
    port: u16,
    password: &str,
    root: &str,
    pubkey: &str,
) -> Result<String, String> {
    if executable("sshd").is_none() {
        return Err("OpenSSH server is not installed. Install openssh-server first.".to_string());
    }
    if port == 0
        || !matches!(password, "On" | "Off")
        || !matches!(pubkey, "On" | "Off")
        || !matches!(
            root,
            "yes" | "no" | "prohibit-password" | "forced-commands-only"
        )
    {
        return Err("Invalid SSH configuration.".to_string());
    }
    let directory = "/etc/ssh/sshd_config.d";
    fs::create_dir_all(directory).map_err(|e| e.to_string())?;
    let path = format!("{directory}/99-dpanel.conf");
    let backup = fs::read(&path).ok();
    let content = format!(
        "# Managed by dPanel\nPort {port}\nPasswordAuthentication {}\nPermitRootLogin {root}\nPubkeyAuthentication {}\n",
        if password == "On" { "yes" } else { "no" },
        if pubkey == "On" { "yes" } else { "no" }
    );
    fs::write(&path, content).map_err(|e| e.to_string())?;
    if let Err(error) = command("sshd", &["-t"]) {
        if let Some(old) = backup {
            let _ = fs::write(&path, old);
        } else {
            let _ = fs::remove_file(&path);
        }
        return Err(format!("SSH config validation failed: {error}"));
    }
    command("systemctl", &["reload", "ssh"])?;
    Ok("SSH settings applied through drust.".to_string())
}

pub fn set_ssh_service(enabled: bool) -> Result<String, String> {
    if executable("sshd").is_none() {
        return Err("OpenSSH server is not installed. Install openssh-server first.".to_string());
    }
    if enabled {
        command("systemctl", &["enable", "--now", "ssh"])?;
    } else {
        command("systemctl", &["disable", "--now", "ssh"])?;
    }
    Ok(format!(
        "SSH service {}.",
        if enabled { "enabled" } else { "disabled" }
    ))
}

pub fn set_ssh_access(ip: &str, action: &str) -> Result<String, String> {
    if executable("sshd").is_none() {
        return Err("OpenSSH server is not installed. Install openssh-server first.".to_string());
    }
    ip.parse::<IpAddr>()
        .map_err(|_| "Invalid IP address.".to_string())?;
    if !matches!(action, "allow" | "revoke") {
        return Err("Invalid access action.".to_string());
    }
    let port = effective_value(&command("sshd", &["-T"])?, "port", "22");
    let mut args = vec![];
    if action == "revoke" {
        args.extend(["--force", "delete"]);
    }
    args.extend([
        "allow", "from", ip, "to", "any", "port", &port, "proto", "tcp",
    ]);
    command("ufw", &args)?;
    Ok(format!(
        "SSH access {} for {ip}.",
        if action == "allow" {
            "allowed"
        } else {
            "revoked"
        }
    ))
}

pub fn apply_firewall(
    enabled: bool,
    incoming: &str,
    outgoing: &str,
    ports: &[u16],
) -> Result<String, String> {
    if !matches!(incoming, "allow" | "deny" | "reject")
        || !matches!(outgoing, "allow" | "deny" | "reject")
        || ports.contains(&0)
    {
        return Err("Invalid firewall configuration.".to_string());
    }
    command("ufw", &["default", incoming, "incoming"])?;
    command("ufw", &["default", outgoing, "outgoing"])?;
    for port in ports {
        command("ufw", &["allow", &format!("{port}/tcp")])?;
    }
    if enabled {
        command("ufw", &["--force", "enable"])?;
    } else {
        command("ufw", &["disable"])?;
    }
    Ok("Firewall settings applied through drust.".to_string())
}
