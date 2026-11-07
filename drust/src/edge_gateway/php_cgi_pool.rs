//! php-cgi workers for systems without PHP-FPM; `plans/platform-spec.md` section 9.
//!
//! A php-cgi process answers one request at a time and has no master process,
//! so this pool is the master: one process per port, one request per process.
//! A `Lease` is the right to use one process until it is dropped.
//!
//! The pool counts requests and stops a process itself. PHP's own limit stays
//! off: a php-cgi that leaves by itself drops what is waiting on its port, and
//! a request can be sent to it just as it goes (`plans/spikes/H1.md`).

use std::{
    collections::{BTreeSet, HashMap, VecDeque},
    fs,
    net::{Ipv4Addr, SocketAddr, TcpListener},
    ops::RangeInclusive,
    path::{Path, PathBuf},
    process::Stdio,
    sync::{Arc, Mutex, OnceLock, Weak},
    time::Duration,
};

use sha2::{Digest, Sha256};
use tokio::{
    io::{AsyncReadExt, AsyncWriteExt},
    net::TcpStream,
    process::{Child, Command},
    sync::{OwnedSemaphorePermit, Semaphore, oneshot},
    time::{Instant, sleep, sleep_until, timeout, timeout_at},
};
use tracing::{error, info, warn};

use crate::platform::{self, fastcgi::FastCgiEndpoint};

/// What a site's PHP may inherit. drust's own environment holds the API token.
const INHERITED_ENV: &[&str] = &["PATH", "PATHEXT", "SystemDrive", "SystemRoot", "TEMP", "TMP", "windir"];
/// A worker at its request limit is stopped once idle. Its lease also ends when
/// a client walks away, with PHP still running; this is how long PHP then gets.
const RETIRE_GRACE: Duration = Duration::from_secs(2);
/// Windows retries a refused loopback connect for about 500 ms, so a connect
/// made before PHP listens must be given up on, not waited for.
const READY_POLL: Duration = Duration::from_millis(10);
/// What `php -m` shows on a WampServer PHP, less Xdebug, so that a site keeps
/// what it had. A PHP without one of these simply does not get it. `exif`
/// needs `mbstring` loaded first.
const EXTENSIONS: &[&str] = &[
    "redis", "ldap", "curl", "com_dotnet", "fileinfo", "gd", "gettext", "gmp", "intl", "imap", "mbstring",
    "exif", "mysqli", "openssl", "pdo_mysql", "pdo_pgsql", "pdo_sqlite", "pgsql", "soap", "sockets",
    "sodium", "sqlite3", "xsl", "zip",
];
/// The limits a stack's php.ini gives a site, so that uploads and long panel
/// requests keep working. Errors with no `error_log` reach the gateway's log.
const INI_SETTINGS: &str = "opcache.enable=1
post_max_size=32M
upload_max_filesize=20M
max_execution_time=120
max_input_vars=2500
output_buffering=4096
display_errors=On
log_errors=On
error_reporting=E_ALL
date.timezone=UTC
variables_order=GPCS
request_order=GP
";

#[derive(Clone, Debug)]
pub struct PoolSettings {
    pub ports: RangeInclusive<u16>,
    pub max_processes: usize,
    pub max_requests: u32,
    pub idle_timeout: Duration,
    pub queue_timeout: Duration,
    pub crash_limit: usize,
    pub crash_window: Duration,
    pub first_backoff: Duration,
    pub ready_timeout: Duration,
}

impl Default for PoolSettings {
    fn default() -> Self {
        Self {
            ports: 9100..=9999,
            max_processes: 4,
            max_requests: 500,
            idle_timeout: Duration::from_secs(600),
            queue_timeout: Duration::from_secs(30),
            crash_limit: 5,
            crash_window: Duration::from_secs(60),
            first_backoff: Duration::from_millis(200),
            ready_timeout: Duration::from_secs(10),
        }
    }
}

#[derive(Debug, PartialEq, Eq)]
pub enum PoolError {
    /// Every process stayed busy for the whole queue time.
    Busy,
    /// The site's PHP crashed too often; it stays down until the window passes.
    Down,
    Failed(String),
}

type Launch = Arc<dyn Fn(SocketAddr) -> Command + Send + Sync>;
type Ports = Arc<Mutex<BTreeSet<u16>>>;

