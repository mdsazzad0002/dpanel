//! Incremental, encrypted backups with restic (backlog W6).
//!
//! `POST /api/v1/restic/init`, `/backup`, `/snapshots` and `/forget`. The
//! panel keeps one repository per server and sends its location and password
//! with every call; drust writes neither down. restic runs as root, because
//! every site's files belong to its own user, with a cleared environment: the
//! password goes in `RESTIC_PASSWORD`, never on the command line.
//!
//! A backup is one snapshot per website: the site user's home and a dump of
//! each of its databases, tagged `dpanel` and `website:<id>`. Each snapshot
//! is a restore point; restoring from one is W7.

use std::{
    collections::BTreeMap,
    fs,
    path::{Path, PathBuf},
    process::Command,
    sync::Arc,
};

use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;
use serde_json::{Value, json};

use crate::{
    api::{ApiResponse, ApiState, check_token},
    database::cli,
    platform::{self, paths::Tool},
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/restic/init", post(init_handle))
        .route("/api/v1/restic/backup", post(backup_handle))
        .route("/api/v1/restic/snapshots", post(snapshots_handle))
        .route("/api/v1/restic/forget", post(forget_handle))
}

/// What may reach restic besides its own variables: cloud credentials.
const ALLOWED_ENV: [&str; 5] =
    ["AWS_ACCESS_KEY_ID", "AWS_SECRET_ACCESS_KEY", "AWS_DEFAULT_REGION", "B2_ACCOUNT_ID", "B2_ACCOUNT_KEY"];

/// The kinds of repository restic reaches without extra setup here.
const REMOTE_PREFIXES: [&str; 5] = ["sftp:", "s3:", "rest:", "rclone:", "b2:"];

#[derive(Deserialize, Clone)]
pub(crate) struct Repo {
    pub repository: String,
    pub password: String,
    #[serde(default)]
    pub env: BTreeMap<String, String>,
}

impl Repo {
    /// The repository a site user can never write to, and nothing restic
    /// would read as an option.
    pub(crate) fn checked(&self, sites_root: &Path) -> Result<(), String> {
        let location = self.repository.trim();
        if location.is_empty() || location.len() > 1000 || location.chars().any(char::is_control) {
            return Err("The repository location is missing or malformed.".into());
        }
        if location.starts_with('-') {
            return Err("The repository location cannot start with '-'.".into());
        }
        if !REMOTE_PREFIXES.iter().any(|prefix| location.starts_with(prefix)) {
            let path = Path::new(location);
            if !path.is_absolute() || path.parent().is_none() {
                return Err(format!(
                    "Give a folder on this server (an absolute path) or a {} repository.",
                    REMOTE_PREFIXES.join(" ")
                ));
            }
            if path.components().any(|part| matches!(part, std::path::Component::ParentDir))
                || path.starts_with(sites_root)
            {
                return Err("A local repository cannot be inside the websites' folder.".into());
            }
        }
        if self.password.is_empty() || self.password.len() > 1024 {
            return Err("The repository password is missing.".into());
        }
        if let Some(name) = self.env.keys().find(|name| !ALLOWED_ENV.contains(&name.as_str())) {
            return Err(format!("{name} is not a setting restic is given."));
        }
        Ok(())
    }
}

/// What one restic run said.
pub(crate) struct Run {
    pub code: Option<i32>,
    pub stdout: String,
    pub stderr: String,
}

impl Run {
    pub(crate) fn ok(&self) -> bool {
        self.code == Some(0)
    }

    pub(crate) fn reason(&self) -> String {
        let text = if self.stderr.trim().is_empty() { self.stdout.trim() } else { self.stderr.trim() };
        text.lines().filter(|line| !line.trim().is_empty()).take(8).collect::<Vec<_>>().join("; ").chars().take(1500).collect()
    }
}

/// Runs restic against a repository. A trait so the tests need no restic.
pub(crate) trait Restic: Send + Sync {
    fn run(&self, repo: &Repo, args: &[String]) -> Result<Run, String>;
    /// Hands restic's stdout to `read` as it comes (W7's `dump`), then fails
    /// if restic did.
    fn stream(&self, repo: &Repo, args: &[String], read: &mut dyn FnMut(&mut dyn std::io::Read) -> Result<(), String>) -> Result<(), String>;
}

pub(crate) struct SystemRestic;

