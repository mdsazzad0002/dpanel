use std::{
    collections::HashMap,
    fs,
    net::SocketAddr,
    path::{Path, PathBuf},
    process::Stdio,
    sync::{Arc, Mutex, OnceLock},
    time::{Duration, Instant},
};

use tokio::{net::TcpStream, time::timeout};

/// Gunicorn's own default is one sync worker, which handles a single request
/// at a time; every other request queues behind it.
pub const DEFAULT_PYTHON_WORKERS: u16 = 4;
const MAX_PYTHON_WORKERS: u16 = 32;

/// How long a successful liveness probe is trusted. Probing opens a real
/// connection to gunicorn, and a sync worker spends an accept on it, so doing
/// it on every request roughly doubled the connections the app had to serve.
const LIVENESS_TTL: Duration = Duration::from_secs(5);

/// ASGI frameworks cannot run under gunicorn's default (WSGI) sync worker;
/// these get uvicorn's worker class instead.
const ASGI_PACKAGES: &[&str] = &["fastapi", "starlette", "quart", "litestar"];

/// Gunicorn settings for live sites: recycle workers periodically so slow
/// memory leaks cannot grow forever, and keep the worker heartbeat in RAM so
/// a busy disk cannot make the master think workers hung.
const PRODUCTION_GUNICORN_ARGS: &str =
    "--max-requests 1000 --max-requests-jitter 100 --worker-tmp-dir /dev/shm";
const DEVELOPMENT_GUNICORN_ARGS: &str = "--reload --log-level debug";

fn liveness_cache() -> &'static Mutex<HashMap<u16, Instant>> {
    static CACHE: OnceLock<Mutex<HashMap<u16, Instant>>> = OnceLock::new();
    CACHE.get_or_init(|| Mutex::new(HashMap::new()))
}

fn recently_live(port: u16) -> bool {
    liveness_cache()
        .lock()
        .ok()
        .and_then(|cache| cache.get(&port).copied())
        .is_some_and(|seen| seen.elapsed() < LIVENESS_TTL)
}

fn mark_live(port: u16) {
    if let Ok(mut cache) = liveness_cache().lock() {
        cache.insert(port, Instant::now());
    }
}

/// Drops the cached liveness for `port`, so the next request probes (and if
/// needed restarts) the app instead of trusting a stale "up".
pub fn forget_python_liveness(port: u16) {
    if let Ok(mut cache) = liveness_cache().lock() {
        cache.remove(&port);
    }
}

/// How a site's gunicorn runs, beyond its entry point.
#[derive(Clone, Copy, Debug, Default, PartialEq, Eq)]
pub struct PythonRunOptions {
    pub workers: Option<u16>,
    /// Development mode reloads workers whenever code changes, so edits show
    /// up without a manual restart. It watches every file, so production
    /// sites leave it off.
    pub development: bool,
    /// Seconds a request may take before gunicorn kills the worker; the
    /// gateway waits slightly longer so the app's own limit fires first.
    pub timeout: Option<u16>,
}

pub const DEFAULT_PYTHON_TIMEOUT: u16 = 30;
const MIN_PYTHON_TIMEOUT: u16 = 10;
const MAX_PYTHON_TIMEOUT: u16 = 300;

impl PythonRunOptions {
    pub fn timeout_seconds(&self) -> u16 {
        self.timeout
            .unwrap_or(DEFAULT_PYTHON_TIMEOUT)
            .clamp(MIN_PYTHON_TIMEOUT, MAX_PYTHON_TIMEOUT)
    }

    /// How long the gateway waits for the app's response head.
    pub fn proxy_timeout(&self) -> Duration {
        Duration::from_secs(u64::from(self.timeout_seconds()) + 5)
    }
}

fn worker_count(workers: Option<u16>) -> u16 {
    match workers {
        Some(workers) if workers > 0 => workers.min(MAX_PYTHON_WORKERS),
        _ => DEFAULT_PYTHON_WORKERS,
    }
}