struct Worker {
    id: u64,
    port: u16,
    busy: bool,
    served: u32,
    last_used: Instant,
    /// Taken once the pool tells it to stop, so its exit is not a crash.
    stop: Option<oneshot::Sender<Duration>>,
}

#[derive(Default)]
struct State {
    workers: Vec<Worker>,
    crashes: VecDeque<Instant>,
    next_id: u64,
}

impl State {
    fn forget_old_crashes(&mut self, window: Duration) {
        while self.crashes.front().is_some_and(|at| at.elapsed() >= window) {
            self.crashes.pop_front();
        }
    }

    fn record_crash(&mut self, site: &str, limit: usize) {
        self.crashes.push_back(Instant::now());
        if self.crashes.len() == limit {
            error!(site, crashes = limit, "PHP keeps crashing; answering 503 until it calms down");
        }
    }

    /// Doubles with every recent crash.
    fn backoff_left(&self, first: Duration) -> Duration {
        let Some(last) = self.crashes.back() else {
            return Duration::ZERO;
        };
        let shift = (self.crashes.len() - 1).min(10) as u32;
        (first * 2u32.pow(shift)).saturating_sub(last.elapsed())
    }
}

fn lock(state: &Mutex<State>) -> std::sync::MutexGuard<'_, State> {
    state.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}

pub struct Lease {
    state: Arc<Mutex<State>>,
    id: u64,
    port: u16,
    max_requests: u32,
    _slot: OwnedSemaphorePermit,
}

impl Lease {
    pub fn endpoint(&self) -> FastCgiEndpoint {
        FastCgiEndpoint::Tcp(SocketAddr::from((Ipv4Addr::LOCALHOST, self.port)))
    }
}

impl Drop for Lease {
    fn drop(&mut self) {
        let mut state = lock(&self.state);
        // Gone already if it crashed during the request.
        let Some(worker) = state.workers.iter_mut().find(|worker| worker.id == self.id) else {
            return;
        };
        worker.busy = false;
        worker.last_used = Instant::now();
        if worker.served >= self.max_requests {
            if let Some(stop) = worker.stop.take() {
                info!(port = self.port, served = worker.served, "php-cgi recycled at its request limit");
                let _ = stop.send(RETIRE_GRACE);
            }
        }
    }
}

pub struct SitePool {
    site: String,
    settings: PoolSettings,
    launch: Launch,
    ports: Ports,
    state: Arc<Mutex<State>>,
    slots: Arc<Semaphore>,
}

impl SitePool {
    fn new(site: String, settings: PoolSettings, launch: Launch, ports: Ports) -> Self {
        let state = Arc::new(Mutex::new(State::default()));
        spawn_idle_reaper(Arc::downgrade(&state), settings.idle_timeout);
        let slots = Arc::new(Semaphore::new(settings.max_processes));
        Self { site, settings, launch, ports, state, slots }
    }

    pub async fn acquire(&self) -> Result<Lease, PoolError> {
        let deadline = Instant::now() + self.settings.queue_timeout;
        let slot = timeout_at(deadline, self.slots.clone().acquire_owned())
            .await
            .map_err(|_| PoolError::Busy)?
            .map_err(|error| PoolError::Failed(error.to_string()))?;
        loop {
            let backoff = {
                let mut state = lock(&self.state);
                state.forget_old_crashes(self.settings.crash_window);
                if state.crashes.len() >= self.settings.crash_limit {
                    return Err(PoolError::Down);
                }
                let max_requests = self.settings.max_requests;
                let idle = state.workers.iter_mut().find(|worker| {
                    !worker.busy && worker.stop.is_some() && worker.served < max_requests
                });
                if let Some(worker) = idle {
                    worker.busy = true;
                    worker.served += 1;
                    let (id, port) = (worker.id, worker.port);
                    return Ok(self.lease(id, port, slot));
                }
                state.backoff_left(self.settings.first_backoff)
            };
            if !backoff.is_zero() {
                if Instant::now() + backoff >= deadline {
                    return Err(PoolError::Busy);
                }
                sleep(backoff).await;
                continue;
            }
            match self.start_worker().await {
                Ok((id, port)) => return Ok(self.lease(id, port, slot)),
                Err(reason) => {
                    warn!(site = %self.site, %reason, "php-cgi did not start");
                    if Instant::now() >= deadline {
                        return Err(PoolError::Failed(reason));
                    }
                }
            }
        }
    }

