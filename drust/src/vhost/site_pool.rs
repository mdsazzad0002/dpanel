use std::{fs, path::Path, path::PathBuf, thread, time::Duration};

use crate::app::{info, run_status, valid_username, warn, write_string};

/// Every website sharing the default `www-data` pool means one site's PHP code can
/// read every other site's files, including the panel's own `.env`. A pool per
/// account moves each site onto its own uid, so ordinary file permissions do the
/// isolating.
///
/// Returns the FPM socket the vhost should talk to. Falls back to the shared
/// socket whenever a private pool cannot be proven to work, because a vhost
/// pointing at a missing socket takes the site down.
pub(super) fn resolve_socket(document_root: &Path, php_version: &str) -> PathBuf {
    let shared = PathBuf::from(format!("/run/php/php{php_version}-fpm.sock"));

    if !pools_enabled() {
        return shared;
    }

    let Some(owner) = owner_from_path(document_root) else {
        return shared;
    };

    match ensure_pool(&owner, php_version) {
        Ok(socket) => {
            info(&format!(
                "Using private PHP-FPM pool for {owner} ({})",
                socket.display()
            ));
            socket
        }
        Err(error) => {
            warn(&format!(
                "Private PHP-FPM pool for {owner} unavailable ({error}); using the shared pool."
            ));
            shared
        }
    }
}

/// Escape hatch for hosts that need every site back on the shared pool:
/// `DRUST_SITE_POOLS=0` in /etc/drust/drust.env.
fn pools_enabled() -> bool {
    match std::env::var("DRUST_SITE_POOLS") {
        Ok(value) => !matches!(
            value.trim().to_ascii_lowercase().as_str(),
            "0" | "false" | "off" | "no"
        ),
        Err(_) => true,
    }
}

/// Site roots live at /home/<account>/... . Anything else (for example a path
/// under /var/www) keeps the shared pool.
fn owner_from_path(path: &Path) -> Option<String> {
    let mut parts = path.components().skip(1);
    let home = parts.next()?.as_os_str().to_str()?;
    if home != "home" {
        return None;
    }

    let owner = parts.next()?.as_os_str().to_str()?.to_string();
    if !valid_username(&owner) {
        return None;
    }

    // A pool can only run as an account that actually exists.
    run_status("id", &["-u", &owner]).ok()?;
    Some(owner)
}

fn ensure_pool(owner: &str, php_version: &str) -> Result<PathBuf, String> {
    let pool_dir = PathBuf::from(format!("/etc/php/{php_version}/fpm/pool.d"));
    if !pool_dir.is_dir() {
        return Err(format!("{} does not exist", pool_dir.display()));
    }

    let socket = PathBuf::from(format!("/run/php/dpanel-{owner}-php{php_version}.sock"));
    let pool_file = pool_dir.join(format!("dpanel-{owner}.conf"));
    let home = format!("/home/{owner}");
    let tmp_dir = format!("{home}/tmp");
    let session_dir = format!("{tmp_dir}/sessions");

    for directory in [&tmp_dir, &session_dir] {
        fs::create_dir_all(directory)
            .map_err(|error| format!("failed to create {directory}: {error}"))?;
        run_status("chown", &[&format!("{owner}:{owner}"), directory])?;
        run_status("chmod", &["0700", directory])?;
    }

    let contents = pool_contents(owner, &socket, &home, &tmp_dir, &session_dir, max_children());
    let previous = fs::read_to_string(&pool_file).ok();
    let unchanged = previous.as_deref() == Some(contents.as_str());

    if !unchanged {
        write_string(&pool_file, &contents)?;

        // php-fpm exits when it is told to reload a configuration it cannot
        // parse, which would take every site on this PHP version down. Test
        // first and put the old file back if the new one is not accepted.
        if let Err(error) = verify_fpm_config(php_version) {
            match previous {
                Some(old) => {
                    let _ = write_string(&pool_file, &old);
                }
                None => {
                    let _ = fs::remove_file(&pool_file);
                }
            }
            return Err(error);
        }
    }

    if unchanged && socket.exists() {
        return Ok(socket);
    }

    reload_fpm(php_version)?;
    wait_for_socket(&socket)?;
    Ok(socket)
}

/// One pool per site must not turn into one memory budget per site. `ondemand`
/// keeps an idle site at zero children, and the ceiling is derived from what the
/// machine actually has so a 1 GB VPS hosting twenty sites cannot be pushed into
/// swap by a single busy one.
fn max_children() -> u32 {
    if let Some(override_value) = std::env::var("DRUST_SITE_POOL_MAX_CHILDREN")
        .ok()
        .and_then(|value| value.trim().parse::<u32>().ok())
        .filter(|value| *value > 0)
    {
        return override_value;
    }

    children_for(total_memory_mb(), cpu_count())
}

