//! Process settings that differ by OS.

use tokio::process::Command;

/// Whether this process is root. A Windows server has no such thing until
/// task J, and a local profile never asks.
pub fn is_root() -> Result<bool, String> {
    #[cfg(unix)]
    {
        let output = std::process::Command::new("id")
            .arg("-u")
            .output()
            .map_err(|error| format!("failed to check uid: {error}"))?;
        if !output.status.success() {
            return Err("failed to determine effective uid".into());
        }
        Ok(String::from_utf8_lossy(&output.stdout).trim() == "0")
    }
    #[cfg(windows)]
    {
        Ok(false)
    }
}

/// Whether `program` can be run by name.
pub fn program_exists(program: &str) -> bool {
    #[cfg(unix)]
    {
        std::process::Command::new("sh")
            .arg("-c")
            .arg(format!("command -v {program} >/dev/null 2>&1"))
            .status()
            .map(|status| status.success())
            .unwrap_or(false)
    }
    #[cfg(windows)]
    {
        let path_var = std::env::var_os("PATH").unwrap_or_default();
        super::paths::on_windows_path(
            program,
            std::env::split_paths(&path_var),
            std::path::Path::is_file,
        )
        .is_some()
    }
}

/// Starts the child in its own process group, so that it and everything it
/// spawns can be stopped together.
pub fn new_process_group(command: &mut Command) -> &mut Command {
    #[cfg(unix)]
    {
        command.process_group(0)
    }
    #[cfg(windows)]
    {
        const CREATE_NEW_PROCESS_GROUP: u32 = 0x0000_0200;
        command.creation_flags(CREATE_NEW_PROCESS_GROUP)
    }
}

/// Makes the child end when drust ends, even when drust crashes. On Windows
/// this puts it in drust's Job Object. On Unix it does nothing: the children
/// that need it there (PHP-FPM, apps) belong to systemd, not to drust.
pub fn end_with_drust(child: &tokio::process::Child) -> Result<(), String> {
    #[cfg(unix)]
    {
        let _ = child;
        Ok(())
    }
    #[cfg(windows)]
    {
        let handle = child.raw_handle().ok_or("the process has already exited")?;
        super::windows::job::end_with_this_process(handle)
    }
}

/// Gives up root for good, becoming `nobody`, once a process has read what
/// only root may (the WAF worker after loading its rules). Does nothing when
/// not root, or where there is no such thing.
pub fn drop_privileges() -> Result<(), String> {
    #[cfg(unix)]
    {
        // SAFETY: plain libc calls on this process's own credentials.
        unsafe {
            if libc::geteuid() != 0 {
                return Ok(());
            }
            let (uid, gid) = super::accounts()
                .lookup("nobody")
                .map(|owner| (owner.uid, owner.gid))
                .unwrap_or((65534, 65534));
            if libc::setgroups(0, std::ptr::null()) != 0 || libc::setgid(gid) != 0 || libc::setuid(uid) != 0 {
                return Err(format!("cannot become nobody: {}", std::io::Error::last_os_error()));
            }
            if libc::setuid(0) == 0 {
                return Err("still able to become root after dropping privileges".into());
            }
        }
        Ok(())
    }
    #[cfg(windows)]
    {
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn finds_a_program_that_is_there_and_not_one_that_is_not() {
        let present = if cfg!(windows) { "cmd" } else { "sh" };
        assert!(program_exists(present));
        assert!(!program_exists("dpanel-no-such-program"));
    }
}
