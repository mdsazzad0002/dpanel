//! drust's own process supervisor (backlog F4, spec §6.3): systemd's job on
//! systems without systemd. It starts each program, restarts it when it
//! dies (waiting longer after each quick failure, and giving up after five
//! in a row, as systemd's start limit does), writes its output to a log
//! file, and remembers which programs are enabled so they come back when
//! drust starts again after a reboot.
//!
//! Each program has a thread that owns its child process; callers only flip
//! flags and wake it. The children are also put in drust's Job Object on
//! Windows, so they end when drust ends, however it ends.

use std::{
    collections::{BTreeSet, HashMap},
    ffi::OsString,
    fs::{self, File, OpenOptions},
    io::Write,
    path::{Path, PathBuf},
    process::{Child, Command, ExitStatus, Stdio},
    sync::{Arc, Condvar, Mutex, MutexGuard},
    time::{Duration, Instant},
};

use super::{
    PlatformError,
    capabilities::Capability,
    service::{LogStyle, ServiceManager, ServiceStatus},
};

mod units;
#[cfg(test)]
mod tests;

pub use units::LocalUnits;

/// What a program needs to run. `setup` runs once first, until `creates`
/// exists, like MariaDB's data folder.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ProgramSpec {
    pub program: PathBuf,
    pub args: Vec<OsString>,
    pub cwd: Option<PathBuf>,
    pub env: EnvPolicy,
    pub setup: Option<Setup>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Setup {
    pub program: PathBuf,
    pub args: Vec<OsString>,
    pub creates: PathBuf,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum EnvPolicy {
    /// drust's own environment, for drust's own parts (the gateway, the panel's queue).
    Inherit,
    /// Only the allowlist of `platform::runner`, so `DRUST_API_TOKEN` stays in drust.
    Clean,
}

/// The programs the supervisor knows.
pub trait Catalog: Send + Sync + std::fmt::Debug {
    fn names(&self) -> Vec<String>;
    /// `None` when `name` is not a program here; an error when it is one but
    /// cannot run yet, such as MariaDB before it is installed.
    fn spec(&self, name: &str) -> Option<Result<ProgramSpec, String>>;
}

#[derive(Debug, Clone, Copy)]
pub struct Timing {
    /// How often a running child is checked.
    pub poll: Duration,
    /// The first wait before a restart; it doubles after each quick failure.
    pub backoff: Duration,
    pub max_backoff: Duration,
    /// A run at least this long was not a quick failure.
    pub healthy_after: Duration,
    /// Quick failures in a row before the program is left `failed`.
    pub start_limit: u32,
}

impl Default for Timing {
    fn default() -> Self {
        Self {
            poll: Duration::from_millis(200),
            backoff: Duration::from_secs(1),
            max_backoff: Duration::from_secs(30),
            healthy_after: Duration::from_secs(60),
            start_limit: 5,
        }
    }
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
enum Phase {
    Stopped,
    Starting,
    Running,
    /// Died; waiting before the next start.
    Backoff,
    Failed,
}

impl Phase {
    /// systemd's words, which the panel already understands.
    fn as_str(self) -> &'static str {
        match self {
            Self::Stopped => "inactive",
            Self::Starting | Self::Backoff => "activating",
            Self::Running => "active",
            Self::Failed => "failed",
        }
    }
}

#[derive(Debug)]
struct UnitState {
    /// Should be running now.
    want: bool,
    /// Kill the child and start it again straight away.
    restart: bool,
    phase: Phase,
    restarts: u64,
    worker: bool,
}

#[derive(Debug)]
struct Unit {
    state: Mutex<UnitState>,
    wake: Condvar,
}

impl Unit {
    fn lock(&self) -> MutexGuard<'_, UnitState> {
        self.state.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
    }
}

#[derive(Debug)]
pub struct Supervisor {
    catalog: Box<dyn Catalog>,
    /// Which programs are enabled: `{"enabled": [...]}`.
    state_file: PathBuf,
    log_dir: PathBuf,
    timing: Timing,
    units: Mutex<HashMap<String, Arc<Unit>>>,
    enabled_lock: Mutex<()>,
}