impl SystemRestic {
    fn command(repo: &Repo, args: &[String]) -> Result<Command, String> {
        let binary = platform::paths()
            .binary(Tool::Restic)
            .map_err(|_| "restic is not installed on this server.".to_string())?;
        let mut command = Command::new(binary);
        command
            .env_clear()
            .args(args)
            .env("RESTIC_REPOSITORY", repo.repository.trim())
            .env("RESTIC_PASSWORD", &repo.password)
            .env("RESTIC_CACHE_DIR", platform::paths().state_dir().join("restic-cache"))
            .envs(repo.env.iter());
        // sftp needs ssh, and ssh needs root's home for its keys.
        for name in ["PATH", "HOME"] {
            if let Some(value) = std::env::var_os(name) {
                command.env(name, value);
            }
        }
        Ok(command)
    }
}

impl Restic for SystemRestic {
    fn stream(&self, repo: &Repo, args: &[String], read: &mut dyn FnMut(&mut dyn std::io::Read) -> Result<(), String>) -> Result<(), String> {
        use std::process::Stdio;
        let mut child = Self::command(repo, args)?
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|error| format!("cannot run restic: {error}"))?;
        let mut stdout = child.stdout.take().ok_or("restic gave no output.")?;
        let mut stderr = child.stderr.take().ok_or("restic gave no output.")?;
        // stderr drains on its own thread so a chatty restic never blocks.
        let errors = std::thread::spawn(move || {
            let mut text = String::new();
            let _ = std::io::Read::read_to_string(&mut stderr, &mut text);
            text
        });
        let consumed = read(&mut stdout);
        drop(stdout);
        if consumed.is_err() {
            let _ = child.kill();
        }
        let status = child.wait().map_err(|error| format!("restic did not finish: {error}"))?;
        let errors = errors.join().unwrap_or_default();
        consumed?;
        if !status.success() {
            return Err(format!("restic failed: {}", Run { code: status.code(), stdout: String::new(), stderr: errors }.reason()));
        }
        Ok(())
    }

    fn run(&self, repo: &Repo, args: &[String]) -> Result<Run, String> {
        let output = Self::command(repo, args)?.output().map_err(|error| format!("cannot run restic: {error}"))?;
        Ok(Run {
            code: output.status.code(),
            stdout: String::from_utf8_lossy(&output.stdout).into_owned(),
            stderr: String::from_utf8_lossy(&output.stderr).into_owned(),
        })
    }
}

pub(crate) fn args(list: &[&str]) -> Vec<String> {
    list.iter().map(|value| value.to_string()).collect()
}

/// Opens the repository, or creates it when there is none. A repository that
/// exists but does not open with this password is an error, never a new one.
pub(crate) fn init(restic: &dyn Restic, repo: &Repo) -> Result<Value, String> {
    let probe = restic.run(repo, &args(&["cat", "config", "--json"]))?;
    if probe.ok() {
        return Ok(json!({ "created": false }));
    }
    let reason = probe.reason();
    if reason.contains("wrong password") || reason.contains("no key found") {
        return Err("The password does not open this repository.".into());
    }
    let created = restic.run(repo, &args(&["init", "--json"]))?;
    if !created.ok() {
        return Err(format!("restic could not create the repository: {}", created.reason()));
    }
    Ok(json!({ "created": true }))
}

#[derive(Deserialize)]
pub(crate) struct Database {
    pub name: String,
    pub user: String,
    pub password: String,
    pub host: String,
}

#[derive(Deserialize)]
pub(crate) struct Website {
    pub id: String,
    pub domain: String,
    pub site_owner: String,
    #[serde(default)]
    pub databases: Vec<Database>,
}

pub(crate) fn safe_word(value: &str, extra: &[u8]) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && !value.starts_with('-')
        && value.bytes().all(|byte| byte.is_ascii_alphanumeric() || extra.contains(&byte))
}

/// Where a website's database dumps wait for restic: owner-only, emptied
/// after every snapshot, the same path each time.
pub(crate) fn staging_dir(state_dir: &Path, website_id: &str) -> PathBuf {
    state_dir.join("restic-staging").join(website_id)
}

/// The numbers restic's `summary` line gives.
fn summary(stdout: &str) -> Option<Value> {
    stdout
        .lines()
        .filter_map(|line| serde_json::from_str::<Value>(line.trim()).ok())
        .find(|message| message["message_type"] == "summary")
}

