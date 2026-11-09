//! An account's own PHP-FPM master (backlog P4).
//!
//! Site pools normally live in the shared `php<v>-fpm` master, whose workers
//! all sit in that service's cgroup, so an account's systemd slice cannot
//! limit them. An account with CPU or memory limits instead gets one master
//! per site user and PHP version, `dpanel-php<v>-fpm-<owner>.service`, in
//! its slice. The pool itself and its socket stay the same, so the gateway
//! notices nothing; only the master that runs it changes.
//!
//! Files, per PHP version:
//!   /etc/php/<v>/fpm/dpanel-dedicated/<owner>.pool   the pool, moved out of pool.d
//!   /etc/php/<v>/fpm/dpanel-dedicated/<owner>.conf   the master's [global] part
//!   /etc/systemd/system/dpanel-php<v>-fpm-<owner>.service
//! and `<state_dir>/fpm-dedicated/<owner>` holding the account group, which
//! also covers a PHP version the site switches to later.

use std::{
    fs,
    path::{Path, PathBuf},
    thread,
    time::Duration,
};

use tracing::{info, warn};

use super::php::{
    fpm_socket, provision_lock, reload_fpm_service, site_pool_content, validate_php_version,
    validate_system_user,
};
use crate::platform::{self, limits::valid_group};

const UNIT_DIR: &str = "/etc/systemd/system";

fn marker_dir() -> PathBuf {
    platform::paths().state_dir().join("fpm-dedicated")
}

fn valid_owner(owner: &str) -> bool {
    crate::app::valid_username(owner)
}

/// The account group whose slice this site user's PHP runs in, if any.
pub fn group_of(owner: &str) -> Option<String> {
    if !valid_owner(owner) {
        return None;
    }
    let group = fs::read_to_string(marker_dir().join(owner)).ok()?.trim().to_string();
    valid_group(&group).then_some(group)
}

/// Site users whose PHP runs in this group's slice.
pub fn owners_of(group: &str) -> Vec<String> {
    let Ok(entries) = fs::read_dir(marker_dir()) else { return Vec::new() };
    let mut owners: Vec<String> = entries
        .filter_map(Result::ok)
        .map(|entry| entry.file_name().to_string_lossy().to_string())
        .filter(|owner| group_of(owner).as_deref() == Some(group))
        .collect();
    owners.sort();
    owners
}

fn php_root() -> PathBuf {
    PathBuf::from("/etc/php")
}

fn dedicated_dir(version: &str) -> PathBuf {
    php_root().join(version).join("fpm/dpanel-dedicated")
}

fn shared_pool(owner: &str, version: &str) -> PathBuf {
    php_root().join(version).join(format!("fpm/pool.d/dpanel-{owner}.conf"))
}

fn own_pool(owner: &str, version: &str) -> PathBuf {
    dedicated_dir(version).join(format!("{owner}.pool"))
}

fn own_conf(owner: &str, version: &str) -> PathBuf {
    dedicated_dir(version).join(format!("{owner}.conf"))
}

pub fn unit_name(owner: &str, version: &str) -> String {
    format!("dpanel-php{version}-fpm-{owner}.service")
}

fn unit_path(owner: &str, version: &str) -> PathBuf {
    Path::new(UNIT_DIR).join(unit_name(owner, version))
}

fn socket(owner: &str, version: &str) -> PathBuf {
    fpm_socket(&format!("dpanel-{owner}-php{version}.sock"))
}

fn master_conf(owner: &str, version: &str, pool: &Path) -> String {
    let run = platform::paths().run_dir().join("php");
    format!(
        "; Managed by drust (P4): {owner}'s own PHP {version} master.\n\
         [global]\n\
         pid = {}\n\
         error_log = /var/log/php{version}-fpm-dpanel-{owner}.log\n\
         include = {}\n",
        run.join(format!("dpanel-{owner}-php{version}.pid")).display(),
        pool.display(),
    )
}

