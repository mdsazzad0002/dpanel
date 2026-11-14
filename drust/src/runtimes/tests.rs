use std::{
    io::Write,
    net::TcpListener,
    path::Path,
    sync::{
        Arc,
        atomic::{AtomicUsize, Ordering},
    },
};

use axum::{Router, extract::State, http::StatusCode, response::IntoResponse, routing::get};
use sha2::{Digest, Sha256};

use super::*;

fn zip_of(files: &[(&str, &[u8])]) -> Vec<u8> {
    let mut writer = zip::ZipWriter::new(std::io::Cursor::new(Vec::new()));
    for (name, body) in files {
        writer.start_file(*name, zip::write::SimpleFileOptions::default()).unwrap();
        writer.write_all(body).unwrap();
    }
    writer.finish().unwrap().into_inner()
}

fn sha256(bytes: &[u8]) -> String {
    Sha256::digest(bytes).iter().map(|byte| format!("{byte:02x}")).collect()
}

struct Mirror {
    base: String,
    hits: Arc<AtomicUsize>,
}

/// Serves `body` at `/good.zip`; `/missing.zip` is a 404. Counts requests.
fn mirror(body: Vec<u8>) -> Mirror {
    let hits = Arc::new(AtomicUsize::new(0));
    let listener = TcpListener::bind("127.0.0.1:0").unwrap();
    listener.set_nonblocking(true).unwrap();
    let base = format!("http://{}", listener.local_addr().unwrap());
    let state = (Arc::new(body), hits.clone());
    let app = Router::new()
        .route(
            "/good.zip",
            get(|State((body, hits)): State<(Arc<Vec<u8>>, Arc<AtomicUsize>)>| async move {
                hits.fetch_add(1, Ordering::SeqCst);
                body.as_ref().clone().into_response()
            }),
        )
        .route("/missing.zip", get(|| async { StatusCode::NOT_FOUND }))
        .with_state(state);
    tokio::spawn(async move {
        axum::serve(tokio::net::TcpListener::from_std(listener).unwrap(), app).await.unwrap();
    });
    Mirror { base, hits }
}

fn package(sha256: String, root: &str, urls: Vec<String>) -> Package {
    Package {
        name: "php".into(),
        version: "8.3".into(),
        release: "8.3.36".into(),
        os: std::env::consts::OS.into(),
        arch: std::env::consts::ARCH.into(),
        sha256,
        root: root.into(),
        check: vec!["php.exe".into(), "ext/php_curl.dll".into()],
        probe: Vec::new(),
        urls,
    }
}

fn php_zip(prefix: &str, marker: &[u8]) -> Vec<u8> {
    zip_of(&[
        (&format!("{prefix}php.exe"), marker),
        (&format!("{prefix}ext/php_curl.dll"), b"dll"),
    ])
}

async fn run(runtime: &Path, package: &Package) -> Result<Outcome, InstallError> {
    install(&jobs::client(), runtime, package, &|_| {}).await
}

/// Nothing but the installed folder is left: no download, no staging.
fn leftovers(runtime: &Path) -> Vec<String> {
    fs::read_dir(runtime.join("php"))
        .map(|entries| entries.map(|entry| entry.unwrap().file_name().to_string_lossy().into_owned()).collect())
        .unwrap_or_default()
}

#[tokio::test]
async fn installs_a_package_whose_hash_matches() {
    let body = php_zip("", b"new");
    let mirror = mirror(body.clone());
    let runtime = tempfile::tempdir().unwrap();
    let package = package(sha256(&body), "", vec![format!("{}/good.zip", mirror.base)]);

    assert_eq!(run(runtime.path(), &package).await, Ok(Outcome::Installed));

    let dir = package_dir(runtime.path(), "php", "8.3");
    assert_eq!(fs::read(dir.join("php.exe")).unwrap(), b"new");
    assert_eq!(
        installed(runtime.path(), "php", "8.3"),
        Some(Installed { release: "8.3.36".into(), sha256: sha256(&body) })
    );
    assert_eq!(leftovers(runtime.path()), ["8.3"]);
}

