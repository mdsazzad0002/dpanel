use std::collections::HashSet;
use std::fs;
use std::io::{self, Read, Write};
use std::os::unix::fs::OpenOptionsExt;
use std::path::{Component, Path, PathBuf};
use std::sync::Arc;

use crate::api::{ApiResponse, ApiState, check_token, operation_response};
use crate::app::info;
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use super::common::{
    ensure_canonical_inside_home, ensure_directory_inside_home, validate_account,
    validate_user_path,
};
use crate::app::run_status;

const DEFAULT_MAX_ENTRIES: usize = 100_000;
const DEFAULT_MAX_EXPANDED_BYTES: u64 = 20 * 1024 * 1024 * 1024;

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    destination: Option<String>,
}

pub fn unzip_user_archive(
    username: &str,
    archive: &str,
    destination: Option<&str>,
) -> Result<(), String> {
    let (user_home, canonical_home, group) = validate_account(username)?;
    let archive_path = validate_user_path(username, archive)?;
    if !archive_path.is_file() {
        return Err(format!("Archive not found: {}", archive_path.display()));
    }
    let archive_kind = archive_kind(&archive_path)
        .ok_or_else(|| "Supported archives are .zip, .tar, .tar.gz, .tgz, and .gz.".to_string())?;
    if archive_kind == ArchiveKind::Gzip && archive_path.file_name().is_none() {
        return Err("The gzip archive filename is invalid.".into());
    }

    let canonical_archive =
        ensure_canonical_inside_home(&canonical_home, &archive_path, "Archive")?;
    let canonical_root = match destination.map(str::trim).filter(|value| !value.is_empty()) {
        Some(destination) => {
            let destination_path = validate_user_path(username, destination)?;
            ensure_directory_inside_home(
                username,
                &group,
                &user_home,
                &canonical_home,
                &destination_path,
                "Extract folder",
            )?
        }
        None => {
            let extract_root = archive_path
                .parent()
                .ok_or_else(|| "Archive parent is missing.".to_string())?;
            ensure_canonical_inside_home(&canonical_home, extract_root, "Extract folder")?
        }
    };
    if !canonical_root.is_dir() {
        return Err("Extract target is not a folder.".into());
    }

    let max_entries = env_usize("DRUST_MAX_ZIP_ENTRIES", DEFAULT_MAX_ENTRIES);
    let max_expanded_bytes = env_u64("DRUST_MAX_ZIP_EXPANDED_BYTES", DEFAULT_MAX_EXPANDED_BYTES);
    if archive_kind == ArchiveKind::Tar || archive_kind == ArchiveKind::TarGzip {
        let file = fs::File::open(&canonical_archive)
            .map_err(|e| format!("failed to open tar archive: {e}"))?;
        if archive_kind == ArchiveKind::TarGzip {
            let decoder = flate2::read::MultiGzDecoder::new(file);
            extract_tar(
                username,
                &group,
                decoder,
                &canonical_archive,
                &canonical_root,
                max_entries,
                max_expanded_bytes,
            )?;
        } else {
            extract_tar(
                username,
                &group,
                file,
                &canonical_archive,
                &canonical_root,
                max_entries,
                max_expanded_bytes,
            )?;
        }
        fix_laravel_writable_dirs(&canonical_root.to_string_lossy())?;
        info(&format!(
            "tar archive extracted: {} -> {}",
            archive_path.display(),
            canonical_root.display()
        ));
        return Ok(());
    }
    if archive_kind == ArchiveKind::Gzip {
        extract_gzip(
            username,
            &group,
            &canonical_archive,
            &canonical_root,
            max_expanded_bytes,
        )?;
        fix_laravel_writable_dirs(&canonical_root.to_string_lossy())?;
        info(&format!(
            "gzip archive extracted: {} -> {}",
            archive_path.display(),
            canonical_root.display()
        ));
        return Ok(());
    }

    let source = fs::File::open(&canonical_archive)
        .map_err(|e| format!("failed to open ZIP archive: {e}"))?;
    let mut zip = zip::ZipArchive::new(source)
        .map_err(|e| format!("invalid or unsupported zip archive: {e}"))?;

    validate_archive(&mut zip, max_entries, max_expanded_bytes)?;

    let mut expanded_bytes = 0_u64;
    let mut touched_dirs: HashSet<PathBuf> = HashSet::new();
    let mut touched_files: Vec<PathBuf> = Vec::new();
    for index in 0..zip.len() {
        let mut entry = zip
            .by_index(index)
            .map_err(|e| format!("failed to read zip entry #{index}: {e}"))?;
        let relative = safe_entry_path(&entry, index)?;
        if relative.as_os_str().is_empty() {
            continue;
        }

        if is_symlink_entry(&entry) {
            continue;
        }

        if entry.is_dir() {
            ensure_directory_tree(&canonical_root, &relative, &mut touched_dirs, true)?;
            continue;
        }

        let parent_relative = relative.parent().unwrap_or_else(|| Path::new(""));
        let parent =
            ensure_directory_tree(&canonical_root, parent_relative, &mut touched_dirs, true)?;
        let target = canonical_root.join(&relative);
        if target == canonical_archive {
            return Err("Archive cannot overwrite itself during extraction.".into());
        }
        replace_existing_target(&target, false)?;

        let temporary = parent.join(format!(
            ".dpanel-unzip-{}-{index}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap_or_default()
                .as_nanos()
        ));
        let extract_result = (|| -> Result<(), String> {
            let mut output = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(&temporary)
                .map_err(|e| format!("failed to create {}: {e}", temporary.display()))?;
            let copied = io::copy(&mut entry, &mut output)
                .map_err(|e| format!("failed to extract {}: {e}", relative.display()))?;
            expanded_bytes = expanded_bytes.saturating_add(copied);
            if expanded_bytes > max_expanded_bytes {
                return Err("Zip expanded data exceeds the server limit.".into());
            }
            output
                .flush()
                .map_err(|e| format!("failed to flush {}: {e}", relative.display()))?;
            fs::rename(&temporary, &target)
                .map_err(|e| format!("failed to install {}: {e}", target.display()))?;
            Ok(())
        })();

        if extract_result.is_err() {
            let _ = fs::remove_file(&temporary);
        }
        extract_result?;
        touched_files.push(target);
    }

    fix_touched_permissions(username, &group, &touched_dirs, &touched_files)?;
    fix_laravel_writable_dirs(&canonical_root.to_string_lossy())?;

    info(&format!(
        "zip extracted: {} -> {}",
        archive_path.display(),
        canonical_root.display()
    ));
    Ok(())
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
enum ArchiveKind {
    Zip,
    Tar,
    TarGzip,
    Gzip,
}

fn archive_kind(path: &Path) -> Option<ArchiveKind> {
    let name = path.file_name()?.to_str()?.to_ascii_lowercase();
    if name.ends_with(".tar.gz") || name.ends_with(".tgz") {
        Some(ArchiveKind::TarGzip)
    } else if name.ends_with(".tar") {
        Some(ArchiveKind::Tar)
    } else if name.ends_with(".gz") {
        Some(ArchiveKind::Gzip)
    } else if name.ends_with(".zip") {
        Some(ArchiveKind::Zip)
    } else {
        None
    }
}

fn extract_tar<R: Read>(
    username: &str,
    group: &str,
    reader: R,
    archive_path: &Path,
    root: &Path,
    max_entries: usize,
    max_expanded_bytes: u64,
) -> Result<(), String> {
    let mut archive = tar::Archive::new(reader);
    let entries = archive
        .entries()
        .map_err(|e| format!("invalid or unsupported tar archive: {e}"))?;
    let mut touched_dirs = HashSet::new();
    let mut touched_files = Vec::new();
    let mut expanded_bytes = 0_u64;
    let mut entry_count = 0_usize;

    for entry_result in entries {
        let mut entry = entry_result.map_err(|e| format!("failed to read tar entry: {e}"))?;
        entry_count += 1;
        if entry_count > max_entries {
            return Err(format!(
                "Tar contains too many entries (more than {max_entries})."
            ));
        }

        let entry_path = entry
            .path()
            .map_err(|e| format!("failed to read tar entry path: {e}"))?;
        let relative = safe_tar_entry_path(&entry_path)?;
        if relative.as_os_str().is_empty() {
            continue;
        }
        let entry_type = entry.header().entry_type();
        if entry_type.is_dir() {
            ensure_directory_tree(root, &relative, &mut touched_dirs, true)?;
            continue;
        }
        if !entry_type.is_file() {
            return Err(format!(
                "Tar entry type is not supported: {}",
                relative.display()
            ));
        }

        let declared_size = entry
            .header()
            .size()
            .map_err(|e| format!("failed to read tar entry size: {e}"))?;
        expanded_bytes = expanded_bytes.saturating_add(declared_size);
        if expanded_bytes > max_expanded_bytes {
            return Err("Tar expanded data exceeds the server limit.".into());
        }

        let parent_relative = relative.parent().unwrap_or_else(|| Path::new(""));
        let parent = ensure_directory_tree(root, parent_relative, &mut touched_dirs, true)?;
        let target = root.join(&relative);
        if target == archive_path {
            return Err("Archive cannot overwrite itself during extraction.".into());
        }
        validate_replaceable_existing_target(&target)?;
        replace_existing_target(&target, false)?;
        let temporary = extraction_temp_path(&parent, entry_count);
        let extract_result = (|| -> Result<(), String> {
            let mut output = fs::OpenOptions::new()
                .write(true)
                .create_new(true)
                .mode(0o600)
                .open(&temporary)
                .map_err(|e| format!("failed to create {}: {e}", temporary.display()))?;
            let copied = io::copy(&mut entry, &mut output)
                .map_err(|e| format!("failed to extract {}: {e}", relative.display()))?;
            if copied != declared_size {
                return Err(format!("Tar entry size mismatch: {}", relative.display()));
            }
            output
                .flush()
                .map_err(|e| format!("failed to flush {}: {e}", relative.display()))?;
            fs::rename(&temporary, &target)
                .map_err(|e| format!("failed to install {}: {e}", target.display()))?;
            Ok(())
        })();
        if extract_result.is_err() {
            let _ = fs::remove_file(&temporary);
        }
        extract_result?;
        touched_files.push(target);
    }

    fix_touched_permissions(username, group, &touched_dirs, &touched_files)
}

fn extract_gzip(
    username: &str,
    group: &str,
    archive_path: &Path,
    root: &Path,
    max_expanded_bytes: u64,
) -> Result<(), String> {
    let name = archive_path
        .file_name()
        .and_then(|name| name.to_str())
        .ok_or_else(|| "Gzip archive filename is invalid.".to_string())?;
    let output_name = name
        .get(..name.len().saturating_sub(3))
        .filter(|_| name.to_ascii_lowercase().ends_with(".gz"))
        .filter(|name| !name.is_empty() && *name != "." && *name != "..")
        .ok_or_else(|| "Gzip archive must have a filename before .gz.".to_string())?;
    let target = root.join(output_name);
    if target == archive_path {
        return Err("Archive cannot overwrite itself during extraction.".into());
    }
    validate_replaceable_existing_target(&target)?;
    replace_existing_target(&target, false)?;

    let temporary = extraction_temp_path(root, 0);
    let result = (|| -> Result<(), String> {
        let file = fs::File::open(archive_path)
            .map_err(|e| format!("failed to open gzip archive: {e}"))?;
        let decoder = flate2::read::MultiGzDecoder::new(file);
        let mut output = fs::OpenOptions::new()
            .write(true)
            .create_new(true)
            .mode(0o600)
            .open(&temporary)
            .map_err(|e| format!("failed to create {}: {e}", temporary.display()))?;
        let copied = io::copy(
            &mut decoder.take(max_expanded_bytes.saturating_add(1)),
            &mut output,
        )
        .map_err(|e| format!("failed to decompress gzip archive: {e}"))?;
        if copied > max_expanded_bytes {
            return Err("Gzip expanded data exceeds the server limit.".into());
        }
        output
            .flush()
            .map_err(|e| format!("failed to flush {}: {e}", output_name))?;
        fs::rename(&temporary, &target)
            .map_err(|e| format!("failed to install {}: {e}", target.display()))?;
        Ok(())
    })();
    if result.is_err() {
        let _ = fs::remove_file(&temporary);
        return result;
    }

    fix_touched_permissions(username, group, &HashSet::new(), &[target])
}

fn extraction_temp_path(parent: &Path, index: usize) -> PathBuf {
    parent.join(format!(
        ".dpanel-unarchive-{}-{index}-{}",
        std::process::id(),
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
    ))
}

fn safe_tar_entry_path(path: &Path) -> Result<PathBuf, String> {
    if path.as_os_str().is_empty()
        || path.to_string_lossy().contains('\\')
        || path
            .components()
            .any(|component| !matches!(component, Component::Normal(_) | Component::CurDir))
    {
        return Err(format!("Tar entry has an unsafe path: {}", path.display()));
    }
    Ok(path.to_path_buf())
}

pub(crate) fn validate_archive<R: io::Read + io::Seek>(
    zip: &mut zip::ZipArchive<R>,
    max_entries: usize,
    max_expanded_bytes: u64,
) -> Result<(), String> {
    if zip.len() > max_entries {
        return Err(format!(
            "Zip contains too many entries ({}; maximum {max_entries}).",
            zip.len()
        ));
    }

    let mut declared_size = 0_u64;
    for index in 0..zip.len() {
        let entry = zip
            .by_index(index)
            .map_err(|e| format!("failed to inspect zip entry #{index}: {e}"))?;
        safe_entry_path(&entry, index)?;
        declared_size = declared_size.saturating_add(entry.size());
        if declared_size > max_expanded_bytes {
            return Err("Zip expanded data exceeds the server limit.".into());
        }
    }
    Ok(())
}

pub(crate) fn safe_entry_path<R: io::Read>(
    entry: &zip::read::ZipFile<'_, R>,
    index: usize,
) -> Result<PathBuf, String> {
    let path = entry
        .enclosed_name()
        .ok_or_else(|| format!("Zip entry #{index} has an unsafe path: {}", entry.name()))?;
    if path
        .components()
        .any(|component| !matches!(component, Component::Normal(_) | Component::CurDir))
    {
        return Err(format!("Zip entry #{index} has an unsafe path."));
    }
    Ok(path.to_path_buf())
}

pub(crate) fn is_symlink_entry<R: io::Read>(entry: &zip::read::ZipFile<'_, R>) -> bool {
    entry
        .unix_mode()
        .map(|mode| mode & 0o170000 == 0o120000)
        .unwrap_or(false)
}

/// With `replace` set (file manager extracts), a file or symbolic link sitting
/// where a directory is needed is removed instead of failing the extraction.
/// Removing a link never touches what it points to.
pub(crate) fn ensure_directory_tree(
    root: &Path,
    relative: &Path,
    touched_dirs: &mut HashSet<PathBuf>,
    replace: bool,
) -> Result<PathBuf, String> {
    let mut current = root.to_path_buf();
    for component in relative.components() {
        let Component::Normal(name) = component else {
            if matches!(component, Component::CurDir) {
                continue;
            }
            return Err("Zip entry contains an unsafe directory component.".into());
        };
        current.push(name);
        if replace
            && fs::symlink_metadata(&current)
                .is_ok_and(|metadata| metadata.file_type().is_symlink() || !metadata.is_dir())
        {
            fs::remove_file(&current)
                .map_err(|e| format!("failed to replace {}: {e}", current.display()))?;
        }
        match fs::symlink_metadata(&current) {
            Ok(metadata) if metadata.file_type().is_symlink() => {
                return Err(format!(
                    "Refusing to extract through symbolic link: {}",
                    current.display()
                ));
            }
            Ok(metadata) if !metadata.is_dir() => {
                return Err(format!(
                    "Extract path is not a directory: {}",
                    current.display()
                ));
            }
            Ok(_) => {}
            Err(e) if e.kind() == io::ErrorKind::NotFound => {
                fs::create_dir(&current)
                    .map_err(|e| format!("failed to create {}: {e}", current.display()))?;
                touched_dirs.insert(current.clone());
            }
            Err(e) => return Err(format!("failed to inspect {}: {e}", current.display())),
        }
    }
    Ok(current)
}

/// Fixes ownership/mode only on the directories and files this extraction actually
/// created, instead of walking the whole destination tree (which used to dominate
/// unzip time when extracting into a folder that already held many files, e.g. an
/// existing `vendor/` or `node_modules/`).
pub(crate) fn fix_touched_permissions(
    username: &str,
    group: &str,
    touched_dirs: &HashSet<PathBuf>,
    touched_files: &[PathBuf],
) -> Result<(), String> {
    let owner = format!("{username}:{group}");
    let dir_paths: Vec<String> = touched_dirs
        .iter()
        .map(|p| p.to_string_lossy().into_owned())
        .collect();
    let file_paths: Vec<String> = touched_files
        .iter()
        .map(|p| p.to_string_lossy().into_owned())
        .collect();

    let all_paths: Vec<&str> = dir_paths
        .iter()
        .chain(file_paths.iter())
        .map(String::as_str)
        .collect();
    for chunk in all_paths.chunks(500) {
        let mut args = vec![owner.as_str()];
        args.extend_from_slice(chunk);
        run_status("chown", &args)?;
    }

    let dir_refs: Vec<&str> = dir_paths.iter().map(String::as_str).collect();
    for chunk in dir_refs.chunks(500) {
        let mut args = vec!["0755"];
        args.extend_from_slice(chunk);
        run_status("chmod", &args)?;
    }

    let file_refs: Vec<&str> = file_paths.iter().map(String::as_str).collect();
    for chunk in file_refs.chunks(500) {
        let mut args = vec!["0644"];
        args.extend_from_slice(chunk);
        run_status("chmod", &args)?;
    }

    Ok(())
}

fn fix_laravel_writable_dirs(root: &str) -> Result<(), String> {
    let _ = run_status(
        "find",
        &[
            root,
            "-type",
            "d",
            "(",
            "-name",
            "storage",
            "-o",
            "-path",
            "*/bootstrap/cache",
            ")",
            "-exec",
            "chgrp",
            "-R",
            "www-data",
            "{}",
            "+",
        ],
    );
    run_status(
        "find",
        &[
            root,
            "-type",
            "d",
            "(",
            "-name",
            "storage",
            "-o",
            "-path",
            "*/bootstrap/cache",
            ")",
            "-exec",
            "chmod",
            "-R",
            "0775",
            "{}",
            "+",
        ],
    )
}

/// Clears whatever sits at `target` so a new file or folder can take its
/// place. Returns true when both are folders: the caller then merges into the
/// existing folder (same-named files are replaced, others are kept). A
/// symbolic link is removed as a link and never followed.
pub(crate) fn replace_existing_target(target: &Path, source_is_dir: bool) -> Result<bool, String> {
    let metadata = match fs::symlink_metadata(target) {
        Ok(metadata) => metadata,
        Err(e) if e.kind() == io::ErrorKind::NotFound => return Ok(false),
        Err(e) => return Err(format!("failed to inspect {}: {e}", target.display())),
    };
    if metadata.is_dir() && !metadata.file_type().is_symlink() {
        if source_is_dir {
            return Ok(true);
        }
        fs::remove_dir_all(target)
    } else if source_is_dir || metadata.file_type().is_symlink() {
        fs::remove_file(target)
    } else {
        return Ok(false);
    }
    .map_err(|e| format!("failed to replace {}: {e}", target.display()))?;
    Ok(false)
}

/// A merge copies into the existing tree as root, so a link planted inside it
/// could redirect writes outside the account home.
pub(crate) fn ensure_tree_has_no_symlinks(root: &Path) -> Result<(), String> {
    let mut pending = vec![root.to_path_buf()];
    while let Some(dir) = pending.pop() {
        let entries =
            fs::read_dir(&dir).map_err(|e| format!("failed to read {}: {e}", dir.display()))?;
        for entry in entries {
            let entry = entry.map_err(|e| format!("failed to read {}: {e}", dir.display()))?;
            let file_type = entry
                .file_type()
                .map_err(|e| format!("failed to inspect {}: {e}", entry.path().display()))?;
            if file_type.is_symlink() {
                return Err(format!(
                    "Cannot replace into a folder that contains a symbolic link: {}",
                    entry.path().display()
                ));
            }
            if file_type.is_dir() {
                pending.push(entry.path());
            }
        }
    }
    Ok(())
}

pub(crate) fn validate_replaceable_existing_target(target: &Path) -> Result<(), String> {
    match fs::symlink_metadata(target) {
        Ok(metadata) if metadata.file_type().is_symlink() => Err(format!(
            "Refusing to replace symbolic link: {}",
            target.display()
        )),
        Ok(metadata) if metadata.is_dir() => Err(format!(
            "Refusing to replace directory with file: {}",
            target.display()
        )),
        Ok(_) => Ok(()),
        Err(e) if e.kind() == io::ErrorKind::NotFound => Ok(()),
        Err(e) => Err(format!("failed to inspect {}: {e}", target.display())),
    }
}

fn env_usize(name: &str, fallback: usize) -> usize {
    std::env::var(name)
        .ok()
        .and_then(|value| value.parse().ok())
        .filter(|value| *value > 0)
        .unwrap_or(fallback)
}

fn env_u64(name: &str, fallback: u64) -> u64 {
    std::env::var(name)
        .ok()
        .and_then(|value| value.parse().ok())
        .filter(|value| *value > 0)
        .unwrap_or(fallback)
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::Cursor;
    use zip::write::SimpleFileOptions;

    #[test]
    fn rejects_parent_path_entry() {
        let mut bytes = Cursor::new(Vec::new());
        {
            let mut writer = zip::ZipWriter::new(&mut bytes);
            writer
                .start_file("../escape.txt", SimpleFileOptions::default())
                .unwrap();
            writer.write_all(b"unsafe").unwrap();
            writer.finish().unwrap();
        }
        bytes.set_position(0);
        let mut archive = zip::ZipArchive::new(bytes).unwrap();
        let error = validate_archive(&mut archive, 10, 1024).unwrap_err();
        assert!(error.contains("unsafe path"));
    }

    #[test]
    fn rejects_archive_over_expanded_limit() {
        let mut bytes = Cursor::new(Vec::new());
        {
            let mut writer = zip::ZipWriter::new(&mut bytes);
            writer
                .start_file("large.txt", SimpleFileOptions::default())
                .unwrap();
            writer.write_all(&[b'x'; 32]).unwrap();
            writer.finish().unwrap();
        }
        bytes.set_position(0);
        let mut archive = zip::ZipArchive::new(bytes).unwrap();
        let error = validate_archive(&mut archive, 10, 16).unwrap_err();
        assert!(error.contains("expanded data"));
    }

    #[test]
    fn accepts_environment_and_project_config_files() {
        let mut bytes = Cursor::new(Vec::new());
        {
            let mut writer = zip::ZipWriter::new(&mut bytes);
            for name in [".env", ".env.example", ".htaccess", "composer.json"] {
                writer
                    .start_file(name, SimpleFileOptions::default())
                    .unwrap();
                writer.write_all(b"text configuration").unwrap();
            }
            writer.finish().unwrap();
        }
        bytes.set_position(0);
        let mut archive = zip::ZipArchive::new(bytes).unwrap();
        validate_archive(&mut archive, 10, 1024).unwrap();
        for index in 0..archive.len() {
            let entry = archive.by_index(index).unwrap();
            let name = safe_entry_path(&entry, index).unwrap();
            assert!(
                [".env", ".env.example", ".htaccess", "composer.json"]
                    .contains(&name.to_str().unwrap())
            );
        }
    }
}
pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(response) = check_token(&state, &headers) {
        return response.into_response();
    }

    let result = tokio::task::spawn_blocking(move || {
        unzip_user_archive(
            &request.username,
            &request.path,
            request.destination.as_deref(),
        )
    })
    .await;
    match result {
        Ok(result) => operation_response(result, "Archive extracted"),
        Err(error) => axum::response::IntoResponse::into_response(ApiResponse::error(&format!(
            "Unzip worker failed: {error}"
        ))),
    }
}

