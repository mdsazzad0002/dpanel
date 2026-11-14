//! Starting drust's supervisor with the OS (F4). Windows has two ways:
//! at the user's logon (local profile, runs as the user, no administrator)
//! and as a Windows Service (server profile, LocalSystem, at boot). Other
//! systems have systemd units or, on macOS later (E2/K8), launchd.

use std::path::Path;

use super::{PlatformError, capabilities::Capability};

#[cfg(windows)]
use super::windows::{autostart as logon, service};

fn unsupported<T>() -> Result<T, PlatformError> {
    Err(PlatformError::Unsupported { capability: Capability::Services })
}

fn failed(action: &str) -> impl FnOnce(String) -> PlatformError + '_ {
    move |detail| PlatformError::Failed { action: action.to_string(), detail }
}

/// `"C:\Program Files\x\drust.exe" supervise --env-file "C:\…\drust.env"`:
/// each part quoted when it has a space. Windows paths cannot hold `"`.
pub fn command_line(program: &Path, args: &[&str]) -> String {
    std::iter::once(program.to_string_lossy().into_owned())
        .chain(args.iter().map(|arg| arg.to_string()))
        .map(|part| if part.is_empty() || part.contains([' ', '\t']) { format!("\"{part}\"") } else { part })
        .collect::<Vec<_>>()
        .join(" ")
}

pub fn enable_at_logon(command_line: &str) -> Result<(), PlatformError> {
    #[cfg(windows)]
    {
        logon::enable(command_line).map_err(failed("enable start at logon"))
    }
    #[cfg(not(windows))]
    {
        let _ = command_line;
        unsupported()
    }
}

/// `false` when it was not enabled.
pub fn disable_at_logon() -> Result<bool, PlatformError> {
    #[cfg(windows)]
    {
        logon::disable().map_err(failed("disable start at logon"))
    }
    #[cfg(not(windows))]
    {
        unsupported()
    }
}

pub fn logon_command_line() -> Result<Option<String>, PlatformError> {
    #[cfg(windows)]
    {
        logon::command_line().map_err(failed("read start at logon"))
    }
    #[cfg(not(windows))]
    {
        unsupported()
    }
}

pub fn install_service(command_line: &str) -> Result<(), PlatformError> {
    #[cfg(windows)]
    {
        service::install(command_line).map_err(failed("install the service"))
    }
    #[cfg(not(windows))]
    {
        let _ = command_line;
        unsupported()
    }
}

/// `false` when it was not installed.
pub fn uninstall_service() -> Result<bool, PlatformError> {
    #[cfg(windows)]
    {
        service::uninstall().map_err(failed("remove the service"))
    }
    #[cfg(not(windows))]
    {
        unsupported()
    }
}

pub fn service_installed() -> Result<bool, PlatformError> {
    #[cfg(windows)]
    {
        service::installed().map_err(failed("query the service"))
    }
    #[cfg(not(windows))]
    {
        unsupported()
    }
}

/// Runs as the service the OS started; see `windows::service::run`.
pub fn run_as_service(
    main: impl Fn() + Send + Sync + 'static,
    stop: impl Fn() + Send + Sync + 'static,
) -> Result<(), PlatformError> {
    #[cfg(windows)]
    {
        service::run(main, stop).map_err(failed("run as a service"))
    }
    #[cfg(not(windows))]
    {
        let _ = (main, stop);
        unsupported()
    }
}

/// Starts `program` with no console and outside drust's Job Object, so it
/// outlives this process: `drust supervise --background` at logon.
pub fn spawn_detached(program: &Path, args: &[&str]) -> Result<(), PlatformError> {
    let mut command = std::process::Command::new(program);
    command
        .args(args)
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null());
    #[cfg(windows)]
    {
        use std::os::windows::process::CommandExt;
        const DETACHED_PROCESS: u32 = 0x0000_0008;
        const CREATE_NEW_PROCESS_GROUP: u32 = 0x0000_0200;
        const CREATE_BREAKAWAY_FROM_JOB: u32 = 0x0100_0000;
        command.creation_flags(DETACHED_PROCESS | CREATE_NEW_PROCESS_GROUP | CREATE_BREAKAWAY_FROM_JOB);
    }
    #[cfg(unix)]
    {
        use std::os::unix::process::CommandExt;
        command.process_group(0);
    }
    command.spawn().map(drop).map_err(|error| PlatformError::Failed {
        action: "start in the background".into(),
        detail: format!("{}: {error}", program.display()),
    })
}

#[cfg(test)]
mod tests {
    use super::command_line;
    use std::path::Path;

    #[test]
    fn quotes_only_the_parts_with_spaces() {
        assert_eq!(
            command_line(
                Path::new(r"C:\Program Files\dPanel\drust.exe"),
                &["supervise", "--env-file", r"C:\Users\A B\dPanel\config\drust.env"]
            ),
            r#""C:\Program Files\dPanel\drust.exe" supervise --env-file "C:\Users\A B\dPanel\config\drust.env""#
        );
        assert_eq!(command_line(Path::new("drust"), &["service", "run"]), "drust service run");
    }
}