pub(crate) fn backup(
    restic: &dyn Restic,
    repo: &Repo,
    website: &Website,
    home: &Path,
    state_dir: &Path,
    dump: &dyn Fn(&Database, &Path) -> Result<(), String>,
) -> Result<Value, String> {
    if !safe_word(&website.id, b"-") {
        return Err("Invalid website id.".into());
    }
    if !safe_word(&website.site_owner, b"_-") {
        return Err("Invalid website owner.".into());
    }
    if !home.is_dir() {
        return Err(format!("{} has no folder at {}.", website.domain, home.display()));
    }

    let staging = staging_dir(state_dir, &website.id);
    let _ = fs::remove_dir_all(&staging);
    let databases = staging.join("databases");
    platform::files().create_dir_private(&databases).map_err(|error| error.to_string())?;
    let result = (|| {
        for database in &website.databases {
            dump(database, &databases.join(cli::dump_file_name(&database.name)))?;
        }
        let website_tag = format!("website:{}", website.id);
        let owner_tag = format!("owner:{}", website.site_owner);
        // A restore test's `check` may hold the lock; wait for it rather than fail.
        let mut command = args(&["backup", "--json", "--retry-lock", "30m", "--host", "dpanel", "--tag", "dpanel", "--exclude-caches"]);
        command.extend(["--tag".into(), website_tag, "--tag".into(), owner_tag]);
        command.extend([home.display().to_string(), staging.display().to_string()]);
        let run = restic.run(repo, &command)?;
        // 3: some files could not be read, but the snapshot exists.
        if !matches!(run.code, Some(0) | Some(3)) {
            return Err(format!("restic backup failed: {}", run.reason()));
        }
        let summary = summary(&run.stdout).ok_or("restic finished without a snapshot.")?;
        let snapshot = summary["snapshot_id"].as_str().filter(|id| !id.is_empty()).ok_or("restic finished without a snapshot.")?;
        Ok(json!({
            "snapshot_id": snapshot,
            "files_new": summary["files_new"],
            "files_changed": summary["files_changed"],
            "data_added": summary["data_added"],
            "total_files": summary["total_files_processed"],
            "total_bytes": summary["total_bytes_processed"],
            "warning": (run.code == Some(3)).then(|| format!("Some files could not be read: {}", run.reason())),
        }))
    })();
    let _ = fs::remove_dir_all(&staging);
    result
}

/// The restore points, newest last, as restic lists them.
pub(crate) fn snapshots(restic: &dyn Restic, repo: &Repo, website_id: Option<&str>) -> Result<Value, String> {
    let mut tags = "dpanel".to_string();
    if let Some(id) = website_id {
        if !safe_word(id, b"-") {
            return Err("Invalid website id.".into());
        }
        tags.push_str(&format!(",website:{id}"));
    }
    let run = restic.run(repo, &args(&["snapshots", "--json", "--tag", &tags]))?;
    if !run.ok() {
        return Err(format!("restic could not list the restore points: {}", run.reason()));
    }
    let listed: Vec<Value> = serde_json::from_str(run.stdout.trim()).map_err(|_| "restic listed something that is not JSON.")?;
    Ok(Value::Array(listed.iter().map(snapshot_json).collect()))
}

fn snapshot_json(snapshot: &Value) -> Value {
    let tags: Vec<&str> = snapshot["tags"].as_array().into_iter().flatten().filter_map(Value::as_str).collect();
    let website = tags.iter().find_map(|tag| tag.strip_prefix("website:"));
    json!({
        "id": snapshot["id"],
        "short_id": snapshot["short_id"],
        "time": snapshot["time"],
        "website_id": website,
        "paths": snapshot["paths"],
    })
}

#[derive(Deserialize)]
pub(crate) struct Keep {
    pub daily: u32,
    pub weekly: u32,
    pub monthly: u32,
}

/// Drops restore points beyond what `keep` asks, per website, then frees
/// their space. Returns the ids that went.
pub(crate) fn forget(restic: &dyn Restic, repo: &Repo, keep: &Keep) -> Result<Value, String> {
    if keep.daily + keep.weekly + keep.monthly == 0 {
        return Err("Keep at least one restore point.".into());
    }
    let (daily, weekly, monthly) = (keep.daily.min(366).to_string(), keep.weekly.min(520).to_string(), keep.monthly.min(240).to_string());
    let run = restic.run(
        repo,
        &args(&[
            "forget", "--json", "--tag", "dpanel", "--group-by", "host,tags", "--keep-daily", &daily, "--keep-weekly",
            &weekly, "--keep-monthly", &monthly,
        ]),
    )?;
    if !run.ok() {
        return Err(format!("restic could not drop old restore points: {}", run.reason()));
    }
    let groups: Vec<Value> = serde_json::from_str(run.stdout.trim()).unwrap_or_default();
    let removed: Vec<Value> = groups
        .iter()
        .flat_map(|group| group["remove"].as_array().cloned().unwrap_or_default())
        .map(|snapshot| snapshot["id"].clone())
        .collect();
    let mut pruned = false;
    if !removed.is_empty() {
        let prune = restic.run(repo, &args(&["prune"]))?;
        if !prune.ok() {
            return Err(format!("restic dropped old restore points but could not free their space: {}", prune.reason()));
        }
        pruned = true;
    }
    Ok(json!({ "removed": removed, "pruned": pruned }))
}