/// Ensures the systemd-managed gunicorn process for a Python site is running
/// and listening on `port`, provisioning (or repairing) its virtualenv and
/// unit file on demand. Mirrors `node_process::ensure_node_process_running`.
pub async fn ensure_python_process_running(
    site_id: &str,
    owner: &str,
    project_root: &Path,
    entry_module: Option<&str>,
    start_command: Option<&str>,
    python_version: Option<&str>,
    options: PythonRunOptions,
    port: u16,
) -> Result<(), String> {
    if recently_live(port) {
        return Ok(());
    }
    if port_is_listening(port).await {
        mark_live(port);
        return Ok(());
    }

    provision(
        site_id,
        owner,
        project_root,
        entry_module,
        start_command,
        python_version,
        options,
        port,
        false,
    )
    .await?;
    wait_until_listening(site_id, port).await
}

/// Rewrites the site's unit from its current settings (so a changed worker
/// count or start command takes effect) and restarts it.
#[allow(clippy::too_many_arguments)]
pub async fn reprovision_and_restart_python_process(
    site_id: &str,
    owner: &str,
    project_root: &Path,
    entry_module: Option<&str>,
    start_command: Option<&str>,
    python_version: Option<&str>,
    options: PythonRunOptions,
    port: u16,
) -> Result<(), String> {
    provision(
        site_id,
        owner,
        project_root,
        entry_module,
        start_command,
        python_version,
        options,
        port,
        true,
    )
    .await?;
    restart_python_process(site_id)?;
    wait_until_listening(site_id, port).await
}

#[allow(clippy::too_many_arguments)]
async fn provision(
    site_id: &str,
    owner: &str,
    project_root: &Path,
    entry_module: Option<&str>,
    start_command: Option<&str>,
    python_version: Option<&str>,
    options: PythonRunOptions,
    port: u16,
    force: bool,
) -> Result<(), String> {
    let owner = validate_system_user(owner)?;
    let site_id = site_id.to_string();
    let project_root = project_root.to_path_buf();
    let entry_module = entry_module.map(str::to_string);
    let start_command = start_command.map(str::to_string);
    let python_version = python_version.map(str::to_string);

    tokio::task::spawn_blocking(move || {
        ensure_unit_provisioned(
            &site_id,
            &owner,
            &project_root,
            entry_module.as_deref(),
            start_command.as_deref(),
            python_version.as_deref(),
            options,
            port,
            force,
        )
    })
    .await
    .map_err(|error| format!("python process provision worker failed: {error}"))?
}

async fn wait_until_listening(site_id: &str, port: u16) -> Result<(), String> {
    let unit_name = unit_name(site_id);
    forget_python_liveness(port);
    for _ in 0..60 {
        if port_is_listening(port).await {
            mark_live(port);
            return Ok(());
        }
        tokio::time::sleep(Duration::from_millis(250)).await;
    }
    Err(format!(
        "python process for site {unit_name} did not start listening on port {port}"
    ))
}

async fn port_is_listening(port: u16) -> bool {
    let addr = SocketAddr::from(([127, 0, 0, 1], port));
    timeout(Duration::from_millis(300), TcpStream::connect(addr))
        .await
        .is_ok_and(|result| result.is_ok())
}

pub fn unit_name(site_id: &str) -> String {
    format!("dpanel-python-{site_id}")
}

/// Stops a site's process without deleting its systemd unit. The gateway
/// will not bring it back up on the next request as long as the caller
/// also marks the site's `python_process_status` as stopped, since only that
/// flag (not this call) is what keeps the route out of `RouteAction::Proxy`.
pub fn stop_python_process(site_id: &str) -> Result<(), String> {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return Ok(());
    }
    run_systemctl(&["stop", &unit])
}

pub fn restart_python_process(site_id: &str) -> Result<(), String> {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return Err(format!("python process {unit} has not been provisioned yet"));
    }
    run_systemctl(&["restart", &unit])
}

pub struct PythonProcessStatus {
    pub unit_exists: bool,
    pub active_state: String,
    pub listening: bool,
}

pub async fn python_process_status(site_id: &str, port: u16) -> PythonProcessStatus {
    let unit = unit_name(site_id);
    if !unit_file_exists(&unit) {
        return PythonProcessStatus {
            unit_exists: false,
            active_state: "not-provisioned".to_string(),
            listening: false,
        };
    }
    let active_state = std::process::Command::new("systemctl")
        .args(["is-active", &unit])
        .output()
        .map(|output| String::from_utf8_lossy(&output.stdout).trim().to_string())
        .unwrap_or_else(|_| "unknown".to_string());
    PythonProcessStatus {
        unit_exists: true,
        active_state,
        listening: port_is_listening(port).await,
    }
}

