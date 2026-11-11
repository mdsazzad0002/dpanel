//! Restore tests (backlog W8): proof that the restore points can be read
//! back, not only that restic wrote them. `POST /api/v1/restic/check` and
//! `/verify`.
//!
//! `check` is restic's own integrity check of the repository, reading a share
//! of the stored data back. `verify` reads one website's restore point the way
//! a restore would: every database dump in full, which must end the way a
//! finished `mysqldump` ends, and a spread of the home's files, whose bytes
//! must add up to what the snapshot recorded. restic checks each piece of data
//! against its hash as it reads, so damage shows as a failed read. Nothing is
//! written anywhere: what is read is counted and dropped.

use std::{path::Path, sync::Arc};

use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::Response,
    routing::post,
};
use serde::Deserialize;
use serde_json::{Value, json};

use super::{
    restic::{Repo, Restic, SystemRestic, answer, args, staging_dir},
    restic_restore::{Target, nodes, owned},
};
use crate::{api::ApiState, platform};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/restic/check", post(check_handle))
        .route("/api/v1/restic/verify", post(verify_handle))
}

/// How many of the home's files a test reads at most.
const MAX_SAMPLE: usize = 100;

/// Files larger than this are counted, not read, so a test stays short.
const MAX_SAMPLE_BYTES: u64 = 256 * 1024 * 1024;

/// How a finished `mysqldump` (MySQL and MariaDB) ends its output.
const DUMP_TRAILER: &[u8] = b"-- Dump completed";

/// restic's integrity check. `read_data_percent` of the packs are read back
/// in full (0: only the structure). A damaged repository is an answer, not an
/// error; an error means restic could not run or open it.
pub(crate) fn check(restic: &dyn Restic, repo: &Repo, read_data_percent: u32) -> Result<Value, String> {
    // A backup may hold the lock; wait for it rather than fail.
    let mut command = args(&["check", "--retry-lock", "10m"]);
    let percent = read_data_percent.min(100);
    if percent > 0 {
        command.push(format!("--read-data-subset={percent}%"));
    }
    let run = restic.run(repo, &command)?;
    let reason = run.reason();
    if !run.ok() && (reason.contains("wrong password") || reason.contains("no key found") || reason.contains("unable to open")) {
        return Err(format!("restic could not open the repository: {reason}"));
    }
    Ok(json!({
        "healthy": run.ok(),
        "read_data_percent": percent,
        "message": if run.ok() { "No errors were found.".to_string() } else { reason },
    }))
}

/// Reads all of `path` from the restore point; gives its byte count and its
/// last bytes.
fn read_back(restic: &dyn Restic, repo: &Repo, snapshot: &str, path: &str) -> Result<(u64, Vec<u8>), String> {
    let mut total = 0u64;
    let mut tail: Vec<u8> = Vec::new();
    restic.stream(repo, &args(&["dump", snapshot, path]), &mut |input| {
        let mut buffer = vec![0u8; 64 * 1024];
        loop {
            let read = input.read(&mut buffer).map_err(|error| format!("reading {path} failed: {error}"))?;
            if read == 0 {
                return Ok(());
            }
            total += read as u64;
            tail.extend_from_slice(&buffer[..read]);
            if tail.len() > 1024 {
                tail.drain(..tail.len() - 1024);
            }
        }
    })?;
    Ok((total, tail))
}

fn size_of(node: &Value) -> u64 {
    node["size"].as_u64().unwrap_or(0)
}

/// `count` files spread evenly over the sorted list, so a test sees the whole
/// home and the same restore point is always tested the same way.
fn spread(files: &[&Value], count: usize) -> Vec<usize> {
    if files.is_empty() || count == 0 {
        return Vec::new();
    }
    let count = count.min(files.len());
    let mut picked: Vec<usize> = (0..count).map(|step| step * files.len() / count).collect();
    picked.dedup();
    picked
}

fn mismatch(bytes: u64, expected: u64) -> String {
    format!("read {bytes} bytes, the restore point recorded {expected}")
}

