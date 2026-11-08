//! Running a program as another account. `plans/platform-spec.md` section 6.5.
//!
//! Site owners control what runs here: npm and composer scripts, build
//! scripts, git hooks. The environment is therefore built from an allowlist,
//! so that `DRUST_API_TOKEN` and the like never reach them.

use super::{PlatformError, capabilities::Capability, paths::Tool};
use std::{ffi::OsStr, process::Command};

/// The only variables a child inherits; callers add their own after that.
const INHERITED_ENV: &[&str] = &[
    "PATH", "LANG", "LC_ALL", "TZ",
    "HTTP_PROXY", "HTTPS_PROXY", "NO_PROXY", "http_proxy", "https_proxy", "no_proxy",
    // Windows programs do not start without these.
    "PATHEXT", "SystemDrive", "SystemRoot", "windir", "TEMP", "TMP",
    "USERPROFILE", "LOCALAPPDATA", "APPDATA", "ComSpec",
];

pub trait IsolatedRunner: Send + Sync + std::fmt::Debug {
    /// `program` as `owner`, with the allowlisted environment and nothing else.
    fn command(&self, owner: &str, program: &OsStr) -> Result<Command, PlatformError>;
}

fn clean_environment(command: &mut Command, lookup: impl Fn(&str) -> Option<std::ffi::OsString>) {
    command.env_clear();
    for name in INHERITED_ENV {
        if let Some(value) = lookup(name) {
            command.env(name, value);
        }
    }
}

/// `runuser`, which keeps the environment it is given but for `HOME`, `SHELL`,
/// `USER` and `LOGNAME`.
#[derive(Debug)]
pub struct RunuserRunner;

impl IsolatedRunner for RunuserRunner {
    fn command(&self, owner: &str, program: &OsStr) -> Result<Command, PlatformError> {
        let mut command = Command::new(super::paths().binary_or_name(Tool::Runuser));
        command.args(["-u", owner, "--"]).arg(program);
        clean_environment(&mut command, |name| std::env::var_os(name));
        Ok(command)
    }
}

/// Everything runs as the logged-in user on a local profile.
#[derive(Debug)]
pub struct SameUserRunner;

impl IsolatedRunner for SameUserRunner {
    fn command(&self, _owner: &str, program: &OsStr) -> Result<Command, PlatformError> {
        let mut command = Command::new(program);
        clean_environment(&mut command, |name| std::env::var_os(name));
        Ok(command)
    }
}

/// A server without per-site users yet (tasks J2, K1) must not run site code
/// as the panel's own user.
#[derive(Debug)]
pub struct NoRunner;

impl IsolatedRunner for NoRunner {
    fn command(&self, _owner: &str, _program: &OsStr) -> Result<Command, PlatformError> {
        Err(PlatformError::Unsupported {
            capability: Capability::Isolation,
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::ffi::OsString;

    fn environment(lookup: impl Fn(&str) -> Option<OsString>) -> Vec<(String, String)> {
        let mut command = Command::new("anything");
        clean_environment(&mut command, lookup);
        command
            .get_envs()
            .filter_map(|(name, value)| {
                Some((name.to_str()?.to_string(), value?.to_str()?.to_string()))
            })
            .collect()
    }

    #[test]
    fn secrets_are_not_on_the_allowlist() {
        for name in ["DRUST_API_TOKEN", "APP_KEY", "DB_PASSWORD", "LD_PRELOAD", "GIT_CONFIG_VALUE_0"] {
            assert!(!INHERITED_ENV.contains(&name), "{name}");
        }
    }

    #[test]
    fn only_allowed_variables_are_passed_on() {
        let passed = environment(|name| Some(OsString::from(format!("value-of-{name}"))));
        // Windows treats `HTTP_PROXY` and `http_proxy` as one variable.
        let key = |name: &str| if cfg!(windows) { name.to_ascii_uppercase() } else { name.to_string() };
        let mut expected: Vec<String> = INHERITED_ENV.iter().map(|name| key(name)).collect();
        expected.sort();
        expected.dedup();
        let mut seen: Vec<String> = passed.iter().map(|(name, _)| key(name)).collect();
        seen.sort();
        assert_eq!(seen, expected);
    }

    #[test]
    fn a_child_really_does_not_see_the_token() {
        if cfg!(windows) {
            return;
        }
        // A child's environment is the proof; set the token on a parent shell.
        let script = "DRUST_API_TOKEN=secret \"$0\" --exact platform::runner::tests::probe --ignored --nocapture";
        let output = Command::new("sh")
            .args(["-c", script])
            .arg(std::env::current_exe().unwrap())
            .output()
            .unwrap();
        assert!(String::from_utf8_lossy(&output.stdout).contains("1 passed"));
    }

    #[test]
    #[ignore = "run by a_child_really_does_not_see_the_token"]
    fn probe() {
        assert_eq!(std::env::var("DRUST_API_TOKEN").as_deref(), Ok("secret"));
        let mut command = Command::new("env");
        clean_environment(&mut command, |name| std::env::var_os(name));
        let output = command.output().unwrap();
        let seen = String::from_utf8_lossy(&output.stdout).into_owned();
        assert!(output.status.success());
        assert!(!seen.contains("DRUST_API_TOKEN"), "{seen}");
    }

    #[test]
    fn a_server_without_site_users_refuses() {
        assert!(matches!(
            NoRunner.command("shop", OsStr::new("git")),
            Err(PlatformError::Unsupported { capability: Capability::Isolation })
        ));
    }
}
