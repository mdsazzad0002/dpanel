//! Linux `DiskQuota`: spec section 6.7. Usage is counted by walking the sites
//! root, so it works on any filesystem. A limit is set with `setquota`, which
//! the filesystem enforces only when user quotas are on (ext4 or XFS mounted
//! with `usrquota`); without them the limit is refused and the panel's
//! `report` mode is all there is.

use std::{
    collections::HashSet,
    fs,
    os::unix::fs::MetadataExt,
    path::{Path, PathBuf},
    process::Command,
};

use crate::platform::{
    PlatformError,
    capabilities::Capability,
    fs::OwnerId,
    limits::{DiskQuota, LimitLevel},
    paths::Tool,
};

#[derive(Debug)]
pub struct LinuxQuota {
    pub sites_root: PathBuf,
}

impl DiskQuota for LinuxQuota {
    fn level(&self) -> LimitLevel {
        if crate::platform::paths().binary(Tool::Setquota).is_ok() {
            LimitLevel::Hard
        } else {
            LimitLevel::Soft
        }
    }

    fn set(&self, owner: &OwnerId, limit_mb: u64) -> Result<(), PlatformError> {
        let kb = limit_mb.saturating_mul(1024);
        setquota(owner, kb)
    }

    fn usage_mb(&self, owner: &OwnerId) -> Result<u64, PlatformError> {
        Ok(bytes_owned_by(&self.sites_root, owner.uid).div_ceil(1024 * 1024))
    }

    fn clear(&self, owner: &OwnerId) -> Result<(), PlatformError> {
        setquota(owner, 0)
    }
}

/// `0` means no limit to `setquota`. The soft and hard block limits are the
/// same, so there is no grace period to write past the limit in.
fn setquota(owner: &OwnerId, kb: u64) -> Result<(), PlatformError> {
    let program = crate::platform::paths()
        .binary(Tool::Setquota)
        .map_err(|_| PlatformError::Unsupported { capability: Capability::DiskQuota })?;
    let output = Command::new(&program)
        .args(["-u", &owner.uid.to_string(), &kb.to_string(), &kb.to_string(), "0", "0", "-a"])
        .output()
        .map_err(|error| PlatformError::from_io("set disk quota", error))?;
    if output.status.success() {
        return Ok(());
    }
    let detail = String::from_utf8_lossy(&output.stderr).trim().to_string();
    Err(PlatformError::Failed {
        action: "set disk quota".into(),
        detail: if detail.is_empty() { "setquota exited unsuccessfully".into() } else { detail },
    })
}

/// Space on disk (allocated blocks, as a quota counts it) of every file and
/// folder under `root` that `uid` owns. A hard link is counted once and
/// symlinks are not followed. Folders that cannot be read are skipped.
pub fn bytes_owned_by(root: &Path, uid: u32) -> u64 {
    let mut seen = HashSet::new();
    let mut total = 0u64;
    let mut pending = vec![root.to_path_buf()];
    while let Some(dir) = pending.pop() {
        let Ok(entries) = fs::read_dir(&dir) else { continue };
        for entry in entries.flatten() {
            let Ok(meta) = entry.path().symlink_metadata() else { continue };
            if meta.is_dir() {
                pending.push(entry.path());
            }
            if meta.uid() != uid || (meta.nlink() > 1 && !meta.is_dir() && !seen.insert((meta.dev(), meta.ino()))) {
                continue;
            }
            total = total.saturating_add(meta.blocks().saturating_mul(512));
        }
    }
    total
}

#[cfg(test)]
mod tests {
    use super::*;

    fn current_uid(dir: &Path) -> u32 {
        dir.metadata().unwrap().uid()
    }

    #[test]
    fn counts_the_owners_files_once_each_and_never_follows_a_symlink() {
        let root = tempfile::tempdir().unwrap();
        let site = root.path().join("shop/public_html");
        fs::create_dir_all(&site).unwrap();
        fs::write(site.join("a.bin"), vec![7u8; 256 * 1024]).unwrap();
        fs::hard_link(site.join("a.bin"), site.join("b.bin")).unwrap();
        let outside = tempfile::tempdir().unwrap();
        fs::write(outside.path().join("big.bin"), vec![1u8; 1024 * 1024]).unwrap();
        std::os::unix::fs::symlink(outside.path(), site.join("link")).unwrap();
        let uid = current_uid(root.path());

        let bytes = bytes_owned_by(root.path(), uid);

        assert!(bytes >= 256 * 1024, "{bytes}");
        assert!(bytes < 512 * 1024, "the hard link or the symlink target was counted: {bytes}");
    }

    #[test]
    fn another_owners_files_do_not_count() {
        let root = tempfile::tempdir().unwrap();
        fs::write(root.path().join("a.bin"), vec![7u8; 64 * 1024]).unwrap();
        let other = current_uid(root.path()).wrapping_add(1);

        assert_eq!(bytes_owned_by(root.path(), other), 0);
    }

    #[test]
    fn usage_rounds_up_to_whole_megabytes() {
        let root = tempfile::tempdir().unwrap();
        fs::write(root.path().join("a.bin"), vec![7u8; 10 * 1024]).unwrap();
        let quota = LinuxQuota { sites_root: root.path().to_path_buf() };
        let owner = OwnerId { uid: current_uid(root.path()), gid: 0 };

        assert_eq!(quota.usage_mb(&owner).unwrap(), 1);
    }
}
