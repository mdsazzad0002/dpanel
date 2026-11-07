//! Linux accounts that can (or could) log in to the server: root plus every
//! regular user (uid >= 1000). Lets the panel see who can open a shell and
//! change that — login shell, password lock, password, live sessions.

use serde_json::{Value, json};
use crate::platform::{account::{AccountList, can_login_shell}, accounts};
use std::{
    collections::{BTreeMap, BTreeSet},
    fs,
    path::Path,
    process::Command,
};

const NOBODY_UID: u32 = 65534;
const ADMIN_GROUPS: &[&str] = &["sudo", "admin", "wheel"];

struct PasswdEntry {
    name: String,
    uid: u32,
    gid: u32,
    gecos: String,
    home: String,
    shell: String,
}

fn passwd_entries() -> Result<Vec<PasswdEntry>, String> {
    let text = accounts()
        .list(AccountList::Passwd)
        .map_err(|e| format!("read /etc/passwd: {e}"))?;
    Ok(text
        .lines()
        .filter_map(|line| {
            let fields: Vec<&str> = line.split(':').collect();
            if fields.len() < 7 {
                return None;
            }
            Some(PasswdEntry {
                name: fields[0].to_string(),
                uid: fields[2].parse().ok()?,
                gid: fields[3].parse().ok()?,
                gecos: fields[4].to_string(),
                home: fields[5].to_string(),
                shell: fields[6].to_string(),
            })
        })
        .filter(|entry| entry.uid == 0 || (entry.uid >= 1000 && entry.uid != NOBODY_UID))
        .collect())
}

/// name -> (password locked, has a usable password)
fn shadow_states() -> BTreeMap<String, (bool, bool)> {
    let Ok(text) = accounts().list(AccountList::Shadow) else {
        return BTreeMap::new();
    };
    text.lines()
        .filter_map(|line| {
            let mut fields = line.split(':');
            let name = fields.next()?.to_string();
            let hash = fields.next().unwrap_or("");
            let locked = hash.starts_with('!');
            let usable = hash.trim_start_matches('!');
            let has_password = !usable.is_empty() && usable != "*";
            Some((name, (locked, has_password)))
        })
        .collect()
}

fn admin_members() -> BTreeSet<String> {
    let Ok(text) = accounts().list(AccountList::Group) else {
        return BTreeSet::new();
    };
    text.lines()
        .filter_map(|line| {
            let fields: Vec<&str> = line.split(':').collect();
            (fields.len() >= 4 && ADMIN_GROUPS.contains(&fields[0])).then(|| fields[3])
        })
        .flat_map(|members| members.split(',').map(str::trim).filter(|m| !m.is_empty()))
        .map(str::to_string)
        .collect()
}

/// name -> number of open login sessions (from `who`).
fn session_counts() -> BTreeMap<String, usize> {
    let mut counts = BTreeMap::new();
    if let Ok(output) = Command::new("who").output() {
        for line in String::from_utf8_lossy(&output.stdout).lines() {
            if let Some(name) = line.split_whitespace().next() {
                *counts.entry(name.to_string()).or_insert(0) += 1;
            }
        }
    }
    counts
}

fn authorized_key_count(home: &str) -> usize {
    fs::read_to_string(Path::new(home).join(".ssh/authorized_keys"))
        .map(|text| {
            text.lines()
                .filter(|line| !line.trim().is_empty() && !line.trim_start().starts_with('#'))
                .count()
        })
        .unwrap_or(0)
}

pub fn allowed_shells() -> Vec<String> {
    let mut shells: Vec<String> = fs::read_to_string("/etc/shells")
        .unwrap_or_default()
        .lines()
        .map(str::trim)
        .filter(|line| line.starts_with('/') && Path::new(line).is_file())
        .map(str::to_string)
        .collect();
    let nologin = ["/usr/sbin/nologin", "/sbin/nologin"]
        .into_iter()
        .find(|path| Path::new(path).is_file())
        .unwrap_or("/usr/sbin/nologin");
    shells.push(nologin.to_string());
    shells.sort();
    shells.dedup();
    shells
}

