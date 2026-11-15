use std::{
    collections::HashMap,
    ffi::OsString,
    path::PathBuf,
    sync::Arc,
    time::{Duration, Instant},
};

use super::*;

/// A shell line in this OS's shell.
fn shell(line: &str) -> (PathBuf, Vec<OsString>) {
    if cfg!(windows) {
        ("cmd".into(), vec!["/C".into(), line.into()])
    } else {
        ("sh".into(), vec!["-c".into(), line.into()])
    }
}

/// Prints `started`, then runs until killed.
fn long_running() -> (PathBuf, Vec<OsString>) {
    shell(if cfg!(windows) { "echo started& ping -n 600 127.0.0.1 >NUL" } else { "echo started; exec sleep 600" })
}

fn exits_with(code: u8) -> (PathBuf, Vec<OsString>) {
    shell(&format!("exit {code}"))
}

fn prints_environment() -> (PathBuf, Vec<OsString>) {
    shell(if cfg!(windows) { "set& ping -n 600 127.0.0.1 >NUL" } else { "env; exec sleep 600" })
}

fn spec((program, args): (PathBuf, Vec<OsString>)) -> ProgramSpec {
    ProgramSpec { program, args, cwd: None, env: EnvPolicy::Inherit, setup: None }
}

#[derive(Debug)]
struct TestCatalog(HashMap<String, Result<ProgramSpec, String>>);

impl Catalog for TestCatalog {
    fn names(&self) -> Vec<String> {
        let mut names: Vec<String> = self.0.keys().cloned().collect();
        names.sort();
        names
    }

    fn spec(&self, name: &str) -> Option<Result<ProgramSpec, String>> {
        self.0.get(name).cloned()
    }
}

fn timing() -> Timing {
    Timing {
        poll: Duration::from_millis(20),
        backoff: Duration::from_millis(20),
        max_backoff: Duration::from_millis(100),
        healthy_after: Duration::from_secs(30),
        start_limit: 3,
    }
}

struct Fixture {
    dir: tempfile::TempDir,
}

impl Fixture {
    fn new() -> Self {
        Self { dir: tempfile::tempdir().unwrap() }
    }

    fn supervisor(&self, programs: Vec<(&str, Result<ProgramSpec, String>)>) -> Arc<Supervisor> {
        let catalog = TestCatalog(programs.into_iter().map(|(name, spec)| (name.to_string(), spec)).collect());
        Supervisor::new(
            Box::new(catalog),
            self.dir.path().join("state").join("supervisor.json"),
            self.dir.path().join("logs"),
            timing(),
        )
    }
}

fn wait_for(what: &str, mut done: impl FnMut() -> bool) {
    let deadline = Instant::now() + Duration::from_secs(15);
    while !done() {
        assert!(Instant::now() < deadline, "timed out waiting for {what}");
        std::thread::sleep(Duration::from_millis(20));
    }
}

fn state(supervisor: &Supervisor, name: &str) -> String {
    supervisor.status(name).state
}

#[test]
fn runs_a_program_and_stops_it() {
    let fixture = Fixture::new();
    let supervisor = fixture.supervisor(vec![("web", Ok(spec(long_running())))]);

    supervisor.start("web").unwrap();
    wait_for("the program to run", || supervisor.status("web").active);
    wait_for("its output", || supervisor.logs("web", 50).unwrap().contains("started"));
    assert!(supervisor.logs("web", 50).unwrap().contains("drust] starting"));

    supervisor.stop("web").unwrap();
    wait_for("the program to stop", || state(&supervisor, "web") == "inactive");
    assert!(!supervisor.status("web").active);
}

#[test]
fn a_program_that_dies_is_restarted_then_left_failed() {
    let fixture = Fixture::new();
    let supervisor = fixture.supervisor(vec![("crash", Ok(spec(exits_with(3))))]);

    supervisor.start("crash").unwrap();
    wait_for("the start limit", || state(&supervisor, "crash") == "failed");
    assert_eq!(supervisor.restart_count("crash").unwrap(), 3);
    assert!(supervisor.logs("crash", 50).unwrap().contains("failed too often"));

    // Starting it again is a person's decision, and it runs again.
    supervisor.start("crash").unwrap();
    wait_for("another round", || supervisor.restart_count("crash").unwrap() >= 4);
}

#[test]
fn restart_starts_a_new_process() {
    let fixture = Fixture::new();
    let supervisor = fixture.supervisor(vec![("web", Ok(spec(long_running())))]);
    supervisor.start("web").unwrap();
    wait_for("the first run", || supervisor.status("web").active);

    supervisor.restart("web").unwrap();
    wait_for("the second run", || supervisor.logs("web", 50).unwrap().matches("drust] starting").count() == 2);
    wait_for("it to be back", || supervisor.status("web").active);
    // A restart someone asked for is not a failure.
    assert_eq!(supervisor.restart_count("web").unwrap(), 0);
    supervisor.stop_all(Duration::from_secs(5));
}

#[test]
fn enabled_programs_come_back_after_a_restart_of_drust() {
    let fixture = Fixture::new();
    let programs = || vec![("web", Ok(spec(long_running()))), ("idle", Ok(spec(long_running())))];
    let first = fixture.supervisor(programs());
    first.set_enabled("web", true).unwrap();
    first.boot();
    first.stop_all(Duration::from_secs(5));
    first.set_enabled("idle", false).unwrap();
    assert!(first.status("web").enabled);

    // A new drust, as after a reboot, reads what was enabled.
    let second = fixture.supervisor(programs());
    second.boot();
    wait_for("the enabled program", || second.status("web").active);
    assert_eq!(state(&second, "idle"), "inactive");
    second.stop_all(Duration::from_secs(5));
    assert_eq!(state(&second, "web"), "inactive");
}

