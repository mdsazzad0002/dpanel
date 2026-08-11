use std::fs;
use std::io::{Read, Write};
use std::path::Path;
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use zip::write::SimpleFileOptions;

use crate::api::{ApiState, check_token, operation_response};
use crate::app::info;

use super::common::{
    apply_owner_and_mode, ensure_canonical_inside_home, validate_account, validate_user_path,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    paths: Vec<String>,
    destination: String,
}

pub fn create_user_zip(username: &str, paths: &[String], destination: &str) -> Result<(), String> {
    if paths.is_empty() {
        return Err("At least one source path is required.".into());
    }

    let (_, canonical_home, group) = validate_account(username)?;
    let requested_destination = validate_user_path(username, destination)?;
    if requested_destination
        .extension()
        .and_then(|value| value.to_str())
        .map(|value| !value.eq_ignore_ascii_case("zip"))
        .unwrap_or(true)
    {
        return Err("The destination must be a .zip file.".into());
    }
    if requested_destination.exists() {
        return Err("Zip file already exists.".into());
    }

    let parent = requested_destination
        .parent()
        .ok_or_else(|| "Zip destination parent is missing.".to_string())?;
    let canonical_parent = ensure_canonical_inside_home(&canonical_home, parent, "Zip folder")?;
    if !canonical_parent.is_dir() {
        return Err("Zip destination parent is not a folder.".into());
    }
    let file_name = requested_destination
        .file_name()
        .ok_or_else(|| "Zip destination filename is missing.".to_string())?;
    let destination = canonical_parent.join(file_name);

    let mut sources = Vec::with_capacity(paths.len());
    for source in paths {
        let path = validate_user_path(username, source)?;
        let canonical = ensure_canonical_inside_home(&canonical_home, &path, "Zip source")?;
        if canonical == destination {
            return Err("A zip file cannot include itself.".into());
        }
        sources.push(canonical);
    }

    let temporary = canonical_parent.join(format!(
        ".dpanel-zip-{}-{}",
        std::process::id(),
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
    ));

    let result = (|| -> Result<(), String> {
        let output = fs::File::create(&temporary)
            .map_err(|e| format!("failed to create temporary zip: {e}"))?;
        let mut writer = zip::ZipWriter::new(output);
        let mut buffer = vec![0_u8; 128 * 1024];

        for source in &sources {
            let name = source
                .file_name()
                .ok_or_else(|| format!("Invalid zip source: {}", source.display()))?;
            add_path(
                &mut writer,
                source,
                Path::new(name),
                &temporary,
                &mut buffer,
            )?;
        }

        writer
            .finish()
            .map_err(|e| format!("failed to finalize zip file: {e}"))?;
        fs::rename(&temporary, &destination)
            .map_err(|e| format!("failed to save zip file: {e}"))?;
        apply_owner_and_mode(username, &group, &destination, "0640")?;
        Ok(())
    })();

    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result?;

    info(&format!("zip created: {}", destination.display()));
    Ok(())
}

fn add_path(
    writer: &mut zip::ZipWriter<fs::File>,
    source: &Path,
    archive_path: &Path,
    temporary: &Path,
    buffer: &mut [u8],
) -> Result<(), String> {
    let metadata = fs::symlink_metadata(source)
        .map_err(|e| format!("failed to inspect {}: {e}", source.display()))?;
    if metadata.file_type().is_symlink() || source == temporary {
        return Ok(());
    }

    let archive_name = archive_path.to_string_lossy().replace('\\', "/");
    if metadata.is_dir() {
        writer
            .add_directory(
                format!("{}/", archive_name.trim_end_matches('/')),
                SimpleFileOptions::default().unix_permissions(0o755),
            )
            .map_err(|e| format!("failed to add directory {archive_name}: {e}"))?;
        for entry in fs::read_dir(source)
            .map_err(|e| format!("failed to read directory {}: {e}", source.display()))?
        {
            let entry = entry.map_err(|e| format!("failed to read directory entry: {e}"))?;
            add_path(
                writer,
                &entry.path(),
                &archive_path.join(entry.file_name()),
                temporary,
                buffer,
            )?;
        }
        return Ok(());
    }

    if !metadata.is_file() {
        return Ok(());
    }
    writer
        .start_file(
            archive_name.clone(),
            SimpleFileOptions::default().unix_permissions(0o644),
        )
        .map_err(|e| format!("failed to add file {archive_name}: {e}"))?;
    let mut input =
        fs::File::open(source).map_err(|e| format!("failed to open {}: {e}", source.display()))?;
    loop {
        let count = input
            .read(buffer)
            .map_err(|e| format!("failed to read {}: {e}", source.display()))?;
        if count == 0 {
            break;
        }
        writer
            .write_all(&buffer[..count])
            .map_err(|e| format!("failed to compress {}: {e}", source.display()))?;
    }
    Ok(())
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    operation_response(
        create_user_zip(&request.username, &request.paths, &request.destination),
        "Zip created",
    )
}

#[cfg(test)]
mod tests {
    use super::add_path;
    use std::fs;
    use std::io::Read;

    #[test]
    fn includes_hidden_and_project_config_files() {
        let root = std::env::temp_dir().join(format!(
            "drust-zip-test-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir(&root).unwrap();
        for (name, content) in [
            (".env", "APP_KEY=test"),
            (".env.example", "APP_KEY="),
            (".htaccess", "RewriteEngine On"),
            ("composer.json", "{}"),
        ] {
            fs::write(root.join(name), content).unwrap();
        }

        let archive_path = root.with_extension("zip");
        let output = fs::File::create(&archive_path).unwrap();
        let mut writer = zip::ZipWriter::new(output);
        let mut buffer = vec![0_u8; 4096];
        add_path(
            &mut writer,
            &root,
            std::path::Path::new("project"),
            &archive_path,
            &mut buffer,
        )
        .unwrap();
        writer.finish().unwrap();

        let input = fs::File::open(&archive_path).unwrap();
        let mut archive = zip::ZipArchive::new(input).unwrap();
        for name in [
            "project/.env",
            "project/.env.example",
            "project/.htaccess",
            "project/composer.json",
        ] {
            let mut entry = archive.by_name(name).unwrap();
            let mut content = String::new();
            entry.read_to_string(&mut content).unwrap();
            assert!(!content.is_empty());
        }

        fs::remove_file(archive_path).unwrap();
        fs::remove_dir_all(root).unwrap();
    }
}