/// Logs over this size move to `<name>.log.1` when the program next starts.
const MAX_LOG_BYTES: u64 = 5 * 1024 * 1024;

impl Supervisor {
    pub fn new(catalog: Box<dyn Catalog>, state_file: PathBuf, log_dir: PathBuf, timing: Timing) -> Arc<Self> {
        Arc::new(Self {
            catalog,
            state_file,
            log_dir,
            timing,
            units: Mutex::new(HashMap::new()),
            enabled_lock: Mutex::new(()),
        })
    }

    /// Starts every enabled program. The first time, with no state saved,
    /// that is every program that can run here.
    pub fn boot(self: &Arc<Self>) {
        let enabled = match self.read_enabled() {
            Some(enabled) => enabled,
            None => {
                let ready: BTreeSet<String> = self
                    .catalog
                    .names()
                    .into_iter()
                    .filter(|name| matches!(self.catalog.spec(name), Some(Ok(_))))
                    .collect();
                let _ = self.write_enabled(&ready);
                ready
            }
        };
        for name in enabled {
            if let Err(error) = self.start(&name) {
                tracing::warn!(unit = %name, %error, "cannot start");
            }
        }
    }

    /// Stops every program and waits up to `timeout` for them to end, without
    /// changing which are enabled. For drust's own shutdown.
    pub fn stop_all(&self, timeout: Duration) {
        let units: Vec<Arc<Unit>> = self.lock_units().values().cloned().collect();
        for unit in &units {
            unit.lock().want = false;
            unit.wake.notify_all();
        }
        let deadline = Instant::now() + timeout;
        for unit in &units {
            let mut state = unit.lock();
            while matches!(state.phase, Phase::Starting | Phase::Running | Phase::Backoff) {
                let left = deadline.saturating_duration_since(Instant::now());
                if left.is_zero() {
                    break;
                }
                state = unit.wake.wait_timeout(state, left).unwrap_or_else(|poisoned| poisoned.into_inner()).0;
            }
        }
    }

    pub fn start(self: &Arc<Self>, name: &str) -> Result<(), PlatformError> {
        let unit = self.unit(name)?;
        let mut state = unit.lock();
        state.want = true;
        if state.phase == Phase::Failed {
            state.phase = Phase::Stopped;
        }
        self.ensure_worker(name, &unit, &mut state);
        drop(state);
        unit.wake.notify_all();
        Ok(())
    }

    pub fn stop(&self, name: &str) -> Result<(), PlatformError> {
        let unit = self.unit(name)?;
        unit.lock().want = false;
        unit.wake.notify_all();
        Ok(())
    }

    pub fn restart(self: &Arc<Self>, name: &str) -> Result<(), PlatformError> {
        let unit = self.unit(name)?;
        let mut state = unit.lock();
        state.restart = state.want;
        state.want = true;
        if state.phase == Phase::Failed {
            state.phase = Phase::Stopped;
        }
        self.ensure_worker(name, &unit, &mut state);
        drop(state);
        unit.wake.notify_all();
        Ok(())
    }

    pub fn set_enabled(&self, name: &str, on: bool) -> Result<(), PlatformError> {
        self.unit(name)?;
        let _guard = self.enabled_lock.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        let mut enabled = self.read_enabled().unwrap_or_default();
        let changed = if on { enabled.insert(name.to_string()) } else { enabled.remove(name) };
        if changed {
            self.write_enabled(&enabled)
                .map_err(|error| PlatformError::Failed { action: format!("save {}", self.state_file.display()), detail: error })?;
        }
        Ok(())
    }

    pub fn status(&self, name: &str) -> ServiceStatus {
        if self.catalog.spec(name).is_none() {
            return ServiceStatus { installed: false, active: false, enabled: false, state: "not-found".into() };
        }
        let enabled = self.read_enabled().is_some_and(|enabled| enabled.contains(name));
        let phase = self.lock_units().get(name).map_or(Phase::Stopped, |unit| unit.lock().phase);
        ServiceStatus { installed: true, active: phase == Phase::Running, enabled, state: phase.as_str().into() }
    }