#[derive(Deserialize)]
struct InitRequest {
    #[serde(flatten)]
    repo: Repo,
}

#[derive(Deserialize)]
struct BackupRequest {
    #[serde(flatten)]
    repo: Repo,
    website: Website,
}

#[derive(Deserialize)]
struct SnapshotsRequest {
    #[serde(flatten)]
    repo: Repo,
    website_id: Option<String>,
}

#[derive(Deserialize)]
struct ForgetRequest {
    #[serde(flatten)]
    repo: Repo,
    keep: Keep,
}

/// Checks the token and the repository, then runs `work` off the async
/// workers: restic takes as long as the data does.
pub(crate) async fn answer(
    state: &ApiState,
    headers: &HeaderMap,
    repo: &Repo,
    message: &'static str,
    work: impl FnOnce() -> Result<Value, String> + Send + 'static,
) -> Response {
    if let Err(error) = check_token(state, headers) {
        return error.into_response();
    }
    if let Err(error) = crate::app::ensure_root() {
        return ApiResponse::error(&error).into_response();
    }
    if let Err(error) = repo.checked(platform::paths().sites_root()) {
        return ApiResponse::error(&error).into_response();
    }
    match tokio::task::spawn_blocking(work).await.unwrap_or_else(|error| Err(format!("the restic task stopped: {error}"))) {
        Ok(data) => ApiResponse::ok_data(message, data).into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

async fn init_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<InitRequest>) -> Response {
    let repo = request.repo.clone();
    answer(&state, &headers, &request.repo, "Repository ready", move || init(&SystemRestic, &repo)).await
}

async fn backup_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<BackupRequest>) -> Response {
    let repo = request.repo.clone();
    let website = request.website;
    answer(&state, &headers, &request.repo, "Restore point created", move || {
        let paths = platform::paths();
        let home = paths.site_home(&website.site_owner);
        let dump = |database: &Database, target: &Path| {
            let login = cli::Login { host: &database.host, user: &database.user, password: &database.password };
            cli::dump_to_file(&login, &database.name, &["--single-transaction", "--quick", "--routines", "--triggers"], target)
        };
        backup(&SystemRestic, &repo, &website, &home, paths.state_dir(), &dump)
    })
    .await
}

async fn snapshots_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<SnapshotsRequest>) -> Response {
    let repo = request.repo.clone();
    let website = request.website_id;
    answer(&state, &headers, &request.repo, "Restore points listed", move || {
        snapshots(&SystemRestic, &repo, website.as_deref()).map(|list| json!({ "snapshots": list }))
    })
    .await
}

async fn forget_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<ForgetRequest>) -> Response {
    let repo = request.repo.clone();
    let keep = request.keep;
    answer(&state, &headers, &request.repo, "Old restore points dropped", move || forget(&SystemRestic, &repo, &keep)).await
}

#[cfg(test)]
mod tests {
    use std::sync::Mutex;

    use super::*;

    /// Answers each restic call from a script and remembers what it was asked.
    struct FakeRestic {
        answers: Mutex<Vec<Run>>,
        asked: Mutex<Vec<Vec<String>>>,
        /// What the staging folder held when `backup` ran.
        staged: Mutex<Vec<String>>,
    }

    fn run(code: i32, stdout: &str, stderr: &str) -> Run {
        Run { code: Some(code), stdout: stdout.into(), stderr: stderr.into() }
    }

    impl FakeRestic {
        fn new(answers: Vec<Run>) -> Self {
            Self { answers: Mutex::new(answers.into_iter().rev().collect()), asked: Mutex::default(), staged: Mutex::default() }
        }

        fn asked(&self) -> Vec<Vec<String>> {
            self.asked.lock().unwrap().clone()
        }
    }

    impl Restic for FakeRestic {
        fn stream(&self, _: &Repo, _: &[String], _: &mut dyn FnMut(&mut dyn std::io::Read) -> Result<(), String>) -> Result<(), String> {
            unreachable!()
        }