fn unit_file_exists(unit: &str) -> bool {
    Path::new(&format!("/etc/systemd/system/{unit}.service")).is_file()
}

/// Provisioning (venv creation, `pip install`) can take minutes, so the lock
/// is per site: one site's slow install must not stall every other Python
/// site's cold start behind it.
fn site_provision_lock(site_id: &str) -> Result<Arc<Mutex<()>>, String> {
    static PROVISION_LOCKS: OnceLock<Mutex<HashMap<String, Arc<Mutex<()>>>>> = OnceLock::new();
    let mut locks = PROVISION_LOCKS
        .get_or_init(|| Mutex::new(HashMap::new()))
        .lock()
        .map_err(|_| "python process provision lock table is poisoned".to_string())?;
    Ok(locks.entry(site_id.to_string()).or_default().clone())
}

#[allow(clippy::too_many_arguments)]
fn ensure_unit_provisioned(
    site_id: &str,
    owner: &str,
    project_root: &Path,
    entry_module: Option<&str>,
    start_command: Option<&str>,
    python_version: Option<&str>,
    options: PythonRunOptions,
    port: u16,
    force: bool,
) -> Result<(), String> {
    let workers = worker_count(options.workers);
    let (app_env, flask_debug, mode_args) = if options.development {
        ("development", 1, DEVELOPMENT_GUNICORN_ARGS)
    } else {
        ("production", 0, PRODUCTION_GUNICORN_ARGS)
    };
    let gunicorn_cmd_args = format!("--timeout {} {mode_args}", options.timeout_seconds());
    let lock = site_provision_lock(site_id)?;
    let _guard = lock
        .lock()
        .map_err(|_| "python process provision lock is poisoned".to_string())?;

    // Requests that queued behind a cold start find the app already up and
    // must not re-run pip and systemctl one after another.
    if !force && port_is_listening_blocking(port) {
        return Ok(());
    }

    if !project_root.is_dir() {
        return Err(format!(
            "project root is unavailable: {}",
            project_root.display()
        ));
    }

    let python_binary = resolve_python_binary(python_version)?;
    let venv_path = project_root.join(".venv");
    let app_dir = resolve_app_dir(project_root, entry_module);
    ensure_virtualenv(&python_binary, &venv_path)?;
    let asgi = requirements_use_asgi(&app_dir);
    install_dependencies(&venv_path, &app_dir, asgi)?;
    let worker_class = if asgi {
        " --worker-class uvicorn.workers.UvicornWorker"
    } else {
        ""
    };

    let gunicorn_bin = venv_path.join("bin").join("gunicorn");
    let exec_start = match start_command {
        Some(command) if !command.trim().is_empty() => {
            format!("/bin/bash -lc {}", shell_quote(command.trim()))
        }
        _ => {
            let module = entry_module
                .map(str::trim)
                .filter(|value| !value.is_empty())
                .ok_or_else(|| "python entry module (WSGI app path) is not configured".to_string())?;
            format!(
                "{} {} --bind 127.0.0.1:{port} --workers {workers}{worker_class}",
                gunicorn_bin.display(),
                shell_quote(module)
            )
        }
    };

    let unit_name = unit_name(site_id);
    let unit_path = PathBuf::from(format!("/etc/systemd/system/{unit_name}.service"));
    let content = format!(
        "; Managed dynamically by drust edge gateway. Do not edit by hand.\n\
         [Unit]\n\
         Description=dPanel Python site {site_id}\n\
         After=network.target\n\
         StartLimitIntervalSec=0\n\
         \n\
         [Service]\n\
         Type=simple\n\
         User={owner}\n\
         Group={owner}\n\
         WorkingDirectory={workdir}\n\
         Environment=PORT={port}\n\
         Environment=PYTHONUNBUFFERED=1\n\
         Environment=WEB_CONCURRENCY={workers}\n\
         Environment=APP_ENV={app_env}\n\
         Environment=FLASK_DEBUG={flask_debug}\n\
         Environment=\"GUNICORN_CMD_ARGS={gunicorn_cmd_args}\"\n\
         ExecStart={exec_start}\n\
         LimitNOFILE=65536\n\
         Restart=always\n\
         RestartSec=3\n\
         StandardOutput=journal\n\
         StandardError=journal\n\
         \n\
         [Install]\n\
         WantedBy=multi-user.target\n",
        workdir = app_dir.display(),
        // Tuning goes through the environment so it also reaches gunicorn
        // when a custom start command is used; flags on the command line win.
    );

    let existing = fs::read_to_string(&unit_path).ok();
    let needs_write = existing.as_deref() != Some(content.as_str());
    if needs_write {
        fs::write(&unit_path, &content)
            .map_err(|error| format!("cannot write systemd unit {}: {error}", unit_path.display()))?;
        run_systemctl(&["daemon-reload"])?;
    }

    // A unit that crashed (e.g. before the code was fixed) sits in "failed";
    // clear that so this start is not refused.
    let _ = run_systemctl(&["reset-failed", &unit_name]);
    run_systemctl(&["enable", "--now", &unit_name])
}