/// One website's restore point, read back. `ok` only when every dump is whole
/// and every sampled file reads back at its recorded size.
pub(crate) fn verify(restic: &dyn Restic, repo: &Repo, snapshot: &str, website: &Target, home: &Path, state_dir: &Path, sample: usize) -> Result<Value, String> {
    website.checked()?;
    owned(restic, repo, snapshot, website)?;

    let listed = nodes(restic, repo, snapshot, home)?;
    let mut files: Vec<&Value> = listed
        .iter()
        .filter(|node| node["type"] == "file")
        .filter(|node| node["path"].as_str().is_some_and(|path| Path::new(path).starts_with(home)))
        .collect();
    files.sort_by(|a, b| a["path"].as_str().cmp(&b["path"].as_str()));
    let total_bytes: u64 = files.iter().map(|node| size_of(node)).sum();
    let readable: Vec<&Value> = files.iter().copied().filter(|node| size_of(node) <= MAX_SAMPLE_BYTES).collect();

    let mut sampled = Vec::new();
    let mut files_ok = true;
    for index in spread(&readable, sample.min(MAX_SAMPLE)) {
        let node = readable[index];
        let path = node["path"].as_str().unwrap_or_default();
        let expected = size_of(node);
        let (ok, error) = match read_back(restic, repo, snapshot, path) {
            Ok((bytes, _)) if bytes == expected => (true, None),
            Ok((bytes, _)) => (false, Some(mismatch(bytes, expected))),
            Err(error) => (false, Some(error)),
        };
        files_ok &= ok;
        sampled.push(json!({ "path": path, "size": expected, "ok": ok, "error": error }));
    }

    let dump_dir = staging_dir(state_dir, &website.id).join("databases");
    // A website without databases has no dumps in its restore point.
    let dumps: Vec<Value> = match nodes(restic, repo, snapshot, &dump_dir) {
        Ok(listed) => listed
            .into_iter()
            .filter(|node| node["type"] == "file" && node["path"].as_str().is_some_and(|path| Path::new(path).parent() == Some(dump_dir.as_path())))
            .collect(),
        Err(error) if error.contains("not found") || error.contains("no such file") => Vec::new(),
        Err(error) => return Err(error),
    };
    let mut databases = Vec::new();
    let mut databases_ok = true;
    for node in &dumps {
        let path = node["path"].as_str().unwrap_or_default();
        let expected = size_of(node);
        let (ok, error) = match read_back(restic, repo, snapshot, path) {
            Ok((bytes, _)) if bytes != expected => (false, Some(mismatch(bytes, expected))),
            Ok((_, tail)) if !tail.windows(DUMP_TRAILER.len()).any(|window| window == DUMP_TRAILER) => {
                (false, Some("the dump does not end like a finished mysqldump; it may be cut short".to_string()))
            }
            Ok(_) => (true, None),
            Err(error) => (false, Some(error)),
        };
        databases_ok &= ok;
        databases.push(json!({ "name": node["name"], "size": expected, "ok": ok, "error": error }));
    }

    Ok(json!({
        "snapshot_id": snapshot,
        "ok": files_ok && databases_ok,
        "files": files.len(),
        "bytes": total_bytes,
        "too_large_to_sample": files.len() - readable.len(),
        "sampled": sampled,
        "databases": databases,
    }))
}

#[derive(Deserialize)]
struct CheckRequest {
    #[serde(flatten)]
    repo: Repo,
    #[serde(default)]
    read_data_percent: u32,
}

#[derive(Deserialize)]
struct VerifyRequest {
    #[serde(flatten)]
    repo: Repo,
    snapshot_id: String,
    website: Target,
    #[serde(default = "default_sample")]
    sample: usize,
}

fn default_sample() -> usize {
    20
}

async fn check_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<CheckRequest>) -> Response {
    let repo = request.repo.clone();
    answer(&state, &headers, &request.repo, "Repository checked", move || check(&SystemRestic, &repo, request.read_data_percent)).await
}

async fn verify_handle(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<VerifyRequest>) -> Response {
    let repo = request.repo.clone();
    answer(&state, &headers, &request.repo, "Restore point tested", move || {
        let paths = platform::paths();
        let home = paths.site_home(&request.website.site_owner);
        verify(&SystemRestic, &repo, &request.snapshot_id, &request.website, &home, paths.state_dir(), request.sample)
    })
    .await
}

#[cfg(test)]
mod tests {
    use std::{collections::BTreeMap, io::Read, path::PathBuf, sync::Mutex};

    use super::super::restic::Run;
    use super::*;

    const SNAP: &str = "aaaa1111bbbb2222";

    /// `snapshots` and `ls` from fixed text (`ls` by the folder asked), `dump`
    /// from bytes or an error, `check` from `check`.
    struct FakeRestic {
        ls: BTreeMap<String, String>,
        dumps: BTreeMap<String, Result<Vec<u8>, String>>,
        check: Option<(i32, String)>,
        asked: Mutex<Vec<Vec<String>>>,
    }