    fn lease(&self, id: u64, port: u16, slot: OwnedSemaphorePermit) -> Lease {
        Lease {
            state: self.state.clone(),
            id,
            port,
            max_requests: self.settings.max_requests,
            _slot: slot,
        }
    }

    /// Starts a process, already leased to the caller.
    async fn start_worker(&self) -> Result<(u64, u16), String> {
        let port = reserve_port(&self.ports, &self.settings.ports)?;
        let address = SocketAddr::from((Ipv4Addr::LOCALHOST, port));
        let mut command = (self.launch)(address);
        command
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .kill_on_drop(true);
        let child = match command.spawn() {
            Ok(child) => child,
            Err(error) => {
                release_port(&self.ports, port);
                let mut state = lock(&self.state);
                state.record_crash(&self.site, self.settings.crash_limit);
                return Err(format!("cannot run php-cgi: {error}"));
            }
        };
        let (stop, stopped) = oneshot::channel();
        let id = {
            let mut state = lock(&self.state);
            state.next_id += 1;
            let id = state.next_id;
            state.workers.push(Worker {
                id,
                port,
                busy: true,
                served: 1,
                last_used: Instant::now(),
                stop: Some(stop),
            });
            id
        };
        tokio::spawn(watch_worker(
            child,
            stopped,
            self.state.clone(),
            self.ports.clone(),
            self.site.clone(),
            id,
            port,
            self.settings.clone(),
        ));

        let give_up = Instant::now() + self.settings.ready_timeout;
        loop {
            if !lock(&self.state).workers.iter().any(|worker| worker.id == id) {
                return Err(format!("php-cgi exited while starting on port {port}"));
            }
            let next_try = Instant::now() + READY_POLL;
            if is_listening(address).await {
                return Ok((id, port));
            }
            if Instant::now() >= give_up {
                let mut state = lock(&self.state);
                if let Some(worker) = state.workers.iter_mut().find(|worker| worker.id == id) {
                    if let Some(stop) = worker.stop.take() {
                        let _ = stop.send(Duration::ZERO);
                    }
                }
                state.record_crash(&self.site, self.settings.crash_limit);
                return Err(format!("php-cgi never listened on port {port}"));
            }
            sleep_until(next_try).await;
        }
    }
}

async fn is_listening(address: SocketAddr) -> bool {
    matches!(timeout(READY_POLL, TcpStream::connect(address)).await, Ok(Ok(_)))
}

/// Returns once PHP has no request running. A busy php-cgi leaves a connection
/// in its backlog; it only takes this one, finds it empty and closes it between
/// requests.
async fn php_went_idle(port: u16) {
    if let Ok(mut stream) = TcpStream::connect((Ipv4Addr::LOCALHOST, port)).await {
        let _ = stream.shutdown().await;
        let _ = stream.read(&mut [0]).await;
    }
}

/// Owns the child: notices it exit, and stops it when told to.
#[allow(clippy::too_many_arguments)]
async fn watch_worker(
    mut child: Child,
    mut stopped: oneshot::Receiver<Duration>,
    state: Arc<Mutex<State>>,
    ports: Ports,
    site: String,
    id: u64,
    port: u16,
    settings: PoolSettings,
) {
    let status = tokio::select! {
        status = child.wait() => status,
        grace = &mut stopped => {
            let _ = timeout(grace.unwrap_or(Duration::ZERO), php_went_idle(port)).await;
            let _ = child.kill().await;
            child.wait().await
        }
    };
    {
        let mut state = lock(&state);
        if let Some(index) = state.workers.iter().position(|worker| worker.id == id) {
            let worker = state.workers.remove(index);
            // PHP has no limit of its own, so leaving unasked is always a crash.
            if worker.stop.is_some() {
                warn!(%site, port, ?status, "php-cgi crashed; the next request restarts it");
                state.record_crash(&site, settings.crash_limit);
            }
        }
    }
    release_port(&ports, port);
}

fn spawn_idle_reaper(state: Weak<Mutex<State>>, idle_timeout: Duration) {
    let tick = (idle_timeout / 4).clamp(Duration::from_millis(10), Duration::from_secs(30));
    tokio::spawn(async move {
        loop {
            sleep(tick).await;
            let Some(state) = state.upgrade() else {
                return;
            };
            let mut state = lock(&state);
            for worker in &mut state.workers {
                if !worker.busy && worker.last_used.elapsed() >= idle_timeout {
                    if let Some(stop) = worker.stop.take() {
                        let _ = stop.send(Duration::ZERO);
                    }
                }
            }
        }
    });
}

