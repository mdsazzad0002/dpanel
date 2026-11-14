//! Installs started from the API run in the background; the panel polls
//! `GET /api/v1/runtimes` for their state. The state lives in memory, so a
//! drust restart forgets a finished or failed job (the installed package is
//! still seen on disk) and abandons a running one, whose staging folder the
//! next install of that version clears.

use std::{
    collections::HashMap,
    path::PathBuf,
    sync::{Mutex, OnceLock},
    time::Duration,
};

use serde::Serialize;

use super::{Outcome, Package, Stage, install};

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
#[serde(tag = "state", rename_all = "snake_case")]
pub enum JobState {
    Downloading { received: u64, total: Option<u64> },
    Unpacking,
    Installed { release: String },
    Failed { error: String },
}

impl JobState {
    fn running(&self) -> bool {
        matches!(self, Self::Downloading { .. } | Self::Unpacking)
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum StartError {
    /// That package is already being installed.
    Running,
}

fn jobs() -> &'static Mutex<HashMap<String, JobState>> {
    static JOBS: OnceLock<Mutex<HashMap<String, JobState>>> = OnceLock::new();
    JOBS.get_or_init(Default::default)
}

fn set(key: &str, state: JobState) {
    jobs().lock().unwrap_or_else(|poisoned| poisoned.into_inner()).insert(key.to_string(), state);
}

pub fn job(key: &str) -> Option<JobState> {
    jobs().lock().unwrap_or_else(|poisoned| poisoned.into_inner()).get(key).cloned()
}

pub fn client() -> reqwest::Client {
    reqwest::Client::builder()
        .connect_timeout(Duration::from_secs(30))
        .read_timeout(Duration::from_secs(120))
        .user_agent(concat!("dPanel-drust/", env!("CARGO_PKG_VERSION")))
        .build()
        .unwrap_or_default()
}

/// Starts installing `package` on the current Tokio runtime.
pub fn start(runtime_dir: PathBuf, package: Package) -> Result<(), StartError> {
    let key = package.key();
    {
        let mut jobs = jobs().lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        if jobs.get(&key).is_some_and(JobState::running) {
            return Err(StartError::Running);
        }
        jobs.insert(key.clone(), JobState::Downloading { received: 0, total: None });
    }
    tokio::spawn(async move {
        let progress = |stage: Stage| {
            set(
                &key,
                match stage {
                    Stage::Downloading { received, total } => JobState::Downloading { received, total },
                    Stage::Unpacking => JobState::Unpacking,
                },
            )
        };
        let state = match install(&client(), &runtime_dir, &package, &progress).await {
            Ok(Outcome::Installed | Outcome::AlreadyInstalled) => JobState::Installed { release: package.release.clone() },
            Err(error) => {
                tracing::warn!(package = %key, %error, "runtime install failed");
                JobState::Failed { error: error.to_string() }
            }
        };
        set(&key, state);
    });
    Ok(())
}
