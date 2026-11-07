//! The one place that knows which operating system drust runs on. Code above
//! this layer asks for what it needs and never names an OS, and every
//! `#[cfg(unix)]`-style attribute stays inside this module. See
//! `plans/platform-spec.md`.

pub mod account;
pub mod capabilities;
pub mod fastcgi;
pub mod fs;
pub mod paths;
pub mod process;
#[cfg(unix)]
mod unix;
#[cfg(windows)]
mod windows;

use account::{AccountManager, LinuxAccounts, LocalAccounts, NoAccounts};
use capabilities::Capability;
use fs::FileAccess;
use paths::Paths;
use std::{env, fmt, io, sync::OnceLock};

#[cfg(unix)]
use unix::{fs::NativeFileAccess, random::fill};
#[cfg(windows)]
use windows::{fs::NativeFileAccess, random::fill};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Os {
    Linux,
    Macos,
    Windows,
}

impl Os {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Linux => "linux",
            Self::Macos => "macos",
            Self::Windows => "windows",
        }
    }

    fn detect() -> Self {
        if cfg!(windows) {
            Self::Windows
        } else if cfg!(target_os = "macos") {
            Self::Macos
        } else {
            Self::Linux
        }
    }
}

/// `server` hosts real sites; `local` is a developer's own machine.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Profile {
    Server,
    Local,
}

impl Profile {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Server => "server",
            Self::Local => "local",
        }
    }

    /// Anything but `local` is a server, so a typo never drops hosting features.
    fn parse(value: Option<&str>) -> Self {
        match value.map(|value| value.trim().to_ascii_lowercase()).as_deref() {
            Some("local") => Self::Local,
            _ => Self::Server,
        }
    }
}

#[derive(Debug)]
pub struct Platform {
    os: Os,
    profile: Profile,
    paths: Paths,
    accounts: Box<dyn AccountManager>,
}

impl Platform {
    pub fn os(&self) -> Os {
        self.os
    }

    pub fn profile(&self) -> Profile {
        self.profile
    }

    pub fn paths(&self) -> &Paths {
        &self.paths
    }

    pub fn files(&self) -> &'static dyn FileAccess {
        &NativeFileAccess
    }

    pub fn accounts(&self) -> &dyn AccountManager {
        self.accounts.as_ref()
    }

    /// A local profile on a PC runs every site as the logged-in user, so a site
    /// account is only a folder and there is no other owner to give a file to.
    /// Linux keeps real accounts on every profile.
    pub fn one_user(&self) -> bool {
        one_user(self.os, self.profile)
    }

    /// PHP-FPM has no Windows build, so php-cgi processes stand in for it.
    /// A Windows server needs them under site users first (task J4).
    pub fn uses_php_cgi_pool(&self) -> bool {
        self.os == Os::Windows && self.profile == Profile::Local
    }

    /// Section 11 of the spec: a developer's PC serves nobody but itself.
    /// `0.0.0.0` becomes loopback; any other outside address is refused.
    pub fn local_bind(&self, bind: &str) -> Result<String, String> {
        local_bind(bind, self.one_user())
    }

    /// Only a machine with real accounts has to be root.
    pub fn needs_root(&self) -> bool {
        !self.one_user()
    }
}

fn one_user(os: Os, profile: Profile) -> bool {
    os != Os::Linux && profile == Profile::Local
}

fn local_bind(bind: &str, loopback_only: bool) -> Result<String, String> {
    if !loopback_only {
        return Ok(bind.to_string());
    }
    let address: std::net::SocketAddr =
        bind.parse().map_err(|_| format!("invalid listen address: {bind}"))?;
    if address.ip().is_loopback() {
        Ok(bind.to_string())
    } else if address.ip().is_unspecified() {
        Ok(format!("127.0.0.1:{}", address.port()))
    } else {
        Err(format!("a local profile listens on loopback only, not {bind}"))
    }
}

pub fn current() -> &'static Platform {
    static CURRENT: OnceLock<Platform> = OnceLock::new();
    CURRENT.get_or_init(|| {
        let os = Os::detect();
        let profile = Profile::parse(env::var("DPANEL_PROFILE").ok().as_deref());
        let paths = Paths::for_system(os, profile, |name| env::var(name).ok());
        let accounts = accounts_for(os, profile, &paths);
        Platform {
            os,
            profile,
            paths,
            accounts,
        }
    })
}

fn accounts_for(os: Os, profile: Profile, paths: &Paths) -> Box<dyn AccountManager> {
    match (os, profile) {
        (Os::Linux, _) => Box::new(LinuxAccounts),
        (_, Profile::Local) => Box::new(LocalAccounts {
            sites_root: paths.sites_root().to_path_buf(),
        }),
        (_, Profile::Server) => Box::new(NoAccounts),
    }
}

