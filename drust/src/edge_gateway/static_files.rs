use bytes::Bytes;
use std::{
    collections::HashMap,
    fs,
    path::{Path, PathBuf},
    sync::{
        OnceLock, RwLock,
        atomic::{AtomicU64, Ordering},
    },
    time::SystemTime,
};

#[derive(Clone, Debug)]
pub struct StaticFileConfig {
    pub document_root: PathBuf,
    pub index_file: String,
    pub spa_fallback: bool,
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
    let cache = static_cache();
    if let Ok(cache) = cache.read() {
        if let Some(cached) = cache.items.get(path) {
            let asset = &cached.asset;
            if asset.last_modified == last_modified
                && matches!(&asset.body, StaticAssetBody::Memory(body) if body.len() as u64 == meta.len())
            {
                cached.touch();
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
        if let Ok(mut cache) = cache.write() {
            cache.insert(asset.clone(), meta.len());
        }
    }
    Ok(asset)
}

static STATIC_CACHE: OnceLock<RwLock<StaticCache>> = OnceLock::new();
/// Ticks on every cache hit; a file's last tick says how recently it was used.
static USE_CLOCK: AtomicU64 = AtomicU64::new(0);

fn static_cache() -> &'static RwLock<StaticCache> {
    STATIC_CACHE.get_or_init(|| {
        RwLock::new(StaticCache::new(static_cache_max_entries(), static_cache_max_bytes()))
    })
}

struct CachedAsset {
    asset: StaticAsset,
    size: u64,
    last_used: AtomicU64,
}

impl CachedAsset {
    fn touch(&self) {
        self.last_used
            .store(USE_CLOCK.fetch_add(1, Ordering::Relaxed), Ordering::Relaxed);
    }
}

/// One cache for every site's small files, bounded by count and bytes. When
/// full it drops the least recently used tenth, so busy sites with many assets
/// keep their hot files instead of the whole cache being wiped.
struct StaticCache {
    items: HashMap<PathBuf, CachedAsset>,
    bytes: u64,
    max_entries: usize,
    max_bytes: u64,
}

impl StaticCache {
    fn new(max_entries: usize, max_bytes: u64) -> Self {
        Self { items: HashMap::new(), bytes: 0, max_entries, max_bytes }
    }

    fn insert(&mut self, asset: StaticAsset, size: u64) {
        let cached = CachedAsset { asset, size, last_used: AtomicU64::new(0) };
        cached.touch();
        if let Some(old) = self.items.insert(cached.asset.path.clone(), cached) {
            self.bytes -= old.size;
        }
        self.bytes += size;
        if self.items.len() > self.max_entries || self.bytes > self.max_bytes {
            self.evict();
        }
    }

    fn evict(&mut self) {
        let entry_target = self.max_entries / 10 * 9;
        let byte_target = self.max_bytes / 10 * 9;
        let mut by_use = self
            .items
            .iter()
            .map(|(path, cached)| (cached.last_used.load(Ordering::Relaxed), path.clone()))
            .collect::<Vec<_>>();
        by_use.sort_unstable();
        for (_, path) in by_use {
            if self.items.len() <= entry_target && self.bytes <= byte_target {
                break;
            }
            if let Some(old) = self.items.remove(&path) {
                self.bytes -= old.size;
            }
        }
    }

    fn retain(&mut self, keep: impl Fn(&Path) -> bool) {
        self.items.retain(|path, _| keep(path));
        self.bytes = self.items.values().map(|cached| cached.size).sum();
    }
}
/// How long a browser may reuse a static file without asking again.
///
/// Build output with a content hash in its name (Vite `app-C4as9d92.js`,
/// webpack `app.3f2a1b9c.js`) never changes, so it is cached for a year. HTML
/// is revalidated on every load (a cheap 304 via the ETag) so a deploy that
/// points it at new hashed files shows up at once. Anything else is reused
/// for an hour.
pub fn browser_cache_control(path: &Path, content_type: &str) -> &'static str {
    if content_type.starts_with("text/html") {
        return "no-cache";
    }
    if is_fingerprinted(path) {
        return "public, max-age=31536000, immutable";
    }
    "public, max-age=3600"
}

fn is_fingerprinted(path: &Path) -> bool {
    const BUILD_DIRS: &[&str] = &["build", "assets", "dist", "static", "_next", "chunks"];
    let in_build_dir = path
        .parent()
        .is_some_and(|dir| dir.components().any(|c| BUILD_DIRS.contains(&c.as_os_str().to_string_lossy().as_ref())));
    let Some(stem) = path.file_stem().and_then(|s| s.to_str()) else {
        return false;
    };
    // Webpack and others add `.min` or `.chunk` after the hash.
    let stem = stem.trim_end_matches(".min").trim_end_matches(".chunk");
    let bytes = stem.as_bytes();
    if !in_build_dir || bytes.len() < 10 {
        return false;
    }
    // An 8+ character hash after a `-` or `.`, mixing letters and digits.
    (8..=20).any(|len| {
        if bytes.len() <= len {
            return false;
        }
        let (head, hash) = stem.split_at(bytes.len() - len);
        matches!(head.as_bytes().last(), Some(b'-' | b'.'))
            && hash.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_' || b == b'-')
            && hash.bytes().any(|b| b.is_ascii_digit())
            && hash.bytes().any(|b| b.is_ascii_alphabetic())
    })
}

