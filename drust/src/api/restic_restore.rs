//! Restoring from a restore point (backlog W7): one file or folder, one
//! database, or one mailbox. `POST /api/v1/restic/ls` and `/restore`.
//!
//! restic runs as root, because only root may hold the repository key. What
//! it gives back is written by a `tar` running as the site user, so a link
//! the customer planted in their home can never steer a root write, and the
//! files count against their quota like anything else they own. Nothing in
//! place is overwritten: files land in `restored-<stamp>/` in the home, mail
//! in a new `Restored-<stamp>` folder of the mailbox, and a database is first
//! dumped to `restored-<stamp>/before-restore/` before the old one goes in.

use std::{
    collections::BTreeSet,
    fs,
    io::{Read, Write},
    path::{Component, Path, PathBuf},
    process::Stdio,
    sync::Arc,
};

use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::Response,
    routing::post,
};
use serde::Deserialize;
use serde_json::{Value, json};

use super::restic::{Database, Repo, Restic, SystemRestic, answer, args, safe_word, staging_dir};
use crate::{
    api::ApiState,
    database::cli,
    platform::{self, paths::Tool},
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/restic/ls", post(ls_handle))
        .route("/api/v1/restic/restore", post(restore_handle))
}

/// Writes a tar stream into `dir` as `owner`. A trait so tests need no users.
pub(crate) trait Extractor: Send + Sync {
    fn extract(&self, owner: &str, dir: &Path, feed: &mut dyn FnMut(&mut dyn Write) -> Result<(), String>) -> Result<(), String>;
}

struct UserTar;

impl Extractor for UserTar {
    fn extract(&self, owner: &str, dir: &Path, feed: &mut dyn FnMut(&mut dyn Write) -> Result<(), String>) -> Result<(), String> {
        let tar = platform::paths().binary(Tool::Tar).map_err(|error| error.to_string())?;
        // -k: an existing file is never replaced; the user cannot chown anyway.
        let mut child = platform::current()
            .runner()
            .command(owner, tar.as_os_str())
            .map_err(|error| error.to_string())?
            .args(["-x", "-k", "--no-same-owner", "-f", "-", "-C"])
            .arg(dir)
            .stdin(Stdio::piped())
            .stdout(Stdio::null())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|error| format!("cannot run tar as {owner}: {error}"))?;
        let mut stdin = child.stdin.take().ok_or("tar took no input.")?;
        let fed = feed(&mut stdin);
        drop(stdin);
        let output = child.wait_with_output().map_err(|error| format!("tar did not finish: {error}"))?;
        fed?;
        if !output.status.success() {
            let reason = String::from_utf8_lossy(&output.stderr);
            return Err(format!("writing the restored files failed: {}", reason.trim().chars().take(1000).collect::<String>()));
        }
        Ok(())
    }
}

/// A database's current content out, and a dump in.
pub(crate) trait DatabaseIo: Send + Sync {
    fn dump(&self, database: &Database, out: &mut dyn Write) -> Result<(), String>;
    fn import(&self, database: &Database, input: &mut dyn Read) -> Result<(), String>;
}

struct MysqlIo;

impl MysqlIo {
    fn login(database: &Database) -> cli::Login<'_> {
        cli::Login { host: &database.host, user: &database.user, password: &database.password }
    }
}

impl DatabaseIo for MysqlIo {
    fn dump(&self, database: &Database, out: &mut dyn Write) -> Result<(), String> {
        let mut child = cli::dump_command(&Self::login(database), &database.name, &["--single-transaction", "--quick", "--routines", "--triggers"])
            .stdout(Stdio::piped())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|error| format!("cannot run mysqldump: {error}"))?;
        let mut stdout = child.stdout.take().ok_or("mysqldump gave no output.")?;
        let copied = std::io::copy(&mut stdout, out).map_err(|error| error.to_string());
        let output = child.wait_with_output().map_err(|error| error.to_string())?;
        copied?;
        if !output.status.success() {
            return Err(format!("database dump failed for {}: {}", database.name, String::from_utf8_lossy(&output.stderr).trim()));
        }
        Ok(())
    }

    fn import(&self, database: &Database, input: &mut dyn Read) -> Result<(), String> {
        let mut child = cli::import_command(&Self::login(database), &database.name)
            .stdin(Stdio::piped())
            .stdout(Stdio::null())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|error| format!("cannot run mysql: {error}"))?;
        let mut stdin = child.stdin.take().ok_or("mysql took no input.")?;
        let copied = std::io::copy(input, &mut stdin).map_err(|error| error.to_string());
        drop(stdin);
        let output = child.wait_with_output().map_err(|error| error.to_string())?;
        copied?;
        if !output.status.success() {
            return Err(format!("importing {} failed: {}", database.name, String::from_utf8_lossy(&output.stderr).trim()));
        }
        Ok(())
    }
}