fn unit_file(owner: &str, version: &str, group: &str, conf: &Path) -> String {
    let binary = format!("/usr/sbin/php-fpm{version}");
    let conf = conf.display();
    format!(
        "# Managed by drust (backlog P4): runs {owner}'s PHP {version} sites inside\n\
         # account {group}'s CPU and memory limit.\n\
         [Unit]\n\
         Description=PHP {version} FastCGI for {owner} (dPanel)\n\
         After=network.target\n\
         \n\
         [Service]\n\
         Type=notify\n\
         Slice={slice}\n\
         ExecStartPre={binary} --test --fpm-config {conf}\n\
         ExecStart={binary} --nodaemonize --fpm-config {conf}\n\
         ExecReload=/bin/kill -USR2 $MAINPID\n\
         Restart=on-failure\n\
         RestartSec=2\n\
         \n\
         [Install]\n\
         WantedBy=multi-user.target\n",
        slice = platform::limits::slice_name(group),
    )
}

/// PHP versions this site user has a pool for, in either master.
fn versions_of(owner: &str) -> Vec<String> {
    let Ok(entries) = fs::read_dir(php_root()) else { return Vec::new() };
    let mut versions: Vec<String> = entries
        .filter_map(Result::ok)
        .map(|entry| entry.file_name().to_string_lossy().to_string())
        .filter(|version| validate_php_version(version).is_ok())
        .filter(|version| shared_pool(owner, version).is_file() || own_pool(owner, version).is_file())
        .collect();
    versions.sort();
    versions
}

/// True when the file changed.
fn write_if_changed(path: &Path, content: &str) -> Result<bool, String> {
    if fs::read_to_string(path).ok().as_deref() == Some(content) {
        return Ok(false);
    }
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent).map_err(|error| format!("cannot create {}: {error}", parent.display()))?;
    }
    fs::write(path, content).map_err(|error| format!("cannot write {}: {error}", path.display()))?;
    Ok(true)
}

fn wait_for(socket: &Path, present: bool) -> bool {
    for _ in 0..100 {
        if socket.exists() == present {
            return true;
        }
        thread::sleep(Duration::from_millis(50));
    }
    false
}

/// Moves this site user's PHP into account `group`'s slice: each of its
/// pools goes from the shared master to its own. On any failure everything
/// goes back to the shared master.
pub fn dedicate(owner: &str, group: &str) -> Result<(), String> {
    if !valid_owner(owner) || !valid_group(group) {
        return Err(format!("invalid site user or account group: {owner} {group}"));
    }
    validate_system_user(owner)?;
    let _guard = provision_lock()?;

    let marker = marker_dir().join(owner);
    write_if_changed(&marker, group)?;
    for version in versions_of(owner) {
        if let Err(error) = start_own_master(owner, &version, group) {
            warn!(owner, version, %error, "own PHP-FPM master failed; back to the shared one");
            release_locked(owner);
            return Err(error);
        }
    }
    Ok(())
}

/// Called with the provision lock held, when the gateway finds no socket for
/// a site user that runs its own master: a new PHP version, or the master stopped.
pub(super) fn ensure_running(owner: &str, version: &str, socket: &Path, group: &str) -> Result<(), String> {
    if !own_pool(owner, version).is_file() && !shared_pool(owner, version).is_file() {
        write_if_changed(&own_pool(owner, version), &site_pool_content(owner, socket))?;
    }
    start_own_master(owner, version, group)
}