#[tokio::test]
async fn a_wrong_hash_stops_before_anything_is_unpacked() {
    let body = php_zip("", b"tampered");
    let mirror = mirror(body.clone());
    let runtime = tempfile::tempdir().unwrap();
    let expected = sha256(b"something else");
    // The second mirror would serve the same file; it must not be tried.
    let urls = vec![format!("{}/good.zip", mirror.base), format!("{}/good.zip", mirror.base)];
    let package = package(expected.clone(), "", urls);

    let result = run(runtime.path(), &package).await;

    assert_eq!(result, Err(InstallError::HashMismatch { expected, actual: sha256(&body) }));
    assert_eq!(mirror.hits.load(Ordering::SeqCst), 1);
    assert!(!package_dir(runtime.path(), "php", "8.3").exists());
    assert!(leftovers(runtime.path()).is_empty());
}

#[tokio::test]
async fn a_wrong_hash_keeps_the_installed_copy() {
    let good = php_zip("", b"old");
    let runtime = tempfile::tempdir().unwrap();
    let first = mirror(good.clone());
    run(runtime.path(), &package(sha256(&good), "", vec![format!("{}/good.zip", first.base)])).await.unwrap();

    let bad = mirror(php_zip("", b"tampered"));
    let result = run(runtime.path(), &package(sha256(b"newer"), "", vec![format!("{}/good.zip", bad.base)])).await;

    assert!(matches!(result, Err(InstallError::HashMismatch { .. })));
    assert_eq!(fs::read(package_dir(runtime.path(), "php", "8.3").join("php.exe")).unwrap(), b"old");
}

#[tokio::test]
async fn a_mirror_that_fails_is_skipped() {
    let body = php_zip("", b"new");
    let mirror = mirror(body.clone());
    let runtime = tempfile::tempdir().unwrap();
    let urls = vec![format!("{}/missing.zip", mirror.base), format!("{}/good.zip", mirror.base)];

    assert_eq!(run(runtime.path(), &package(sha256(&body), "", urls)).await, Ok(Outcome::Installed));
}

#[tokio::test]
async fn every_mirror_failing_says_why() {
    let mirror = mirror(Vec::new());
    let runtime = tempfile::tempdir().unwrap();
    let urls = vec![format!("{}/missing.zip", mirror.base)];

    let result = run(runtime.path(), &package(sha256(b""), "", urls)).await;

    assert!(matches!(&result, Err(InstallError::Download(error)) if error.contains("404")), "{result:?}");
    assert!(leftovers(runtime.path()).is_empty());
}

#[tokio::test]
async fn takes_the_package_out_of_its_root_folder() {
    let body = php_zip("mariadb-11.8.9-winx64/", b"new");
    let mirror = mirror(body.clone());
    let runtime = tempfile::tempdir().unwrap();
    let package = package(sha256(&body), "mariadb-11.8.9-winx64", vec![format!("{}/good.zip", mirror.base)]);

    run(runtime.path(), &package).await.unwrap();

    assert!(package_dir(runtime.path(), "php", "8.3").join("php.exe").is_file());
}

#[tokio::test]
async fn a_package_missing_a_file_is_not_installed() {
    let body = zip_of(&[("php.exe", b"new")]);
    let mirror = mirror(body.clone());
    let runtime = tempfile::tempdir().unwrap();

    let result = run(runtime.path(), &package(sha256(&body), "", vec![format!("{}/good.zip", mirror.base)])).await;

    assert!(matches!(&result, Err(InstallError::Check(error)) if error.contains("php_curl.dll")), "{result:?}");
    assert!(leftovers(runtime.path()).is_empty());
}

#[tokio::test]
async fn an_unsafe_archive_is_not_installed() {
    let body = zip_of(&[("php.exe", b"x"), ("ext/php_curl.dll", b"x"), ("../escape.txt", b"x")]);
    let mirror = mirror(body.clone());
    let runtime = tempfile::tempdir().unwrap();

    let result = run(runtime.path(), &package(sha256(&body), "", vec![format!("{}/good.zip", mirror.base)])).await;

    assert!(matches!(result, Err(InstallError::Unpack(_))), "{result:?}");
    assert!(!runtime.path().join("escape.txt").exists());
    assert!(leftovers(runtime.path()).is_empty());
}