#[derive(Deserialize)]
pub(crate) struct Target {
    pub id: String,
    pub site_owner: String,
    #[serde(default)]
    pub databases: Vec<Database>,
}

impl Target {
    fn checked(&self) -> Result<(), String> {
        if !safe_word(&self.id, b"-") {
            return Err("Invalid website id.".into());
        }
        if !safe_word(&self.site_owner, b"_-") {
            return Err("Invalid website owner.".into());
        }
        Ok(())
    }
}

fn safe_snapshot(id: &str) -> bool {
    (8..=64).contains(&id.len()) && id.bytes().all(|byte| byte.is_ascii_hexdigit())
}

/// An absolute path at or below `home`, without `.` or `..`.
fn within(home: &Path, path: &str) -> Result<PathBuf, String> {
    let candidate = PathBuf::from(path.trim_end_matches('/'));
    let plain = candidate.is_absolute()
        && path.len() <= 4096
        && !path.chars().any(char::is_control)
        && candidate.components().all(|part| matches!(part, Component::RootDir | Component::Normal(_)));
    if !plain || !candidate.starts_with(home) {
        return Err(format!("Only paths in {} can be restored.", home.display()));
    }
    Ok(candidate)
}

/// The snapshot, once its tags show it belongs to `website`.
fn owned(restic: &dyn Restic, repo: &Repo, snapshot: &str, website: &Target) -> Result<Value, String> {
    if !safe_snapshot(snapshot) {
        return Err("Invalid restore point.".into());
    }
    let run = restic.run(repo, &args(&["snapshots", "--json", snapshot]))?;
    if !run.ok() {
        return Err(format!("restic could not find the restore point: {}", run.reason()));
    }
    let listed: Vec<Value> = serde_json::from_str(run.stdout.trim()).unwrap_or_default();
    let tag = format!("website:{}", website.id);
    listed
        .into_iter()
        .find(|item| item["tags"].as_array().is_some_and(|tags| tags.iter().any(|value| value.as_str() == Some(&tag))))
        .ok_or_else(|| "That restore point is not this website's.".to_string())
}

/// `restic ls` of `path`: its own node and, for a folder, what is directly in it.
fn nodes(restic: &dyn Restic, repo: &Repo, snapshot: &str, path: &Path) -> Result<Vec<Value>, String> {
    let run = restic.run(repo, &args(&["ls", "--json", snapshot, &path.display().to_string()]))?;
    if !run.ok() {
        return Err(format!("restic could not list the restore point: {}", run.reason()));
    }
    Ok(run
        .stdout
        .lines()
        .filter_map(|line| serde_json::from_str::<Value>(line).ok())
        .filter(|node| node["struct_type"] == "node" || (node.get("struct_type").is_none() && node.get("type").is_some() && node.get("path").is_some()))
        .collect())
}

fn node_at<'a>(nodes: &'a [Value], path: &Path) -> Option<&'a Value> {
    nodes.iter().find(|node| node["path"].as_str().is_some_and(|value| Path::new(value) == path))
}

pub(crate) fn list(restic: &dyn Restic, repo: &Repo, snapshot: &str, website: &Target, home: &Path, path: &str) -> Result<Value, String> {
    website.checked()?;
    let path = within(home, path)?;
    owned(restic, repo, snapshot, website)?;
    let entries: Vec<Value> = nodes(restic, repo, snapshot, &path)?
        .iter()
        .filter(|node| node["path"].as_str().is_some_and(|value| Path::new(value).parent() == Some(path.as_path())))
        .map(|node| json!({ "name": node["name"], "path": node["path"], "type": node["type"], "size": node["size"], "mtime": node["mtime"] }))
        .collect();
    Ok(json!({ "path": path.display().to_string(), "entries": entries }))
}

