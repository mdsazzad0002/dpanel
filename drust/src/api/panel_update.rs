//! `GET`, `POST /api/v1/panel-update/runs` and `GET /api/v1/panel-update/runs/{id}`
//! (backlog U6): updating dPanel from the panel, and rolling an update back.
//!
//! An update replaces the panel's code and rebuilds and restarts drust, so it
//! cannot run inside either. drust starts `dscript/scripts/panel-update.sh` as
//! its own short-lived service unit, which outlives both. Each run has a
//! folder, `<state_dir>/panel-update/<id>`, holding a `state` file of
//! `key=value` lines that the script keeps current, the script's `log`, and
//! the `backup` it takes before changing anything. The panel polls this API.

use std::{
    collections::BTreeMap,
    fs,
    io::{Read, Seek, SeekFrom},
    path::{Path, PathBuf},
    sync::Arc,
};

use axum::{
    Router,
    extract::{Json, Path as UrlPath, Query, State},
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::get,
};
use serde::{Deserialize, Serialize};

use super::{ApiResponse, ApiState, check_token};
use crate::platform::{PlatformError, paths::Tool};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/panel-update/runs", get(list).post(start))
        .route("/api/v1/panel-update/runs/{id}", get(show))
}

/// The keys of a run's `state` file; anything else in it is ignored.
const FIELDS: &[&str] = &[
    "action", "status", "step", "target_ref", "repo", "from_version", "from_ref", "from_commit",
    "to_version", "to_ref", "to_commit", "rollback_of", "restore_database", "message", "started_at", "finished_at",
];

/// Backups hold the panel's database and code, so only the newest few stay.
const KEEP_BACKUPS: usize = 3;
const SHOWN_RUNS: usize = 30;
const LOG_CHUNK: u64 = 256 * 1024;

/// What drust asks of the system, so tests can stand in for systemd.
trait Host {
    fn launch(&self, unit: &str, program: &Path, args: &[String]) -> Result<(), PlatformError>;
    fn running(&self, unit: &str) -> bool;
}

struct System;

impl Host for System {
    fn launch(&self, unit: &str, program: &Path, args: &[String]) -> Result<(), PlatformError> {
        crate::platform::services().run_detached(unit, program, args)
    }

    fn running(&self, unit: &str) -> bool {
        crate::platform::services()
            .status(unit)
            .is_ok_and(|status| status.active || status.state == "activating")
    }
}

/// Where runs are kept, and where the code an update replaces lives
/// (`dpanel`, `drust` and `dscript` side by side).
struct Layout {
    runs: PathBuf,
    install_root: PathBuf,
    bash: PathBuf,
}

impl Layout {
    fn system() -> Result<Self, PlatformError> {
        let paths = crate::platform::paths();
        Ok(Self {
            runs: paths.state_dir().join("panel-update"),
            install_root: paths.install_root().to_path_buf(),
            bash: paths.binary(Tool::Bash)?,
        })
    }

    fn script(&self) -> PathBuf {
        self.install_root.join("dscript").join("scripts").join("panel-update.sh")
    }
}

#[derive(Debug, Clone, Serialize)]
struct Run {
    id: String,
    #[serde(flatten)]
    fields: BTreeMap<String, String>,
    has_backup: bool,
}

impl Run {
    fn field(&self, key: &str) -> &str {
        self.fields.get(key).map(String::as_str).unwrap_or_default()
    }

    fn active(&self) -> bool {
        matches!(self.field("status"), "queued" | "running")
    }
}

#[derive(Deserialize)]
struct StartRequest {
    action: String,
    #[serde(default)]
    target_ref: Option<String>,
    #[serde(default)]
    repo: Option<String>,
    /// For a rollback: the update whose backup to restore; the newest
    /// successful one when missing.
    #[serde(default)]
    from_run: Option<String>,
    #[serde(default = "restore_database_by_default")]
    restore_database: bool,
}

fn restore_database_by_default() -> bool {
    true
}

#[derive(Debug)]
enum Refusal {
    Invalid(String),
    Busy(String),
    Platform(PlatformError),
}

impl IntoResponse for Refusal {
    fn into_response(self) -> Response {
        match self {
            Self::Invalid(message) => ApiResponse::error(&message).into_response(),
            Self::Busy(message) => (StatusCode::CONFLICT, Json(ApiResponse::error(&message))).into_response(),
            Self::Platform(error) => error.into_response(),
        }
    }
}

fn unit_name(id: &str) -> String {
    format!("dpanel-update-{id}")
}