#[tokio::test]
async fn the_same_archive_is_not_downloaded_twice() {
    let body = php_zip("", b"new");
    let mirror = mirror(body.clone());
    let runtime = tempfile::tempdir().unwrap();
    let package = package(sha256(&body), "", vec![format!("{}/good.zip", mirror.base)]);

    run(runtime.path(), &package).await.unwrap();
    assert_eq!(run(runtime.path(), &package).await, Ok(Outcome::AlreadyInstalled));
    assert_eq!(mirror.hits.load(Ordering::SeqCst), 1);
}

#[tokio::test]
async fn a_new_release_replaces_the_old_one() {
    let runtime = tempfile::tempdir().unwrap();
    let old = php_zip("", b"old");
    let first = mirror(old.clone());
    run(runtime.path(), &package(sha256(&old), "", vec![format!("{}/good.zip", first.base)])).await.unwrap();
    fs::write(package_dir(runtime.path(), "php", "8.3").join("stale.txt"), "left by the old release").unwrap();

    let new = zip_of(&[("php.exe", b"new"), ("ext/php_curl.dll", b"dll")]);
    let second = mirror(new.clone());
    let mut newer = package(sha256(&new), "", vec![format!("{}/good.zip", second.base)]);
    newer.release = "8.3.37".into();
    assert_eq!(run(runtime.path(), &newer).await, Ok(Outcome::Installed));

    let dir = package_dir(runtime.path(), "php", "8.3");
    assert_eq!(fs::read(dir.join("php.exe")).unwrap(), b"new");
    assert!(!dir.join("stale.txt").exists());
    assert_eq!(installed(runtime.path(), "php", "8.3").unwrap().release, "8.3.37");
    assert_eq!(leftovers(runtime.path()), ["8.3"]);
}

#[test]
fn the_built_in_manifest_is_valid() {
    let manifest = Manifest::parse(BUILT_IN).unwrap();
    for name in NAMES {
        assert!(manifest.packages.iter().any(|package| &package.name == name), "{name} has no package");
    }
    for package in &manifest.packages {
        assert_eq!((package.os.as_str(), package.arch.as_str()), ("windows", "x86_64"), "{}", package.key());
        assert!(!package.probe.is_empty(), "{} has no probe", package.key());
        assert!(package.release.starts_with(&format!("{}.", package.version)), "{}", package.key());
    }
}

#[test]
fn the_manifest_refuses_unsafe_or_unchecked_entries() {
    let entry = |change: &str| {
        let mut package = serde_json::json!({
            "name": "php", "version": "8.3", "release": "8.3.36", "os": "windows", "arch": "x86_64",
            "sha256": "a".repeat(64), "root": "", "check": ["php.exe"], "probe": ["php.exe", "-v"],
            "urls": ["https://example.com/php.zip"]
        });
        let (key, value) = change.split_once('=').unwrap();
        package[key] = serde_json::from_str(value).unwrap();
        Manifest::parse(&serde_json::json!({ "schema": 1, "packages": [package] }).to_string())
    };
    assert!(entry("root=\"\"").is_ok());
    assert!(entry("urls=[\"http://example.com/php.zip\"]").is_err());
    assert!(entry("urls=[]").is_err());
    assert!(entry("sha256=\"ABC\"").is_err());
    assert!(entry(&format!("sha256=\"{}\"", "A".repeat(64))).is_err());
    assert!(entry("version=\"8.3/../..\"").is_err());
    assert!(entry("version=\"latest\"").is_err());
    assert!(entry("name=\"redis\"").is_err());
    assert!(entry("check=[]").is_err());
    assert!(entry("check=[\"../php.exe\"]").is_err());
    assert!(entry("root=\"/abs\"").is_err());
    assert!(entry("root=\"a/../..\"").is_err());
    assert!(entry("probe=[\"/abs/sh\", \"-c\"]").is_err());
    assert!(Manifest::parse(r#"{"schema": 2, "packages": []}"#).is_err());
    assert!(Manifest::parse(r#"{"schema": 1, "packages": [], "extra": 1}"#).is_err());
}

#[test]
fn only_this_systems_packages_are_offered() {
    let mut other = package(sha256(b""), "", vec!["https://example.com/a.zip".into()]);
    other.os = "plan9".into();
    let mine = package(sha256(b""), "", vec!["https://example.com/b.zip".into()]);
    let manifest = Manifest { schema: 1, packages: vec![other, mine.clone()] };
    assert_eq!(manifest.find("php", "8.3"), Some(&mine));
    assert_eq!(manifest.for_this_system().count(), 1);
}