/// A restic tar entry's path relative to the folder that was dumped, however
/// restic names it (`/home/a/x/f`, `home/a/x/f` or `x/f`). `None` for the
/// folder itself, and for anything that climbs out.
fn relative(entry: &str, dumped: &Path) -> Option<PathBuf> {
    let entry = Path::new(entry.trim_start_matches("./").trim_start_matches('/'));
    let full = dumped.strip_prefix("/").unwrap_or(dumped);
    let name = dumped.file_name().map(Path::new);
    let rest = entry
        .strip_prefix(full)
        .ok()
        .or_else(|| name.and_then(|name| entry.strip_prefix(name).ok()))
        .unwrap_or(entry);
    let plain = rest.components().all(|part| matches!(part, Component::Normal(_)));
    (plain && rest.components().next().is_some()).then(|| rest.to_path_buf())
}

/// Copies restic's tar into `out`, each entry moved by `place` (`None`
/// drops it). Only files, folders and symlinks; no owners; links stay links.
fn rewrite(input: &mut dyn Read, out: &mut dyn Write, place: &mut dyn FnMut(&Path, tar::EntryType) -> Vec<(PathBuf, tar::EntryType)>) -> Result<(), String> {
    let mut archive = tar::Archive::new(input);
    let mut builder = tar::Builder::new(out);
    builder.mode(tar::HeaderMode::Deterministic);
    let failed = |error: std::io::Error| format!("the restore point could not be read: {error}");
    for entry in archive.entries().map_err(failed)? {
        let mut entry = entry.map_err(failed)?;
        let kind = entry.header().entry_type();
        if !matches!(kind, tar::EntryType::Regular | tar::EntryType::Directory | tar::EntryType::Symlink) {
            continue;
        }
        let path = entry.path().map_err(failed)?.into_owned();
        let link = entry.link_name().map_err(failed)?.map(|link| link.into_owned());
        let mode = entry.header().mode().unwrap_or(0o644) & 0o777;
        let mtime = entry.header().mtime().unwrap_or(0);
        let size = entry.header().size().unwrap_or(0);
        for (target, as_kind) in place(&path, kind) {
            let mut header = tar::Header::new_gnu();
            header.set_entry_type(as_kind);
            header.set_mode(if as_kind == tar::EntryType::Directory { mode | 0o700 } else { mode | 0o600 });
            header.set_mtime(mtime);
            match as_kind {
                tar::EntryType::Regular => {
                    header.set_size(size);
                    builder.append_data(&mut header, &target, &mut entry).map_err(|error| error.to_string())?;
                }
                tar::EntryType::Symlink => {
                    header.set_size(0);
                    let Some(link) = link.as_ref() else { continue };
                    builder.append_link(&mut header, &target, link).map_err(|error| error.to_string())?;
                }
                _ => {
                    header.set_size(0);
                    builder.append_data(&mut header, &target, std::io::empty()).map_err(|error| error.to_string())?;
                }
            }
        }
    }
    builder.finish().map_err(|error| error.to_string())
}

/// One file as a tar of one entry.
fn single(input: &mut dyn Read, out: &mut dyn Write, target: &Path, size: u64, mode: u32) -> Result<(), String> {
    let mut builder = tar::Builder::new(out);
    let mut header = tar::Header::new_gnu();
    header.set_entry_type(tar::EntryType::Regular);
    header.set_size(size);
    header.set_mode((mode & 0o777) | 0o600);
    header.set_mtime(0);
    builder.append_data(&mut header, target, input).map_err(|error| format!("the file could not be restored: {error}"))?;
    builder.finish().map_err(|error| error.to_string())
}

/// Unix mode bits from restic's `mode` (Go's FileMode keeps them low).
fn mode_of(node: &Value) -> u32 {
    node["mode"].as_u64().map(|mode| (mode & 0o777) as u32).unwrap_or(0o644)
}

pub(crate) struct Tools<'a> {
    pub restic: &'a dyn Restic,
    pub extract: &'a dyn Extractor,
    pub databases: &'a dyn DatabaseIo,
}