fn children_for(total_memory_mb: Option<u64>, cpus: usize) -> u32 {
    // Roughly 512 MB of RAM per allowed child leaves room for MariaDB, the web
    // servers and the other pools on the box.
    let by_memory = total_memory_mb.map_or(4, |total| (total / 512).clamp(2, 10) as u32);
    // More workers than the CPU can run only adds context switching under load.
    let by_cpu = (cpus.saturating_mul(2)).clamp(2, 10) as u32;

    by_memory.min(by_cpu).max(2)
}

fn total_memory_mb() -> Option<u64> {
    let meminfo = fs::read_to_string("/proc/meminfo").ok()?;
    let line = meminfo
        .lines()
        .find(|line| line.starts_with("MemTotal:"))?;
    let kb = line.split_whitespace().nth(1)?.parse::<u64>().ok()?;
    Some(kb / 1024)
}

fn cpu_count() -> usize {
    thread::available_parallelism()
        .map(|count| count.get())
        .unwrap_or(1)
}

fn pool_contents(
    owner: &str,
    socket: &Path,
    home: &str,
    tmp_dir: &str,
    session_dir: &str,
    max_children: u32,
) -> String {
    format!(
        "\
; Managed by dpanel. Edits are overwritten on the next vhost sync.
[{owner}]
user = {owner}
group = {owner}
listen = {socket}
; nginx and Apache reach the socket through the web group only.
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; ondemand: an idle site holds no worker at all, which is what makes one pool
; per site affordable on a small server.
pm = ondemand
pm.max_children = {max_children}
pm.process_idle_timeout = 10s
pm.max_requests = 500

; Keep temporary and session data inside the account instead of a shared
; directory every site can read.
php_admin_value[open_basedir] = {home}:/tmp:/usr/share/php
php_admin_value[upload_tmp_dir] = {tmp_dir}
php_admin_value[sys_temp_dir] = {tmp_dir}
php_admin_value[session.save_path] = {session_dir}
php_admin_flag[log_errors] = on
",
        owner = owner,
        socket = socket.display(),
        home = home,
        tmp_dir = tmp_dir,
        session_dir = session_dir,
        max_children = max_children,
    )
}

/// `php-fpm -t` validates every pool file. A missing binary means there is
/// nothing to validate against, which is not a reason to refuse the pool.
fn verify_fpm_config(php_version: &str) -> Result<(), String> {
    let binary = format!("php-fpm{php_version}");
    if !Path::new(&format!("/usr/sbin/{binary}")).exists() {
        return Ok(());
    }

    run_status(&binary, &["-t"])
        .map_err(|error| format!("php-fpm rejected the generated pool: {error}"))
}

fn reload_fpm(php_version: &str) -> Result<(), String> {
    let service = format!("php{php_version}-fpm");
    run_status("systemctl", &["reload", &service])
        .or_else(|_| run_status("systemctl", &["restart", &service]))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn ignores_roots_outside_home() {
        assert_eq!(owner_from_path(Path::new("/var/www/html")), None);
        assert_eq!(owner_from_path(Path::new("/home")), None);
        assert_eq!(owner_from_path(Path::new("/home/../etc/passwd")), None);
    }

    #[test]
    fn rejects_invalid_account_names() {
        assert_eq!(owner_from_path(Path::new("/home/Bad Name/public")), None);
    }

    #[test]
    fn worker_ceiling_follows_the_smaller_of_memory_and_cpu() {
        // 1 GB / 1 core VPS: the floor, so a busy site cannot swap the box out.
        assert_eq!(children_for(Some(1024), 1), 2);
        // 2 GB / 2 cores.
        assert_eq!(children_for(Some(2048), 2), 4);
        // Plenty of RAM but a single core stays CPU-bound.
        assert_eq!(children_for(Some(16384), 1), 2);
        // Large box hits the cap rather than growing without limit.
        assert_eq!(children_for(Some(16384), 8), 10);
        // Below the per-child budget the floor still applies.
        assert_eq!(children_for(Some(384), 1), 2);
        // Unreadable /proc/meminfo falls back to a safe middle value.
        assert_eq!(children_for(None, 8), 4);
    }

    #[test]
    fn pool_keeps_the_account_inside_its_own_home() {
        let contents = pool_contents(
            "example",
            Path::new("/run/php/dpanel-example-php8.3.sock"),
            "/home/example",
            "/home/example/tmp",
            "/home/example/tmp/sessions",
            4,
        );

        assert!(contents.contains("[example]"));
        assert!(contents.contains("user = example"));
        assert!(contents.contains("group = example"));
        assert!(contents.contains("listen.owner = www-data"));
        assert!(contents.contains("open_basedir] = /home/example:/tmp:/usr/share/php"));
        assert!(contents.contains("session.save_path] = /home/example/tmp/sessions"));
    }
}

fn wait_for_socket(socket: &Path) -> Result<(), String> {
    for _ in 0..25 {
        if socket.exists() {
            return Ok(());
        }
        thread::sleep(Duration::from_millis(200));
    }

    Err(format!("{} never appeared", socket.display()))
}