/// Shorthand for `current().paths()`.
pub fn paths() -> &'static Paths {
    current().paths()
}

/// Shorthand for `current().files()`.
pub fn files() -> &'static dyn FileAccess {
    current().files()
}

/// Shorthand for `current().accounts()`.
pub fn accounts() -> &'static dyn AccountManager {
    current().accounts()
}

/// Fills the buffer from the OS random source.
pub fn random_bytes(buffer: &mut [u8]) -> Result<(), PlatformError> {
    fill(buffer).map_err(|error| PlatformError::from_io("read random bytes", error))
}

/// For a test of exact Unix modes, which Windows ACLs have no form of.
#[cfg(test)]
pub fn os_has_no_file_modes() -> bool {
    current().os() == Os::Windows
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum PlatformError {
    Unsupported { capability: Capability },
    NotFound { what: String },
    PermissionDenied { what: String },
    Conflict { what: String },
    Failed { action: String, detail: String },
}

/// Callers already say what they were doing, as in `cannot write ticket: {e}`,
/// so an error from the OS prints exactly the text it always did.
impl fmt::Display for PlatformError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::Unsupported { capability } => {
                write!(f, "{} is not supported on this system", capability.as_str())
            }
            Self::NotFound { what } | Self::PermissionDenied { what } | Self::Conflict { what } => {
                f.write_str(what)
            }
            Self::Failed { detail, .. } => f.write_str(detail),
        }
    }
}

impl std::error::Error for PlatformError {}

impl PlatformError {
    pub fn from_io(action: &str, error: io::Error) -> Self {
        let what = error.to_string();
        match error.kind() {
            io::ErrorKind::NotFound => Self::NotFound { what },
            io::ErrorKind::PermissionDenied => Self::PermissionDenied { what },
            io::ErrorKind::AlreadyExists => Self::Conflict { what },
            _ => Self::Failed {
                action: action.to_string(),
                detail: what,
            },
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn current_names_the_os_it_was_built_for() {
        let expected = if cfg!(windows) {
            "windows"
        } else if cfg!(target_os = "macos") {
            "macos"
        } else {
            "linux"
        };
        assert_eq!(current().os().as_str(), expected);
    }

    #[test]
    fn only_a_local_profile_off_linux_is_one_user() {
        assert!(one_user(Os::Windows, Profile::Local));
        assert!(one_user(Os::Macos, Profile::Local));
        assert!(!one_user(Os::Linux, Profile::Local));
        assert!(!one_user(Os::Linux, Profile::Server));
        assert!(!one_user(Os::Windows, Profile::Server));
        assert!(!one_user(Os::Macos, Profile::Server));
    }

    #[test]
    fn a_local_profile_never_listens_outside_this_machine() {
        assert_eq!(local_bind("0.0.0.0:80", true).unwrap(), "127.0.0.1:80");
        assert_eq!(local_bind("[::]:443", true).unwrap(), "127.0.0.1:443");
        assert_eq!(local_bind("127.0.0.1:19080", true).unwrap(), "127.0.0.1:19080");
        assert!(local_bind("192.0.2.1:80", true).is_err());
        assert_eq!(local_bind("0.0.0.0:80", false).unwrap(), "0.0.0.0:80");
    }

    #[test]
    fn profile_defaults_to_server() {
        assert_eq!(Profile::parse(None), Profile::Server);
        assert_eq!(Profile::parse(Some("")), Profile::Server);
        assert_eq!(Profile::parse(Some("server")), Profile::Server);
        assert_eq!(Profile::parse(Some("lcoal")), Profile::Server);
        assert_eq!(Profile::parse(Some("local")), Profile::Local);
        assert_eq!(Profile::parse(Some(" Local\n")), Profile::Local);
    }

    #[test]
    fn io_errors_keep_their_kind_and_their_text() {
        let cases = [
            (io::ErrorKind::NotFound, "gone"),
            (io::ErrorKind::PermissionDenied, "denied"),
            (io::ErrorKind::AlreadyExists, "taken"),
            (io::ErrorKind::InvalidInput, "bad"),
        ];
        for (kind, text) in cases {
            let error = PlatformError::from_io("write file", io::Error::new(kind, text));
            assert_eq!(error.to_string(), text);
            let matched = match kind {
                io::ErrorKind::NotFound => matches!(error, PlatformError::NotFound { .. }),
                io::ErrorKind::PermissionDenied => {
                    matches!(error, PlatformError::PermissionDenied { .. })
                }
                io::ErrorKind::AlreadyExists => matches!(error, PlatformError::Conflict { .. }),
                _ => matches!(&error, PlatformError::Failed { action, .. } if action == "write file"),
            };
            assert!(matched, "{error:?}");
        }
    }
}