/// `20261111-093000-1a2b`: sorts by time, and is safe in a path and a unit name.
fn valid_id(id: &str) -> bool {
    let bytes = id.as_bytes();
    bytes.len() == 20
        && bytes.iter().enumerate().all(|(index, byte)| match index {
            8 | 15 => *byte == b'-',
            16.. => byte.is_ascii_digit() || (b'a'..=b'f').contains(byte),
            _ => byte.is_ascii_digit(),
        })
}

/// A tag, branch or commit, as git and GitHub's archive URLs accept it, and
/// never an option for a program.
fn valid_ref(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 100
        && value.bytes().all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-' | b'/'))
        && !value.starts_with(['-', '.', '/'])
        && !value.ends_with(['/', '.'])
        && !value.contains("..")
        && !value.contains("//")
        && !value.ends_with(".lock")
}

/// `owner/name` on GitHub.
fn valid_repo(value: &str) -> bool {
    let mut parts = value.split('/');
    let part_ok = |part: Option<&str>| {
        part.is_some_and(|part| {
            !part.is_empty()
                && part.len() <= 100
                && !part.starts_with(['.', '-'])
                && part.bytes().all(|b| b.is_ascii_alphanumeric() || matches!(b, b'.' | b'_' | b'-'))
        })
    };
    part_ok(parts.next()) && part_ok(parts.next()) && parts.next().is_none()
}

fn parse_state(text: &str) -> BTreeMap<String, String> {
    text.lines()
        .filter_map(|line| line.split_once('='))
        .filter(|(key, _)| FIELDS.contains(key))
        .map(|(key, value)| (key.to_string(), value.trim().to_string()))
        .collect()
}

/// Written whole and renamed into place, so a reader never sees half a file.
fn write_state(dir: &Path, fields: &BTreeMap<String, String>) -> std::io::Result<()> {
    let text: String = fields
        .iter()
        .map(|(key, value)| format!("{key}={}\n", value.replace(['\n', '\r'], " ")))
        .collect();
    let temp = dir.join("state.tmp");
    fs::write(&temp, text)?;
    fs::rename(temp, dir.join("state"))
}

fn backup_complete(dir: &Path) -> bool {
    dir.join("backup").join("complete").is_file()
}

/// A run as the script last left it. A run that says it is still going but
/// whose unit is gone was cut short (a reboot, a killed process).
fn read_run(layout: &Layout, host: &dyn Host, id: &str) -> Option<Run> {
    let dir = layout.runs.join(id);
    let fields = parse_state(&fs::read_to_string(dir.join("state")).ok()?);
    let mut run = Run { id: id.to_string(), fields, has_backup: backup_complete(&dir) };
    if run.active() && !host.running(&unit_name(id)) {
        run.fields.insert("status".into(), "interrupted".into());
        if run.field("message").is_empty() {
            run.fields.insert("message".into(), "The update stopped without finishing; see its log.".into());
        }
    }
    Some(run)
}

/// Newest first.
fn run_ids(layout: &Layout) -> Vec<String> {
    let mut ids: Vec<String> = fs::read_dir(&layout.runs)
        .map(|entries| {
            entries
                .flatten()
                .filter_map(|entry| entry.file_name().into_string().ok())
                .filter(|name| valid_id(name))
                .collect()
        })
        .unwrap_or_default();
    ids.sort_unstable_by(|a, b| b.cmp(a));
    ids
}

fn all_runs(layout: &Layout, host: &dyn Host) -> Vec<Run> {
    run_ids(layout).iter().filter_map(|id| read_run(layout, host, id)).collect()
}

/// Keeps the newest backups and the one a rollback is about to restore.
fn prune_backups(layout: &Layout, keep: usize, protect: Option<&str>) {
    let with_backup = run_ids(layout).into_iter().filter(|id| layout.runs.join(id).join("backup").is_dir());
    for id in with_backup.skip(keep) {
        if Some(id.as_str()) != protect {
            let _ = fs::remove_dir_all(layout.runs.join(&id).join("backup"));
        }
    }
}

fn now() -> String {
    chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs, true)
}

fn new_id() -> String {
    let mut random = [0_u8; 2];
    let _ = crate::platform::random_bytes(&mut random);
    format!("{}-{:02x}{:02x}", chrono::Utc::now().format("%Y%m%d-%H%M%S"), random[0], random[1])
}

