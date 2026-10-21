use std::fs;
use std::io::{Read, Write};
use std::path::Path;
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use flate2::Compression;
use flate2::write::GzEncoder;
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
    #[serde(default = "default_archive_format")]
    format: String,
}

fn default_archive_format() -> String {
    "zip".into()
}

pub fn create_user_archive(
    username: &str,
    paths: &[String],
    destination: &str,
    format: &str,
) -> Result<(), String> {
    if paths.is_empty() {
        return Err("At least one source path is required.".into());
    }

    let format = match format {
        "zip" => ArchiveFormat::Zip,
        "tar" => ArchiveFormat::Tar,
        "tar.gz" => ArchiveFormat::TarGzip,
        _ => return Err("Archive format must be zip, tar, or tar.gz.".into()),
    };
    let (_, canonical_home, group) = validate_account(username)?;
    let requested_destination = validate_user_path(username, destination)?;
    let expected_extension = match format {
        ArchiveFormat::Zip => ".zip",
        ArchiveFormat::Tar => ".tar",
        ArchiveFormat::TarGzip => ".tar.gz",
    };
    if !requested_destination
        .file_name()
        .and_then(|value| value.to_str())
        .is_some_and(|name| name.to_ascii_lowercase().ends_with(expected_extension))
    {
        return Err(format!(
            "The destination must end with {expected_extension}."
        ));
    }
    if requested_destination.exists() {
        return Err("Archive file already exists.".into());
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
            return Err("An archive cannot include itself.".into());
        }
        sources.push(canonical);
    }

    let temporary = canonical_parent.join(format!(
        ".dpanel-archive-{}-{}",
        std::process::id(),
        std::time::SystemTime::now()
            .duration_since(std::time::UNIX_EPOCH)
            .unwrap_or_default()
            .as_nanos()
    ));

    let result = (|| -> Result<(), String> {
        let mut file_count = 0_u64;
        match format {
            ArchiveFormat::Zip => create_zip_file(&sources, &temporary, &mut file_count)?,
            ArchiveFormat::Tar => create_tar_file(&sources, &temporary, &mut file_count)?,
            ArchiveFormat::TarGzip => create_tar_gzip_file(&sources, &temporary, &mut file_count)?,
        }
        if file_count == 0 {
            return Err("Nothing to archive: the selected item(s) contain no files.".into());
        }
        fs::rename(&temporary, &destination).map_err(|e| format!("failed to save archive: {e}"))?;
        apply_owner_and_mode(username, &group, &destination, "0640")?;
        Ok(())
    })();

    if result.is_err() {
        let _ = fs::remove_file(&temporary);
    }
    result?;

    info(&format!("archive created: {}", destination.display()));
    Ok(())
}

#[derive(Clone, Copy)]
enum ArchiveFormat {
    Zip,
    Tar,
    TarGzip,
}

fn create_zip_file(
    sources: &[std::path::PathBuf],
    temporary: &Path,
    file_count: &mut u64,
) -> Result<(), String> {
    let output =
        fs::File::create(temporary).map_err(|e| format!("failed to create temporary ZIP: {e}"))?;
    let mut writer = zip::ZipWriter::new(output);
    let mut buffer = vec![0_u8; 128 * 1024];
    for source in sources {
        let name = source
            .file_name()
            .ok_or_else(|| format!("Invalid archive source: {}", source.display()))?;
        add_path(
            &mut writer,
            source,
            Path::new(name),
            temporary,
            &mut buffer,
            file_count,
        )?;
    }
    writer
        .finish()
        .map_err(|e| format!("failed to finalize ZIP file: {e}"))?;
    Ok(())
}

fn create_tar_file(
    sources: &[std::path::PathBuf],
    temporary: &Path,
    file_count: &mut u64,
) -> Result<(), String> {
    let output =
        fs::File::create(temporary).map_err(|e| format!("failed to create temporary tar: {e}"))?;
    let mut builder = tar::Builder::new(output);
    for source in sources {
        let name = source
            .file_name()
            .ok_or_else(|| format!("Invalid archive source: {}", source.display()))?;
        add_tar_path(&mut builder, source, Path::new(name), temporary, file_count)?;
    }
    builder
        .finish()
        .map_err(|e| format!("failed to finalize tar file: {e}"))?;
    Ok(())
}

fn create_tar_gzip_file(
    sources: &[std::path::PathBuf],
    temporary: &Path,
    file_count: &mut u64,
) -> Result<(), String> {
    let output = fs::File::create(temporary)
        .map_err(|e| format!("failed to create temporary tar.gz: {e}"))?;
    let encoder = GzEncoder::new(output, Compression::default());
    let mut builder = tar::Builder::new(encoder);
    for source in sources {
        let name = source
            .file_name()
            .ok_or_else(|| format!("Invalid archive source: {}", source.display()))?;
        add_tar_path(&mut builder, source, Path::new(name), temporary, file_count)?;
    }
    let encoder = builder
        .into_inner()
        .map_err(|e| format!("failed to finalize tar.gz archive: {e}"))?;
    encoder
        .finish()
        .map_err(|e| format!("failed to compress tar.gz archive: {e}"))?;
    Ok(())
}

