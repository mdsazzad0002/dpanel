//! `drust supervise`, `drust service …` and `drust autostart …` (F4): drust
//! as its own service manager where there is no systemd. One process runs
//! the API and supervises the gateway, MariaDB and the queue worker
//! (`platform::supervisor`); the OS only has to start that one process.

use std::{
    net::TcpListener,
    path::{Path, PathBuf},
    process::ExitCode,
    time::Duration,
};

use crate::platform::{self, autostart};

/// How long the programs get to stop when drust stops.
const STOP_TIMEOUT: Duration = Duration::from_secs(10);

struct Options {
    env_file: PathBuf,
    background: bool,
    /// Passed on to the API server (`--port`, `--token`).
    api_args: Vec<String>,
}

fn options(args: &[String]) -> Result<Options, String> {
    let mut env_file = None;
    let mut background = false;
    let mut api_args = Vec::new();
    let mut iter = args.iter();
    while let Some(arg) = iter.next() {
        match arg.as_str() {
            "--env-file" => env_file = Some(PathBuf::from(iter.next().ok_or("--env-file needs a path")?)),
            "--background" => background = true,
            _ => api_args.push(arg.clone()),
        }
    }
    let env_file = env_file.unwrap_or_else(platform::env_file::default_path);
    Ok(Options { env_file, background, api_args })
}

fn fail(message: impl std::fmt::Display) -> ExitCode {
    eprintln!("{message}");
    ExitCode::from(1)
}

/// Reads `drust.env` before anything asks for `platform::current()`, which
/// fixes the profile and the paths.
fn load_env(path: &Path) -> Result<(), String> {
    platform::env_file::load(path).map(drop)
}

fn api_port(api_args: &[String]) -> u16 {
    api_args
        .iter()
        .position(|arg| arg == "--port" || arg == "-p")
        .and_then(|index| api_args.get(index + 1))
        .and_then(|port| port.parse().ok())
        .unwrap_or(9500)
}

fn supervisor() -> Result<&'static std::sync::Arc<platform::supervisor::Supervisor>, String> {
    platform::supervisor().ok_or_else(|| "systemd runs drust's services on this system; use `drust serve`".to_string())
}

/// Boots the enabled programs and serves the API; never returns while the
/// API runs.
fn serve(api_args: Vec<String>) -> ExitCode {
    let supervisor = match supervisor() {
        Ok(supervisor) => supervisor,
        Err(error) => return fail(error),
    };
    // A second copy would start every program twice.
    if TcpListener::bind(("127.0.0.1", api_port(&api_args))).is_err() {
        return fail(format!("drust is already running (port {} is in use)", api_port(&api_args)));
    }
    supervisor.boot();
    crate::api::serve(api_args)
}

pub fn supervise(args: &[String]) -> ExitCode {
    let options = match options(args) {
        Ok(options) => options,
        Err(error) => return fail(error),
    };
    if options.background {
        let exe = match std::env::current_exe() {
            Ok(exe) => exe,
            Err(error) => return fail(error),
        };
        let env_file = options.env_file.to_string_lossy().into_owned();
        let mut child_args = vec!["supervise", "--env-file", env_file.as_str()];
        child_args.extend(options.api_args.iter().map(String::as_str));
        return match autostart::spawn_detached(&exe, &child_args) {
            Ok(()) => ExitCode::SUCCESS,
            Err(error) => fail(error),
        };
    }
    if let Err(error) = load_env(&options.env_file) {
        return fail(error);
    }
    serve(options.api_args)
}

fn launch_line(subcommand: &[&str], env_file: &Path) -> Result<String, String> {
    let exe = std::env::current_exe().map_err(|error| error.to_string())?;
    let env_file = env_file.to_string_lossy();
    let mut args = subcommand.to_vec();
    args.extend(["--env-file", &env_file]);
    Ok(autostart::command_line(&exe, &args))
}

