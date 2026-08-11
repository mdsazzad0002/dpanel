use bytes::Bytes;
use std::{
    collections::HashMap,
    fs,
    path::{Path, PathBuf},
    sync::{OnceLock, RwLock},
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
    pub body: StaticAssetBody,
    pub etag: String,
    pub last_modified: SystemTime,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub enum StaticAssetBody {
    Memory(Bytes),
    Stream(PathBuf),
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

    let last_modified = meta.modified().unwrap_or(SystemTime::UNIX_EPOCH);
    let cache = STATIC_CACHE.get_or_init(|| RwLock::new(HashMap::new()));
    if let Ok(items) = cache.read() {
        if let Some(asset) = items.get(path) {
            if asset.last_modified == last_modified
                && matches!(&asset.body, StaticAssetBody::Memory(body) if body.len() as u64 == meta.len())
            {
                return Ok(asset.clone());
            }
        }
    }
    let body = if meta.len() <= static_cache_max_file_bytes() {
        StaticAssetBody::Memory(Bytes::from(
            fs::read(path).map_err(|error| format!("file read failed: {error}"))?,
        ))
    } else {
        StaticAssetBody::Stream(path.to_path_buf())
    };
    let etag = format!(
        "\"{:x}-{:x}\"",
        meta.len(),
        last_modified
            .duration_since(SystemTime::UNIX_EPOCH)
            .unwrap_or_default()
            .as_secs()
    );
    let content_type = guess_content_type(path);

    let asset = StaticAsset {
        path: path.to_path_buf(),
        content_type,
        body,
        etag,
        last_modified,
    };
    if meta.len() <= static_cache_max_file_bytes() {
        if let Ok(mut items) = cache.write() {
            if items.len() >= static_cache_max_entries() {
                items.clear();
            }
            items.insert(path.to_path_buf(), asset.clone());
        }
    }
    Ok(asset)
}

static STATIC_CACHE: OnceLock<RwLock<HashMap<PathBuf, StaticAsset>>> = OnceLock::new();
pub fn clear_static_cache() {
    if let Some(cache) = STATIC_CACHE.get() {
        if let Ok(mut items) = cache.write() {
            items.clear();
        }
    }
}

pub fn clear_static_cache_under(roots: &[PathBuf]) {
    if roots.is_empty() {
        return;
    }
    if let Some(cache) = STATIC_CACHE.get() {
        if let Ok(mut items) = cache.write() {
            items.retain(|path, _| !roots.iter().any(|root| path.starts_with(root)));
        }
    }
}
fn static_cache_max_file_bytes() -> u64 {
    std::env::var("DRUST_STATIC_CACHE_MAX_FILE_BYTES")
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(1_048_576)
}
fn static_cache_max_entries() -> usize {
    std::env::var("DRUST_STATIC_CACHE_MAX_ENTRIES")
        .ok()
        .and_then(|v| v.parse().ok())
        .unwrap_or(1024)
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
        "ico" => "image/x-icon".into(),
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
        assert!(
            matches!(asset.body, StaticAssetBody::Memory(ref body) if body.as_ref() == b"body{}")
        );
        assert!(asset.content_type.contains("text/css"));

        let _ = fs::remove_dir_all(&base);
    }
}