/// A file or folder into `restored-<stamp>/` in the home.
pub(crate) fn restore_files(tools: &Tools, repo: &Repo, snapshot: &str, website: &Target, home: &Path, path: &str, stamp: &str) -> Result<Value, String> {
    website.checked()?;
    let path = within(home, path)?;
    owned(tools.restic, repo, snapshot, website)?;
    let listed = nodes(tools.restic, repo, snapshot, &path)?;
    let node = node_at(&listed, &path).ok_or_else(|| format!("{} is not in that restore point.", path.display()))?;
    let name = path.file_name().map(PathBuf::from).unwrap_or_else(|| PathBuf::from(&website.site_owner));
    let folder = PathBuf::from(format!("restored-{stamp}"));
    let target = folder.join(&name);
    let path_text = path.display().to_string();

    match node["type"].as_str() {
        Some("file") => {
            let size = node["size"].as_u64().unwrap_or(0);
            let mode = mode_of(node);
            tools.extract.extract(&website.site_owner, home, &mut |out| {
                tools.restic.stream(repo, &args(&["dump", snapshot, &path_text]), &mut |input| single(input, out, &target, size, mode))
            })?;
        }
        Some("dir") => {
            tools.extract.extract(&website.site_owner, home, &mut |out| {
                let mut place = |entry: &Path, kind| match relative(&entry.display().to_string(), &path) {
                    Some(rest) => vec![(target.join(rest), kind)],
                    None => vec![],
                };
                tools.restic.stream(repo, &args(&["dump", "--archive", "tar", snapshot, &path_text]), &mut |input| rewrite(input, out, &mut place))
            })?;
        }
        _ => return Err("Only files and folders can be restored.".into()),
    }
    Ok(json!({ "kind": "files", "restored_to": home.join(&target).display().to_string() }))
}

/// Maildir++: messages of the mailbox and of each subfolder land in a new
/// `Restored-<stamp>` folder (and `Restored-<stamp>.<Sub>`), next to what
/// is there now. Indexes and `tmp` are left behind; Dovecot rebuilds them.
fn mail_place(folder: &str, created: &mut BTreeSet<String>, entry: &Path, kind: tar::EntryType) -> Vec<(PathBuf, tar::EntryType)> {
    let parts: Vec<String> = entry.components().map(|part| part.as_os_str().to_string_lossy().into_owned()).collect();
    let (sub, box_dir, file) = match parts.as_slice() {
        [state, file] if state == "cur" || state == "new" => (String::new(), state.clone(), file.clone()),
        [sub, state, file] if sub.starts_with('.') && (state == "cur" || state == "new") => (sub.clone(), state.clone(), file.clone()),
        _ => return vec![],
    };
    if kind != tar::EntryType::Regular {
        return vec![];
    }
    let restored = format!(".{folder}{sub}");
    let mut out = Vec::new();
    if created.insert(restored.clone()) {
        // The folder, then the three subfolders every Maildir has.
        out.push((PathBuf::from(&restored), tar::EntryType::Directory));
        for dir in ["cur", "new", "tmp"] {
            out.push((PathBuf::from(&restored).join(dir), tar::EntryType::Directory));
        }
    }
    // A restored message counts as read-or-not as it was; `new` ones go to `new`.
    out.push((PathBuf::from(format!("{restored}/{box_dir}/{file}")), tar::EntryType::Regular));
    out
}

pub(crate) fn restore_mailbox(tools: &Tools, repo: &Repo, snapshot: &str, website: &Target, home: &Path, maildir: &str, stamp: &str) -> Result<Value, String> {
    website.checked()?;
    let maildir = within(home, maildir)?;
    if !maildir.starts_with(home.join("mail")) || maildir.file_name().and_then(|name| name.to_str()) != Some("Maildir") {
        return Err("That is not a mailbox of this website.".into());
    }
    if !maildir.is_dir() {
        return Err("The mailbox does not exist now. Create it again, then restore into it.".into());
    }
    owned(tools.restic, repo, snapshot, website)?;
    let listed = nodes(tools.restic, repo, snapshot, &maildir)?;
    if node_at(&listed, &maildir).is_none_or(|node| node["type"] != "dir") {
        return Err("That mailbox is not in the restore point.".into());
    }
    let folder = format!("Restored-{stamp}");
    let mut created = BTreeSet::new();
    let path_text = maildir.display().to_string();
    tools.extract.extract(&website.site_owner, &maildir, &mut |out| {
        let mut place = |entry: &Path, kind| match relative(&entry.display().to_string(), &maildir) {
            Some(rest) => mail_place(&folder, &mut created, &rest, kind),
            None => vec![],
        };
        tools.restic.stream(repo, &args(&["dump", "--archive", "tar", snapshot, &path_text]), &mut |input| rewrite(input, out, &mut place))
    })?;
    // Without the marker Dovecot does not treat the folder as a mailbox.
    for restored in &created {
        let marker = PathBuf::from(restored).join("maildirfolder");
        tools.extract.extract(&website.site_owner, &maildir, &mut |out| single(&mut std::io::empty(), out, &marker, 0, 0o600))?;
    }
    Ok(json!({ "kind": "mailbox", "folder": folder, "folders": created.len() }))
}