pub fn service(args: &[String]) -> ExitCode {
    let (action, rest) = match args.split_first() {
        Some((action, rest)) => (action.as_str(), rest),
        None => return fail("usage: drust service install|uninstall|status|run [--env-file <path>]"),
    };
    let options = match options(rest) {
        Ok(options) => options,
        Err(error) => return fail(error),
    };
    match action {
        "install" => {
            let line = match launch_line(&["service", "run"], &options.env_file) {
                Ok(line) => line,
                Err(error) => return fail(error),
            };
            match autostart::install_service(&line) {
                Ok(()) => {
                    println!("Installed and started the dPanel service; it starts at boot.");
                    println!("Settings: {}", options.env_file.display());
                    ExitCode::SUCCESS
                }
                Err(error) => fail(error),
            }
        }
        "uninstall" => match autostart::uninstall_service() {
            Ok(true) => {
                println!("Removed the dPanel service.");
                ExitCode::SUCCESS
            }
            Ok(false) => {
                println!("The dPanel service is not installed.");
                ExitCode::SUCCESS
            }
            Err(error) => fail(error),
        },
        "status" => match autostart::service_installed() {
            Ok(installed) => {
                println!("{}", if installed { "installed" } else { "not installed" });
                ExitCode::SUCCESS
            }
            Err(error) => fail(error),
        },
        "run" => {
            if let Err(error) = load_env(&options.env_file) {
                return fail(error);
            }
            let api_args = options.api_args.clone();
            let result = autostart::run_as_service(
                move || {
                    let _ = serve(api_args.clone());
                },
                || {
                    if let Some(supervisor) = platform::supervisor() {
                        supervisor.stop_all(STOP_TIMEOUT);
                    }
                },
            );
            match result {
                Ok(()) => ExitCode::SUCCESS,
                Err(error) => fail(error),
            }
        }
        other => fail(format!("unknown service action: {other}")),
    }
}

pub fn autostart(args: &[String]) -> ExitCode {
    let (action, rest) = match args.split_first() {
        Some((action, rest)) => (action.as_str(), rest),
        None => return fail("usage: drust autostart enable|disable|status [--env-file <path>]"),
    };
    let options = match options(rest) {
        Ok(options) => options,
        Err(error) => return fail(error),
    };
    match action {
        "enable" => {
            let line = match launch_line(&["supervise", "--background"], &options.env_file) {
                Ok(line) => line,
                Err(error) => return fail(error),
            };
            match autostart::enable_at_logon(&line) {
                Ok(()) => {
                    println!("drust now starts when you log on: {line}");
                    ExitCode::SUCCESS
                }
                Err(error) => fail(error),
            }
        }
        "disable" => match autostart::disable_at_logon() {
            Ok(removed) => {
                println!("{}", if removed { "drust no longer starts at logon." } else { "drust was not set to start at logon." });
                ExitCode::SUCCESS
            }
            Err(error) => fail(error),
        },
        "status" => match autostart::logon_command_line() {
            Ok(Some(line)) => {
                println!("starts at logon: {line}");
                ExitCode::SUCCESS
            }
            Ok(None) => {
                println!("does not start at logon");
                ExitCode::SUCCESS
            }
            Err(error) => fail(error),
        },
        other => fail(format!("unknown autostart action: {other}")),
    }
}

#[cfg(test)]
mod tests {
    use super::{api_port, options};

    #[test]
    fn options_keep_the_api_arguments() {
        let args = ["--env-file", "x.env", "--port", "9600", "--background"].map(String::from);
        let parsed = options(&args).unwrap();
        assert_eq!(parsed.env_file, std::path::PathBuf::from("x.env"));
        assert!(parsed.background);
        assert_eq!(parsed.api_args, ["--port", "9600"]);
        assert_eq!(api_port(&parsed.api_args), 9600);
        assert_eq!(api_port(&[]), 9500);
        assert!(options(&["--env-file".to_string()]).is_err());
    }
}
