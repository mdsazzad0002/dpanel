use std::{
    collections::HashSet,
    fs::{self, File},
    io::{self, Read},
    os::unix::fs::{MetadataExt, PermissionsExt, chown, lchown, symlink},
    path::{Component, Path, PathBuf},
    process::Command,
    sync::Arc,
};

use axum::{
    Router,
    extract::{Json, State},
    response::{IntoResponse, Response},
    routing::post,
};
use flate2::{Compression, read::GzDecoder, write::GzEncoder};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};

use crate::api::{ApiResponse, ApiState, check_token};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/file-sync", post(handle))
}

#[derive(Deserialize)]
struct Request {
    /// manifest | pack | apply
    action: String,
    site_owner: String,
    /// The website directory being synced.
    root: String,
    /// pack: the files to put in the archive (relative to root).
    #[serde(default)]
    paths: Vec<String>,
    /// pack: where to write the .tar.gz; apply: the .tar.gz to unpack.
    #[serde(default)]
    archive: String,
    /// apply: files to remove (relative to root).
    #[serde(default)]
    delete: Vec<String>,
    /// apply: where the files being replaced or removed are moved first,
    /// under /home/{owner}/.trash.
    #[serde(default)]
    backup_dir: String,
}

#[derive(Serialize, Debug, PartialEq)]
struct Entry {
    path: String,
    size: u64,
    sha256: String,
}