fn port_is_listening_blocking(port: u16) -> bool {
    let addr = SocketAddr::from(([127, 0, 0, 1], port));
    std::net::TcpStream::connect_timeout(&addr, Duration::from_millis(300)).is_ok()
}

/// Directories checked, in order, for files under `/static/` (Django's
/// `collectstatic` output, then the conventional Flask/Django source dir).
const STATIC_DIRS: &[&str] = &["staticfiles", "static"];

/// Finds the file a `/static/...` request names in the site's own static
/// directory, so the gateway can serve it without a gunicorn worker. Returns
/// None (the app handles it) when the file is not there. `/media/` is left
/// to the app on purpose: uploads may sit behind the app's access checks.
pub fn python_static_file(
    project_root: &Path,
    entry_module: Option<&str>,
    request_path: &str,
) -> Option<PathBuf> {
    let relative = request_path.strip_prefix("/static/")?;
    if relative.is_empty() {
        return None;
    }
    let app_dir = resolve_app_dir(project_root, entry_module);
    STATIC_DIRS.iter().find_map(|dir| {
        let root = app_dir.join(dir).canonicalize().ok()?;
        let candidate = super::resolve_static_path(&root, relative, "", false)?;
        // drust runs as root: never follow a symlink out of the static dir.
        let candidate = candidate.canonicalize().ok()?;
        (candidate.starts_with(&root) && candidate.is_file()).then_some(candidate)
    })
}

/// Picks the directory gunicorn runs from. The venv lives in the home dir
/// (`project_root`), but site code is normally uploaded to `public_html`, so
/// prefer whichever directory actually contains the entry module.
fn resolve_app_dir(project_root: &Path, entry_module: Option<&str>) -> PathBuf {
    let public_html = project_root.join("public_html");
    let module = entry_module
        .and_then(|value| value.split(':').next())
        .map(str::trim)
        .filter(|value| !value.is_empty() && !value.starts_with('-'));
    let has_module = |dir: &Path| match module {
        Some(module) => {
            let relative = module.replace('.', "/");
            dir.join(format!("{relative}.py")).is_file()
                || dir.join(&relative).join("__init__.py").is_file()
        }
        None => dir.join("requirements.txt").is_file(),
    };
    if !has_module(project_root) && public_html.is_dir() && has_module(&public_html) {
        public_html
    } else {
        project_root.to_path_buf()
    }
}