/// Checks the request, writes the run's first state and starts the script.
fn begin(layout: &Layout, host: &dyn Host, request: StartRequest, id: &str) -> Result<Run, Refusal> {
    let invalid = |message: &str| Err(Refusal::Invalid(message.to_string()));
    let rollback = match request.action.as_str() {
        "update" => false,
        "rollback" => true,
        _ => return invalid("The action must be update or rollback."),
    };
    if layout.install_root.join(".git").exists() {
        return invalid("dPanel runs from a git checkout on this server; update it with git instead.");
    }
    if !layout.script().is_file() {
        return invalid("panel-update.sh is missing; update once with installer.sh to get it.");
    }
    let runs = all_runs(layout, host);
    if let Some(active) = runs.iter().find(|run| run.active()) {
        return Err(Refusal::Busy(format!("Run {} is still going.", active.id)));
    }

    let mut fields = BTreeMap::new();
    let mut args = vec![layout.script().to_string_lossy().into_owned(), request.action.clone()];
    let dir = layout.runs.join(id);
    args.extend(["--run-dir".into(), dir.to_string_lossy().into_owned()]);
    args.extend(["--root".into(), layout.install_root.to_string_lossy().into_owned()]);
    let mut source = None;
    if rollback {
        let from = match request.from_run.as_deref() {
            Some(from) if !valid_id(from) => return invalid("Unknown run."),
            Some(from) => runs.iter().find(|run| run.id == from),
            None => runs.iter().find(|run| {
                run.field("action") == "update"
                    && run.field("status") == "succeeded"
                    && run.has_backup
                    && !runs.iter().any(|later| later.field("status") == "succeeded" && later.field("rollback_of") == run.id)
            }),
        };
        let Some(from) = from.filter(|run| run.has_backup) else {
            return invalid("There is no update with a backup to roll back to.");
        };
        args.extend(["--from".into(), layout.runs.join(&from.id).join("backup").to_string_lossy().into_owned()]);
        if !request.restore_database {
            args.push("--keep-database".into());
        }
        fields.insert("rollback_of".to_string(), from.id.clone());
        fields.insert("target_ref".to_string(), from.field("from_ref").to_string());
        fields.insert("restore_database".to_string(), if request.restore_database { "1" } else { "0" }.to_string());
        source = Some(from.id.clone());
    } else {
        let target = request.target_ref.as_deref().unwrap_or_default().trim().to_string();
        if !valid_ref(&target) {
            return invalid("Give a release tag, branch or commit to update to.");
        }
        args.extend(["--ref".into(), target.clone()]);
        fields.insert("target_ref".to_string(), target);
    }
    if let Some(repo) = request.repo.as_deref().map(str::trim).filter(|repo| !repo.is_empty()) {
        if !valid_repo(repo) {
            return invalid("The repository must look like owner/name.");
        }
        args.extend(["--repo".into(), repo.to_string()]);
        fields.insert("repo".to_string(), repo.to_string());
    }

    let io = |error: std::io::Error| Refusal::Platform(PlatformError::from_io("create the update run", error));
    fs::create_dir_all(&layout.runs).map_err(io)?;
    fs::create_dir(&dir).map_err(io)?;
    fields.insert("action".into(), request.action.clone());
    fields.insert("status".into(), "queued".into());
    fields.insert("step".into(), "queued".into());
    fields.insert("started_at".into(), now());
    write_state(&dir, &fields).map_err(io)?;

    if let Err(error) = host.launch(&unit_name(id), &layout.bash, &args) {
        fields.insert("status".into(), "failed".into());
        fields.insert("message".into(), format!("The update could not start: {error}"));
        fields.insert("finished_at".into(), now());
        let _ = write_state(&dir, &fields);
        return Err(Refusal::Platform(error));
    }
    prune_backups(layout, KEEP_BACKUPS, source.as_deref());
    read_run(layout, host, id).ok_or_else(|| Refusal::Invalid("The run disappeared.".into()))
}

/// The log from byte `offset` on, at most one chunk, and where the next read starts.
/// A log shorter than `offset` was started over, so it is read from the top.
fn read_log(path: &Path, offset: u64) -> (String, u64) {
    let Ok(mut file) = fs::File::open(path) else {
        return (String::new(), 0);
    };
    let length = file.metadata().map(|meta| meta.len()).unwrap_or(0);
    let start = if offset > length { 0 } else { offset };
    let mut bytes = Vec::new();
    if file.seek(SeekFrom::Start(start)).is_err() || file.take(LOG_CHUNK).read_to_end(&mut bytes).is_err() {
        return (String::new(), start);
    }
    let next = start + bytes.len() as u64;
    (String::from_utf8_lossy(&bytes).into_owned(), next)
}