pub fn clear_static_cache() {
    if let Some(cache) = STATIC_CACHE.get() {
        if let Ok(mut cache) = cache.write() {
            cache.retain(|_| false);
        }
    }
}

pub fn clear_static_cache_under(roots: &[PathBuf]) {
    if roots.is_empty() {
        return;
    }
    if let Some(cache) = STATIC_CACHE.get() {
        if let Ok(mut cache) = cache.write() {
            cache.retain(|path| !roots.iter().any(|root| path.starts_with(root)));
        }
    }
}
// Read once: this runs on every static request and env lookups take a lock.
fn static_cache_max_file_bytes() -> u64 {
    static VALUE: OnceLock<u64> = OnceLock::new();
    *VALUE.get_or_init(|| env_or("DRUST_STATIC_CACHE_MAX_FILE_BYTES", 1_048_576))
}
fn static_cache_max_entries() -> usize {
    env_or("DRUST_STATIC_CACHE_MAX_ENTRIES", 8192)
}
fn static_cache_max_bytes() -> u64 {
    env_or("DRUST_STATIC_CACHE_MAX_BYTES", 128 * 1024 * 1024)
}
fn env_or<T: std::str::FromStr>(name: &str, default: T) -> T {
    std::env::var(name).ok().and_then(|v| v.parse().ok()).unwrap_or(default)
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
        "webmanifest" => "application/manifest+json; charset=utf-8".into(),
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

    #[test]
    fn hashed_build_files_are_cached_for_a_year() {
        let immutable = "public, max-age=31536000, immutable";
        for path in [
            "/home/a/public/build/assets/Access-C4as9d92.js",
            "/home/a/public/build/assets/Access-C4EN5T-s.js",
            "/home/a/public/build/assets/accessGroups-C_MN_82O.css",
            "/var/www/app/dist/js/app.3f2a1b9c.js",
            "/var/www/app/build/static/js/main.8e2f4a1b.chunk.js",
        ] {
            assert_eq!(browser_cache_control(Path::new(path), "application/javascript"), immutable, "{path}");
        }
        for path in [
            "/var/www/site/assets/js/jquery-3.6.0.min.js",
            "/var/www/site/assets/css/bootstrap-datepicker.css",
            "/var/www/site/wp-content/themes/x/app-C4as9d92.js",
            "/var/www/site/assets/img/photo-20240101.jpg",
        ] {
            assert_eq!(browser_cache_control(Path::new(path), "text/css"), "public, max-age=3600", "{path}");
        }
        assert_eq!(browser_cache_control(Path::new("/var/www/site/build/index.html"), "text/html; charset=utf-8"), "no-cache");
    }
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

    fn asset(path: &str) -> StaticAsset {
        StaticAsset {
            path: PathBuf::from(path),
            content_type: "text/plain".into(),
            body: StaticAssetBody::Memory(Bytes::from_static(b"x")),
            etag: String::new(),
            last_modified: SystemTime::UNIX_EPOCH,
        }
    }

    #[test]
    fn full_static_cache_drops_least_recently_used_files() {
        let mut cache = StaticCache::new(10, u64::MAX);
        for index in 0..10 {
            cache.insert(asset(&format!("/f{index}")), 1);
        }
        // /f0 is the oldest insert but was just used, so it must survive.
        cache.items[Path::new("/f0")].touch();
        cache.insert(asset("/f10"), 1);
        assert_eq!(cache.items.len(), 9);
        assert!(cache.items.contains_key(Path::new("/f0")));
        assert!(cache.items.contains_key(Path::new("/f10")));
        assert!(!cache.items.contains_key(Path::new("/f1")));
        assert_eq!(cache.bytes, 9);
    }

    #[test]
    fn static_cache_stays_under_its_byte_limit() {
        let mut cache = StaticCache::new(1000, 100);
        for index in 0..20 {
            cache.insert(asset(&format!("/f{index}")), 10);
        }
        assert!(cache.bytes <= 100);
        assert_eq!(cache.bytes, cache.items.len() as u64 * 10);
        cache.retain(|path| path != Path::new("/f19"));
        assert_eq!(cache.bytes, cache.items.len() as u64 * 10);
    }
}
