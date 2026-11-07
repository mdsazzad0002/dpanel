use crate::platform::{
    PlatformError,
    fs::{FileAccess, FileMode, LinkPolicy, OwnerId},
};
use std::{
    fs::{self, DirBuilder, File, Metadata, OpenOptions, Permissions},
    io,
    os::unix::fs::{
        DirBuilderExt, MetadataExt, OpenOptionsExt, PermissionsExt, chown, lchown, symlink,
    },
    path::Path,
};

pub struct NativeFileAccess;

fn failed(action: &str) -> impl Fn(io::Error) -> PlatformError {
    move |error| PlatformError::from_io(action, error)
}

impl FileAccess for NativeFileAccess {
    fn set_mode(&self, path: &Path, mode: FileMode) -> Result<(), PlatformError> {
        fs::set_permissions(path, Permissions::from_mode(mode.bits())).map_err(failed("set mode"))
    }

    fn mode_bits(&self, metadata: &Metadata) -> Option<u32> {
        Some(metadata.permissions().mode())
    }

    fn owner(&self, metadata: &Metadata) -> Result<OwnerId, PlatformError> {
        Ok(OwnerId {
            uid: metadata.uid(),
            gid: metadata.gid(),
        })
    }

    fn set_owner(&self, path: &Path, owner: &OwnerId) -> Result<(), PlatformError> {
        chown(path, Some(owner.uid), Some(owner.gid)).map_err(failed("set owner"))
    }

    fn set_link_owner(&self, path: &Path, owner: &OwnerId) -> Result<(), PlatformError> {
        lchown(path, Some(owner.uid), Some(owner.gid)).map_err(failed("set link owner"))
    }

    fn create_private(&self, path: &Path) -> Result<File, PlatformError> {
        OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(path)
            .map_err(failed("create private file"))
    }

    fn replace_private(&self, path: &Path, links: LinkPolicy) -> Result<File, PlatformError> {
        let mut options = OpenOptions::new();
        options.write(true).create(true).truncate(true).mode(0o600);
        if links == LinkPolicy::Refuse {
            options.custom_flags(libc::O_NOFOLLOW);
        }
        options.open(path).map_err(failed("replace private file"))
    }

    fn create_dir_private(&self, path: &Path) -> Result<(), PlatformError> {
        DirBuilder::new()
            .recursive(true)
            .mode(0o700)
            .create(path)
            .map_err(failed("create private directory"))
    }

    fn symlink(&self, target: &Path, link: &Path) -> Result<(), PlatformError> {
        symlink(target, link).map_err(failed("create symlink"))
    }

    fn remove_file(&self, path: &Path) -> Result<(), PlatformError> {
        fs::remove_file(path).map_err(failed("remove file"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::platform::{files, fs::mode_string};

    fn mode_of(path: &Path) -> u32 {
        fs::symlink_metadata(path).unwrap().permissions().mode() & 0o7777
    }

    #[test]
    fn every_file_mode_sets_the_expected_bits() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("file");
        fs::write(&path, "x").unwrap();
        for (mode, expected) in [
            (FileMode::Private, 0o600),
            (FileMode::PrivateDir, 0o700),
            (FileMode::SiteDir, 0o750),
            (FileMode::SiteFile, 0o640),
            (FileMode::Shared, 0o644),
            (FileMode::Executable, 0o755),
            (FileMode::Raw(0o640), 0o640),
            (FileMode::Raw(0o2775), 0o2775),
        ] {
            files().set_mode(&path, mode).unwrap();
            assert_eq!(mode_of(&path), expected, "{mode:?}");
        }
    }

    #[test]
    fn mode_string_matches_what_the_panel_shows() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("file");
        fs::write(&path, "x").unwrap();
        files().set_mode(&path, FileMode::Raw(0o640)).unwrap();
        let metadata = fs::metadata(&path).unwrap();
        assert_eq!(mode_string(files(), &metadata).as_deref(), Some("0640"));
    }

    #[test]
    fn mode_bits_reads_the_link_when_given_its_metadata() {
        let dir = tempfile::tempdir().unwrap();
        let target = dir.path().join("target");
        let link = dir.path().join("link");
        fs::write(&target, "x").unwrap();
        files().set_mode(&target, FileMode::Private).unwrap();
        files().symlink(&target, &link).unwrap();
        let metadata = fs::symlink_metadata(&link).unwrap();
        // The link's own mode differs by OS, but it is never the target's.
        assert_ne!(files().mode_bits(&metadata).unwrap() & 0o777, 0o600);
    }

    #[test]
    fn create_private_is_owner_only_and_never_overwrites() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("secret");
        files().create_private(&path).unwrap();
        assert_eq!(mode_of(&path), 0o600);
        assert!(matches!(
            files().create_private(&path),
            Err(PlatformError::Conflict { .. })
        ));
    }

    #[test]
    fn replace_private_truncates_and_keeps_an_existing_mode() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("env");
        files().replace_private(&path, LinkPolicy::Follow).unwrap();
        assert_eq!(mode_of(&path), 0o600);

        fs::write(&path, "old").unwrap();
        files().set_mode(&path, FileMode::Shared).unwrap();
        files().replace_private(&path, LinkPolicy::Follow).unwrap();
        assert_eq!(fs::read(&path).unwrap(), b"");
        assert_eq!(mode_of(&path), 0o644);
    }

    #[test]
    fn replace_private_can_refuse_a_symlink() {
        let dir = tempfile::tempdir().unwrap();
        let target = dir.path().join("target");
        let link = dir.path().join("link");
        fs::write(&target, "keep").unwrap();
        files().symlink(&target, &link).unwrap();

        assert!(files().replace_private(&link, LinkPolicy::Refuse).is_err());
        assert_eq!(fs::read(&target).unwrap(), b"keep");

        files().replace_private(&link, LinkPolicy::Follow).unwrap();
        assert_eq!(fs::read(&target).unwrap(), b"");
    }

    #[test]
    fn create_dir_private_makes_parents_and_tolerates_an_existing_directory() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("a/b");
        files().create_dir_private(&path).unwrap();
        files().create_dir_private(&path).unwrap();
        assert_eq!(mode_of(&path), 0o700);
    }

    #[test]
    fn owner_round_trips_for_a_file_and_a_link() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("file");
        let link = dir.path().join("link");
        fs::write(&path, "x").unwrap();
        files().symlink(&path, &link).unwrap();
        let owner = files().owner(&fs::metadata(&path).unwrap()).unwrap();
        files().set_owner(&path, &owner).unwrap();
        files().set_link_owner(&link, &owner).unwrap();
        assert_eq!(files().owner(&fs::symlink_metadata(&link).unwrap()).unwrap(), owner);
    }

    #[test]
    fn a_missing_path_is_not_found_with_the_os_text() {
        let dir = tempfile::tempdir().unwrap();
        let error = files()
            .set_mode(&dir.path().join("missing"), FileMode::Private)
            .unwrap_err();
        assert!(matches!(error, PlatformError::NotFound { .. }));
        assert_eq!(error.to_string(), "No such file or directory (os error 2)");
    }
}
