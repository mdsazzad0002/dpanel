//! The one place that knows which operating system drust runs on. Code above
//! this layer asks for what it needs and never names an OS, and every
//! `#[cfg(unix)]`-style attribute stays inside this module. See
//! `plans/platform-spec.md`.

pub mod capabilities;
pub mod fastcgi;
pub mod fs;
#[cfg(unix)]
mod unix;
#[cfg(windows)]
mod windows;

use capabilities::Capability;
use fs::FileAccess;
use std::{fmt, io, sync::OnceLock};

#[cfg(unix)]
use unix::fs::NativeFileAccess;
#[cfg(windows)]
use windows::fs::NativeFileAccess;

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

#[derive(Debug)]
pub struct Platform {
    os: Os,
}

impl Platform {
    pub fn os(&self) -> Os {
        self.os
    }

    pub fn files(&self) -> &'static dyn FileAccess {
        &NativeFileAccess
    }
}

pub fn current() -> &'static Platform {
    static CURRENT: OnceLock<Platform> = OnceLock::new();
    CURRENT.get_or_init(|| Platform { os: Os::detect() })
}

/// Shorthand for `current().files()`.
pub fn files() -> &'static dyn FileAccess {
    current().files()
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