async fn blocking(work: impl FnOnce() -> Response + Send + 'static) -> Response {
    tokio::task::spawn_blocking(work).await.unwrap_or_else(|error| ApiResponse::error(&error.to_string()).into_response())
}

async fn list(State(state): State<Arc<ApiState>>, headers: HeaderMap) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    blocking(|| {
        let layout = match Layout::system() {
            Ok(layout) => layout,
            Err(error) => return error.into_response(),
        };
        let runs = all_runs(&layout, &System);
        let active = runs.iter().find(|run| run.active()).map(|run| run.id.clone());
        let runs: Vec<Run> = runs.into_iter().take(SHOWN_RUNS).collect();
        Json(serde_json::json!({"success": true, "active": active, "runs": runs})).into_response()
    })
    .await
}

#[derive(Deserialize)]
struct LogQuery {
    #[serde(default)]
    offset: u64,
}

async fn show(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    UrlPath(id): UrlPath<String>,
    Query(query): Query<LogQuery>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if !valid_id(&id) {
        return PlatformError::NotFound { what: "update run".into() }.into_response();
    }
    blocking(move || {
        let layout = match Layout::system() {
            Ok(layout) => layout,
            Err(error) => return error.into_response(),
        };
        let Some(run) = read_run(&layout, &System, &id) else {
            return PlatformError::NotFound { what: format!("update run {id}") }.into_response();
        };
        let (log, offset) = read_log(&layout.runs.join(&id).join("log"), query.offset);
        Json(serde_json::json!({"success": true, "run": run, "log": log, "offset": offset})).into_response()
    })
    .await
}