/// Creates the site's virtualenv under `{project_root}/.venv` if it doesn't
/// already exist. Idempotent: re-provisioning a redeployed site is a no-op
/// here unless the venv was removed.
fn ensure_virtualenv(python_binary: &Path, venv_path: &Path) -> Result<(), String> {
    if venv_path.join("bin").join("python").is_file() {
        return Ok(());
    }

    let output = std::process::Command::new(python_binary)
        .args(["-m", "venv"])
        .arg(venv_path)
        .output()
        .map_err(|error| format!("cannot create virtualenv at {}: {error}", venv_path.display()))?;
    if !output.status.success() {
        return Err(format!(
            "virtualenv creation failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    Ok(())
}

/// Installs `requirements.txt` (if present) plus gunicorn into the site's
/// virtualenv. Unlike Node's `npm install`, this runs automatically as part
/// of provisioning since there is no separate "install dependencies" step
/// exposed for Python sites.
/// True when requirements.txt pulls in an ASGI framework (e.g. FastAPI).
fn requirements_use_asgi(app_dir: &Path) -> bool {
    let Ok(requirements) = fs::read_to_string(app_dir.join("requirements.txt")) else {
        return false;
    };
    requirements.lines().any(|line| {
        let name: String = line
            .trim()
            .chars()
            .take_while(|character| character.is_ascii_alphanumeric() || matches!(character, '-' | '_' | '.'))
            .collect::<String>()
            .to_ascii_lowercase()
            .replace('_', "-");
        ASGI_PACKAGES.contains(&name.as_str())
    })
}

fn install_dependencies(venv_path: &Path, project_root: &Path, asgi: bool) -> Result<(), String> {
    let pip_bin = venv_path.join("bin").join("pip");
    let requirements = project_root.join("requirements.txt");

    // pip re-resolves every package even when nothing changed, which adds
    // seconds to every start; skip it while requirements.txt is unchanged.
    let installed_marker = venv_path.join(".dpanel-installed-requirements.txt");
    let requirements_content = fs::read(&requirements).ok();
    let requirements_changed =
        requirements_content.is_some() && fs::read(&installed_marker).ok() != requirements_content;

    if requirements_changed {
        let output = std::process::Command::new(&pip_bin)
            .args(["install", "-r"])
            .arg(&requirements)
            .output()
            .map_err(|error| format!("cannot run pip install: {error}"))?;
        if !output.status.success() {
            return Err(format!(
                "pip install -r requirements.txt failed: {}",
                String::from_utf8_lossy(&output.stderr).trim()
            ));
        }
        if let Some(content) = &requirements_content {
            let _ = fs::write(&installed_marker, content);
        }
    }

    let mut missing = Vec::new();
    if !venv_path.join("bin").join("gunicorn").is_file() {
        missing.push("gunicorn");
    }
    if asgi && !venv_path.join("bin").join("uvicorn").is_file() {
        missing.push("uvicorn");
    }
    if !missing.is_empty() {
        let output = std::process::Command::new(&pip_bin)
            .arg("install")
            .args(&missing)
            .output()
            .map_err(|error| format!("cannot install {}: {error}", missing.join(" ")))?;
        if !output.status.success() {
            return Err(format!(
                "pip install {} failed: {}",
                missing.join(" "),
                String::from_utf8_lossy(&output.stderr).trim()
            ));
        }
    }

    Ok(())
}

fn resolve_python_binary(version: Option<&str>) -> Result<PathBuf, String> {
    if let Some(version) = version {
        let pyenv_style = PathBuf::from(format!(
            "/usr/local/pyenv/versions/{version}/bin/python3"
        ));
        if pyenv_style.is_file() {
            return Ok(pyenv_style);
        }
        let minor = version.trim();
        let system_versioned = PathBuf::from(format!("/usr/bin/python{minor}"));
        if system_versioned.is_file() {
            return Ok(system_versioned);
        }
    }
    for candidate in ["/usr/bin/python3", "/usr/local/bin/python3"] {
        let path = PathBuf::from(candidate);
        if path.is_file() {
            return Ok(path);
        }
    }
    Err("no python binary found on this server; install Python or configure DRUST_PYTHON_BIN".to_string())
}

fn shell_quote(value: &str) -> String {
    format!("'{}'", value.replace('\'', "'\\''"))
}

fn validate_system_user(owner: &str) -> Result<String, String> {
    let owner = owner.trim().to_ascii_lowercase();
    if owner.is_empty()
        || !owner
            .chars()
            .all(|character| character.is_ascii_alphanumeric() || matches!(character, '_' | '-'))
    {
        return Err(format!("invalid site owner: {owner}"));
    }
    let status = std::process::Command::new("id")
        .args(["-u", &owner])
        .stdout(Stdio::null())
        .stderr(Stdio::null())
        .status()
        .map_err(|error| format!("cannot validate site owner {owner}: {error}"))?;
    if status.success() {
        Ok(owner)
    } else {
        Err(format!("site owner does not exist: {owner}"))
    }
}

fn run_systemctl(args: &[&str]) -> Result<(), String> {
    let output = std::process::Command::new("systemctl")
        .args(args)
        .output()
        .map_err(|error| format!("cannot run systemctl {args:?}: {error}"))?;
    if output.status.success() {
        Ok(())
    } else {
        Err(format!(
            "systemctl {args:?} failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn quotes_start_commands_safely() {
        assert_eq!(shell_quote("gunicorn app:app"), "'gunicorn app:app'");
        assert_eq!(shell_quote("it's"), "'it'\\''s'");
    }

    #[test]
    fn defaults_to_four_workers_and_caps_the_count() {
        assert_eq!(worker_count(None), 4);
        assert_eq!(worker_count(Some(0)), 4);
        assert_eq!(worker_count(Some(8)), 8);
        assert_eq!(worker_count(Some(500)), MAX_PYTHON_WORKERS);
    }

    #[test]
    fn serves_static_files_only_from_inside_the_static_dir() {
        let root = std::env::temp_dir().join(format!("drust-pystatic-{}", std::process::id()));
        let static_dir = root.join("staticfiles").join("css");
        fs::create_dir_all(&static_dir).unwrap();
        fs::write(root.join("app.py"), "").unwrap();
        fs::write(root.join("secret.txt"), "no").unwrap();
        fs::write(static_dir.join("site.css"), "body{}").unwrap();
        #[cfg(unix)]
        std::os::unix::fs::symlink(root.join("secret.txt"), static_dir.join("leak.css")).unwrap();

        let found = python_static_file(&root, Some("app:app"), "/static/css/site.css");
        assert_eq!(found, Some(static_dir.join("site.css").canonicalize().unwrap()));
        assert_eq!(python_static_file(&root, Some("app:app"), "/static/missing.css"), None);
        assert_eq!(python_static_file(&root, Some("app:app"), "/static/../secret.txt"), None);
        assert_eq!(python_static_file(&root, Some("app:app"), "/static/css/leak.css"), None);
        assert_eq!(python_static_file(&root, Some("app:app"), "/static/css"), None);
        assert_eq!(python_static_file(&root, Some("app:app"), "/media/site.css"), None);
        fs::remove_dir_all(&root).unwrap();
    }

    #[test]
    fn timeout_defaults_and_clamps() {
        let options = |timeout| PythonRunOptions { timeout, ..Default::default() };
        assert_eq!(options(None).timeout_seconds(), 30);
        assert_eq!(options(Some(2)).timeout_seconds(), 10);
        assert_eq!(options(Some(120)).timeout_seconds(), 120);
        assert_eq!(options(Some(9000)).timeout_seconds(), 300);
        assert_eq!(options(Some(120)).proxy_timeout(), Duration::from_secs(125));
    }

    #[test]
    fn detects_asgi_frameworks_in_requirements() {
        let dir = std::env::temp_dir().join(format!("drust-asgi-{}", std::process::id()));
        fs::create_dir_all(&dir).unwrap();
        fs::write(dir.join("requirements.txt"), "flask==3.0\ngunicorn\n").unwrap();
        assert!(!requirements_use_asgi(&dir));
        fs::write(dir.join("requirements.txt"), "# api\nFastAPI>=0.110\n").unwrap();
        assert!(requirements_use_asgi(&dir));
        fs::write(dir.join("requirements.txt"), "fastapi-utils\n").unwrap();
        assert!(!requirements_use_asgi(&dir));
        fs::remove_dir_all(&dir).unwrap();
    }

    #[test]
    fn liveness_is_trusted_briefly_and_can_be_forgotten() {
        assert!(!recently_live(65001));
        mark_live(65001);
        assert!(recently_live(65001));
        forget_python_liveness(65001);
        assert!(!recently_live(65001));
    }

    #[test]
    fn provision_locks_are_per_site() {
        let a = site_provision_lock("lock-test-a").unwrap();
        let _held = a.lock().unwrap();
        let b = site_provision_lock("lock-test-b").unwrap();
        assert!(b.try_lock().is_ok());
        assert!(Arc::ptr_eq(&a, &site_provision_lock("lock-test-a").unwrap()));
    }

    #[test]
    fn rejects_unsafe_site_owner_names() {
        assert!(validate_system_user("../root").is_err());
        assert!(validate_system_user("bad name").is_err());
    }

    #[test]
    fn runs_from_public_html_when_entry_module_lives_there() {
        let root = std::env::temp_dir().join(format!("drust-pyapp-{}", std::process::id()));
        let public_html = root.join("public_html");
        fs::create_dir_all(&public_html).unwrap();
        fs::write(public_html.join("app.py"), "").unwrap();
        assert_eq!(resolve_app_dir(&root, Some("app:app")), public_html);

        fs::write(root.join("app.py"), "").unwrap();
        assert_eq!(resolve_app_dir(&root, Some("app:app")), root);
        fs::remove_dir_all(&root).unwrap();
    }
}
