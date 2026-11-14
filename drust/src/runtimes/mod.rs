//! Software dPanel installs for itself where the OS gives it no package
//! manager to rely on (backlog F3): PHP and MariaDB on Windows, from
//! `manifest.json`. Every archive is checked against the manifest's SHA-256
//! before anything in it is unpacked; a mismatch stops the install and leaves
//! nothing behind.
//!
//! A package lands in `runtime_dir/<name>/<version>`, the folder
//! `platform::paths()` already looks in for `php.exe`, `php-cgi.exe` and the
//! MariaDB tools.

use std::{
    fs,
    path::{Path, PathBuf},
};

use serde::{Deserialize, Serialize};

mod cli;
mod install;
mod jobs;
#[cfg(test)]
mod tests;

pub use cli::run as cli;
pub use install::{InstallError, Outcome, Stage, install};
pub use jobs::{JobState, StartError, job, start};

/// The packages dPanel knows how to install.
pub const NAMES: &[&str] = &["php", "mariadb"];

/// Written into an installed package's folder; its hash says which archive
/// the folder came from, so a second install of the same one does nothing.
const MARKER: &str = ".dpanel-runtime.json";

const BUILT_IN: &str = include_str!("manifest.json");

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Package {
    pub name: String,
    /// The series a site asks for, like `8.3`; also the folder name.
    pub version: String,
    /// The exact upstream release, like `8.3.36-1`.
    pub release: String,
    /// `std::env::consts::OS` and `ARCH` of the system it runs on.
    pub os: String,
    pub arch: String,
    /// Lowercase hex of the archive's SHA-256.
    pub sha256: String,
    /// The folder inside the archive that becomes the package's folder;
    /// empty when the files sit at the top.
    #[serde(default)]
    pub root: String,
    /// Files that must be in the package, relative to it.
    pub check: Vec<String>,
    /// A program in the package and its arguments, run once after unpacking
    /// to prove it starts. Empty to skip.
    #[serde(default)]
    pub probe: Vec<String>,
    /// Mirrors, tried in order until one answers.
    pub urls: Vec<String>,
}

impl Package {
    pub fn key(&self) -> String {
        format!("{}/{}", self.name, self.version)
    }

    fn for_this_system(&self) -> bool {
        self.os == std::env::consts::OS && self.arch == std::env::consts::ARCH
    }
}

#[derive(Debug, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Manifest {
    pub schema: u32,
    pub packages: Vec<Package>,
}

impl Manifest {
    /// The built-in manifest, or the file `DRUST_RUNTIME_MANIFEST` names.
    pub fn load() -> Result<Self, String> {
        match std::env::var_os("DRUST_RUNTIME_MANIFEST") {
            Some(path) => {
                let text = fs::read_to_string(&path)
                    .map_err(|error| format!("cannot read {}: {error}", PathBuf::from(&path).display()))?;
                Self::parse(&text)
            }
            None => Self::parse(BUILT_IN),
        }
    }

    pub fn parse(text: &str) -> Result<Self, String> {
        let manifest: Self = serde_json::from_str(text).map_err(|error| format!("invalid runtime manifest: {error}"))?;
        if manifest.schema != 1 {
            return Err(format!("runtime manifest schema {} is not supported", manifest.schema));
        }
        let mut seen = std::collections::HashSet::new();
        for package in &manifest.packages {
            validate(package).map_err(|error| format!("runtime manifest, {}: {error}", package.key()))?;
            if !seen.insert((package.key(), &package.os, &package.arch)) {
                return Err(format!("runtime manifest lists {} twice", package.key()));
            }
        }
        Ok(manifest)
    }

    /// The packages this OS and CPU can run.
    pub fn for_this_system(&self) -> impl Iterator<Item = &Package> {
        self.packages.iter().filter(|package| package.for_this_system())
    }

    pub fn find(&self, name: &str, version: &str) -> Option<&Package> {
        self.for_this_system().find(|package| package.name == name && package.version == version)
    }
}

fn validate(package: &Package) -> Result<(), String> {
    if !NAMES.contains(&package.name.as_str()) {
        return Err("unknown package name".into());
    }
    if !valid_version(&package.version) {
        return Err("version must look like 8.3".into());
    }
    if package.release.is_empty() || package.os.is_empty() || package.arch.is_empty() {
        return Err("release, os and arch are required".into());
    }
    if package.sha256.len() != 64 || !package.sha256.bytes().all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f')) {
        return Err("sha256 must be 64 lowercase hex characters".into());
    }
    if package.urls.is_empty() || package.urls.iter().any(|url| !url.starts_with("https://")) {
        return Err("every url must be https".into());
    }
    if package.check.is_empty() {
        return Err("check must name at least one file".into());
    }
    let names = std::iter::once(&package.root)
        .filter(|root| !root.is_empty())
        .chain(&package.check)
        .chain(package.probe.first());
    for name in names {
        if !crate::app::archive::safe_relative(name) {
            return Err(format!("{name} is not a path inside the package"));
        }
    }
    Ok(())
}

/// `8.3`: two numbers, nothing else, so it is safe as a folder name.
pub fn valid_version(version: &str) -> bool {
    let Some((major, minor)) = version.split_once('.') else {
        return false;
    };
    let number = |part: &str| !part.is_empty() && part.len() <= 3 && part.bytes().all(|byte| byte.is_ascii_digit());
    number(major) && number(minor)
}

pub fn package_dir(runtime_dir: &Path, name: &str, version: &str) -> PathBuf {
    runtime_dir.join(name).join(version)
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct Installed {
    pub release: String,
    pub sha256: String,
}

/// What dPanel installed in that folder, if anything.
pub fn installed(runtime_dir: &Path, name: &str, version: &str) -> Option<Installed> {
    let text = fs::read_to_string(package_dir(runtime_dir, name, version).join(MARKER)).ok()?;
    serde_json::from_str(&text).ok()
}

#[derive(Debug, Serialize)]
pub struct Status {
    pub name: String,
    pub version: String,
    /// The release the manifest offers.
    pub release: String,
    /// The release in place, if any.
    pub installed: Option<String>,
    /// Installed, but from another archive than the manifest's.
    pub update_available: bool,
    pub path: PathBuf,
    pub job: Option<JobState>,
}

/// Every package this system can run, with what is installed and any install
/// under way.
pub fn statuses(manifest: &Manifest, runtime_dir: &Path) -> Vec<Status> {
    manifest
        .for_this_system()
        .map(|package| {
            let current = installed(runtime_dir, &package.name, &package.version);
            Status {
                name: package.name.clone(),
                version: package.version.clone(),
                release: package.release.clone(),
                update_available: current.as_ref().is_some_and(|current| current.sha256 != package.sha256),
                installed: current.map(|current| current.release),
                path: package_dir(runtime_dir, &package.name, &package.version),
                job: job(&package.key()),
            }
        })
        .collect()
}
