//! `drust runtime list` and `drust runtime install <name> <version>`, for
//! the installer and for a person at the console.

use std::{io::Write, process::ExitCode};

use super::{Manifest, Outcome, Stage, install, statuses};

pub fn run(args: &[String]) -> ExitCode {
    let manifest = match Manifest::load() {
        Ok(manifest) => manifest,
        Err(error) => return fail(&error),
    };
    let runtime_dir = crate::platform::paths().runtime_dir().to_path_buf();
    match args.iter().map(String::as_str).collect::<Vec<_>>().as_slice() {
        ["list"] => {
            for status in statuses(&manifest, &runtime_dir) {
                let installed = match (&status.installed, status.update_available) {
                    (Some(release), true) => format!("installed {release}, {} available", status.release),
                    (Some(release), false) => format!("installed {release}"),
                    (None, _) => format!("not installed ({} available)", status.release),
                };
                println!("{:<8} {:<6} {installed}", status.name, status.version);
            }
            ExitCode::SUCCESS
        }
        ["install", name, version] => {
            let Some(package) = manifest.find(name, version) else {
                return fail(&format!("no {name} {version} for this system; see `drust runtime list`"));
            };
            let runtime = match tokio::runtime::Builder::new_current_thread().enable_all().build() {
                Ok(runtime) => runtime,
                Err(error) => return fail(&error.to_string()),
            };
            let shown = std::sync::atomic::AtomicU64::new(u64::MAX);
            let progress = |stage: Stage| match stage {
                Stage::Downloading { received, total: Some(total) } if total > 0 => {
                    let percent = received * 100 / total;
                    if shown.swap(percent, std::sync::atomic::Ordering::Relaxed) != percent {
                        eprint!("\rDownloading {name} {version}: {percent}%");
                        let _ = std::io::stderr().flush();
                    }
                }
                Stage::Downloading { .. } => {}
                Stage::Unpacking => eprintln!("\nUnpacking and checking"),
            };
            let result = runtime.block_on(install(&super::jobs::client(), &runtime_dir, package, &progress));
            match result {
                Ok(Outcome::Installed) => {
                    println!("Installed {name} {} in {}", package.release, super::package_dir(&runtime_dir, name, version).display());
                    ExitCode::SUCCESS
                }
                Ok(Outcome::AlreadyInstalled) => {
                    println!("{name} {} is already installed", package.release);
                    ExitCode::SUCCESS
                }
                Err(error) => fail(&format!("\n{error}")),
            }
        }
        _ => fail("usage: drust runtime list | drust runtime install <php|mariadb> <version>"),
    }
}

fn fail(message: &str) -> ExitCode {
    eprintln!("runtime: {message}");
    ExitCode::from(1)
}
