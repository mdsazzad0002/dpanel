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

/// `chmod -R`: the folder and everything under it, without following a
/// symlink inside it, which could point anywhere on the system.
pub fn set_mode_tree(files: &dyn FileAccess, root: &Path, mode: FileMode) -> Result<(), PlatformError> {
    files.set_mode(root, mode)?;
    let entries = std::fs::read_dir(root).map_err(|error| PlatformError::from_io("read directory", error))?;
    for entry in entries {
        let entry = entry.map_err(|error| PlatformError::from_io("read directory", error))?;
        let kind = entry.file_type().map_err(|error| PlatformError::from_io("read file type", error))?;
        if kind.is_symlink() {
            continue;
        }
        if kind.is_dir() {
            set_mode_tree(files, &entry.path(), mode)?;
        } else {
            files.set_mode(&entry.path(), mode)?;
        }
    }
    Ok(())
}

/// A `chmod` mode, applied the way GNU `chmod` applies it.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum ModeChange {
    /// A number such as `chmod 0750`. A folder keeps its set-user-ID and
    /// set-group-ID bits unless the number sets them; a file gets the number.
    Exact(u32),
    /// A symbolic mode such as `u+rwX,g+rX,g-w,o-rwx`: `add`, then `add_exec`
    /// (the `X`, given only to a folder or a file someone can already run),
    /// then `remove`.
    Adjust { add: u32, add_exec: u32, remove: u32 },
}

impl ModeChange {
    /// The mode `old` becomes.
    pub fn apply(self, old: u32, is_dir: bool) -> u32 {
        let old = old & 0o7777;
        match self {
            Self::Exact(bits) if is_dir => bits | (old & 0o6000),
            Self::Exact(bits) => bits,
            Self::Adjust { add, add_exec, remove } => {
                let mut mode = old | add;
                if is_dir || mode & 0o111 != 0 {
                    mode |= add_exec;
                }
                mode & !remove
            }
        }
    }
}

/// `chmod`: follows a symlink, like the command.
pub fn change_mode(files: &dyn FileAccess, path: &Path, change: ModeChange) -> Result<(), PlatformError> {
    let metadata = std::fs::metadata(path).map_err(|error| PlatformError::from_io("read metadata", error))?;
    apply_mode(files, path, &metadata, change)
}

fn apply_mode(files: &dyn FileAccess, path: &Path, metadata: &Metadata, change: ModeChange) -> Result<(), PlatformError> {
    let old = files.mode_bits(metadata).unwrap_or(0);
    files.set_mode(path, FileMode::Raw(change.apply(old, metadata.is_dir())))
}

/// `chmod -R`: follows `root` if it is a symlink, never one inside it.
/// Like the command, goes on past a failure and returns the first.
pub fn change_mode_tree(files: &dyn FileAccess, root: &Path, change: ModeChange) -> Result<(), PlatformError> {
    walk(root, true, &mut |path, metadata| {
        if metadata.file_type().is_symlink() {
            return Ok(());
        }
        apply_mode(files, path, metadata, change)
    })
}

/// `chown -R`: changes every entry, a symlink itself rather than what it
/// points at (`root` too), and never goes through one.
pub fn set_owner_tree(files: &dyn FileAccess, root: &Path, owner: &OwnerId) -> Result<(), PlatformError> {
    walk(root, false, &mut |path, _| files.set_link_owner(path, owner))
}

/// `chgrp -R`: as `set_owner_tree`, keeping each entry's user.
pub fn set_group_tree(files: &dyn FileAccess, root: &Path, gid: u32) -> Result<(), PlatformError> {
    walk(root, false, &mut |path, metadata| {
        let uid = files.owner(metadata)?.uid;
        files.set_link_owner(path, &OwnerId { uid, gid })
    })
}

/// For `map_err`: the error as text, after the path it happened at.
pub fn error_at(path: &Path) -> impl FnOnce(PlatformError) -> String + '_ {
    move |error| format!("{}: {error}", path.display())
}