        fn run(&self, repo: &Repo, args: &[String]) -> Result<Run, String> {
            assert_eq!(repo.password, "secret");
            if args[0] == "backup" {
                let staging = PathBuf::from(args.last().unwrap()).join("databases");
                let mut names: Vec<String> = fs::read_dir(staging).unwrap().map(|entry| entry.unwrap().file_name().to_string_lossy().into_owned()).collect();
                names.sort();
                *self.staged.lock().unwrap() = names;
            }
            self.asked.lock().unwrap().push(args.to_vec());
            Ok(self.answers.lock().unwrap().pop().expect("an answer for every call"))
        }
    }

    fn repo(location: &str) -> Repo {
        Repo { repository: location.into(), password: "secret".into(), env: BTreeMap::new() }
    }

    #[test]
    fn only_repositories_outside_the_sites_and_known_settings_are_accepted() {
        let sites = Path::new("/home");
        assert!(repo("/var/backups/restic").checked(sites).is_ok());
        assert!(repo("s3:s3.amazonaws.com/bucket/dpanel").checked(sites).is_ok());
        assert!(repo("sftp:backup@203.0.113.9:/srv/restic").checked(sites).is_ok());
        assert!(repo("rclone:gdrive:dpanel").checked(sites).is_ok());

        for bad in ["", "relative/path", "/", "/home/shop/backups", "/var/../home/x", "--repo=/tmp", "ftp:host/x", "/var/backups\nx"] {
            assert!(repo(bad).checked(sites).is_err(), "{bad:?} was accepted");
        }
        let mut no_password = repo("/var/backups/restic");
        no_password.password.clear();
        assert!(no_password.checked(sites).is_err());
        let mut odd_env = repo("s3:host/bucket");
        odd_env.env.insert("LD_PRELOAD".into(), "/tmp/x.so".into());
        assert!(odd_env.checked(sites).unwrap_err().contains("LD_PRELOAD"));
        odd_env.env.clear();
        odd_env.env.insert("AWS_ACCESS_KEY_ID".into(), "AKIA".into());
        assert!(odd_env.checked(sites).is_ok());
    }

    #[test]
    fn init_opens_an_existing_repository_and_never_replaces_one_with_another_key() {
        let fake = FakeRestic::new(vec![run(0, "{}", "")]);
        assert_eq!(init(&fake, &repo("/r")).unwrap()["created"], false);
        assert_eq!(fake.asked().len(), 1);

        let fake = FakeRestic::new(vec![run(10, "", "Fatal: unable to open config file: Is there a repository at the following location?"), run(0, "{}", "")]);
        assert_eq!(init(&fake, &repo("/r")).unwrap()["created"], true);
        assert_eq!(fake.asked()[1][0], "init");

        let fake = FakeRestic::new(vec![run(12, "", "Fatal: wrong password or no key found")]);
        assert!(init(&fake, &repo("/r")).unwrap_err().contains("password does not open"));
        assert_eq!(fake.asked().len(), 1);
    }

    #[test]
    fn a_backup_snapshots_the_home_and_the_dumps_then_clears_them() {
        let state = tempfile::tempdir().unwrap();
        let home = tempfile::tempdir().unwrap();
        let summary = r#"{"message_type":"status","percent_done":0.5}
{"message_type":"summary","files_new":3,"files_changed":1,"data_added":2048,"total_files_processed":40,"total_bytes_processed":90000,"snapshot_id":"abc123"}"#;
        let fake = FakeRestic::new(vec![run(0, summary, "")]);
        let website = Website {
            id: "9b6f-11".into(),
            domain: "shop.example.com".into(),
            site_owner: "shop".into(),
            databases: vec![Database { name: "shop_wp".into(), user: "u".into(), password: "p".into(), host: "127.0.0.1".into() }],
        };
        let dumped = Mutex::new(Vec::new());
        let dump = |database: &Database, target: &Path| {
            dumped.lock().unwrap().push(database.name.clone());
            fs::write(target, "-- dump").map_err(|error| error.to_string())
        };

        let data = backup(&fake, &repo("/r"), &website, home.path(), state.path(), &dump).unwrap();

        assert_eq!(data["snapshot_id"], "abc123");
        assert_eq!(data["data_added"], 2048);
        assert!(data["warning"].is_null());
        let asked = &fake.asked()[0];
        assert_eq!(asked[0], "backup");
        for tag in ["dpanel", "website:9b6f-11", "owner:shop"] {
            assert!(asked.windows(2).any(|pair| pair[0] == "--tag" && pair[1] == tag), "{tag} missing: {asked:?}");
        }
        assert!(asked.windows(2).any(|pair| pair[0] == "--retry-lock" && pair[1] == "30m"), "{asked:?}");
        assert_eq!(asked[asked.len() - 2], home.path().display().to_string());
        assert_eq!(*fake.staged.lock().unwrap(), [cli::dump_file_name("shop_wp")]);
        assert!(!staging_dir(state.path(), "9b6f-11").exists());
    }