    pub fn restart_count(&self, name: &str) -> Result<u64, PlatformError> {
        self.unit(name)?;
        Ok(self.lock_units().get(name).map_or(0, |unit| unit.lock().restarts))
    }

    pub fn reset_failed(&self, name: &str) -> Result<(), PlatformError> {
        let unit = self.unit(name)?;
        let mut state = unit.lock();
        if state.phase == Phase::Failed {
            state.phase = Phase::Stopped;
        }
        Ok(())
    }

    pub fn names(&self, pattern: &str) -> Vec<String> {
        self.catalog.names().into_iter().filter(|name| glob_matches(pattern, name)).collect()
    }

    pub fn log_file(&self, name: &str) -> PathBuf {
        self.log_dir.join(format!("{name}.log"))
    }

    /// The last `lines` lines of the program's log.
    pub fn logs(&self, name: &str, lines: u32) -> Result<String, PlatformError> {
        self.unit(name)?;
        let text = match fs::read(self.log_file(name)) {
            Ok(bytes) => String::from_utf8_lossy(&bytes).into_owned(),
            Err(error) if error.kind() == std::io::ErrorKind::NotFound => String::new(),
            Err(error) => return Err(PlatformError::Failed { action: "read log".into(), detail: error.to_string() }),
        };
        let all: Vec<&str> = text.lines().collect();
        let from = all.len().saturating_sub(lines as usize);
        Ok(all[from..].join("\n"))
    }