fn start_own_master(owner: &str, version: &str, group: &str) -> Result<(), String> {
    let services = platform::services();
    let shared = shared_pool(owner, version);
    let pool = own_pool(owner, version);
    let unit = unit_name(owner, version);

    // Out of the shared master first: two masters cannot listen on one socket.
    let mut left_shared = false;
    if shared.is_file() {
        fs::create_dir_all(dedicated_dir(version)).map_err(|error| format!("cannot create {}: {error}", dedicated_dir(version).display()))?;
        if pool.is_file() {
            fs::remove_file(&shared).map_err(|error| format!("cannot remove {}: {error}", shared.display()))?;
        } else {
            fs::rename(&shared, &pool).map_err(|error| format!("cannot move {}: {error}", shared.display()))?;
        }
        left_shared = true;
    }

    let conf = own_conf(owner, version);
    write_if_changed(&conf, &master_conf(owner, version, &pool))?;
    let unit_changed = write_if_changed(&unit_path(owner, version), &unit_file(owner, version, group, &conf))?;
    services.reload_definitions().map_err(|error| error.to_string())?;

    let socket = socket(owner, version);
    if left_shared {
        reload_fpm_service(version)?;
        // The shared master normally takes its socket away when it lets the
        // pool go; a leftover file would only make the new master wait.
        if !wait_for(&socket, false) {
            let _ = fs::remove_file(&socket);
        }
    }

    let running = services.status(&unit).map(|status| status.active).unwrap_or(false);
    let started = if running && unit_changed {
        services.restart(&unit)
    } else if running {
        Ok(())
    } else {
        let _ = services.reset_failed(&unit);
        services.switch(&[unit.as_str()], true)
    };
    started.map_err(|error| format!("cannot start {unit}: {error}"))?;

    if wait_for(&socket, true) {
        info!(owner, version, group, "PHP runs in the account's own master");
        Ok(())
    } else {
        Err(format!("{unit} started but its socket {} did not appear", socket.display()))
    }
}

/// Puts this site user's PHP back in the shared master.
pub fn release(owner: &str) -> Result<(), String> {
    if !valid_owner(owner) {
        return Err(format!("invalid site user: {owner}"));
    }
    let _guard = provision_lock()?;
    let errors = release_locked(owner);
    if errors.is_empty() { Ok(()) } else { Err(errors.join("; ")) }
}

fn release_locked(owner: &str) -> Vec<String> {
    let _ = fs::remove_file(marker_dir().join(owner));
    let services = platform::services();
    let mut errors = Vec::new();
    for version in versions_of(owner) {
        let unit = unit_name(owner, version.as_str());
        let _ = services.switch(&[unit.as_str()], false);
        let _ = fs::remove_file(unit_path(owner, &version));
        let _ = fs::remove_file(own_conf(owner, &version));
        let (pool, shared) = (own_pool(owner, &version), shared_pool(owner, &version));
        if pool.is_file() {
            let moved = if shared.is_file() { fs::remove_file(&pool) } else { fs::rename(&pool, &shared) };
            if let Err(error) = moved {
                errors.push(format!("cannot move {} back: {error}", pool.display()));
                continue;
            }
        }
        let _ = services.reload_definitions();
        // A pool whose user is gone would stop the shared master from loading.
        if validate_system_user(owner).is_err() {
            let _ = fs::remove_file(&shared);
        }
        if let Err(error) = reload_fpm_service(&version) {
            errors.push(error);
        }
        if !wait_for(&socket(owner, &version), true) {
            warn!(owner, version, "shared PHP-FPM master has not opened the pool's socket yet");
        }
    }
    errors
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn the_unit_runs_the_owner_s_master_in_the_account_slice() {
        let unit = unit_file("shop", "8.3", "a12", Path::new("/etc/php/8.3/fpm/dpanel-dedicated/shop.conf"));
        assert!(unit.contains("Slice=dpanel-a12.slice\n"));
        assert!(unit.contains("Type=notify\n"));
        assert!(unit.contains(
            "ExecStart=/usr/sbin/php-fpm8.3 --nodaemonize --fpm-config /etc/php/8.3/fpm/dpanel-dedicated/shop.conf\n"
        ));
        assert_eq!(unit_name("shop", "8.3"), "dpanel-php8.3-fpm-shop.service");
    }

    #[test]
    fn the_master_includes_only_the_owner_s_pool() {
        let conf = master_conf("shop", "8.3", Path::new("/etc/php/8.3/fpm/dpanel-dedicated/shop.pool"));
        assert!(conf.contains("[global]\n"));
        assert!(conf.contains("include = /etc/php/8.3/fpm/dpanel-dedicated/shop.pool\n"));
        assert!(conf.contains("dpanel-shop-php8.3.pid"));
        assert!(!conf.contains("pool.d"));
    }

    #[test]
    fn an_unknown_or_odd_owner_has_no_group() {
        assert_eq!(group_of("../etc"), None);
        assert_eq!(group_of("nobody-has-this-marker"), None);
    }
}
