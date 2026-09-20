use std::collections::HashSet;
use std::fs;
use std::io::{self, Write};
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
        return Err(format!("Zip archive not found: {}", archive_path.display()));
    }
    if archive_path
        .extension()
        .and_then(|value| value.to_str())
        .map(|value| !value.eq_ignore_ascii_case("zip"))
        .unwrap_or(true)
    {
        return Err("The selected file is not a .zip archive.".into());
    }

    let canonical_archive =
        ensure_canonical_inside_home(&canonical_home, &archive_path, "Zip archive")?;
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
                .ok_or_else(|| "Zip archive parent is missing.".to_string())?;
            ensure_canonical_inside_home(&canonical_home, extract_root, "Extract folder")?
        }
    };
    if !canonical_root.is_dir() {
        return Err("Extract target is not a folder.".into());
    }

    let max_entries = env_usize("DRUST_MAX_ZIP_ENTRIES", DEFAULT_MAX_ENTRIES);
    let max_expanded_bytes = env_u64("DRUST_MAX_ZIP_EXPANDED_BYTES", DEFAULT_MAX_EXPANDED_BYTES);
    let source = fs::File::open(&canonical_archive)
        .map_err(|e| format!("failed to open zip archive: {e}"))?;
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
            ensure_directory_tree(&canonical_root, &relative, &mut touched_dirs)?;
            continue;
        }

        let parent_relative = relative.parent().unwrap_or_else(|| Path::new(""));
        let parent = ensure_directory_tree(&canonical_root, parent_relative, &mut touched_dirs)?;
        let target = canonical_root.join(&relative);
        if target == canonical_archive {
            return Err("Zip archive cannot overwrite itself during extraction.".into());
        }
        validate_replaceable_existing_target(&target)?;

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

pub(super) fn validate_archive<R: io::Read + io::Seek>(
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

pub(super) fn safe_entry_path<R: io::Read>(
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

pub(super) fn is_symlink_entry<R: io::Read>(entry: &zip::read::ZipFile<'_, R>) -> bool {
    entry
        .unix_mode()
        .map(|mode| mode & 0o170000 == 0o120000)
        .unwrap_or(false)
}

pub(super) fn ensure_directory_tree(
    root: &Path,
    relative: &Path,
    touched_dirs: &mut HashSet<PathBuf>,
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
pub(super) fn fix_touched_permissions(
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

pub(super) fn validate_replaceable_existing_target(target: &Path) -> Result<(), String> {
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
        Ok(result) => operation_response(result, "Zip extracted"),
        Err(error) => axum::response::IntoResponse::into_response(ApiResponse::error(&format!(
            "Unzip worker failed: {error}"
        ))),
    }
}