async fn start(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<StartRequest>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    blocking(move || {
        let layout = match Layout::system() {
            Ok(layout) => layout,
            Err(error) => return error.into_response(),
        };
        match begin(&layout, &System, request, &new_id()) {
            Ok(run) => Json(serde_json::json!({"success": true, "message": "Started.", "run": run})).into_response(),
            Err(refusal) => refusal.into_response(),
        }
    })
    .await
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::sync::Mutex;

    #[derive(Default)]
    struct FakeHost {
        launched: Mutex<Vec<(String, PathBuf, Vec<String>)>>,
        running: Vec<String>,
        fail: bool,
    }

    impl Host for FakeHost {
        fn launch(&self, unit: &str, program: &Path, args: &[String]) -> Result<(), PlatformError> {
            if self.fail {
                return Err(PlatformError::Failed { action: "systemd-run".into(), detail: "no systemd".into() });
            }
            self.launched.lock().unwrap().push((unit.to_string(), program.to_path_buf(), args.to_vec()));
            Ok(())
        }

        fn running(&self, unit: &str) -> bool {
            self.running.iter().any(|running| running == unit)
        }
    }

    struct Fixture {
        _folder: tempfile::TempDir,
        layout: Layout,
    }

    fn fixture() -> Fixture {
        let folder = tempfile::tempdir().unwrap();
        let layout = Layout {
            runs: folder.path().join("state").join("panel-update"),
            install_root: folder.path().join("www"),
            bash: PathBuf::from("bash"),
        };
        fs::create_dir_all(layout.script().parent().unwrap()).unwrap();
        fs::write(layout.script(), "#!/bin/bash\n").unwrap();
        Fixture { _folder: folder, layout }
    }

    fn old_run(layout: &Layout, id: &str, lines: &str, backup: bool) {
        let dir = layout.runs.join(id);
        fs::create_dir_all(&dir).unwrap();
        fs::write(dir.join("state"), lines).unwrap();
        if backup {
            fs::create_dir_all(dir.join("backup")).unwrap();
            fs::write(dir.join("backup").join("complete"), "").unwrap();
        }
    }

    fn update(target: &str) -> StartRequest {
        StartRequest { action: "update".into(), target_ref: Some(target.into()), repo: None, from_run: None, restore_database: true }
    }

    fn rollback(from: Option<&str>, restore_database: bool) -> StartRequest {
        StartRequest { action: "rollback".into(), target_ref: None, repo: None, from_run: from.map(Into::into), restore_database }
    }

    const ID: &str = "20261111-120000-abcd";

    #[test]
    fn ids_refs_and_repos_are_checked() {
        assert!(valid_id(ID));
        for id in ["20261111-120000-ABCD", "20261111-120000-abc", "../../etc/passwd000", "20261111x120000-abcd"] {
            assert!(!valid_id(id), "{id}");
        }
        for ok in ["v1.2.3", "main", "feature/x-1", "3f2c1e0d9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e"] {
            assert!(valid_ref(ok), "{ok}");
        }
        for bad in ["", "-x", "--upload-pack=x", "a..b", "a b", "a;b", "a/", "/a", "x.lock", ".hidden", "a//b"] {
            assert!(!valid_ref(bad), "{bad}");
        }
        assert!(valid_repo("mdsazzad0002/dpanel"));
        for bad in ["dpanel", "a/b/c", "-a/b", "a/.b", "a b/c", "a/"] {
            assert!(!valid_repo(bad), "{bad}");
        }
    }

    #[test]
    fn the_state_file_keeps_only_known_keys() {
        let fields = parse_state("status=running\nmessage=a=b\nPATH=/x\nnot a line\n");
        assert_eq!(fields.get("status").unwrap(), "running");
        assert_eq!(fields.get("message").unwrap(), "a=b");
        assert_eq!(fields.len(), 2);
    }

    #[test]
    fn an_update_writes_its_state_and_starts_the_script_as_its_own_unit() {
        let fixture = fixture();
        let host = FakeHost { running: vec![unit_name(ID)], ..Default::default() };
        let mut request = update("v1.2.0");
        request.repo = Some("me/dpanel".into());

        let run = begin(&fixture.layout, &host, request, ID).unwrap();

        assert_eq!(run.field("status"), "queued");
        assert_eq!(run.field("target_ref"), "v1.2.0");
        let launched = host.launched.lock().unwrap();
        let (unit, program, args) = &launched[0];
        assert_eq!(unit, "dpanel-update-20261111-120000-abcd");
        assert_eq!(program, Path::new("bash"));
        let dir = fixture.layout.runs.join(ID);
        assert_eq!(
            args,
            &[
                fixture.layout.script().to_string_lossy().into_owned(),
                "update".into(),
                "--run-dir".into(),
                dir.to_string_lossy().into_owned(),
                "--root".into(),
                fixture.layout.install_root.to_string_lossy().into_owned(),
                "--ref".into(),
                "v1.2.0".into(),
                "--repo".into(),
                "me/dpanel".into(),
            ]
        );
    }

    #[test]
    fn a_second_run_is_refused_while_one_is_going() {
        let fixture = fixture();
        old_run(&fixture.layout, "20261110-120000-0001", "action=update\nstatus=running\n", false);
        let host = FakeHost { running: vec![unit_name("20261110-120000-0001")], ..Default::default() };

        let refusal = begin(&fixture.layout, &host, update("v1.2.0"), ID).unwrap_err();

        assert!(matches!(refusal, Refusal::Busy(_)), "{refusal:?}");
        assert!(host.launched.lock().unwrap().is_empty());
        assert_eq!(refusal.into_response().status(), StatusCode::CONFLICT);
    }

    #[test]
    fn a_run_whose_unit_is_gone_is_interrupted_and_does_not_block() {
        let fixture = fixture();
        old_run(&fixture.layout, "20261110-120000-0001", "action=update\nstatus=running\nstep=install\n", false);
        let host = FakeHost::default();

        let old = read_run(&fixture.layout, &host, "20261110-120000-0001").unwrap();
        assert_eq!(old.field("status"), "interrupted");
        assert!(!old.field("message").is_empty());
        assert!(begin(&fixture.layout, &host, update("v1.2.0"), ID).is_ok());
    }

    #[test]
    fn a_git_checkout_and_bad_input_are_refused() {
        let fixture = fixture();
        let host = FakeHost::default();
        for request in [update("--help"), update(""), StartRequest { action: "erase".into(), ..update("v1") }] {
            assert!(matches!(begin(&fixture.layout, &host, request, ID), Err(Refusal::Invalid(_))));
        }
        let mut request = update("v1.2.0");
        request.repo = Some("not a repo".into());
        assert!(matches!(begin(&fixture.layout, &host, request, ID), Err(Refusal::Invalid(_))));

        fs::create_dir_all(fixture.layout.install_root.join(".git")).unwrap();
        let Err(Refusal::Invalid(message)) = begin(&fixture.layout, &host, update("v1.2.0"), ID) else {
            panic!("a git checkout must be refused");
        };
        assert!(message.contains("git"), "{message}");
        assert!(host.launched.lock().unwrap().is_empty());
        assert!(!fixture.layout.runs.join(ID).exists());
    }

    #[test]
    fn a_rollback_restores_the_newest_successful_update() {
        let fixture = fixture();
        let layout = &fixture.layout;
        old_run(layout, "20261101-000000-0001", "action=update\nstatus=succeeded\nfrom_ref=v1.0.0\n", true);
        old_run(layout, "20261102-000000-0002", "action=update\nstatus=succeeded\nfrom_ref=v1.1.0\n", true);
        old_run(layout, "20261103-000000-0003", "action=update\nstatus=rolled_back\nfrom_ref=v1.1.0\n", true);
        old_run(layout, "20261104-000000-0004", "action=update\nstatus=succeeded\nfrom_ref=v1.2.0\n", false);
        let host = FakeHost { running: vec![unit_name(ID)], ..Default::default() };

        let run = begin(layout, &host, rollback(None, false), ID).unwrap();

        assert_eq!(run.field("rollback_of"), "20261102-000000-0002");
        assert_eq!(run.field("target_ref"), "v1.1.0");
        assert_eq!(run.field("restore_database"), "0");
        let launched = host.launched.lock().unwrap();
        let args = &launched[0].2;
        let from = layout.runs.join("20261102-000000-0002").join("backup").to_string_lossy().into_owned();
        assert!(args.windows(2).any(|pair| pair[0] == "--from" && pair[1] == from), "{args:?}");
        assert_eq!(args.last().unwrap(), "--keep-database");
        drop(launched);

        // Once that rollback is done, the next one goes a step further back.
        fs::write(layout.runs.join(ID).join("state"), "action=rollback\nstatus=succeeded\nrollback_of=20261102-000000-0002\n").unwrap();
        let run = begin(layout, &host, rollback(None, true), "20261111-130000-abcd").unwrap();
        assert_eq!(run.field("rollback_of"), "20261101-000000-0001");
    }

    #[test]
    fn a_rollback_needs_a_backup() {
        let fixture = fixture();
        old_run(&fixture.layout, "20261101-000000-0001", "action=update\nstatus=succeeded\n", false);
        let host = FakeHost::default();
        assert!(matches!(begin(&fixture.layout, &host, rollback(None, true), ID), Err(Refusal::Invalid(_))));
        assert!(matches!(
            begin(&fixture.layout, &host, rollback(Some("20261101-000000-0001"), true), ID),
            Err(Refusal::Invalid(_))
        ));
        assert!(matches!(begin(&fixture.layout, &host, rollback(Some("../x"), true), ID), Err(Refusal::Invalid(_))));
    }

    #[test]
    fn a_run_that_cannot_start_is_recorded_as_failed() {
        let fixture = fixture();
        let host = FakeHost { fail: true, ..Default::default() };

        assert!(matches!(begin(&fixture.layout, &host, update("v1.2.0"), ID), Err(Refusal::Platform(_))));

        let run = read_run(&fixture.layout, &host, ID).unwrap();
        assert_eq!(run.field("status"), "failed");
        assert!(run.field("message").contains("no systemd"), "{}", run.field("message"));
        assert!(!run.field("finished_at").is_empty());
    }

    #[test]
    fn only_the_newest_backups_and_the_one_being_restored_stay() {
        let fixture = fixture();
        let layout = &fixture.layout;
        for day in 1..=5 {
            old_run(layout, &format!("2026110{day}-000000-000{day}"), "action=update\nstatus=succeeded\n", true);
        }

        prune_backups(layout, 3, Some("20261101-000000-0001"));

        let kept: Vec<String> = run_ids(layout).into_iter().filter(|id| layout.runs.join(id).join("backup").is_dir()).collect();
        assert_eq!(kept, ["20261105-000000-0005", "20261104-000000-0004", "20261103-000000-0003", "20261101-000000-0001"]);
        assert!(layout.runs.join("20261102-000000-0002").join("state").is_file(), "the run itself stays");
    }

    #[test]
    fn the_log_is_read_on_from_where_the_panel_stopped() {
        let folder = tempfile::tempdir().unwrap();
        let log = folder.path().join("log");
        assert_eq!(read_log(&log, 0), (String::new(), 0));
        fs::write(&log, "one\ntwo\n").unwrap();
        assert_eq!(read_log(&log, 0), ("one\ntwo\n".to_string(), 8));
        assert_eq!(read_log(&log, 4), ("two\n".to_string(), 8));
        assert_eq!(read_log(&log, 8), (String::new(), 8));
        assert_eq!(read_log(&log, 100), ("one\ntwo\n".to_string(), 8), "a shorter log was started over");
    }
}