#[cfg(test)]
mod replace_tests {
    use super::{
        ArchiveKind, archive_kind, ensure_directory_tree, ensure_tree_has_no_symlinks,
        replace_existing_target, safe_tar_entry_path,
    };
    use std::{collections::HashSet, fs, path::Path};

    fn scratch(name: &str) -> std::path::PathBuf {
        let root =
            std::env::temp_dir().join(format!("drust-replace-{name}-{}", std::process::id()));
        let _ = fs::remove_dir_all(&root);
        fs::create_dir_all(&root).unwrap();
        root
    }

    #[test]
    fn replaces_or_merges_existing_targets() {
        let root = scratch("targets");
        assert!(!replace_existing_target(&root.join("missing"), true).unwrap());

        fs::create_dir(root.join("dir")).unwrap();
        fs::write(root.join("dir/keep.txt"), "x").unwrap();
        assert!(replace_existing_target(&root.join("dir"), true).unwrap());
        assert!(root.join("dir/keep.txt").is_file());

        assert!(!replace_existing_target(&root.join("dir"), false).unwrap());
        assert!(!root.join("dir").exists());

        fs::write(root.join("file"), "x").unwrap();
        assert!(!replace_existing_target(&root.join("file"), true).unwrap());
        assert!(!root.join("file").exists());

        let outside = root.join("outside");
        fs::create_dir(&outside).unwrap();
        fs::write(outside.join("secret"), "x").unwrap();
        std::os::unix::fs::symlink(&outside, root.join("link")).unwrap();
        assert!(!replace_existing_target(&root.join("link"), true).unwrap());
        assert!(fs::symlink_metadata(root.join("link")).is_err());
        assert!(outside.join("secret").is_file());
        fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn extraction_replaces_files_and_links_in_the_way() {
        let root = scratch("tree");
        fs::write(root.join("assets"), "file").unwrap();
        let mut touched = HashSet::new();
        assert!(
            ensure_directory_tree(&root, Path::new("assets/css"), &mut touched, false).is_err()
        );
        ensure_directory_tree(&root, Path::new("assets/css"), &mut touched, true).unwrap();
        assert!(root.join("assets/css").is_dir());
        fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn refuses_merging_into_trees_with_links() {
        let root = scratch("links");
        fs::create_dir_all(root.join("a/b")).unwrap();
        assert!(ensure_tree_has_no_symlinks(&root).is_ok());
        std::os::unix::fs::symlink("/etc", root.join("a/b/etc")).unwrap();
        assert!(ensure_tree_has_no_symlinks(&root).is_err());
        fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn recognizes_supported_archive_suffixes_case_insensitively() {
        assert_eq!(
            archive_kind(Path::new("backup.ZIP")),
            Some(ArchiveKind::Zip)
        );
        assert_eq!(
            archive_kind(Path::new("backup.tar")),
            Some(ArchiveKind::Tar)
        );
        assert_eq!(
            archive_kind(Path::new("backup.TAR.GZ")),
            Some(ArchiveKind::TarGzip)
        );
        assert_eq!(
            archive_kind(Path::new("backup.tgz")),
            Some(ArchiveKind::TarGzip)
        );
        assert_eq!(
            archive_kind(Path::new("backup.gz")),
            Some(ArchiveKind::Gzip)
        );
        assert_eq!(archive_kind(Path::new("backup.rar")), None);
    }

    #[test]
    fn rejects_unsafe_tar_paths() {
        for path in ["../outside", "/absolute/path", "folder/../../outside"] {
            assert!(safe_tar_entry_path(Path::new(path)).is_err(), "{path}");
        }
        assert!(safe_tar_entry_path(Path::new("folder/file.txt")).is_ok());
    }
}