    impl FakeRestic {
        fn asked(&self) -> Vec<Vec<String>> {
            self.asked.lock().unwrap().clone()
        }
    }

    fn answer(code: i32, stdout: &str, stderr: &str) -> Run {
        Run { code: Some(code), stdout: stdout.into(), stderr: stderr.into() }
    }

    impl Restic for FakeRestic {
        fn run(&self, _: &Repo, args: &[String]) -> Result<Run, String> {
            assert_eq!(args[0] == "check", self.check.is_some(), "unexpected restic {args:?}");
            self.asked.lock().unwrap().push(args.to_vec());
            Ok(match args[0].as_str() {
                "snapshots" => answer(0, &format!(r#"[{{"id":"{SNAP}","tags":["dpanel","website:w1"]}}]"#), ""),
                "ls" => match self.ls.get(args.last().unwrap()) {
                    Some(text) => answer(0, text, ""),
                    None => answer(1, "", "Fatal: cannot ls: path not found"),
                },
                "check" => {
                    let (code, stderr) = self.check.clone().unwrap();
                    answer(code, "", &stderr)
                }
                other => panic!("unexpected restic {other}"),
            })
        }

        fn stream(&self, _: &Repo, args: &[String], read: &mut dyn FnMut(&mut dyn Read) -> Result<(), String>) -> Result<(), String> {
            self.asked.lock().unwrap().push(args.to_vec());
            match self.dumps.get(args.last().unwrap()).unwrap_or_else(|| panic!("no dump of {:?}", args.last())) {
                Ok(bytes) => read(&mut bytes.as_slice()),
                Err(error) => Err(error.clone()),
            }
        }
    }

    fn repo() -> Repo {
        Repo { repository: "/r".into(), password: "secret".into(), env: BTreeMap::new() }
    }

    fn website() -> Target {
        Target { id: "w1".into(), site_owner: "shop".into(), databases: vec![] }
    }

    fn node(path: &str, kind: &str, size: u64) -> String {
        let name = Path::new(path).file_name().unwrap().to_string_lossy();
        format!(r#"{{"struct_type":"node","name":"{name}","type":"{kind}","path":"{path}","size":{size}}}"#)
    }

    fn listing(nodes: &[String]) -> String {
        format!("{{\"struct_type\":\"snapshot\",\"id\":\"{SNAP}\"}}\n{}", nodes.join("\n"))
    }

    fn dumps_path(state: &Path) -> PathBuf {
        staging_dir(state, "w1").join("databases")
    }

    fn fake(state: &Path, files: &[(&str, &[u8])], dumps: &[(&str, &[u8])]) -> FakeRestic {
        let mut ls = BTreeMap::new();
        let mut contents = BTreeMap::new();
        let mut home = vec![node("/home/shop", "dir", 0)];
        for (path, body) in files {
            home.push(node(path, "file", body.len() as u64));
            contents.insert(path.to_string(), Ok(body.to_vec()));
        }
        ls.insert("/home/shop".to_string(), listing(&home));
        if !dumps.is_empty() {
            let dir = dumps_path(state);
            let mut listed = vec![node(&dir.display().to_string(), "dir", 0)];
            for (name, body) in dumps {
                let path = dir.join(name).display().to_string();
                listed.push(node(&path, "file", body.len() as u64));
                contents.insert(path, Ok(body.to_vec()));
            }
            ls.insert(dir.display().to_string(), listing(&listed));
        }
        FakeRestic { ls, dumps: contents, check: None, asked: Mutex::default() }
    }

    const GOOD_DUMP: &[u8] = b"CREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\n-- Dump completed on 2026-11-11  3:00:01\n";

    fn test(fake: &FakeRestic, state: &Path, sample: usize) -> Value {
        verify(fake, &repo(), SNAP, &website(), Path::new("/home/shop"), state, sample).unwrap()
    }

    #[test]
    fn a_whole_restore_point_passes_and_every_dump_is_read_in_full() {
        let state = tempfile::tempdir().unwrap();
        let fake = fake(state.path(), &[("/home/shop/index.php", b"<?php echo 1;"), ("/home/shop/wp/a.css", b"body{}")], &[("shop_wp", GOOD_DUMP)]);

        let report = test(&fake, state.path(), 20);

        assert_eq!(report["ok"], true, "{report}");
        assert_eq!(report["files"], 2);
        assert_eq!(report["bytes"], 19);
        assert_eq!(report["sampled"].as_array().unwrap().len(), 2);
        assert_eq!(report["databases"][0]["name"], "shop_wp");
        assert_eq!(report["databases"][0]["ok"], true);
        let dumped: Vec<String> = fake.asked().iter().filter(|call| call[0] == "dump").map(|call| call[2].clone()).collect();
        assert!(dumped.contains(&dumps_path(state.path()).join("shop_wp").display().to_string()));
        // Only reads: no restore, backup or forget.
        assert!(fake.asked().iter().all(|call| ["snapshots", "ls", "dump"].contains(&call[0].as_str())));
    }

    #[test]
    fn a_cut_short_dump_or_an_unreadable_file_fails_the_test() {
        let state = tempfile::tempdir().unwrap();
        let cut = fake(state.path(), &[("/home/shop/index.php", b"<?php")], &[("shop_wp", b"CREATE TABLE t (id int);\nINSERT INTO t VAL")]);
        let report = test(&cut, state.path(), 20);
        assert_eq!(report["ok"], false);
        assert_eq!(report["sampled"][0]["ok"], true);
        assert!(report["databases"][0]["error"].as_str().unwrap().contains("cut short"));

        let mut fake = fake(state.path(), &[("/home/shop/index.php", b"<?php")], &[("shop_wp", GOOD_DUMP)]);
        fake.dumps.insert("/home/shop/index.php".into(), Err("restic failed: ciphertext verification failed".into()));
        let report = test(&fake, state.path(), 20);
        assert_eq!(report["ok"], false);
        assert_eq!(report["databases"][0]["ok"], true);
        assert!(report["sampled"][0]["error"].as_str().unwrap().contains("ciphertext"));

        // Fewer bytes than the snapshot recorded fails too.
        fake.dumps.insert("/home/shop/index.php".into(), Ok(b"<?".to_vec()));
        let report = test(&fake, state.path(), 20);
        assert!(report["sampled"][0]["error"].as_str().unwrap().contains("read 2 bytes"));
    }

    #[test]
    fn the_sample_spreads_over_the_home_and_skips_huge_files() {
        let state = tempfile::tempdir().unwrap();
        let names: Vec<String> = (0..50).map(|n| format!("/home/shop/f{n:02}")).collect();
        let files: Vec<(&str, &[u8])> = names.iter().map(|name| (name.as_str(), b"x" as &[u8])).collect();
        let mut fake = fake(state.path(), &files, &[]);
        let home = fake.ls.get_mut("/home/shop").unwrap();
        home.push('\n');
        home.push_str(&node("/home/shop/video.mp4", "file", MAX_SAMPLE_BYTES + 1));

        let report = test(&fake, state.path(), 5);

        assert_eq!(report["ok"], true, "{report}");
        assert_eq!(report["files"], 51);
        assert_eq!(report["too_large_to_sample"], 1);
        let picked: Vec<&str> = report["sampled"].as_array().unwrap().iter().map(|item| item["path"].as_str().unwrap()).collect();
        assert_eq!(picked, ["/home/shop/f00", "/home/shop/f10", "/home/shop/f20", "/home/shop/f30", "/home/shop/f40"]);
        assert!(report["databases"].as_array().unwrap().is_empty());
    }

    #[test]
    fn only_the_websites_own_restore_points_are_tested() {
        let state = tempfile::tempdir().unwrap();
        let fake = fake(state.path(), &[], &[]);
        let other = Target { id: "w2".into(), ..website() };
        assert!(verify(&fake, &repo(), SNAP, &other, Path::new("/home/shop"), state.path(), 5).unwrap_err().contains("not this website's"));
        assert!(verify(&fake, &repo(), "../x", &website(), Path::new("/home/shop"), state.path(), 5).is_err());
    }

    #[test]
    fn check_reads_a_share_of_the_data_and_reports_damage_as_an_answer() {
        let mut fake = fake(Path::new("/s"), &[], &[]);
        fake.check = Some((0, String::new()));
        assert_eq!(check(&fake, &repo(), 5).unwrap()["healthy"], true);
        assert_eq!(fake.asked()[0], args(&["check", "--retry-lock", "10m", "--read-data-subset=5%"]));

        fake.check = Some((1, "error: pack 1a2b: not referenced in any index\nFatal: repository contains errors".into()));
        let report = check(&fake, &repo(), 0).unwrap();
        assert_eq!(report["healthy"], false);
        assert!(report["message"].as_str().unwrap().contains("repository contains errors"));
        assert_eq!(fake.asked()[1], args(&["check", "--retry-lock", "10m"]));

        fake.check = Some((12, "Fatal: wrong password or no key found".into()));
        assert!(check(&fake, &repo(), 0).is_err());
    }
}
