//! Download, check, unpack, swap into place.
//!
//! The archive is hashed while it streams to disk and compared with the
//! manifest before it is opened. A wrong hash ends the install: another
//! mirror is not tried, because a mismatch means the manifest and upstream
//! disagree, and that needs a person to look. Only a mirror that does not
//! answer is skipped.

use std::{
    fmt, fs, io,
    path::Path,
    process::{Command, Stdio},
    time::{Duration, Instant},
};

use futures_util::StreamExt;
use sha2::{Digest, Sha256};
use tokio::io::AsyncWriteExt;

use super::{Installed, MARKER, Package, installed, package_dir};

/// Bigger than any PHP or MariaDB zip (MariaDB's is about 90 MB), so a
/// mirror that streams forever cannot fill the disk.
const MAX_ARCHIVE_BYTES: u64 = 1 << 30;

/// How long the probe may take before it counts as failed.
const PROBE_TIMEOUT: Duration = Duration::from_secs(30);

/// Windows' "a DLL was not found" exit code, which is what PHP and MariaDB
/// give without the Visual C++ runtime.
const DLL_NOT_FOUND: i32 = 0xC000_0135_u32 as i32;

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum InstallError {
    /// The archive is not the one the manifest names. Nothing was unpacked.
    HashMismatch { expected: String, actual: String },
    /// No mirror gave the archive.
    Download(String),
    /// The archive could not be unpacked or put in place.
    Unpack(String),
    /// The unpacked package is missing a file or does not start.
    Check(String),
}

impl fmt::Display for InstallError {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        match self {
            Self::HashMismatch { expected, actual } => write!(
                f,
                "the download's SHA-256 is {actual}, but the manifest expects {expected}; nothing was installed"
            ),
            Self::Download(error) => write!(f, "download failed: {error}"),
            Self::Unpack(error) => write!(f, "cannot install: {error}"),
            Self::Check(error) => write!(f, "the package is not usable: {error}"),
        }
    }
}