/// The database as it was. The one there now is saved first, as the site
/// user's file; if that fails, nothing is imported.
#[allow(clippy::too_many_arguments)]
pub(crate) fn restore_database(
    tools: &Tools,
    repo: &Repo,
    snapshot: &str,
    website: &Target,
    home: &Path,
    state_dir: &Path,
    name: &str,
    stamp: &str,
) -> Result<Value, String> {
    website.checked()?;
    let database = website.databases.iter().find(|database| database.name == name).ok_or("That database is not this website's.")?;
    owned(tools.restic, repo, snapshot, website)?;
    let dump = staging_dir(state_dir, &website.id).join("databases").join(cli::dump_file_name(&database.name));
    if node_at(&nodes(tools.restic, repo, snapshot, &dump)?, &dump).is_none() {
        return Err(format!("The restore point has no dump of {name}."));
    }

    // The current content, to a private file first: tar needs its size.
    let scratch = state_dir.join("restic-restore");
    platform::files().create_dir_private(&scratch).map_err(|error| error.to_string())?;
    let saved = scratch.join(format!("{}-{stamp}.sql", website.id));
    let result = (|| {
        let mut file = platform::files().create_private(&saved).map_err(|error| error.to_string())?;
        tools.databases.dump(database, &mut file)?;
        drop(file);
        let size = fs::metadata(&saved).map_err(|error| error.to_string())?.len();
        let before = PathBuf::from(format!("restored-{stamp}")).join("before-restore").join(cli::dump_file_name(&database.name));
        tools.extract.extract(&website.site_owner, home, &mut |out| {
            let mut file = fs::File::open(&saved).map_err(|error| error.to_string())?;
            single(&mut file, out, &before, size, 0o600)
        })?;
        let dump_text = dump.display().to_string();
        tools.restic.stream(repo, &args(&["dump", snapshot, &dump_text]), &mut |input| tools.databases.import(database, input))?;
        Ok(json!({ "kind": "database", "database": name, "before_restore": home.join(before).display().to_string() }))
    })();
    let _ = fs::remove_file(&saved);
    result
}

#[derive(Deserialize)]
struct LsRequest {
    #[serde(flatten)]
    repo: Repo,
    snapshot_id: String,
    website: Target,
    path: String,
}

#[derive(Deserialize)]
#[serde(tag = "kind", rename_all = "snake_case")]
enum Item {
    Files { path: String },
    Database { name: String },
    Mailbox { path: String },
}

#[derive(Deserialize)]
struct RestoreRequest {
    #[serde(flatten)]
    repo: Repo,
    snapshot_id: String,
    website: Target,
    item: Item,
}

async fn ls_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<LsRequest>) -> Response {
    let repo = request.repo.clone();
    answer(&state, &headers, &request.repo, "Restore point listed", move || {
        let home = platform::paths().site_home(&request.website.site_owner);
        list(&SystemRestic, &repo, &request.snapshot_id, &request.website, &home, &request.path)
    })
    .await
}

async fn restore_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<RestoreRequest>) -> Response {
    let repo = request.repo.clone();
    answer(&state, &headers, &request.repo, "Restored", move || {
        let paths = platform::paths();
        let home = paths.site_home(&request.website.site_owner);
        let stamp = chrono::Local::now().format("%Y%m%d-%H%M%S").to_string();
        let tools = Tools { restic: &SystemRestic, extract: &UserTar, databases: &MysqlIo };
        let (snapshot, website) = (&request.snapshot_id, &request.website);
        match &request.item {
            Item::Files { path } => restore_files(&tools, &repo, snapshot, website, &home, path, &stamp),
            Item::Database { name } => restore_database(&tools, &repo, snapshot, website, &home, paths.state_dir(), name, &stamp),
            Item::Mailbox { path } => restore_mailbox(&tools, &repo, snapshot, website, &home, path, &stamp),
        }
    })
    .await
}

#[cfg(test)]
mod tests {
    use std::{collections::BTreeMap, sync::Mutex};

    use super::super::restic::Run;
    use super::*;

    const SNAP: &str = "aaaa1111bbbb2222";

    /// Answers `snapshots` and `ls` from fixed text and `dump` from bytes.
    struct FakeRestic {
        ls: String,
        dumps: BTreeMap<String, Vec<u8>>,
        asked: Mutex<Vec<Vec<String>>>,
        website: String,
    }