    fn lock_units(&self) -> MutexGuard<'_, HashMap<String, Arc<Unit>>> {
        self.units.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
    }

    fn unit(&self, name: &str) -> Result<Arc<Unit>, PlatformError> {
        if self.catalog.spec(name).is_none() {
            return Err(PlatformError::NotFound { what: format!("service {name}") });
        }
        Ok(self
            .lock_units()
            .entry(name.to_string())
            .or_insert_with(|| {
                Arc::new(Unit {
                    state: Mutex::new(UnitState { want: false, restart: false, phase: Phase::Stopped, restarts: 0, worker: false }),
                    wake: Condvar::new(),
                })
            })
            .clone())
    }

    fn ensure_worker(self: &Arc<Self>, name: &str, unit: &Arc<Unit>, state: &mut UnitState) {
        if state.worker {
            return;
        }
        state.worker = true;
        let (supervisor, unit, name) = (Arc::clone(self), Arc::clone(unit), name.to_string());
        std::thread::Builder::new()
            .name(format!("supervise-{name}"))
            .spawn(move || supervisor.work(&name, &unit))
            .expect("cannot start a supervisor thread");
    }

    fn read_enabled(&self) -> Option<BTreeSet<String>> {
        let text = fs::read_to_string(&self.state_file).ok()?;
        let value: serde_json::Value = serde_json::from_str(&text).ok()?;
        Some(value["enabled"].as_array()?.iter().filter_map(|name| name.as_str().map(str::to_string)).collect())
    }

    fn write_enabled(&self, enabled: &BTreeSet<String>) -> Result<(), String> {
        if let Some(parent) = self.state_file.parent() {
            fs::create_dir_all(parent).map_err(|error| error.to_string())?;
        }
        let temporary = self.state_file.with_extension("json.tmp");
        let text = serde_json::to_string_pretty(&serde_json::json!({ "enabled": enabled })).map_err(|e| e.to_string())?;
        fs::write(&temporary, text).map_err(|error| error.to_string())?;
        fs::rename(&temporary, &self.state_file).map_err(|error| error.to_string())
    }

    /// The program's own thread: waits to be wanted, runs it, restarts it.
    fn work(&self, name: &str, unit: &Unit) {
        let mut quick_failures = 0u32;
        loop {
            {
                let mut state = unit.lock();
                while !state.want {
                    if state.phase != Phase::Failed {
                        state.phase = Phase::Stopped;
                    }
                    unit.wake.notify_all();
                    state = unit.wake.wait(state).unwrap_or_else(|poisoned| poisoned.into_inner());
                }
                state.phase = Phase::Starting;
                state.restart = false;
            }
            let child = match self.launch(name) {
                Ok(child) => child,
                Err(error) => {
                    self.note(name, &format!("cannot start: {error}"));
                    tracing::warn!(unit = %name, %error, "cannot start");
                    self.give_up(unit);
                    continue;
                }
            };
            unit.lock().phase = Phase::Running;
            let started = Instant::now();
            match self.watch(unit, child) {
                None => quick_failures = 0,
                Some(status) => {
                    self.note(name, &format!("exited ({status})"));
                    quick_failures = if started.elapsed() >= self.timing.healthy_after { 1 } else { quick_failures + 1 };
                    let mut state = unit.lock();
                    state.restarts += 1;
                    if quick_failures >= self.timing.start_limit {
                        drop(state);
                        self.note(name, "failed too often in a row; not restarting until started again");
                        self.give_up(unit);
                        quick_failures = 0;
                        continue;
                    }
                    state.phase = Phase::Backoff;
                    let wait = self.timing.backoff.saturating_mul(1 << (quick_failures - 1).min(16)).min(self.timing.max_backoff);
                    let deadline = Instant::now() + wait;
                    while state.want && !state.restart {
                        let left = deadline.saturating_duration_since(Instant::now());
                        if left.is_zero() {
                            break;
                        }
                        state = unit.wake.wait_timeout(state, left).unwrap_or_else(|poisoned| poisoned.into_inner()).0;
                    }
                    if state.restart {
                        quick_failures = 0;
                    }
                }
            }
        }
    }

    /// Leaves the program `failed` and not wanted, as systemd does.
    fn give_up(&self, unit: &Unit) {
        let mut state = unit.lock();
        state.phase = Phase::Failed;
        state.want = false;
        unit.wake.notify_all();
    }

    /// Waits for the child to exit (its status) or to be stopped or
    /// restarted (`None`), killing it then.
    fn watch(&self, unit: &Unit, mut child: Child) -> Option<ExitStatus> {
        let mut state = unit.lock();
        loop {
            if let Ok(Some(status)) = child.try_wait() {
                return Some(status);
            }
            if !state.want || state.restart {
                drop(state);
                let _ = child.kill();
                let _ = child.wait();
                return None;
            }
            state = unit.wake.wait_timeout(state, self.timing.poll).unwrap_or_else(|poisoned| poisoned.into_inner()).0;
        }
    }

    fn launch(&self, name: &str) -> Result<Child, String> {
        let spec = self.catalog.spec(name).unwrap_or_else(|| Err("no longer known".into()))?;
        let log = self.open_log(name)?;
        if let Some(setup) = &spec.setup
            && !setup.creates.exists()
        {
            self.note(name, &format!("first start: running {}", setup.program.display()));
            let mut command = self.command(&spec, &setup.program, &setup.args, &log)?;
            let status = command.status().map_err(|error| format!("{}: {error}", setup.program.display()))?;
            if !status.success() || !setup.creates.exists() {
                return Err(format!("setup {} failed ({status})", setup.program.display()));
            }
        }
        self.note(name, &format!("starting {}", spec.program.display()));
        let mut command = self.command(&spec, &spec.program, &spec.args, &log)?;
        let child = command.spawn().map_err(|error| format!("{}: {error}", spec.program.display()))?;
        if let Err(error) = super::process::end_child_with_drust(&child) {
            tracing::warn!(unit = %name, %error, "the child may outlive drust");
        }
        Ok(child)
    }

    fn command(&self, spec: &ProgramSpec, program: &Path, args: &[OsString], log: &File) -> Result<Command, String> {
        let mut command = Command::new(program);
        command.args(args).stdin(Stdio::null());
        command.stdout(log.try_clone().map_err(|error| error.to_string())?);
        command.stderr(log.try_clone().map_err(|error| error.to_string())?);
        if let Some(cwd) = &spec.cwd {
            command.current_dir(cwd);
        }
        if spec.env == EnvPolicy::Clean {
            super::runner::clean_environment(&mut command, |name| std::env::var_os(name));
        }
        super::process::without_console(&mut command);
        Ok(command)
    }

    fn open_log(&self, name: &str) -> Result<File, String> {
        fs::create_dir_all(&self.log_dir).map_err(|error| format!("{}: {error}", self.log_dir.display()))?;
        let path = self.log_file(name);
        if fs::metadata(&path).is_ok_and(|meta| meta.len() > MAX_LOG_BYTES) {
            let _ = fs::rename(&path, path.with_extension("log.1"));
        }
        OpenOptions::new().create(true).append(true).open(&path).map_err(|error| format!("{}: {error}", path.display()))
    }

    /// The supervisor's own line in the program's log.
    fn note(&self, name: &str, message: &str) {
        if let Ok(mut log) = self.open_log(name) {
            let _ = writeln!(log, "[{} drust] {message}", chrono::Local::now().format("%Y-%m-%d %H:%M:%S"));
        }
    }
}

