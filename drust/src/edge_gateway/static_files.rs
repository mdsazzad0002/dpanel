use std::{
    fs,
    path::{Path, PathBuf},
    time::{Duration, SystemTime},
};

#[derive(Clone, Debug)]
pub struct StaticFileConfig {
    pub document_root: PathBuf,
    pub index_file: String,
    pub spa_fallback: bool,
    pub cache_ttl: Duration,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub struct StaticAsset {
    pub path: PathBuf,
    pub content_type: String,
    pub body: Vec<u8>,
    pub etag: String,
    pub last_modified: SystemTime,
}

pub fn resolve_static_path(
    root: &Path,
    request_path: &str,
    index_file: &str,
    spa_fallback: bool,
) -> Option<PathBuf> {
    let normalized = normalize_static_path(request_path);
    let mut candidate = root.join(normalized.trim_start_matches('/'));

    if candidate.is_dir() {
        candidate = candidate.join(index_file);
    }

    if candidate.is_file() {
        return Some(candidate);
    }

    if spa_fallback {
        let fallback = root.join(index_file);
        if fallback.is_file() {
            return Some(fallback);
        }
    }

    None
}

pub fn load_static_asset(path: &Path) -> Result<StaticAsset, String> {
    let meta = fs::metadata(path).map_err(|error| format!("metadata read failed: {error}"))?;
    if !meta.is_file() {
        return Err("not a file".into());
    }

    let body = fs::read(path).map_err(|error| format!("file read failed: {error}"))?;
    let last_modified = meta.modified().unwrap_or(SystemTime::UNIX_EPOCH);
    let etag = format!(
        "\"{:x}-{:x}\"",
        body.len(),
        last_modified
            .duration_since(SystemTime::UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs()
    );
    let content_type = guess_content_type(path);

    Ok(StaticAsset {
        path: path.to_path_buf(),
        content_type,
        body,
        etag,
        last_modified,
    })
}

pub fn normalize_static_path(path: &str) -> String {
    let mut parts = Vec::new();
    for segment in path.split('/') {
        match segment {
            "" | "." => {}
            ".." => {
                let _ = parts.pop();
            }
            other => parts.push(other),
        }
    }

    if parts.is_empty() {
        "/".to_string()
    } else {
        format!("/{}", parts.join("/"))
    }
}

fn guess_content_type(path: &Path) -> String {
    match path.extension().and_then(|ext| ext.to_str()).unwrap_or("") {
        "html" | "htm" => "text/html; charset=utf-8".into(),
        "css" => "text/css; charset=utf-8".into(),
        "js" | "mjs" => "application/javascript; charset=utf-8".into(),
        "json" => "application/json; charset=utf-8".into(),
        "svg" => "image/svg+xml".into(),
        "txt" | "log" => "text/plain; charset=utf-8".into(),
        "xml" => "application/xml; charset=utf-8".into(),
        "wasm" => "application/wasm".into(),
        "png" => "image/png".into(),
        "jpg" | "jpeg" => "image/jpeg".into(),
        "gif" => "image/gif".into(),
        "webp" => "image/webp".into(),
        _ => "application/octet-stream".into(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::fs;

    #[test]
    fn normalize_path_removes_traversal_segments() {
        assert_eq!(normalize_static_path("/a/b/../c"), "/a/c");
        assert_eq!(normalize_static_path("/"), "/");
    }

    #[test]
    fn resolve_static_path_finds_index_file() {
        let base = std::env::temp_dir().join(format!("drust-static-{}", std::process::id()));
        let _ = fs::remove_dir_all(&base);
        fs::create_dir_all(base.join("site")).unwrap();
        fs::write(base.join("site/index.html"), b"hello").unwrap();

        let found = resolve_static_path(&base.join("site"), "/", "index.html", false).unwrap();
        assert_eq!(found, base.join("site/index.html"));

        let _ = fs::remove_dir_all(&base);
    }

    #[test]
    fn load_static_asset_reads_body() {
        let base = std::env::temp_dir().join(format!("drust-static-asset-{}", std::process::id()));
        let _ = fs::remove_dir_all(&base);
        fs::create_dir_all(&base).unwrap();
        let file = base.join("app.css");
        fs::write(&file, b"body{}").unwrap();

        let asset = load_static_asset(&file).unwrap();
        assert_eq!(asset.body, b"body{}");
        assert!(asset.content_type.contains("text/css"));

        let _ = fs::remove_dir_all(&base);
    }
}