    impl Restic for FakeRestic {
        fn run(&self, _: &Repo, args: &[String]) -> Result<Run, String> {
            self.asked.lock().unwrap().push(args.to_vec());
            let stdout = match args[0].as_str() {
                "snapshots" => format!(r#"[{{"id":"{SNAP}","tags":["dpanel","website:{}"]}}]"#, self.website),
                "ls" => self.ls.clone(),
                other => panic!("unexpected restic {other}"),
            };
            Ok(Run { code: Some(0), stdout, stderr: String::new() })
        }

        fn stream(&self, _: &Repo, args: &[String], read: &mut dyn FnMut(&mut dyn Read) -> Result<(), String>) -> Result<(), String> {
            self.asked.lock().unwrap().push(args.to_vec());
            let bytes = self.dumps.get(args.last().unwrap()).unwrap_or_else(|| panic!("no dump of {:?}", args.last()));
            read(&mut bytes.as_slice())
        }
    }

    /// Unpacks into a real folder, as the user's tar would.
    #[derive(Default)]
    struct FakeTar {
        calls: Mutex<Vec<(String, PathBuf)>>,
    }

    impl Extractor for FakeTar {
        fn extract(&self, owner: &str, dir: &Path, feed: &mut dyn FnMut(&mut dyn Write) -> Result<(), String>) -> Result<(), String> {
            self.calls.lock().unwrap().push((owner.into(), dir.into()));
            let mut bytes = Vec::new();
            feed(&mut bytes)?;
            tar::Archive::new(bytes.as_slice()).unpack(dir).map_err(|error| error.to_string())
        }
    }

    #[derive(Default)]
    struct FakeDb {
        imported: Mutex<Vec<String>>,
        broken_dump: bool,
    }

    impl DatabaseIo for FakeDb {
        fn dump(&self, _: &Database, out: &mut dyn Write) -> Result<(), String> {
            if self.broken_dump {
                return Err("database dump failed for shop_db".into());
            }
            out.write_all(b"-- today's content").map_err(|error| error.to_string())
        }

        fn import(&self, _: &Database, input: &mut dyn Read) -> Result<(), String> {
            let mut text = String::new();
            input.read_to_string(&mut text).unwrap();
            self.imported.lock().unwrap().push(text);
            Ok(())
        }
    }

    fn repo() -> Repo {
        Repo { repository: "/r".into(), password: "secret".into(), env: BTreeMap::new() }
    }

    fn website() -> Target {
        Target {
            id: "w1".into(),
            site_owner: "shop".into(),
            databases: vec![Database { name: "shop_db".into(), user: "u".into(), password: "p".into(), host: "h".into() }],
        }
    }

    /// A tar as restic dumps a folder: entries named by their full path.
    fn dumped(entries: &[(&str, Option<&str>)]) -> Vec<u8> {
        let mut builder = tar::Builder::new(Vec::new());
        for (path, body) in entries {
            let mut header = tar::Header::new_gnu();
            match body {
                Some(body) => {
                    header.set_entry_type(tar::EntryType::Regular);
                    header.set_size(body.len() as u64);
                    header.set_mode(0o640);
                    builder.append_data(&mut header, path, body.as_bytes()).unwrap();
                }
                None => {
                    header.set_entry_type(tar::EntryType::Directory);
                    header.set_size(0);
                    header.set_mode(0o750);
                    builder.append_data(&mut header, path, std::io::empty()).unwrap();
                }
            }
        }
        builder.into_inner().unwrap()
    }

