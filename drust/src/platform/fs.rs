//! File modes, owners and private files. `plans/platform-spec.md` section 6.2.

use super::PlatformError;
use std::{
    fs::{File, Metadata},
    path::Path,
};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum FileMode {
    /// Only the owner: `0600`.
    Private,
    /// Only the owner, for a directory: `0700`.
    PrivateDir,
    /// The owner, and the web server reading through the group: `0750`.
    SiteDir,
    /// The same for a file: `0640`.
    SiteFile,
    /// Everyone reads: `0644`.
    Shared,
    /// Everyone reads and runs: `0755`.
    Executable,
    /// An exact mode, such as the file manager's chmod.
    Raw(u32),
}

impl FileMode {
    /// The Unix mode this stands for.
    pub fn bits(self) -> u32 {
        match self {
            Self::Private => 0o600,
            Self::PrivateDir => 0o700,
            Self::SiteDir => 0o750,
            Self::SiteFile => 0o640,
            Self::Shared => 0o644,
            Self::Executable => 0o755,
            Self::Raw(mode) => mode,
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct OwnerId {
    pub uid: u32,
    pub gid: u32,
}

impl OwnerId {
    /// Stands for the user everything runs as on a local profile, where site
    /// accounts are only folders. Never a real uid there, so never `chown`ed to.
    pub const LOGGED_IN_USER: Self = Self { uid: 0, gid: 0 };
}

/// What to do when the path being opened is a symlink.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LinkPolicy {
    Follow,
    Refuse,
}

pub trait FileAccess: Send + Sync {
    fn set_mode(&self, path: &Path, mode: FileMode) -> Result<(), PlatformError>;
    /// The raw mode bits, or `None` where the OS has no such thing. Takes
    /// metadata the caller already has, so a symlink it chose not to follow
    /// is not followed here.
    fn mode_bits(&self, metadata: &Metadata) -> Option<u32>;
    fn owner(&self, metadata: &Metadata) -> Result<OwnerId, PlatformError>;
    /// Follows a symlink, like `chown`.
    fn set_owner(&self, path: &Path, owner: &OwnerId) -> Result<(), PlatformError>;
    /// Changes a symlink itself, like `lchown`.
    fn set_link_owner(&self, path: &Path, owner: &OwnerId) -> Result<(), PlatformError>;
    /// A new owner-only file; fails if the path already exists.
    fn create_private(&self, path: &Path) -> Result<File, PlatformError>;
    /// Truncates an existing file, keeping its mode, or creates an owner-only one.
    fn replace_private(&self, path: &Path, links: LinkPolicy) -> Result<File, PlatformError>;
    /// Creates the directory and any missing parents, owner-only.
    fn create_dir_private(&self, path: &Path) -> Result<(), PlatformError>;
    fn symlink(&self, target: &Path, link: &Path) -> Result<(), PlatformError>;
    /// Removes a file or a symlink, never what the link points at. Windows
    /// removes a link to a folder as a folder, so `fs::remove_file` fails on it.
    fn remove_file(&self, path: &Path) -> Result<(), PlatformError>;
}

/// `0644`-style text for the panel; `None` where the OS has no modes.
pub fn mode_string(files: &dyn FileAccess, metadata: &Metadata) -> Option<String> {
    files
        .mode_bits(metadata)
        .map(|mode| format!("{:04o}", mode & 0o7777))
}