/// `*` matches any run of characters; nothing else is special.
fn glob_matches(pattern: &str, name: &str) -> bool {
    let parts: Vec<&str> = pattern.split('*').collect();
    if parts.len() == 1 {
        return pattern == name;
    }
    let (first, last) = (parts[0], parts[parts.len() - 1]);
    if !name.starts_with(first) || !name[first.len()..].ends_with(last) {
        return false;
    }
    let mut rest = &name[first.len()..name.len() - last.len()];
    for part in &parts[1..parts.len() - 1] {
        match rest.find(part) {
            Some(index) => rest = &rest[index + part.len()..],
            None => return false,
        }
    }
    true
}

/// `ServiceManager` on systems without systemd, backed by one supervisor.
#[derive(Debug)]
pub struct SupervisorServices(pub Arc<Supervisor>);

impl ServiceManager for SupervisorServices {
    fn status(&self, unit: &str) -> Result<ServiceStatus, PlatformError> {
        Ok(self.0.status(unit))
    }

    fn start(&self, unit: &str) -> Result<(), PlatformError> {
        self.0.start(unit)
    }

    fn restart(&self, unit: &str) -> Result<(), PlatformError> {
        self.0.restart(unit)
    }

    /// No program here reloads in place; a restart is the same to callers.
    fn reload(&self, unit: &str) -> Result<(), PlatformError> {
        self.0.restart(unit)
    }

    fn reload_or_restart(&self, unit: &str) -> Result<(), PlatformError> {
        self.0.restart(unit)
    }

    fn restart_count(&self, unit: &str) -> Result<u64, PlatformError> {
        self.0.restart_count(unit)
    }

    fn enable(&self, unit: &str) -> Result<(), PlatformError> {
        self.0.set_enabled(unit, true)
    }

    fn switch(&self, units: &[&str], on: bool) -> Result<(), PlatformError> {
        for unit in units {
            self.0.set_enabled(unit, on)?;
            if on { self.0.start(unit)? } else { self.0.stop(unit)? }
        }
        Ok(())
    }

    fn reset_failed(&self, unit: &str) -> Result<(), PlatformError> {
        self.0.reset_failed(unit)
    }

    /// Definitions are code here; nothing to reload.
    fn reload_definitions(&self) -> Result<(), PlatformError> {
        Ok(())
    }

    fn set_runtime_properties(&self, _unit: &str, _properties: &[String]) -> Result<(), PlatformError> {
        Err(PlatformError::Unsupported { capability: Capability::ResourceLimits })
    }

    fn unit_names(&self, pattern: &str) -> Result<Vec<String>, PlatformError> {
        Ok(self.0.names(pattern))
    }

    /// Every style gives the log's last lines; the supervisor's own lines
    /// carry their time.
    fn logs(&self, unit: &str, lines: u32, _style: LogStyle) -> Result<String, PlatformError> {
        self.0.logs(unit, lines)
    }

    fn run_detached(&self, _unit: &str, _program: &Path, _args: &[String]) -> Result<(), PlatformError> {
        Err(PlatformError::Unsupported { capability: Capability::Services })
    }
}