#[test]
fn the_first_boot_enables_what_can_run() {
    let fixture = Fixture::new();
    let supervisor = fixture.supervisor(vec![
        ("web", Ok(spec(long_running()))),
        ("mariadb", Err("MariaDB is not installed".into())),
    ]);

    supervisor.boot();

    wait_for("the ready program", || supervisor.status("web").active);
    assert!(supervisor.status("web").enabled);
    let mariadb = supervisor.status("mariadb");
    assert!(mariadb.installed && !mariadb.enabled && !mariadb.active);
    supervisor.stop_all(Duration::from_secs(5));
}

#[test]
fn a_program_that_cannot_run_yet_fails_with_its_reason() {
    let fixture = Fixture::new();
    let supervisor = fixture.supervisor(vec![("mariadb", Err("MariaDB is not installed".into()))]);

    supervisor.start("mariadb").unwrap();

    wait_for("the failure", || state(&supervisor, "mariadb") == "failed");
    assert!(supervisor.logs("mariadb", 10).unwrap().contains("MariaDB is not installed"));
}

#[test]
fn setup_runs_once_until_it_has_made_its_file() {
    let fixture = Fixture::new();
    let marker = fixture.dir.path().join("data").join("my.ini");
    let make = if cfg!(windows) {
        // No quotes: Rust escapes them for cmd's parser, which does not undo it.
        format!("mkdir {0}& echo made> {1}", marker.parent().unwrap().display(), marker.display())
    } else {
        format!("mkdir -p '{}' && echo made > '{}'", marker.parent().unwrap().display(), marker.display())
    };
    let (program, args) = shell(&make);
    let mut with_setup = spec(long_running());
    with_setup.setup = Some(Setup { program, args, creates: marker.clone() });
    let supervisor = fixture.supervisor(vec![("db", Ok(with_setup))]);

    supervisor.start("db").unwrap();
    wait_for("the program", || supervisor.status("db").active);
    supervisor.restart("db").unwrap();
    wait_for("the second run", || supervisor.logs("db", 50).unwrap().matches("drust] starting").count() == 2);

    assert!(marker.is_file());
    assert_eq!(supervisor.logs("db", 50).unwrap().matches("first start").count(), 1);
    supervisor.stop_all(Duration::from_secs(5));
}

#[test]
fn a_setup_that_fails_leaves_the_program_failed() {
    let fixture = Fixture::new();
    let (program, args) = exits_with(1);
    let mut with_setup = spec(long_running());
    with_setup.setup = Some(Setup { program, args, creates: fixture.dir.path().join("never") });
    let supervisor = fixture.supervisor(vec![("db", Ok(with_setup))]);

    supervisor.start("db").unwrap();

    wait_for("the failure", || state(&supervisor, "db") == "failed");
    assert!(supervisor.logs("db", 10).unwrap().contains("setup"));
}

#[test]
fn a_clean_program_does_not_see_drusts_secrets() {
    let fixture = Fixture::new();
    // Cargo sets this for every test run; it is not on the allowlist.
    assert!(std::env::var_os("CARGO_PKG_NAME").is_some());
    let mut clean = spec(prints_environment());
    clean.env = EnvPolicy::Clean;
    let supervisor = fixture.supervisor(vec![("clean", Ok(clean)), ("inherit", Ok(spec(prints_environment())))]);

    supervisor.start("clean").unwrap();
    supervisor.start("inherit").unwrap();
    wait_for("both outputs", || {
        ["clean", "inherit"].iter().all(|name| supervisor.logs(name, 500).unwrap().to_uppercase().contains("PATH="))
    });

    assert!(!supervisor.logs("clean", 500).unwrap().contains("CARGO_PKG_NAME"));
    assert!(supervisor.logs("inherit", 500).unwrap().contains("CARGO_PKG_NAME"));
    supervisor.stop_all(Duration::from_secs(5));
}

#[test]
fn unknown_programs_are_not_found() {
    let fixture = Fixture::new();
    let supervisor = fixture.supervisor(vec![("web", Ok(spec(long_running())))]);
    assert!(matches!(supervisor.start("nope"), Err(PlatformError::NotFound { .. })));
    let status = supervisor.status("nope");
    assert!(!status.installed && status.state == "not-found");
}

#[test]
fn the_service_manager_switches_programs_on_and_off() {
    let fixture = Fixture::new();
    let supervisor = fixture.supervisor(vec![("web", Ok(spec(long_running())))]);
    let services = SupervisorServices(supervisor.clone());

    services.switch(&["web"], true).unwrap();
    wait_for("on", || services.status("web").unwrap().active);
    assert!(services.status("web").unwrap().enabled);

    services.switch(&["web"], false).unwrap();
    wait_for("off", || services.status("web").unwrap().state == "inactive");
    assert!(!services.status("web").unwrap().enabled);
    assert_eq!(services.unit_names("w*").unwrap(), ["web"]);
}

#[test]
fn star_matches_any_run_of_characters() {
    assert!(glob_matches("dpanel-*", "dpanel-queue"));
    assert!(glob_matches("*", "mariadb"));
    assert!(glob_matches("a*c*e", "abcde"));
    assert!(glob_matches("mariadb", "mariadb"));
    assert!(!glob_matches("mariadb", "mariadb2"));
    assert!(!glob_matches("dpanel-*", "edge-gateway"));
    assert!(!glob_matches("a*a", "a"));
}