impl std::error::Error for InstallError {}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Stage {
    Downloading { received: u64, total: Option<u64> },
    Unpacking,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Outcome {
    Installed,
    /// The same archive was already in place; nothing was downloaded.
    AlreadyInstalled,
}

/// Installs `package` into `runtime_dir/<name>/<version>`, replacing what is
/// there only once the new copy has passed every check.
pub async fn install(
    client: &reqwest::Client,
    runtime_dir: &Path,
    package: &Package,
    progress: &(dyn Fn(Stage) + Send + Sync),
) -> Result<Outcome, InstallError> {
    let target = package_dir(runtime_dir, &package.name, &package.version);
    if already_in_place(runtime_dir, package, &target) {
        return Ok(Outcome::AlreadyInstalled);
    }
    let parent = runtime_dir.join(&package.name);
    fs::create_dir_all(&parent).map_err(|error| InstallError::Unpack(format!("{}: {error}", parent.display())))?;
    let archive = parent.join(format!(".download-{}-{}.zip", package.version, std::process::id()));

    let downloaded = download(client, package, &archive, progress).await;
    let result = match downloaded {
        Ok(()) => {
            progress(Stage::Unpacking);
            let (archive, parent, package) = (archive.clone(), parent.clone(), package.clone());
            tokio::task::spawn_blocking(move || unpack(&archive, &parent, &package))
                .await
                .unwrap_or_else(|error| Err(InstallError::Unpack(format!("the unpack task stopped: {error}"))))
        }
        Err(error) => Err(error),
    };
    let _ = fs::remove_file(&archive);
    result.map(|()| Outcome::Installed)
}

fn already_in_place(runtime_dir: &Path, package: &Package, target: &Path) -> bool {
    installed(runtime_dir, &package.name, &package.version).is_some_and(|current| current.sha256 == package.sha256)
        && package.check.iter().all(|file| target.join(file).is_file())
}

async fn download(
    client: &reqwest::Client,
    package: &Package,
    into: &Path,
    progress: &(dyn Fn(Stage) + Send + Sync),
) -> Result<(), InstallError> {
    let mut failures = Vec::new();
    for url in &package.urls {
        match fetch(client, url, into, progress).await {
            Ok(actual) if actual == package.sha256 => return Ok(()),
            Ok(actual) => {
                let _ = fs::remove_file(into);
                return Err(InstallError::HashMismatch { expected: package.sha256.clone(), actual });
            }
            Err(error) => {
                let _ = fs::remove_file(into);
                failures.push(format!("{url}: {error}"));
            }
        }
    }
    Err(InstallError::Download(failures.join("; ")))
}

/// Streams `url` into `into` and gives the SHA-256 of what arrived.
async fn fetch(
    client: &reqwest::Client,
    url: &str,
    into: &Path,
    progress: &(dyn Fn(Stage) + Send + Sync),
) -> Result<String, String> {
    let response = client
        .get(url)
        .send()
        .await
        .and_then(reqwest::Response::error_for_status)
        .map_err(|error| error.to_string())?;
    let total = response.content_length();
    if total.is_some_and(|total| total > MAX_ARCHIVE_BYTES) {
        return Err("the archive is larger than 1 GiB".into());
    }
    let mut file = tokio::fs::File::create(into).await.map_err(|error| error.to_string())?;
    let mut hasher = Sha256::new();
    let mut received = 0u64;
    progress(Stage::Downloading { received, total });
    let mut body = response.bytes_stream();
    while let Some(chunk) = body.next().await {
        let chunk = chunk.map_err(|error| error.to_string())?;
        received += chunk.len() as u64;
        if received > MAX_ARCHIVE_BYTES {
            return Err("the archive is larger than 1 GiB".into());
        }
        hasher.update(&chunk);
        file.write_all(&chunk).await.map_err(|error| error.to_string())?;
        progress(Stage::Downloading { received, total });
    }
    file.flush().await.map_err(|error| error.to_string())?;
    Ok(hasher.finalize().iter().map(|byte| format!("{byte:02x}")).collect())
}

/// Unpacks into a staging folder beside the target, checks it, then swaps it in.
fn unpack(archive: &Path, parent: &Path, package: &Package) -> Result<(), InstallError> {
    let staging = parent.join(format!(".staging-{}", package.version));
    remove_dir_if_there(&staging).map_err(InstallError::Unpack)?;
    let result = unpack_into(archive, &staging, parent, package);
    let _ = fs::remove_dir_all(&staging);
    result
}

fn unpack_into(archive: &Path, staging: &Path, parent: &Path, package: &Package) -> Result<(), InstallError> {
    crate::app::archive::extract_all(archive, staging).map_err(InstallError::Unpack)?;
    let content = if package.root.is_empty() { staging.to_path_buf() } else { staging.join(&package.root) };
    if !content.is_dir() {
        return Err(InstallError::Unpack(format!("the archive has no {} folder", package.root)));
    }
    for file in &package.check {
        if !content.join(file).is_file() {
            return Err(InstallError::Check(format!("{file} is not in the archive")));
        }
    }
    probe(&content, &package.probe)?;
    let marker = Installed { release: package.release.clone(), sha256: package.sha256.clone() };
    let marker = serde_json::to_vec_pretty(&marker).map_err(|error| InstallError::Unpack(error.to_string()))?;
    fs::write(content.join(MARKER), marker).map_err(|error| InstallError::Unpack(error.to_string()))?;
    swap_in(&content, parent, package)
}

/// Runs the package's probe; a program that does not start means the
/// package would not work, most often for want of the Visual C++ runtime.
fn probe(content: &Path, probe: &[String]) -> Result<(), InstallError> {
    let Some((program, arguments)) = probe.split_first() else {
        return Ok(());
    };
    let mut child = Command::new(content.join(program))
        .args(arguments)
        .current_dir(content)
        .stdin(Stdio::null())
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .spawn()
        .map_err(|error| InstallError::Check(format!("cannot run {program}: {error}")))?;
    let started = Instant::now();
    let status = loop {
        match child.try_wait() {
            Ok(Some(status)) => break status,
            Ok(None) if started.elapsed() < PROBE_TIMEOUT => std::thread::sleep(Duration::from_millis(50)),
            Ok(None) => {
                let _ = child.kill();
                let _ = child.wait();
                return Err(InstallError::Check(format!("{program} did not finish in 30 seconds")));
            }
            Err(error) => return Err(InstallError::Check(format!("{program}: {error}"))),
        }
    };
    match status.code() {
        Some(0) => Ok(()),
        Some(DLL_NOT_FOUND) => Err(InstallError::Check(format!(
            "{program} cannot start because a DLL is missing; install the Microsoft Visual C++ Redistributable (x64) and try again"
        ))),
        code => Err(InstallError::Check(format!(
            "{program} did not start (exit {})",
            code.map_or_else(|| "by signal".to_string(), |code| code.to_string())
        ))),
    }
}

/// Moves the current copy aside, puts the new one in, and only then deletes
/// the old one; if the new one cannot go in, the old one comes back.
fn swap_in(content: &Path, parent: &Path, package: &Package) -> Result<(), InstallError> {
    let target = parent.join(&package.version);
    let old = parent.join(format!(".old-{}", package.version));
    remove_dir_if_there(&old).map_err(InstallError::Unpack)?;
    let had_one = target.exists();
    if had_one {
        fs::rename(&target, &old).map_err(|error| {
            InstallError::Unpack(format!(
                "cannot replace {} {}: {}",
                package.name,
                package.version,
                crate::platform::fs::describe(&error)
            ))
        })?;
    }
    if let Err(error) = fs::rename(content, &target) {
        if had_one {
            let _ = fs::rename(&old, &target);
        }
        return Err(InstallError::Unpack(format!("{}: {}", target.display(), crate::platform::fs::describe(&error))));
    }
    if had_one {
        let _ = fs::remove_dir_all(&old);
    }
    Ok(())
}

fn remove_dir_if_there(path: &Path) -> Result<(), String> {
    match fs::remove_dir_all(path) {
        Err(error) if error.kind() != io::ErrorKind::NotFound => {
            Err(format!("{}: {}", path.display(), crate::platform::fs::describe(&error)))
        }
        _ => Ok(()),
    }
}