/// The ids `chown user:group` would give a file.
pub fn owner_named(user: &str, group: &str) -> Result<OwnerId, PlatformError> {
    let accounts = super::accounts();
    Ok(OwnerId {
        uid: accounts.lookup(user)?.uid,
        gid: accounts.group_id(group)?,
    })
}

/// Visits `root` and everything under it, parents first, never going
/// through a symlink below `root`. Visits all it can, returns the first error.
fn walk(
    root: &Path,
    follow_root: bool,
    visit: &mut dyn FnMut(&Path, &Metadata) -> Result<(), PlatformError>,
) -> Result<(), PlatformError> {
    let metadata = if follow_root { std::fs::metadata(root) } else { std::fs::symlink_metadata(root) }
        .map_err(|error| PlatformError::from_io("read metadata", error))?;
    let mut first = None;
    walk_entry(root, &metadata, visit, &mut first);
    first.map_or(Ok(()), Err)
}

fn walk_entry(
    path: &Path,
    metadata: &Metadata,
    visit: &mut dyn FnMut(&Path, &Metadata) -> Result<(), PlatformError>,
    first: &mut Option<PlatformError>,
) {
    if let Err(error) = visit(path, metadata) {
        first.get_or_insert(error);
    }
    if !metadata.is_dir() {
        return;
    }
    let entries = match std::fs::read_dir(path) {
        Ok(entries) => entries,
        Err(error) => {
            first.get_or_insert(PlatformError::from_io("read directory", error));
            return;
        }
    };
    for entry in entries {
        let child = entry.and_then(|entry| {
            let path = entry.path();
            std::fs::symlink_metadata(&path).map(|metadata| (path, metadata))
        });
        match child {
            Ok((child, metadata)) => walk_entry(&child, &metadata, visit, first),
            Err(error) => {
                first.get_or_insert(PlatformError::from_io("read directory", error));
            }
        }
    }
}

/// `0644`-style text for the panel; `None` where the OS has no modes.
pub fn mode_string(files: &dyn FileAccess, metadata: &Metadata) -> Option<String> {
    files
        .mode_bits(metadata)
        .map(|mode| format!("{:04o}", mode & 0o7777))
}

/// Whether the OS refused because another program has the file open:
/// Windows' sharing and lock violations. Unix lets a file go while it is
/// open, so there it never is.
pub fn held_by_another_program(error: &std::io::Error) -> bool {
    #[cfg(windows)]
    {
        // ERROR_SHARING_VIOLATION, ERROR_LOCK_VIOLATION
        matches!(error.raw_os_error(), Some(32 | 33))
    }
    #[cfg(unix)]
    {
        let _ = error;
        false
    }
}

/// An OS error as the panel shows it. Windows' own words for a held file
/// ("The process cannot access the file…") do not say what to do.
pub fn describe(error: &std::io::Error) -> String {
    if held_by_another_program(error) {
        format!("it is open in another program; close it there and try again ({error})")
    } else {
        error.to_string()
    }
}