fn add_tar_path<W: Write>(
    builder: &mut tar::Builder<W>,
    source: &Path,
    archive_path: &Path,
    temporary: &Path,
    file_count: &mut u64,
) -> Result<(), String> {
    let metadata = fs::symlink_metadata(source)
        .map_err(|e| format!("failed to inspect {}: {e}", source.display()))?;
    if metadata.file_type().is_symlink() || source == temporary {
        return Ok(());
    }

    if metadata.is_dir() {
        builder
            .append_dir(archive_path, source)
            .map_err(|e| format!("failed to add directory {}: {e}", archive_path.display()))?;
        for entry in fs::read_dir(source)
            .map_err(|e| format!("failed to read directory {}: {e}", source.display()))?
        {
            let entry = entry.map_err(|e| format!("failed to read directory entry: {e}"))?;
            add_tar_path(
                builder,
                &entry.path(),
                &archive_path.join(entry.file_name()),
                temporary,
                file_count,
            )?;
        }
        return Ok(());
    }
    if !metadata.is_file() {
        return Ok(());
    }

    let mut input =
        fs::File::open(source).map_err(|e| format!("failed to open {}: {e}", source.display()))?;
    builder
        .append_file(archive_path, &mut input)
        .map_err(|e| format!("failed to add file {}: {e}", archive_path.display()))?;
    *file_count += 1;
    Ok(())
}

fn add_path(
    writer: &mut zip::ZipWriter<fs::File>,
    source: &Path,
    archive_path: &Path,
    temporary: &Path,
    buffer: &mut [u8],
    file_count: &mut u64,
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
                file_count,
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
    *file_count += 1;
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

    let result = tokio::task::spawn_blocking(move || {
        create_user_archive(
            &request.username,
            &request.paths,
            &request.destination,
            &request.format,
        )
    })
    .await;
    match result {
        Ok(result) => operation_response(result, "Archive created"),
        Err(error) => axum::response::IntoResponse::into_response(crate::api::ApiResponse::error(
            &format!("Zip worker failed: {error}"),
        )),
    }
}

#[cfg(test)]
mod tests {
    use super::{add_path, create_tar_file, create_tar_gzip_file};
    use std::fs;
    use std::io::Read;
    use std::path::Path;

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
        let mut file_count = 0_u64;
        add_path(
            &mut writer,
            &root,
            std::path::Path::new("project"),
            &archive_path,
            &mut buffer,
            &mut file_count,
        )
        .unwrap();
        assert_eq!(file_count, 4);
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

    #[test]
    fn counts_zero_files_for_an_empty_directory() {
        let root = std::env::temp_dir().join(format!(
            "drust-zip-empty-test-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir(&root).unwrap();

        let archive_path = root.with_extension("zip");
        let output = fs::File::create(&archive_path).unwrap();
        let mut writer = zip::ZipWriter::new(output);
        let mut buffer = vec![0_u8; 4096];
        let mut file_count = 0_u64;
        add_path(
            &mut writer,
            &root,
            std::path::Path::new("project"),
            &archive_path,
            &mut buffer,
            &mut file_count,
        )
        .unwrap();
        writer.finish().unwrap();

        assert_eq!(file_count, 0);

        fs::remove_file(archive_path).unwrap();
        fs::remove_dir_all(root).unwrap();
    }

    #[test]
    fn creates_tar_and_tar_gzip_archives_with_selected_files() {
        let root = std::env::temp_dir().join(format!(
            "drust-tar-test-{}-{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .unwrap()
                .as_nanos()
        ));
        fs::create_dir_all(root.join("project/sub")).unwrap();
        fs::write(root.join("project/.env"), "APP_KEY=test").unwrap();
        fs::write(root.join("project/sub/file.txt"), "content").unwrap();

        let sources = [root.join("project")];
        let tar_path = root.join("backup.tar");
        let mut tar_file_count = 0;
        create_tar_file(&sources, &tar_path, &mut tar_file_count).unwrap();
        assert_eq!(tar_file_count, 2);
        let mut archive = tar::Archive::new(fs::File::open(&tar_path).unwrap());
        let paths: Vec<_> = archive
            .entries()
            .unwrap()
            .map(|entry| entry.unwrap().path().unwrap().into_owned())
            .collect();
        assert!(paths.iter().any(|path| path == Path::new("project/.env")));
        assert!(
            paths
                .iter()
                .any(|path| path == Path::new("project/sub/file.txt"))
        );

        let gzip_path = root.join("backup.tar.gz");
        let mut gzip_file_count = 0;
        create_tar_gzip_file(&sources, &gzip_path, &mut gzip_file_count).unwrap();
        assert_eq!(gzip_file_count, 2);
        let decoder = flate2::read::GzDecoder::new(fs::File::open(&gzip_path).unwrap());
        let mut archive = tar::Archive::new(decoder);
        let paths: Vec<_> = archive
            .entries()
            .unwrap()
            .map(|entry| entry.unwrap().path().unwrap().into_owned())
            .collect();
        assert!(paths.iter().any(|path| path == Path::new("project/.env")));
        assert!(
            paths
                .iter()
                .any(|path| path == Path::new("project/sub/file.txt"))
        );

        fs::remove_dir_all(root).unwrap();
    }
}