/// The bind is made without SO_REUSEADDR on purpose. php-cgi sets it, and on
/// Windows that lets two listeners share a port, so its own bind proves nothing.
fn reserve_port(ports: &Ports, range: &RangeInclusive<u16>) -> Result<u16, String> {
    let mut taken = ports.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    for port in range.clone() {
        if !taken.contains(&port) && TcpListener::bind((Ipv4Addr::LOCALHOST, port)).is_ok() {
            taken.insert(port);
            return Ok(port);
        }
    }
    Err(format!("no free PHP port in {}-{}", range.start(), range.end()))
}

fn release_port(ports: &Ports, port: u16) {
    ports.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).remove(&port);
}

/// The ini of a PHP whose extensions are in `ext_dir`. Xdebug is left out, and
/// paths use `/` because a php.ini string treats `\` as an escape.
fn php_ini_text(ext_dir: &Path, is_file: impl Fn(&Path) -> bool) -> String {
    let dir = ext_dir.to_string_lossy().replace('\\', "/");
    let has = |name: &str| is_file(&ext_dir.join(format!("php_{name}.dll")));
    let mut text = format!("; Written by drust for php-cgi; changes are overwritten.\nextension_dir=\"{dir}\"\n");
    for name in EXTENSIONS.iter().filter(|name| has(name)) {
        text.push_str(&format!("extension={name}\n"));
    }
    if has("opcache") {
        text.push_str("zend_extension=opcache\n");
    }
    text + INI_SETTINGS
}

/// Without `-c` php-cgi reads the php.ini beside it, which on a stack such as
/// WampServer loads Xdebug. Replaced in one step: another drust may be reading.
fn write_php_ini(binary: &Path, version: &str, data_dir: &Path) -> Result<PathBuf, String> {
    let ext_dir = binary.parent().unwrap_or(Path::new("")).join("ext");
    let text = php_ini_text(&ext_dir, Path::is_file);
    let directory = data_dir.join("php-cgi");
    let ini = directory.join(format!("php{version}.ini"));
    if fs::read_to_string(&ini).is_ok_and(|current| current == text) {
        return Ok(ini);
    }
    let failed = |error| format!("cannot write {}: {error}", ini.display());
    fs::create_dir_all(&directory).map_err(failed)?;
    let draft = directory.join(format!("php{version}.ini.{}", std::process::id()));
    fs::write(&draft, text).map_err(failed)?;
    fs::rename(&draft, &ini).map_err(failed)?;
    Ok(ini)
}

/// Opcache's memory is shared by name, so this keeps a site's out of every
/// other PHP of this user. PHP reads 32 characters of it.
fn opcache_id(site: &str) -> String {
    let digest = Sha256::digest(site.as_bytes());
    digest[..16].iter().map(|byte| format!("{byte:02x}")).collect()
}

fn php_cgi_command(binary: &Path, ini: &Path, opcache_id: &str, address: SocketAddr) -> Command {
    let mut command = Command::new(binary);
    command
        .arg("-c")
        .arg(ini)
        .arg("-d")
        .arg(format!("opcache.cache_id={opcache_id}"))
        .arg("-b")
        .arg(address.to_string())
        .env_clear();
    for name in INHERITED_ENV {
        if let Some(value) = std::env::var_os(name) {
            command.env(name, value);
        }
    }
    // No children: PHP would share one port between them and the pool could
    // no longer tell a free process from a busy one. No limit: the pool counts.
    command.env("PHP_FCGI_CHILDREN", "0").env("PHP_FCGI_MAX_REQUESTS", "0");
    command
}