/// Test aid: opens `path` so nobody else may read, write or delete it while
/// the handle lives, as an editor or a running program holds a file on
/// Windows. `None` where the OS has no such lock (Unix).
#[cfg(test)]
pub fn hold_exclusively(path: &Path) -> Option<std::fs::File> {
    #[cfg(windows)]
    {
        use std::os::windows::fs::OpenOptionsExt;
        Some(std::fs::OpenOptions::new().read(true).share_mode(0).open(path).unwrap())
    }
    #[cfg(unix)]
    {
        let _ = path;
        None
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{path::PathBuf, sync::Mutex};

    #[derive(Default)]
    struct Recorder(Mutex<Vec<(PathBuf, FileMode)>>);

    impl FileAccess for Recorder {
        fn set_mode(&self, path: &Path, mode: FileMode) -> Result<(), PlatformError> {
            self.0.lock().unwrap().push((path.to_path_buf(), mode));
            Ok(())
        }
        fn mode_bits(&self, _: &Metadata) -> Option<u32> { None }
        fn owner(&self, _: &Metadata) -> Result<OwnerId, PlatformError> { unimplemented!() }
        fn set_owner(&self, _: &Path, _: &OwnerId) -> Result<(), PlatformError> { unimplemented!() }
        fn set_link_owner(&self, _: &Path, _: &OwnerId) -> Result<(), PlatformError> { unimplemented!() }
        fn create_private(&self, _: &Path) -> Result<File, PlatformError> { unimplemented!() }
        fn replace_private(&self, _: &Path, _: LinkPolicy) -> Result<File, PlatformError> { unimplemented!() }
        fn create_dir_private(&self, _: &Path) -> Result<(), PlatformError> { unimplemented!() }
        fn symlink(&self, _: &Path, _: &Path) -> Result<(), PlatformError> { unimplemented!() }
        fn remove_file(&self, _: &Path) -> Result<(), PlatformError> { unimplemented!() }
    }

    #[test]
    fn a_tree_gets_the_mode_everywhere_but_through_a_link() {
        let root = std::env::temp_dir().join(format!("dpanel-mode-tree-{}", std::process::id()));
        let outside = root.with_extension("outside");
        std::fs::create_dir_all(root.join("a/b")).unwrap();
        std::fs::create_dir_all(&outside).unwrap();
        std::fs::write(root.join("a/b/file"), "x").unwrap();
        std::fs::write(outside.join("secret"), "x").unwrap();
        let linked = super::super::files().symlink(&outside, &root.join("link")).is_ok();

        let recorder = Recorder::default();
        set_mode_tree(&recorder, &root, FileMode::Raw(0o777)).unwrap();
        let mut seen: Vec<PathBuf> = recorder.0.into_inner().unwrap().into_iter().map(|(path, _)| path).collect();
        seen.sort();
        let _ = std::fs::remove_dir_all(&root);
        let _ = std::fs::remove_dir_all(&outside);

        let mut expected = vec![root.clone(), root.join("a"), root.join("a/b"), root.join("a/b/file")];
        expected.sort();
        assert_eq!(seen, expected, "symlink made: {linked}");
    }

    #[test]
    fn a_number_is_exact_for_a_file_but_a_folder_keeps_its_id_bits() {
        assert_eq!(ModeChange::Exact(0o640).apply(0o4755, false), 0o640);
        assert_eq!(ModeChange::Exact(0o750).apply(0o2775, true), 0o2750);
        assert_eq!(ModeChange::Exact(0o711).apply(0o40755, true), 0o711);
        assert_eq!(ModeChange::Exact(0o2775).apply(0o755, true), 0o2775);
        assert_eq!(ModeChange::Exact(0o775).apply(0o1755, true), 0o775);
    }

    #[test]
    fn a_symbolic_mode_gives_x_only_to_folders_and_runnable_files() {
        // u+rwX,g+rX,g-w,o-rwx
        let site = ModeChange::Adjust { add: 0o640, add_exec: 0o110, remove: 0o027 };
        assert_eq!(site.apply(0o666, false), 0o640);
        assert_eq!(site.apply(0o755, false), 0o750);
        assert_eq!(site.apply(0o001, false), 0o750);
        assert_eq!(site.apply(0o000, true), 0o750);
        assert_eq!(site.apply(0o4777, false), 0o4750);
        // u+rwX,g+rwX,o-rwx
        let writable = ModeChange::Adjust { add: 0o660, add_exec: 0o110, remove: 0o007 };
        assert_eq!(writable.apply(0o644, false), 0o660);
        assert_eq!(writable.apply(0o2755, true), 0o2770);
        // u+rwX,go-rwx
        let mail = ModeChange::Adjust { add: 0o600, add_exec: 0o100, remove: 0o077 };
        assert_eq!(mail.apply(0o644, false), 0o600);
        assert_eq!(mail.apply(0o755, true), 0o700);
    }

    /// Records owner changes; every file is owned by uid 7.
    #[derive(Default)]
    struct Owners(Mutex<Vec<(PathBuf, OwnerId)>>);

    impl FileAccess for Owners {
        fn set_mode(&self, _: &Path, _: FileMode) -> Result<(), PlatformError> { unimplemented!() }
        fn mode_bits(&self, _: &Metadata) -> Option<u32> { None }
        fn owner(&self, _: &Metadata) -> Result<OwnerId, PlatformError> { Ok(OwnerId { uid: 7, gid: 7 }) }
        fn set_owner(&self, _: &Path, _: &OwnerId) -> Result<(), PlatformError> { unimplemented!() }
        fn set_link_owner(&self, path: &Path, owner: &OwnerId) -> Result<(), PlatformError> {
            self.0.lock().unwrap().push((path.to_path_buf(), *owner));
            Ok(())
        }
        fn create_private(&self, _: &Path) -> Result<File, PlatformError> { unimplemented!() }
        fn replace_private(&self, _: &Path, _: LinkPolicy) -> Result<File, PlatformError> { unimplemented!() }
        fn create_dir_private(&self, _: &Path) -> Result<(), PlatformError> { unimplemented!() }
        fn symlink(&self, _: &Path, _: &Path) -> Result<(), PlatformError> { unimplemented!() }
        fn remove_file(&self, _: &Path) -> Result<(), PlatformError> { unimplemented!() }
    }

    fn tree_with_link(name: &str) -> (PathBuf, PathBuf, bool) {
        let root = std::env::temp_dir().join(format!("dpanel-{name}-{}", std::process::id()));
        let outside = root.with_extension("outside");
        std::fs::create_dir_all(root.join("a")).unwrap();
        std::fs::create_dir_all(&outside).unwrap();
        std::fs::write(root.join("a/file"), "x").unwrap();
        std::fs::write(outside.join("secret"), "x").unwrap();
        let linked = super::super::files().symlink(&outside, &root.join("link")).is_ok();
        (root, outside, linked)
    }

    #[test]
    fn an_owner_tree_changes_a_link_itself_and_never_goes_through_it() {
        let (root, outside, linked) = tree_with_link("owner-tree");
        let owners = Owners::default();
        set_owner_tree(&owners, &root, &OwnerId { uid: 1, gid: 2 }).unwrap();
        set_group_tree(&owners, &root.join("a"), 33).unwrap();
        let seen = owners.0.into_inner().unwrap();
        let _ = std::fs::remove_dir_all(&root);
        let _ = std::fs::remove_dir_all(&outside);

        let mut owned: Vec<PathBuf> = seen.iter().filter(|(_, o)| o.uid == 1).map(|(p, _)| p.clone()).collect();
        owned.sort();
        let mut expected = vec![root.clone(), root.join("a"), root.join("a/file")];
        if linked {
            expected.push(root.join("link"));
        }
        expected.sort();
        assert_eq!(owned, expected);
        assert!(seen.iter().all(|(_, o)| o.uid != 1 || o.gid == 2));

        let mut grouped: Vec<(PathBuf, OwnerId)> = seen.into_iter().filter(|(_, o)| o.uid == 7).collect();
        grouped.sort_by(|a, b| a.0.cmp(&b.0));
        let kept = OwnerId { uid: 7, gid: 33 };
        assert_eq!(grouped, vec![(root.join("a"), kept), (root.join("a/file"), kept)]);
    }

    #[test]
    fn a_mode_tree_works_from_each_entrys_mode_and_skips_links() {
        let (root, outside, linked) = tree_with_link("change-mode-tree");
        let recorder = Recorder::default();
        let change = ModeChange::Adjust { add: 0o600, add_exec: 0o100, remove: 0o077 };
        change_mode_tree(&recorder, &root, change).unwrap();
        let mut seen = recorder.0.into_inner().unwrap();
        seen.sort_by(|a, b| a.0.cmp(&b.0));
        let _ = std::fs::remove_dir_all(&root);
        let _ = std::fs::remove_dir_all(&outside);

        // The recorder reports no mode bits, so each starts from 0.
        assert_eq!(
            seen,
            vec![
                (root.clone(), FileMode::Raw(0o700)),
                (root.join("a"), FileMode::Raw(0o700)),
                (root.join("a/file"), FileMode::Raw(0o600)),
            ],
            "symlink made: {linked}"
        );
    }
}