    #[test]
    fn a_failed_dump_or_backup_leaves_no_dump_behind() {
        let state = tempfile::tempdir().unwrap();
        let home = tempfile::tempdir().unwrap();
        let website = |databases| Website { id: "w1".into(), domain: "a.test".into(), site_owner: "a".into(), databases };
        let none = |_: &Database, _: &Path| Ok(());

        let fake = FakeRestic::new(vec![run(1, "", "Fatal: unable to save snapshot")]);
        let error = backup(&fake, &repo("/r"), &website(vec![]), home.path(), state.path(), &none).unwrap_err();
        assert!(error.contains("unable to save snapshot"), "{error}");
        assert!(!staging_dir(state.path(), "w1").exists());

        let fake = FakeRestic::new(vec![]);
        let broken = |_: &Database, _: &Path| Err("database dump failed for a_db".to_string());
        let db = Database { name: "a_db".into(), user: "u".into(), password: "p".into(), host: "h".into() };
        assert!(backup(&fake, &repo("/r"), &website(vec![db]), home.path(), state.path(), &broken).unwrap_err().contains("a_db"));
        assert!(fake.asked().is_empty());
        assert!(!staging_dir(state.path(), "w1").exists());

        // Unreadable files still give a snapshot, with a warning.
        let fake = FakeRestic::new(vec![run(3, r#"{"message_type":"summary","snapshot_id":"def"}"#, "error: open /home/a/locked: permission denied")]);
        let data = backup(&fake, &repo("/r"), &website(vec![]), home.path(), state.path(), &none).unwrap();
        assert!(data["warning"].as_str().unwrap().contains("permission denied"));

        let bad = Website { id: "--x".into(), ..website(vec![]) };
        assert!(backup(&FakeRestic::new(vec![]), &repo("/r"), &bad, home.path(), state.path(), &none).is_err());
    }

    #[test]
    fn restore_points_are_listed_per_website() {
        let listed = r#"[{"id":"aaa111","short_id":"aaa","time":"2026-11-11T02:30:00Z","tags":["dpanel","website:w1","owner:a"],"paths":["/home/a"]}]"#;
        let fake = FakeRestic::new(vec![run(0, listed, "")]);

        let list = snapshots(&fake, &repo("/r"), Some("w1")).unwrap();

        assert_eq!(list[0]["website_id"], "w1");
        assert_eq!(list[0]["id"], "aaa111");
        assert_eq!(fake.asked()[0], args(&["snapshots", "--json", "--tag", "dpanel,website:w1"]));
        assert!(snapshots(&fake, &repo("/r"), Some("w1,x")).is_err());
    }

    #[test]
    fn forgetting_prunes_only_when_something_went() {
        let groups = r#"[{"tags":["dpanel","website:w1"],"keep":[{"id":"k1"}],"remove":[{"id":"r1"},{"id":"r2"}]}]"#;
        let fake = FakeRestic::new(vec![run(0, groups, ""), run(0, "done", "")]);
        let data = forget(&fake, &repo("/r"), &Keep { daily: 7, weekly: 4, monthly: 6 }).unwrap();
        assert_eq!(data["removed"], json!(["r1", "r2"]));
        assert_eq!(data["pruned"], true);
        assert!(fake.asked()[0].windows(2).any(|pair| pair[0] == "--keep-daily" && pair[1] == "7"));
        assert_eq!(fake.asked()[1], args(&["prune"]));

        let fake = FakeRestic::new(vec![run(0, r#"[{"keep":[{"id":"k1"}],"remove":null}]"#, "")]);
        assert_eq!(forget(&fake, &repo("/r"), &Keep { daily: 7, weekly: 0, monthly: 0 }).unwrap()["pruned"], false);
        assert_eq!(fake.asked().len(), 1);

        assert!(forget(&FakeRestic::new(vec![]), &repo("/r"), &Keep { daily: 0, weekly: 0, monthly: 0 }).is_err());
    }
}