/// A use of PHP by `site`, on its own pool of that PHP version.
pub async fn lease(site: &str, version: &str) -> Result<Lease, PoolError> {
    static POOLS: OnceLock<Mutex<HashMap<(String, String), Arc<SitePool>>>> = OnceLock::new();
    static PORTS: OnceLock<Ports> = OnceLock::new();
    let pool = {
        let mut pools = POOLS
            .get_or_init(Default::default)
            .lock()
            .unwrap_or_else(|poisoned| poisoned.into_inner());
        let key = (site.to_string(), version.to_string());
        match pools.get(&key) {
            Some(pool) => pool.clone(),
            None => {
                let binary = platform::paths()
                    .php_cgi(version)
                    .map_err(|error| PoolError::Failed(error.to_string()))?;
                let ini = write_php_ini(&binary, version, platform::paths().data_dir())
                    .map_err(PoolError::Failed)?;
                let cache_id = opcache_id(site);
                let launch: Launch =
                    Arc::new(move |address| php_cgi_command(&binary, &ini, &cache_id, address));
                let ports = PORTS.get_or_init(Default::default).clone();
                let settings = PoolSettings::default();
                let pool = Arc::new(SitePool::new(site.to_string(), settings, launch, ports));
                pools.insert(key, pool.clone());
                pool
            }
        }
    };
    pool.acquire().await
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::io::{Read, Write};

    const WORKER_TEST: &str = "edge_gateway::php_cgi_pool::tests::fake_php_worker";

    /// Not a test: the process the other tests start in place of php-cgi.
    /// Each connection that sends a byte is a request; `d` makes it die and
    /// `s` makes it slow. Like php-cgi, it never exits unless told to.
    #[test]
    #[ignore = "started by the pool tests"]
    fn fake_php_worker() {
        let port: u16 = std::env::var("FAKE_PHP_PORT").unwrap().parse().unwrap();
        let exit_after: u32 = std::env::var("FAKE_PHP_EXIT_AFTER").unwrap().parse().unwrap();
        let listener = TcpListener::bind((Ipv4Addr::LOCALHOST, port)).unwrap();
        let mut served = 0;
        for stream in listener.incoming() {
            let mut stream = stream.unwrap();
            let mut byte = [0];
            if !matches!(stream.read(&mut byte), Ok(1)) {
                continue;
            }
            if byte[0] == b'd' {
                std::process::exit(3);
            }
            if byte[0] == b's' {
                std::thread::sleep(Duration::from_millis(300));
            }
            write!(stream, "{}", std::process::id()).unwrap();
            drop(stream);
            served += 1;
            if served == exit_after {
                std::process::exit(0);
            }
        }
    }

    fn pool(first_port: u16, exit_after: u32, settings: PoolSettings) -> SitePool {
        let launch: Launch = Arc::new(move |address| {
            let mut command = Command::new(std::env::current_exe().unwrap());
            command
                .args(["--exact", WORKER_TEST, "--ignored", "--nocapture"])
                .env("FAKE_PHP_PORT", address.port().to_string())
                .env("FAKE_PHP_EXIT_AFTER", exit_after.to_string());
            command
        });
        let settings = PoolSettings {
            ports: first_port..=first_port + 9,
            first_backoff: Duration::from_millis(5),
            ..settings
        };
        SitePool::new("site".into(), settings, launch, Ports::default())
    }

    async fn ask(lease: &Lease, byte: u8) -> String {
        let FastCgiEndpoint::Tcp(address) = lease.endpoint() else {
            panic!("the pool only speaks TCP");
        };
        let mut stream = TcpStream::connect(address).await.unwrap();
        let mut reply = String::new();
        stream.write_all(&[byte]).await.unwrap();
        let _ = stream.read_to_string(&mut reply).await;
        reply
    }

    async fn until(what: &str, check: impl Fn() -> bool) {
        for _ in 0..500 {
            if check() {
                return;
            }
            sleep(Duration::from_millis(10)).await;
        }
        panic!("timed out waiting: {what}");
    }

    fn worker_count(pool: &SitePool) -> usize {
        lock(&pool.state).workers.len()
    }

    #[tokio::test]
    async fn the_pool_stops_a_worker_at_its_limit_without_counting_a_crash() {
        let settings = PoolSettings { max_requests: 2, ..Default::default() };
        let pool = pool(19100, 0, settings);
        let mut seen = Vec::new();
        for _ in 0..2 {
            let lease = pool.acquire().await.unwrap();
            seen.push(ask(&lease, b'r').await);
        }
        assert_eq!(seen[0], seen[1], "the second request reuses the process");
        until("the old process to leave", || worker_count(&pool) == 0).await;
        let lease = pool.acquire().await.unwrap();
        assert_ne!(ask(&lease, b'r').await, seen[0], "the third gets a new process");
        assert!(lock(&pool.state).crashes.is_empty());
    }

    #[tokio::test]
    async fn a_worker_at_its_limit_is_left_alone_until_its_request_ends() {
        let settings = PoolSettings { max_requests: 1, ..Default::default() };
        let pool = pool(19170, 0, settings);
        let lease = pool.acquire().await.unwrap();
        sleep(Duration::from_millis(100)).await;
        assert!(!ask(&lease, b'r').await.is_empty(), "the last request is answered");
        assert_eq!(worker_count(&pool), 1);
        drop(lease);
        until("the used process to stop", || worker_count(&pool) == 0).await;
    }

    #[tokio::test]
    async fn a_request_whose_client_left_is_not_killed_by_the_recycle() {
        let settings = PoolSettings { max_requests: 1, ..Default::default() };
        let pool = pool(19180, 0, settings);
        let lease = pool.acquire().await.unwrap();
        let FastCgiEndpoint::Tcp(address) = lease.endpoint() else {
            panic!("the pool only speaks TCP");
        };
        let mut stream = TcpStream::connect(address).await.unwrap();
        stream.write_all(b"s").await.unwrap();
        drop(lease);
        let mut reply = String::new();
        let _ = stream.read_to_string(&mut reply).await;
        assert!(!reply.is_empty(), "PHP finished its request");
        until("the used process to stop", || worker_count(&pool) == 0).await;
    }

    #[tokio::test]
    async fn a_worker_that_leaves_during_its_last_request_is_a_crash() {
        let settings = PoolSettings { max_requests: 1, ..Default::default() };
        let pool = pool(19190, 1, settings);
        let lease = pool.acquire().await.unwrap();
        ask(&lease, b'r').await;
        until("the exit to be seen", || worker_count(&pool) == 0).await;
        assert_eq!(lock(&pool.state).crashes.len(), 1);
    }

    #[tokio::test]
    async fn a_look_at_a_closed_port_does_not_wait_for_windows_to_give_up() {
        let address = TcpListener::bind((Ipv4Addr::LOCALHOST, 0)).unwrap().local_addr().unwrap();
        let started = Instant::now();
        assert!(!is_listening(address).await);
        assert!(started.elapsed() < Duration::from_millis(250), "took {:?}", started.elapsed());
    }

    #[tokio::test]
    async fn a_crashed_worker_is_restarted_by_the_next_request() {
        let pool = pool(19110, 0, PoolSettings::default());
        let lease = pool.acquire().await.unwrap();
        let first = ask(&lease, b'r').await;
        ask(&lease, b'd').await;
        drop(lease);
        until("the crash to be seen", || worker_count(&pool) == 0).await;
        assert_eq!(lock(&pool.state).crashes.len(), 1);
        let lease = pool.acquire().await.unwrap();
        assert_ne!(ask(&lease, b'r').await, first);
    }

    #[tokio::test]
    async fn too_many_crashes_take_the_site_down_until_the_window_passes() {
        let settings = PoolSettings {
            crash_limit: 3,
            crash_window: Duration::from_secs(3),
            ..Default::default()
        };
        let pool = pool(19120, 0, settings);
        for _ in 0..3 {
            let lease = pool.acquire().await.unwrap();
            ask(&lease, b'd').await;
            drop(lease);
            until("the crash to be seen", || worker_count(&pool) == 0).await;
        }
        assert_eq!(pool.acquire().await.err(), Some(PoolError::Down));
        sleep(Duration::from_secs(3)).await;
        assert!(pool.acquire().await.is_ok());
    }

    #[tokio::test]
    async fn a_full_pool_queues_then_answers_busy() {
        let settings = PoolSettings {
            max_processes: 1,
            queue_timeout: Duration::from_millis(100),
            ..Default::default()
        };
        let pool = pool(19130, 0, settings);
        let lease = pool.acquire().await.unwrap();
        assert_eq!(pool.acquire().await.err(), Some(PoolError::Busy));
        drop(lease);
        assert!(pool.acquire().await.is_ok());
    }

    #[tokio::test]
    async fn an_idle_worker_stops_and_is_not_a_crash() {
        let settings = PoolSettings { idle_timeout: Duration::from_millis(50), ..Default::default() };
        let pool = pool(19140, 0, settings);
        drop(pool.acquire().await.unwrap());
        until("the idle process to stop", || worker_count(&pool) == 0).await;
        assert!(lock(&pool.state).crashes.is_empty());
    }

    #[test]
    fn php_gets_no_secret_from_drusts_environment() {
        let address = SocketAddr::from((Ipv4Addr::LOCALHOST, 19150));
        let command = php_cgi_command(Path::new("php-cgi"), Path::new("p.ini"), "id", address);
        let command = command.as_std();
        assert_eq!(
            command.get_args().collect::<Vec<_>>(),
            ["-c", "p.ini", "-d", "opcache.cache_id=id", "-b", "127.0.0.1:19150"]
        );
        for (name, _) in command.get_envs() {
            let name = name.to_string_lossy();
            assert!(
                INHERITED_ENV.iter().any(|allowed| allowed.eq_ignore_ascii_case(&name))
                    || name.eq_ignore_ascii_case("PHP_FCGI_CHILDREN")
                    || name.eq_ignore_ascii_case("PHP_FCGI_MAX_REQUESTS"),
                "{name} must not reach PHP"
            );
        }
    }


    #[test]
    fn php_has_no_limit_of_its_own() {
        let address = SocketAddr::from((Ipv4Addr::LOCALHOST, 19151));
        let command = php_cgi_command(Path::new("php-cgi"), Path::new("p.ini"), "id", address);
        let limit = command.as_std().get_envs().find(|(name, _)| *name == "PHP_FCGI_MAX_REQUESTS");
        assert_eq!(limit.and_then(|(_, value)| value), Some(std::ffi::OsStr::new("0")));
    }

    #[test]
    fn the_ini_turns_opcache_on_and_loads_only_what_this_php_has() {
        let text = php_ini_text(Path::new(r"C:\php 8\ext"), |path| {
            let name = path.to_string_lossy();
            ["php_mbstring.dll", "php_opcache.dll", "php_xdebug.dll"].iter().any(|dll| name.ends_with(dll))
        });
        assert!(text.contains("extension_dir=\"C:/php 8/ext\"\n"));
        assert!(text.contains("\nextension=mbstring\n"));
        assert!(!text.contains("extension=curl"), "this PHP has no curl");
        assert!(text.contains("\nzend_extension=opcache\n"));
        assert!(text.contains("\nopcache.enable=1\n"));
        assert!(!text.to_lowercase().contains("xdebug"));
    }

    #[test]
    fn the_ini_needs_no_opcache_to_be_valid() {
        let text = php_ini_text(Path::new("ext"), |_| false);
        assert!(!text.contains("zend_extension"));
        assert!(!text.contains("\nextension="));
    }

    #[test]
    fn the_ini_is_written_and_a_change_replaces_it() {
        let data = tempfile::tempdir().unwrap();
        let binary = data.path().join("php-cgi.exe");
        let ini = write_php_ini(&binary, "8.3", data.path()).unwrap();
        assert_eq!(ini, data.path().join("php-cgi").join("php8.3.ini"));
        fs::write(&ini, "stale").unwrap();
        assert_eq!(write_php_ini(&binary, "8.3", data.path()).unwrap(), ini);
        assert!(fs::read_to_string(&ini).unwrap().contains("opcache.enable=1"));
        assert_eq!(fs::read_dir(ini.parent().unwrap()).unwrap().count(), 1, "no draft stays");
    }

    #[test]
    fn each_site_has_its_own_opcache_of_a_length_php_keeps() {
        assert_ne!(opcache_id("one"), opcache_id("two"));
        assert_eq!(opcache_id("one"), opcache_id("one"));
        assert_eq!(opcache_id("a site; with\nodd=things").len(), 32);
        assert!(opcache_id("x").bytes().all(|byte| byte.is_ascii_hexdigit()));
    }

    #[test]
    fn a_port_someone_holds_is_skipped() {
        let holder = TcpListener::bind((Ipv4Addr::LOCALHOST, 19160)).unwrap();
        let ports = Ports::default();
        assert_eq!(reserve_port(&ports, &(19160..=19161)), Ok(19161));
        assert!(reserve_port(&ports, &(19160..=19161)).is_err());
        release_port(&ports, 19161);
        assert_eq!(reserve_port(&ports, &(19160..=19161)), Ok(19161));
        drop(holder);
    }
}