pub fn list() -> Result<Value, String> {
    let shadow = shadow_states();
    let admins = admin_members();
    let sessions = session_counts();
    let users: Vec<Value> = passwd_entries()?
        .into_iter()
        .map(|entry| {
            let (password_locked, has_password) =
                shadow.get(&entry.name).copied().unwrap_or((false, false));
            json!({
                "name": entry.name,
                "uid": entry.uid,
                "gid": entry.gid,
                "comment": entry.gecos.split(',').next().unwrap_or(""),
                "home": entry.home,
                "shell": entry.shell,
                "can_login": can_login_shell(&entry.shell),
                "password_locked": password_locked,
                "has_password": has_password,
                "is_admin": entry.uid == 0 || admins.contains(&entry.name),
                "ssh_keys": authorized_key_count(&entry.home),
                "sessions": sessions.get(&entry.name).copied().unwrap_or(0),
            })
        })
        .collect();
    Ok(json!({ "users": users, "shells": allowed_shells() }))
}

fn find_user(name: &str) -> Result<PasswdEntry, String> {
    passwd_entries()?
        .into_iter()
        .find(|entry| entry.name == name)
        .ok_or_else(|| format!("{name} is not a manageable login user."))
}

fn run(program: &str, args: &[&str]) -> Result<(), String> {
    let output = Command::new(program)
        .args(args)
        .output()
        .map_err(|e| format!("{program}: {e}"))?;
    if output.status.success() {
        return Ok(());
    }
    let error = String::from_utf8_lossy(&output.stderr).trim().to_string();
    Err(if error.is_empty() { format!("{program} exited unsuccessfully") } else { error })
}

pub fn set_shell(name: &str, shell: &str) -> Result<String, String> {
    let user = find_user(name)?;
    if !allowed_shells().iter().any(|allowed| allowed == shell) {
        return Err("That shell is not installed on this server.".into());
    }
    if user.uid == 0 && !can_login_shell(shell) {
        return Err("root must keep a login shell, or the server can become unrecoverable.".into());
    }
    accounts().set_shell(name, shell).map_err(|e| e.to_string())?;
    Ok(if can_login_shell(shell) {
        format!("{name} can now log in ({shell}).")
    } else {
        format!("{name} can no longer open a shell.")
    })
}

pub fn set_password_lock(name: &str, locked: bool) -> Result<String, String> {
    find_user(name)?;
    accounts().lock(name, locked).map_err(|e| e.to_string())?;
    Ok(format!(
        "Password login {} for {name}.",
        if locked { "locked" } else { "unlocked" }
    ))
}

pub fn set_password(name: &str, password: &str) -> Result<String, String> {
    find_user(name)?;
    if password.chars().count() < 6 || password.chars().count() > 128 {
        return Err("Password must be 6 to 128 characters.".into());
    }
    if password.contains(['\n', '\r', ':']) {
        return Err("Password cannot contain new lines or ':'.".into());
    }
    accounts().set_password(name, password).map_err(|e| e.to_string())?;
    Ok(format!("Password updated for {name}."))
}

/// Ends interactive login sessions only — site PHP-FPM workers and cron jobs
/// run as the same user but outside logind sessions, so they keep running.
pub fn end_sessions(name: &str) -> Result<String, String> {
    let user = find_user(name)?;
    if user.uid == 0 {
        return Err("Ending root sessions from the panel is not allowed.".into());
    }
    if run("loginctl", &["terminate-user", name]).is_err() {
        // No systemd-logind: end the user's sshd session processes directly.
        let _ = run("pkill", &["-KILL", "-u", name, "-f", "^sshd:"]);
    }
    Ok(format!("Login sessions ended for {name}."))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn nologin_shells_cannot_log_in() {
        assert!(!can_login_shell("/usr/sbin/nologin"));
        assert!(!can_login_shell("/bin/false"));
        assert!(!can_login_shell(""));
        assert!(can_login_shell("/bin/bash"));
    }
}
