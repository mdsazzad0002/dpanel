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
    apply_owner_and_mode, ensure_canonical_inside_home, validate_account, validate_user_path,
};

const DEFAULT_MAX_ENTRIES: usize = 100_000;
const DEFAULT_MAX_EXPANDED_BYTES: u64 = 20 * 1024 * 1024 * 1024;

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
}

pub fn unzip_user_archive(username: &str, archive: &str) -> Result<(), String> {
    let (_, canonical_home, group) = validate_account(username)?;
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
    let extract_root = archive_path
        .parent()
        .ok_or_else(|| "Zip archive parent is missing.".to_string())?;
    let canonical_root =
        ensure_canonical_inside_home(&canonical_home, extract_root, "Extract folder")?;
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
    for index in 0..zip.len() {
        let mut entry = zip
            .by_index(index)
            .map_err(|e| format!("failed to read zip entry #{index}: {e}"))?;
        let relative = safe_entry_path(&entry, index)?;
        if relative.as_os_str().is_empty() {
            continue;
        }

        if entry.is_dir() {
            ensure_directory_tree(&canonical_root, &relative, username, &group)?;
            continue;
        }

        let parent_relative = relative.parent().unwrap_or_else(|| Path::new(""));
        let parent = ensure_directory_tree(&canonical_root, parent_relative, username, &group)?;
        let target = canonical_root.join(&relative);
        if target == canonical_archive {
            return Err("Zip archive cannot overwrite itself during extraction.".into());
        }
        reject_unsafe_existing_target(&target)?;

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
            apply_owner_and_mode(username, &group, &temporary, "0644")?;
            fs::rename(&temporary, &target)
                .map_err(|e| format!("failed to install {}: {e}", target.display()))?;
            Ok(())
        })();

        if extract_result.is_err() {
            let _ = fs::remove_file(&temporary);
        }
        extract_result?;
    }

    info(&format!(
        "zip extracted: {} -> {}",
        archive_path.display(),
        canonical_root.display()
    ));
    Ok(())
}

fn validate_archive<R: io::Read + io::Seek>(
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
        if is_symlink_entry(&entry) {
            return Err(format!(
                "Zip contains a symbolic link entry: {}",
                entry.name()
            ));
        }
        declared_size = declared_size.saturating_add(entry.size());
        if declared_size > max_expanded_bytes {
            return Err("Zip expanded data exceeds the server limit.".into());
        }
    }
    Ok(())
}

fn safe_entry_path<R: io::Read>(
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

fn is_symlink_entry<R: io::Read>(entry: &zip::read::ZipFile<'_, R>) -> bool {
    entry
        .unix_mode()
        .map(|mode| mode & 0o170000 == 0o120000)
        .unwrap_or(false)
}

fn ensure_directory_tree(
    root: &Path,
    relative: &Path,
    username: &str,
    group: &str,
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
                apply_owner_and_mode(username, group, &current, "0755")?;
            }
            Err(e) => return Err(format!("failed to inspect {}: {e}", current.display())),
        }
    }
    Ok(current)
}

fn reject_unsafe_existing_target(target: &Path) -> Result<(), String> {
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
}
pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(response) = check_token(&state, &headers) {
        return response.into_response();
    }

    let result =
        tokio::task::spawn_blocking(move || unzip_user_archive(&request.username, &request.path))
            .await;
    match result {
        Ok(result) => operation_response(result, "Zip extracted"),
        Err(error) => axum::response::IntoResponse::into_response(ApiResponse::error(&format!(
            "Unzip worker failed: {error}"
        ))),
    }
}