/// "Files only" mode of Push to Live: the pushing panel compares its
/// manifest with the live one and sends just the changed files.
/// - manifest: every file .gitignore doesn't exclude (a repository's own
///   rules when the directory is in one, otherwise the .gitignore files
///   alone), never anything inside .git, with its SHA-256;
/// - pack: those of `paths` that still exist into a .tar.gz;
/// - apply: unpack such an archive as the owner and remove `delete`, moving
///   every file it replaces or removes to `backup_dir` first.
async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result = tokio::task::spawn_blocking(move || run(&request))
        .await
        .unwrap_or_else(|error| Err(format!("File sync task failed: {error}")));
    match result {
        Ok((message, data)) => ApiResponse::ok_data(&message, data).into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn run(request: &Request) -> Result<(String, serde_json::Value), String> {
    let root = validate_root(&request.site_owner, &request.root)?;
    match request.action.as_str() {
        "manifest" => {
            if !root.is_dir() {
                // A new live site may not have its directory yet: nothing to compare.
                return Ok((
                    "Directory is empty.".into(),
                    serde_json::json!({"git": false, "files": []}),
                ));
            }
            let (git, files) = manifest(&root)?;
            Ok((
                format!("{} files listed.", files.len()),
                serde_json::json!({"git": git, "files": files}),
            ))
        }
        "pack" => {
            let archive = validate_archive(&request.archive)?;
            let packed = pack(&root, &request.paths, &archive)?;
            // Readable by the panel, which owns the directory it asked for.
            if let Ok(parent) = fs::metadata(archive.parent().unwrap_or(Path::new("/"))) {
                let _ = chown(&archive, Some(parent.uid()), Some(parent.gid()));
                let _ = fs::set_permissions(&archive, fs::Permissions::from_mode(0o640));
            }
            Ok((
                format!("{packed} files packed."),
                serde_json::json!({"files": packed}),
            ))
        }
        "apply" => {
            let archive = validate_archive(&request.archive)?;
            let backup = validate_backup_dir(&request.site_owner, &request.backup_dir)?;
            let owner = Owner::lookup(&request.site_owner)?;
            fs::create_dir_all(&root)
                .map_err(|error| format!("Unable to create {}: {error}", root.display()))?;
            owner.chown(&root);
            let (written, deleted) =
                apply(&root, &archive, &request.delete, &backup, Some(&owner))?;
            Ok((
                format!("{written} files written, {deleted} removed."),
                serde_json::json!({"written": written, "deleted": deleted, "backup_dir": backup}),
            ))
        }
        _ => Err("Unknown file sync action.".into()),
    }
}

fn validate_owner(site_owner: &str) -> Result<(), String> {
    let valid = !site_owner.is_empty()
        && site_owner.chars().enumerate().all(|(index, value)| {
            value.is_ascii_lowercase()
                || value.is_ascii_digit()
                || value == '_'
                || value == '-' && index > 0
        });
    if valid {
        Ok(())
    } else {
        Err("Invalid website owner.".into())
    }
}

fn has_parent_dir(path: &Path) -> bool {
    path.components()
        .any(|part| matches!(part, Component::ParentDir))
}

fn validate_root(site_owner: &str, root: &str) -> Result<PathBuf, String> {
    validate_owner(site_owner)?;
    let root = PathBuf::from(root.trim_end_matches('/'));
    if !root.starts_with(format!("/home/{site_owner}/")) || has_parent_dir(&root) {
        return Err("Website directory is outside the website owner's home.".into());
    }
    Ok(root)
}

fn validate_archive(archive: &str) -> Result<PathBuf, String> {
    let path = PathBuf::from(archive);
    if !path.is_absolute()
        || has_parent_dir(&path)
        || !archive.ends_with(".tar.gz")
        || path.starts_with("/home")
    {
        return Err("Invalid sync archive path.".into());
    }
    Ok(path)
}

fn validate_backup_dir(site_owner: &str, backup_dir: &str) -> Result<PathBuf, String> {
    let path = PathBuf::from(backup_dir.trim_end_matches('/'));
    if !path.starts_with(format!("/home/{site_owner}/.trash/")) || has_parent_dir(&path) {
        return Err("Backup directory must be inside the owner's .trash.".into());
    }
    Ok(path)
}

/// A path from the other panel: plain relative components only, and nothing
/// in or named .git.
fn safe_relative(path: &str) -> Option<PathBuf> {
    let relative = Path::new(path);
    if path.is_empty() || path.contains('\0') {
        return None;
    }
    for part in relative.components() {
        match part {
            Component::Normal(name) if name != ".git" => {}
            _ => return None,
        }
    }
    Some(relative.to_path_buf())
}

/// True when no directory between root and `relative`'s file is a symlink,
/// so writing or removing it can't reach outside root.
fn inside_root(root: &Path, relative: &Path) -> bool {
    let mut current = root.to_path_buf();
    let parts: Vec<_> = relative.components().collect();
    for part in &parts[..parts.len().saturating_sub(1)] {
        current.push(part);
        match fs::symlink_metadata(&current) {
            Ok(meta) if meta.file_type().is_symlink() || !meta.is_dir() => return false,
            _ => {}
        }
    }
    true
}

fn git(root: &Path) -> Command {
    let mut command = Command::new("git");
    // Run as root over the owner's checkout; discovery stops at the owner's
    // home so a repository elsewhere can't decide what is synced.
    command
        .args(["-c", "safe.directory=*", "-c", "core.quotePath=false"])
        .env("GIT_CEILING_DIRECTORIES", root.parent().unwrap_or(root))
        .env_remove("GIT_DIR")
        .env_remove("GIT_WORK_TREE");
    command
}

fn list_files(root: &Path) -> Result<(bool, Vec<String>), String> {
    let in_repository = git(root)
        .arg("-C")
        .arg(root)
        .args(["rev-parse", "--is-inside-work-tree"])
        .output()
        .map(|output| output.status.success())
        .unwrap_or(false);
    // Outside a repository, an empty one pointed at root still applies every
    // .gitignore in the tree (and the user's global excludes) to the listing.
    let scratch = tempfile::tempdir()
        .map_err(|error| format!("Unable to create a temporary directory: {error}"))?;
    let output = if in_repository {
        git(root)
            .arg("-C")
            .arg(root)
            .args([
                "ls-files",
                "-z",
                "--cached",
                "--others",
                "--exclude-standard",
            ])
            .output()
    } else {
        let bare = scratch.path().join("index.git");
        let init = git(root).args(["init", "-q", "--bare"]).arg(&bare).output();
        if !init.map(|output| output.status.success()).unwrap_or(false) {
            return Err("Unable to prepare git to read .gitignore rules.".into());
        }
        git(root)
            .arg("--git-dir")
            .arg(&bare)
            .arg("--work-tree")
            .arg(root)
            .arg("-C")
            .arg(root)
            .args(["ls-files", "-z", "--others", "--exclude-standard"])
            .output()
    }
    .map_err(|error| format!("Unable to run git: {error}"))?;
    if !output.status.success() {
        return Err(format!(
            "git ls-files failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }

    let mut seen = HashSet::new();
    let mut files: Vec<String> = String::from_utf8_lossy(&output.stdout)
        .split('\0')
        // A trailing slash is a nested repository, which syncs with its own Git.
        .filter(|path| !path.is_empty() && !path.ends_with('/'))
        .filter(|path| safe_relative(path).is_some() && !path.starts_with(".trash/"))
        .filter(|path| seen.insert(path.to_string()))
        .map(str::to_string)
        .collect();
    files.sort();
    Ok((in_repository, files))
}

fn hash_entry(path: &Path) -> io::Result<Option<(u64, String)>> {
    let meta = fs::symlink_metadata(path)?;
    let mut hasher = Sha256::new();
    if meta.file_type().is_symlink() {
        // A link is compared by where it points, never followed.
        let target = fs::read_link(path)?;
        hasher.update(b"link:");
        hasher.update(target.as_os_str().as_encoded_bytes());
    } else if meta.is_file() {
        let mut file = File::open(path)?;
        io::copy(&mut file, &mut hasher)?;
        if meta.permissions().mode() & 0o111 != 0 {
            hasher.update(b"\0exec");
        }
    } else {
        return Ok(None);
    }
    Ok(Some((meta.len(), format!("{:x}", hasher.finalize()))))
}

fn manifest(root: &Path) -> Result<(bool, Vec<Entry>), String> {
    let (git, paths) = list_files(root)?;
    let mut entries = Vec::with_capacity(paths.len());
    for path in paths {
        // A tracked file deleted from disk is still listed by --cached.
        if let Ok(Some((size, sha256))) = hash_entry(&root.join(&path)) {
            entries.push(Entry { path, size, sha256 });
        }
    }
    Ok((git, entries))
}

fn pack(root: &Path, paths: &[String], archive: &Path) -> Result<usize, String> {
    let file = File::create(archive)
        .map_err(|error| format!("Unable to create {}: {error}", archive.display()))?;
    let mut builder = tar::Builder::new(GzEncoder::new(file, Compression::default()));
    builder.follow_symlinks(false);
    let mut packed = 0;
    for path in paths {
        let Some(relative) = safe_relative(path) else {
            return Err(format!("Refusing to pack {path}."));
        };
        let source = root.join(&relative);
        let Ok(meta) = fs::symlink_metadata(&source) else {
            continue;
        };
        if !(meta.is_file() || meta.file_type().is_symlink()) || !inside_root(root, &relative) {
            continue;
        }
        builder
            .append_path_with_name(&source, &relative)
            .map_err(|error| format!("Unable to pack {path}: {error}"))?;
        packed += 1;
    }
    builder
        .into_inner()
        .and_then(|encoder| encoder.finish())
        .map_err(|error| format!("Unable to finish the archive: {error}"))?;
    Ok(packed)
}

struct Owner {
    uid: u32,
    gid: u32,
}

impl Owner {
    fn lookup(site_owner: &str) -> Result<Self, String> {
        let id = |flag: &str| -> Result<u32, String> {
            let output = Command::new("id")
                .args([flag, site_owner])
                .output()
                .map_err(|error| format!("Unable to run id: {error}"))?;
            String::from_utf8_lossy(&output.stdout)
                .trim()
                .parse()
                .map_err(|_| format!("Website owner {site_owner} does not exist."))
        };
        Ok(Self {
            uid: id("-u")?,
            gid: id("-g")?,
        })
    }

    fn chown(&self, path: &Path) {
        let _ = lchown(path, Some(self.uid), Some(self.gid));
    }
}

/// Moves what sits at `relative` (if anything) into the backup tree.
fn move_to_backup(
    root: &Path,
    backup: &Path,
    relative: &Path,
    owner: Option<&Owner>,
) -> Result<bool, String> {
    let current = root.join(relative);
    if fs::symlink_metadata(&current).is_err() {
        return Ok(false);
    }
    let destination = backup.join(relative);
    if let Some(parent) = destination.parent() {
        create_dirs(parent, owner)?;
    }
    if fs::rename(&current, &destination).is_err() {
        // Another filesystem: copy, then remove the original.
        fs::copy(&current, &destination)
            .and_then(|_| fs::remove_file(&current))
            .map_err(|error| format!("Unable to back up {}: {error}", relative.display()))?;
    }
    Ok(true)
}

/// create_dir_all, owning each directory it creates.
fn create_dirs(directory: &Path, owner: Option<&Owner>) -> Result<(), String> {
    let mut missing = Vec::new();
    let mut current = directory;
    while !current.exists() {
        missing.push(current.to_path_buf());
        match current.parent() {
            Some(parent) => current = parent,
            None => break,
        }
    }
    fs::create_dir_all(directory)
        .map_err(|error| format!("Unable to create {}: {error}", directory.display()))?;
    if let Some(owner) = owner {
        for path in &missing {
            owner.chown(path);
        }
    }
    Ok(())
}

fn apply(
    root: &Path,
    archive: &Path,
    delete: &[String],
    backup: &Path,
    owner: Option<&Owner>,
) -> Result<(usize, usize), String> {
    let file =
        File::open(archive).map_err(|error| format!("Unable to open the sync archive: {error}"))?;
    let mut entries = tar::Archive::new(GzDecoder::new(file));
    let mut written = 0;

    for entry in entries
        .entries()
        .map_err(|error| format!("Unreadable sync archive: {error}"))?
    {
        let mut entry = entry.map_err(|error| format!("Unreadable sync archive: {error}"))?;
        let name = entry
            .path()
            .map_err(|error| format!("Unreadable archive path: {error}"))?
            .to_string_lossy()
            .to_string();
        let relative =
            safe_relative(&name).ok_or_else(|| format!("Refusing archive entry {name}."))?;
        if !inside_root(root, &relative) {
            return Err(format!(
                "Refusing {name}: a parent directory on this server is a symlink or a file."
            ));
        }
        let destination = root.join(&relative);
        if let Some(parent) = destination.parent() {
            create_dirs(parent, owner)?;
        }
        let kind = entry.header().entry_type();
        if fs::symlink_metadata(&destination).is_ok_and(|meta| meta.is_dir()) {
            return Err(format!(
                "Refusing {name}: it is a directory on this server."
            ));
        }

        if kind.is_symlink() {
            let target = entry
                .link_name()
                .map_err(|error| format!("Unreadable link {name}: {error}"))?
                .ok_or_else(|| format!("Link {name} has no target."))?
                .into_owned();
            move_to_backup(root, backup, &relative, owner)?;
            symlink(&target, &destination)
                .map_err(|error| format!("Unable to create link {name}: {error}"))?;
        } else if kind.is_file() {
            let mode = entry.header().mode().unwrap_or(0o644) & 0o777;
            // Written next to the target and renamed in, so the site never
            // serves a half-written file.
            let partial = destination.with_file_name(format!(
                ".{}.dpanel-sync",
                destination
                    .file_name()
                    .unwrap_or_default()
                    .to_string_lossy()
            ));
            let mut contents = Vec::new();
            entry
                .read_to_end(&mut contents)
                .map_err(|error| format!("Unable to read {name}: {error}"))?;
            fs::write(&partial, contents)
                .map_err(|error| format!("Unable to write {name}: {error}"))?;
            let _ = fs::set_permissions(&partial, fs::Permissions::from_mode(mode | 0o600));
            if let Some(owner) = owner {
                let _ = chown(&partial, Some(owner.uid), Some(owner.gid));
            }
            move_to_backup(root, backup, &relative, owner)?;
            fs::rename(&partial, &destination)
                .map_err(|error| format!("Unable to place {name}: {error}"))?;
        } else {
            return Err(format!("Refusing {name}: only files and links are synced."));
        }
        if let Some(owner) = owner {
            owner.chown(&destination);
        }
        written += 1;
    }

    let mut deleted = 0;
    for path in delete {
        let relative = safe_relative(path).ok_or_else(|| format!("Refusing to delete {path}."))?;
        if !inside_root(root, &relative)
            || fs::symlink_metadata(root.join(&relative)).is_ok_and(|meta| meta.is_dir())
        {
            continue;
        }
        if move_to_backup(root, backup, &relative, owner)? {
            deleted += 1;
            remove_empty_parents(root, &root.join(&relative));
        }
    }
    Ok((written, deleted))
}

fn remove_empty_parents(root: &Path, file: &Path) {
    let mut current = file.parent();
    while let Some(directory) = current {
        if directory == root || !directory.starts_with(root) || fs::remove_dir(directory).is_err() {
            break;
        }
        current = directory.parent();
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn write(root: &Path, path: &str, contents: &str) {
        let path = root.join(path);
        fs::create_dir_all(path.parent().unwrap()).unwrap();
        fs::write(path, contents).unwrap();
    }

    fn paths(entries: &[Entry]) -> Vec<&str> {
        entries.iter().map(|entry| entry.path.as_str()).collect()
    }

    fn site() -> tempfile::TempDir {
        let dir = tempfile::tempdir().unwrap();
        let root = dir.path();
        write(root, ".gitignore", "vendor/\n*.log\n.env\n");
        write(root, "index.php", "<?php echo 1;");
        write(root, ".env", "SECRET=1");
        write(root, "debug.log", "x");
        write(root, "vendor/autoload.php", "v");
        write(root, "app/keep.log.txt", "k");
        write(root, "logs/.gitignore", "!important.log\n");
        write(root, "logs/important.log", "i");
        dir
    }

    #[test]
    fn manifest_follows_gitignore_without_a_repository() {
        let dir = site();
        let (git, entries) = manifest(dir.path()).unwrap();

        assert!(!git);
        assert_eq!(
            paths(&entries),
            [
                ".gitignore",
                "app/keep.log.txt",
                "index.php",
                "logs/.gitignore",
                "logs/important.log"
            ]
        );
    }

    #[test]
    fn manifest_uses_the_repository_and_skips_dot_git() {
        let dir = site();
        let status = Command::new("git")
            .args(["init", "-q"])
            .current_dir(dir.path())
            .status()
            .unwrap();
        assert!(status.success());
        let (git, entries) = manifest(dir.path()).unwrap();

        assert!(git);
        assert!(entries.iter().all(|entry| !entry.path.starts_with(".git/")));
        assert!(paths(&entries).contains(&"index.php"));
        assert!(!paths(&entries).contains(&".env"));
    }

    #[test]
    fn pack_and_apply_replace_changed_files_and_back_up_the_old_ones() {
        let local = site();
        write(local.path(), "index.php", "<?php echo 2;");
        write(local.path(), "app/new.php", "new");
        let live = tempfile::tempdir().unwrap();
        write(live.path(), "index.php", "<?php echo 1;");
        write(live.path(), "old/gone.php", "gone");
        write(live.path(), ".env", "LIVE_SECRET=1");
        let scratch = tempfile::tempdir().unwrap();
        let archive = scratch.path().join("sync.tar.gz");
        let backup = scratch.path().join("trash");

        assert_eq!(
            pack(
                local.path(),
                &[
                    "index.php".into(),
                    "app/new.php".into(),
                    "missing.php".into()
                ],
                &archive
            )
            .unwrap(),
            2
        );
        let (written, deleted) = apply(
            live.path(),
            &archive,
            &["old/gone.php".into()],
            &backup,
            None,
        )
        .unwrap();

        assert_eq!((written, deleted), (2, 1));
        assert_eq!(
            fs::read_to_string(live.path().join("index.php")).unwrap(),
            "<?php echo 2;"
        );
        assert_eq!(
            fs::read_to_string(live.path().join("app/new.php")).unwrap(),
            "new"
        );
        assert_eq!(
            fs::read_to_string(live.path().join(".env")).unwrap(),
            "LIVE_SECRET=1"
        );
        assert!(!live.path().join("old").exists());
        assert_eq!(
            fs::read_to_string(backup.join("index.php")).unwrap(),
            "<?php echo 1;"
        );
        assert_eq!(
            fs::read_to_string(backup.join("old/gone.php")).unwrap(),
            "gone"
        );
    }

    #[test]
    fn paths_into_dot_git_or_above_root_are_refused() {
        for path in [".git/config", "a/.git/HEAD", "../x", "/etc/passwd", ""] {
            assert!(safe_relative(path).is_none(), "{path}");
        }
        assert!(safe_relative(".gitignore").is_some());
        let live = tempfile::tempdir().unwrap();
        let scratch = tempfile::tempdir().unwrap();
        let archive = scratch.path().join("empty.tar.gz");
        pack(live.path(), &[], &archive).unwrap();
        assert!(
            apply(
                live.path(),
                &archive,
                &[".git/config".into()],
                &scratch.path().join("t"),
                None
            )
            .is_err()
        );
    }

    #[test]
    fn writes_through_a_symlinked_directory_are_refused() {
        let live = tempfile::tempdir().unwrap();
        let outside = tempfile::tempdir().unwrap();
        symlink(outside.path(), live.path().join("uploads")).unwrap();

        assert!(!inside_root(live.path(), Path::new("uploads/x.php")));
        assert!(inside_root(live.path(), Path::new("uploads")));
    }

    #[test]
    fn archive_and_backup_paths_are_validated() {
        assert!(validate_archive("/var/www/dpanel/storage/app/x.tar.gz").is_ok());
        assert!(validate_archive("/home/u/x.tar.gz").is_err());
        assert!(validate_archive("relative.tar.gz").is_err());
        assert!(validate_backup_dir("u", "/home/u/.trash/sync/_root/site").is_ok());
        assert!(validate_backup_dir("u", "/home/u/public_html").is_err());
        assert!(validate_root("u", "/home/u/../v/site").is_err());
    }
}