    fn node(path: &str, kind: &str, size: u64) -> String {
        let name = Path::new(path).file_name().unwrap().to_string_lossy();
        format!(r#"{{"struct_type":"node","name":"{name}","type":"{kind}","path":"{path}","size":{size},"mode":420}}"#)
    }

    fn fake(home: &Path, ls: Vec<String>, dumps: Vec<(String, Vec<u8>)>) -> FakeRestic {
        let _ = home;
        FakeRestic {
            ls: format!("{{\"struct_type\":\"snapshot\",\"id\":\"{SNAP}\"}}\n{}", ls.join("\n")),
            dumps: dumps.into_iter().collect(),
            asked: Mutex::default(),
            website: "w1".into(),
        }
    }

    #[test]
    fn a_folder_comes_back_beside_the_live_one_and_nothing_is_overwritten() {
        let folder = tempfile::tempdir().unwrap();
        let home = folder.path().join("shop");
        fs::create_dir_all(home.join("site")).unwrap();
        fs::write(home.join("site/index.php"), "today").unwrap();
        let site = home.join("site").display().to_string();
        let base = site.trim_start_matches('/').to_string();
        let restic = fake(&home, vec![node(&site, "dir", 0)], vec![(
            site.clone(),
            dumped(&[(&base, None), (&format!("{base}/index.php"), Some("then")), (&format!("{base}/sub/a.txt"), Some("A")), ("etc/passwd", Some("x"))]),
        )]);
        let extract = FakeTar::default();
        let tools = Tools { restic: &restic, extract: &extract, databases: &FakeDb::default() };

        let data = restore_files(&tools, &repo(), SNAP, &website(), &home, &site, "20261111-120000").unwrap();

        let restored = home.join("restored-20261111-120000/site");
        assert_eq!(data["restored_to"], restored.display().to_string());
        assert_eq!(fs::read_to_string(restored.join("index.php")).unwrap(), "then");
        assert_eq!(fs::read_to_string(restored.join("sub/a.txt")).unwrap(), "A");
        assert_eq!(fs::read_to_string(home.join("site/index.php")).unwrap(), "today");
        // An entry from elsewhere in the snapshot stays inside the restore folder.
        assert!(!home.join("etc").exists());
        assert_eq!(*extract.calls.lock().unwrap(), [("shop".to_string(), home.clone())]);
    }

    #[test]
    fn a_single_file_is_restored_from_its_raw_dump() {
        let folder = tempfile::tempdir().unwrap();
        let home = folder.path().join("shop");
        fs::create_dir_all(&home).unwrap();
        let file = home.join("wp-config.php").display().to_string();
        let restic = fake(&home, vec![node(&file, "file", 5)], vec![(file.clone(), b"<?php".to_vec())]);
        let extract = FakeTar::default();
        let tools = Tools { restic: &restic, extract: &extract, databases: &FakeDb::default() };

        restore_files(&tools, &repo(), SNAP, &website(), &home, &file, "s1").unwrap();

        assert_eq!(fs::read_to_string(home.join("restored-s1/wp-config.php")).unwrap(), "<?php");
        assert_eq!(restic.asked.lock().unwrap().last().unwrap(), &args(&["dump", SNAP, &file]));
    }

    #[test]
    fn paths_and_restore_points_of_others_are_refused() {
        let folder = tempfile::tempdir().unwrap();
        let home = folder.path().join("shop");
        fs::create_dir_all(&home).unwrap();
        let tools_restic = fake(&home, vec![], vec![]);
        let extract = FakeTar::default();
        let tools = Tools { restic: &tools_restic, extract: &extract, databases: &FakeDb::default() };
        let refused = |path: &str| restore_files(&tools, &repo(), SNAP, &website(), &home, path, "s").unwrap_err();

        assert!(refused("/etc/shadow").contains("Only paths in"));
        assert!(refused(&format!("{}/../other", home.display())).contains("Only paths in"));
        assert!(refused("relative").contains("Only paths in"));
        assert!(restore_files(&tools, &repo(), "../x", &website(), &home, &home.display().to_string(), "s").unwrap_err().contains("Invalid restore point"));

        let theirs = FakeRestic { website: "w2".into(), ..fake(&home, vec![], vec![]) };
        let tools = Tools { restic: &theirs, extract: &extract, databases: &FakeDb::default() };
        assert!(restore_files(&tools, &repo(), SNAP, &website(), &home, &home.display().to_string(), "s").unwrap_err().contains("not this website's"));
        assert!(extract.calls.lock().unwrap().is_empty());
    }

    #[test]
    fn mail_comes_back_as_a_new_folder_of_the_mailbox() {
        let folder = tempfile::tempdir().unwrap();
        let home = folder.path().join("shop");
        let maildir = home.join("mail/shop.test/info/Maildir");
        fs::create_dir_all(maildir.join("cur")).unwrap();
        let text = maildir.display().to_string();
        let base = text.trim_start_matches('/').to_string();
        let restic = fake(&home, vec![node(&text, "dir", 0)], vec![(
            text.clone(),
            dumped(&[
                (&format!("{base}/cur/1.eml:2,S"), Some("old read")),
                (&format!("{base}/new/2.eml"), Some("old new")),
                (&format!("{base}/.Sent/cur/3.eml:2,S"), Some("old sent")),
                (&format!("{base}/tmp/x"), Some("half")),
                (&format!("{base}/dovecot.index"), Some("idx")),
            ]),
        )]);
        let extract = FakeTar::default();
        let tools = Tools { restic: &restic, extract: &extract, databases: &FakeDb::default() };

        let data = restore_mailbox(&tools, &repo(), SNAP, &website(), &home, &text, "2026-11-10").unwrap();

        assert_eq!(data["folder"], "Restored-2026-11-10");
        assert_eq!(fs::read_to_string(maildir.join(".Restored-2026-11-10/cur/1.eml:2,S")).unwrap(), "old read");
        assert_eq!(fs::read_to_string(maildir.join(".Restored-2026-11-10/new/2.eml")).unwrap(), "old new");
        assert_eq!(fs::read_to_string(maildir.join(".Restored-2026-11-10.Sent/cur/3.eml:2,S")).unwrap(), "old sent");
        for restored in [".Restored-2026-11-10", ".Restored-2026-11-10.Sent"] {
            assert!(maildir.join(restored).join("tmp").is_dir());
            assert!(maildir.join(restored).join("maildirfolder").is_file());
        }
        assert!(!maildir.join(".Restored-2026-11-10/tmp/x").exists());
        assert!(!maildir.join(".Restored-2026-11-10/dovecot.index").exists());

        let elsewhere = home.join("site/Maildir").display().to_string();
        assert!(restore_mailbox(&tools, &repo(), SNAP, &website(), &home, &elsewhere, "s").unwrap_err().contains("not a mailbox"));
    }

    #[test]
    fn a_database_is_saved_first_then_replaced_and_kept_if_the_save_fails() {
        let folder = tempfile::tempdir().unwrap();
        let home = folder.path().join("shop");
        let state = folder.path().join("state");
        fs::create_dir_all(&home).unwrap();
        let dump = staging_dir(&state, "w1").join("databases").join(cli::dump_file_name("shop_db")).display().to_string();
        let restic = fake(&home, vec![node(&dump, "file", 9)], vec![(dump.clone(), b"-- then".to_vec())]);
        let extract = FakeTar::default();
        let databases = FakeDb::default();
        let tools = Tools { restic: &restic, extract: &extract, databases: &databases };

        let data = restore_database(&tools, &repo(), SNAP, &website(), &home, &state, "shop_db", "s1").unwrap();

        let before = home.join("restored-s1/before-restore").join(cli::dump_file_name("shop_db"));
        assert_eq!(data["before_restore"], before.display().to_string());
        assert_eq!(fs::read_to_string(&before).unwrap(), "-- today's content");
        assert_eq!(*databases.imported.lock().unwrap(), ["-- then"]);
        assert_eq!(fs::read_dir(state.join("restic-restore")).unwrap().count(), 0);

        let broken = FakeDb { broken_dump: true, ..FakeDb::default() };
        let tools = Tools { restic: &restic, extract: &extract, databases: &broken };
        assert!(restore_database(&tools, &repo(), SNAP, &website(), &home, &state, "shop_db", "s2").unwrap_err().contains("dump failed"));
        assert!(broken.imported.lock().unwrap().is_empty());
        assert!(restore_database(&tools, &repo(), SNAP, &website(), &home, &state, "other_db", "s3").unwrap_err().contains("not this website's"));
    }

    #[test]
    fn listing_shows_what_is_directly_in_a_folder() {
        let folder = tempfile::tempdir().unwrap();
        let home = folder.path().join("shop");
        let site = home.join("site").display().to_string();
        let restic = fake(&home, vec![node(&site, "dir", 0), node(&format!("{site}/index.php"), "file", 10), node(&format!("{site}/sub/deep.txt"), "file", 1)], vec![]);

        let data = list(&restic, &repo(), SNAP, &website(), &home, &site).unwrap();

        let names: Vec<&str> = data["entries"].as_array().unwrap().iter().map(|entry| entry["name"].as_str().unwrap()).collect();
        assert_eq!(names, ["index.php"]);
    }

    #[test]
    fn entry_names_are_read_however_restic_writes_them() {
        let dumped = Path::new("/home/shop/site");
        assert_eq!(relative("/home/shop/site/a/b.txt", dumped), Some(PathBuf::from("a/b.txt")));
        assert_eq!(relative("home/shop/site/a", dumped), Some(PathBuf::from("a")));
        assert_eq!(relative("site/a", dumped), Some(PathBuf::from("a")));
        assert_eq!(relative("home/shop/site", dumped), None);
        assert_eq!(relative("home/shop/site/../../etc", dumped), None);
    }
}
