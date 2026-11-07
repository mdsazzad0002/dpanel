use crate::platform::{
    PlatformError,
    capabilities::Capability,
    fs::{FileAccess, FileMode, LinkPolicy, OwnerId},
};
use std::{
    fs::{File, Metadata},
    path::Path,
};

/// Task F1 replaces this with ACLs. Until then every call that would decide who
/// can reach a file refuses, because a file created here would inherit its
/// folder's ACL and a secret in it would not actually be private.
pub struct NativeFileAccess;

fn unsupported<T>() -> Result<T, PlatformError> {
    Err(PlatformError::Unsupported {
        capability: Capability::FilePermissions,
    })
}

impl FileAccess for NativeFileAccess {
    fn set_mode(&self, _path: &Path, _mode: FileMode) -> Result<(), PlatformError> {
        unsupported()
    }

    fn mode_bits(&self, _metadata: &Metadata) -> Option<u32> {
        None
    }

    fn owner(&self, _metadata: &Metadata) -> Result<OwnerId, PlatformError> {
        unsupported()
    }

    fn set_owner(&self, _path: &Path, _owner: &OwnerId) -> Result<(), PlatformError> {
        unsupported()
    }

    fn set_link_owner(&self, _path: &Path, _owner: &OwnerId) -> Result<(), PlatformError> {
        unsupported()
    }

    fn create_private(&self, _path: &Path) -> Result<File, PlatformError> {
        unsupported()
    }

    fn replace_private(&self, _path: &Path, _links: LinkPolicy) -> Result<File, PlatformError> {
        unsupported()
    }

    fn create_dir_private(&self, _path: &Path) -> Result<(), PlatformError> {
        unsupported()
    }

    fn symlink(&self, _target: &Path, _link: &Path) -> Result<(), PlatformError> {
        unsupported()
    }
}
