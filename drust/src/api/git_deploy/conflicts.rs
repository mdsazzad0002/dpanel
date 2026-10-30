//! Settling a sync where the live site and the repository both changed.
//!
//! `inspect` is read-only: it snapshots the live files into a commit object
//! through a throwaway index (HEAD, the index and the files are left
//! alone), three-way merges it with the remote branch in a second
//! throwaway index, and reports the files that do not merge on their own,
//! with the base, live and remote versions so a person or an AI can choose.
//!
//! `resolve` commits the live changes (so nothing is lost: they stay in
//! history), merges the remote branch, settles every conflicted file by
//! the chosen strategy, commits the merge and pushes it. Any failure before
//! the merge commit aborts the merge and leaves the site as it was.
//!
//! Strategies: `live` keeps the server's version of conflicting changes,
//! `remote` keeps the repository's (code pushed from a developer's local
//! copy), `both` keeps the lines of both sides (git's union merge), and
//! `custom` applies a per-file choice or content (from the panel's
//! per-file picker or an AI suggestion). Changes that do not conflict are
//! merged from both sides under every strategy.

use std::{
    collections::BTreeMap,
    fs,
    io::Write,
    path::Path,
    process::{Output, Stdio},
};

use serde::{Deserialize, Serialize};

use super::{Auth, Request, ancestor, clean, command, finish, git, push};

/// Contents larger than this are not sent back (or to an AI): they can still
/// be settled with live / remote / both.
const MAX_CONTENT_BYTES: usize = 200 * 1024;

const SNAPSHOT_INDEX: &str = ".git/dpanel-live.index";
const MERGE_INDEX: &str = ".git/dpanel-merge.index";

#[derive(Deserialize, Default, Clone)]
pub(super) struct Resolution {
    /// live | remote | both
    #[serde(default)]
    take: Option<String>,
    /// The file's final text; wins over `take`.
    #[serde(default)]
    content: Option<String>,
}

#[derive(Serialize)]
struct Conflict {
    path: String,
    /// text | binary | deleted | too_large
    kind: &'static str,
    base: Option<String>,
    live: Option<String>,
    remote: Option<String>,
    /// The text merge with <<<<<<< live / >>>>>>> remote markers.
    merged: Option<String>,
    live_deleted: bool,
    remote_deleted: bool,
}

fn run(
    request: &Request,
    target: &Path,
    auth: &Auth,
    env: &[(&str, String)],
    args: &[&str],
    stdin: Option<&[u8]>,
) -> Result<Output, String> {
    let mut cmd = command(request, auth);
    cmd.arg("-C").arg(target).args([
        "-c",
        "user.name=dPanel Deployment",
        "-c",
        "user.email=deploy@localhost",
    ]);
    for (key, value) in env {
        cmd.env(key, value);
    }
    cmd.args(args)
        .stdin(if stdin.is_some() {
            Stdio::piped()
        } else {
            Stdio::null()
        })
        .stdout(Stdio::piped())
        .stderr(Stdio::piped());
    let mut child = cmd
        .spawn()
        .map_err(|error| format!("Unable to start Git: {error}"))?;
    if let Some(input) = stdin {
        child
            .stdin
            .take()
            .ok_or("Git stdin unavailable")?
            .write_all(input)
            .map_err(|error| error.to_string())?;
    }
    child.wait_with_output().map_err(|error| error.to_string())
}

/// stdout of a git command that must succeed.
fn out(
    request: &Request,
    target: &Path,
    auth: &Auth,
    env: &[(&str, String)],
    args: &[&str],
    stdin: Option<&[u8]>,
) -> Result<Vec<u8>, String> {
    let output = run(request, target, auth, env, args, stdin)?;
    if output.status.success() {
        Ok(output.stdout)
    } else {
        finish(output, auth).map(|_| Vec::new())
    }
}

fn text(bytes: Vec<u8>) -> String {
    String::from_utf8_lossy(&bytes).trim().to_string()
}

/// Fetch the branch with full history: the deploy clone is shallow, and a
/// merge needs the common ancestor.
fn fetch(request: &Request, target: &Path, auth: &Auth) -> Result<(), String> {
    if !target.join(".git").is_dir() {
        return Err("Repository is not connected yet.".into());
    }
    let mut args = vec!["fetch", "--prune"];
    if target.join(".git/shallow").exists() {
        args.push("--unshallow");
    }
    args.extend(["origin", request.branch.as_str()]);
    git(request, target, auth, &args).map(|_| ())
}

/// A commit holding the live files (tracked and untracked, minus ignored)
/// on top of HEAD, made without touching HEAD, the index or the files.
/// HEAD itself when nothing changed.
fn live_snapshot(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    let index = target.join(SNAPSHOT_INDEX);
    let env = [("GIT_INDEX_FILE", index.to_string_lossy().into_owned())];
    let result = (|| {
        out(request, target, auth, &env, &["read-tree", "HEAD"], None)?;
        out(request, target, auth, &env, &["add", "-A"], None)?;
        let tree = text(out(request, target, auth, &env, &["write-tree"], None)?);
        let head_tree = text(out(
            request,
            target,
            auth,
            &[],
            &["rev-parse", "HEAD^{tree}"],
            None,
        )?);
        if tree == head_tree {
            return Ok(text(out(
                request,
                target,
                auth,
                &[],
                &["rev-parse", "HEAD"],
                None,
            )?));
        }
        Ok(text(out(
            request,
            target,
            auth,
            &[],
            &[
                "commit-tree",
                &tree,
                "-p",
                "HEAD",
                "-m",
                "Live server changes",
            ],
            None,
        )?))
    })();
    let _ = fs::remove_file(&index);
    result
}

#[derive(Default, Debug)]
struct Stages {
    /// stage → (mode, object id); 1 base, 2 live, 3 remote.
    entries: BTreeMap<u8, (String, String)>,
}

/// Unmerged paths of an index (`git ls-files -u`).
fn unmerged(
    request: &Request,
    target: &Path,
    auth: &Auth,
    env: &[(&str, String)],
) -> Result<BTreeMap<String, Stages>, String> {
    let raw = out(request, target, auth, env, &["ls-files", "-u", "-z"], None)?;
    let mut paths: BTreeMap<String, Stages> = BTreeMap::new();
    for record in raw.split(|b| *b == 0).filter(|r| !r.is_empty()) {
        let record = String::from_utf8_lossy(record);
        let Some((meta, path)) = record.split_once('\t') else {
            continue;
        };
        let mut fields = meta.split_whitespace();
        let (Some(mode), Some(oid), Some(stage)) = (fields.next(), fields.next(), fields.next())
        else {
            continue;
        };
        if let Ok(stage) = stage.parse::<u8>() {
            paths
                .entry(path.to_string())
                .or_default()
                .entries
                .insert(stage, (mode.to_string(), oid.to_string()));
        }
    }
    Ok(paths)
}

fn blob(request: &Request, target: &Path, auth: &Auth, oid: &str) -> Result<Vec<u8>, String> {
    out(request, target, auth, &[], &["cat-file", "blob", oid], None)
}

fn binary(bytes: &[u8]) -> bool {
    bytes.iter().take(8000).any(|b| *b == 0)
}

/// Write a stage's blobs into the work tree as git temp files (as the site
/// owner) and return their names; "." for a missing stage.
fn temp_stages(
    request: &Request,
    target: &Path,
    auth: &Auth,
    env: &[(&str, String)],
    path: &str,
) -> Result<[String; 3], String> {
    let listed = text(out(
        request,
        target,
        auth,
        env,
        &["checkout-index", "--stage=all", "--temp", "--", path],
        None,
    )?);
    let names = listed.split('\t').next().unwrap_or("");
    let parts: Vec<String> = names.split(' ').map(str::to_string).collect();
    if parts.len() != 3
        || parts
            .iter()
            .any(|p| p != "." && (!p.starts_with(".merge_file_") || p.contains('/')))
    {
        return Err(format!("Unexpected temporary files for {path}."));
    }
    Ok([parts[0].clone(), parts[1].clone(), parts[2].clone()])
}

fn remove_temp(target: &Path, names: &[String]) {
    for name in names.iter().filter(|n| n.as_str() != ".") {
        // Removing never follows a symlink, so this is safe as root.
        let _ = fs::remove_file(target.join(name));
    }
}

/// `git merge-file -p [--union] live base remote` on temp stage files:
/// (merged text, number of conflicts).
fn merge_file(
    request: &Request,
    target: &Path,
    auth: &Auth,
    [base, live, remote]: &[String; 3],
    union: bool,
) -> Result<(Vec<u8>, i32), String> {
    let mut args = vec![
        "merge-file",
        "-p",
        "-L",
        "live",
        "-L",
        "base",
        "-L",
        "remote",
    ];
    if union {
        args.push("--union");
    }
    args.extend([live.as_str(), base.as_str(), remote.as_str()]);
    let output = run(request, target, auth, &[], &args, None)?;
    let code = output.status.code().unwrap_or(-1);
    if code < 0 {
        return Err(format!(
            "git merge-file failed: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    Ok((output.stdout, code))
}

pub(super) fn inspect(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    fetch(request, target, auth)?;
    let remote = format!("origin/{}", request.branch);
    let live = live_snapshot(request, target, auth)?;
    let remote_head = text(out(
        request,
        target,
        auth,
        &[],
        &["rev-parse", &remote],
        None,
    )?);
    let behind = !ancestor(request, target, auth, &remote, &live);
    let ahead = !ancestor(request, target, auth, &live, &remote);
    let dirty = !clean(request, target, auth)?;
    let mut conflicts = Vec::new();

    if behind && ahead {
        let base = text(
            out(
                request,
                target,
                auth,
                &[],
                &["merge-base", &live, &remote_head],
                None,
            )
            .map_err(|_| {
                "The live site and the repository share no history; re-clone instead.".to_string()
            })?,
        );
        let index = target.join(MERGE_INDEX);
        let env = [("GIT_INDEX_FILE", index.to_string_lossy().into_owned())];
        let _ = fs::remove_file(&index);
        let result = (|| -> Result<(), String> {
            out(
                request,
                target,
                auth,
                &env,
                &[
                    "read-tree",
                    "-m",
                    "--aggressive",
                    &base,
                    &live,
                    &remote_head,
                ],
                None,
            )?;
            for (path, stages) in unmerged(request, target, auth, &env)? {
                conflicts.extend(describe(request, target, auth, &env, &path, &stages)?);
            }
            Ok(())
        })();
        let _ = fs::remove_file(&index);
        result?;
    }

    Ok(serde_json::json!({
        // The repository has commits the site lacks / the site has changes
        // the repository lacks.
        "behind": behind,
        "ahead": ahead,
        "dirty": dirty,
        "conflicts": conflicts,
    })
    .to_string())
}

/// A file both sides changed: report it when the changes do not merge.
fn describe(
    request: &Request,
    target: &Path,
    auth: &Auth,
    env: &[(&str, String)],
    path: &str,
    stages: &Stages,
) -> Result<Option<Conflict>, String> {
    let read = |stage: u8| -> Result<Option<Vec<u8>>, String> {
        stages
            .entries
            .get(&stage)
            .map(|(_, oid)| blob(request, target, auth, oid))
            .transpose()
    };
    let (base, live, remote) = (read(1)?, read(2)?, read(3)?);
    if live.is_some() && live == remote {
        return Ok(None);
    }
    let mut conflict = Conflict {
        path: path.to_string(),
        kind: "text",
        base: None,
        live: None,
        remote: None,
        merged: None,
        live_deleted: live.is_none(),
        remote_deleted: remote.is_none(),
    };
    let sides = [&base, &live, &remote];
    if live.is_none() || remote.is_none() {
        conflict.kind = "deleted";
    } else if sides.iter().any(|s| s.as_deref().is_some_and(binary)) {
        conflict.kind = "binary";
        return Ok(Some(conflict));
    } else {
        let names = temp_stages(request, target, auth, env, path)?;
        let merged = merge_file(request, target, auth, &names, false);
        remove_temp(target, &names);
        let (merged, count) = merged?;
        if count == 0 {
            return Ok(None);
        }
        conflict.merged = Some(String::from_utf8_lossy(&merged).into_owned());
    }
    if sides
        .iter()
        .any(|s| s.as_ref().is_some_and(|b| b.len() > MAX_CONTENT_BYTES))
    {
        conflict.kind = "too_large";
        conflict.merged = None;
        return Ok(Some(conflict));
    }
    let as_text = |s: Option<Vec<u8>>| s.map(|b| String::from_utf8_lossy(&b).into_owned());
    conflict.base = as_text(base);
    conflict.live = as_text(live);
    conflict.remote = as_text(remote);
    Ok(Some(conflict))
}

pub(super) fn resolve(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    let strategy = request.strategy.as_deref().unwrap_or("");
    if !["live", "remote", "both", "custom"].contains(&strategy) {
        return Err("Choose how to resolve: live, remote, both, or custom.".into());
    }
    if target.join(".git/MERGE_HEAD").exists() {
        return Err("A merge is already in progress in the website root.".into());
    }
    fetch(request, target, auth)?;
    if !clean(request, target, auth)? {
        git(request, target, auth, &["add", "-A"])?;
        git(
            request,
            target,
            auth,
            &["commit", "-m", "Live server changes before sync"],
        )?;
    }
    let remote = format!("origin/{}", request.branch);
    if ancestor(request, target, auth, &remote, "HEAD") {
        return push(request, target, auth);
    }

    let mut args = vec!["merge", "--no-ff", "--no-commit"];
    match strategy {
        "live" => args.extend(["-X", "ours"]),
        "remote" => args.extend(["-X", "theirs"]),
        _ => {}
    }
    args.push(&remote);
    let merge = run(request, target, auth, &[], &args, None)?;
    if !target.join(".git/MERGE_HEAD").exists() {
        return finish(merge, auth).and(Err("The merge did not start.".into()));
    }

    let settled = settle(request, target, auth, strategy).and_then(|files| {
        let message = format!("Merge {remote} into the live site (conflicts: {strategy})");
        git(
            request,
            target,
            auth,
            &["commit", "--no-edit", "-m", &message],
        )?;
        Ok(files)
    });
    let files = match settled {
        Ok(files) => files,
        Err(error) => {
            let _ = run(request, target, auth, &[], &["merge", "--abort"], None);
            return Err(error);
        }
    };
    let pushed = push(request, target, auth)?;
    Ok(format!(
        "Merged the repository into the live site and pushed.{}\n{pushed}",
        if files.is_empty() {
            String::new()
        } else {
            format!(" Resolved ({strategy}): {}.", files.join(", "))
        }
    ))
}

/// Settle every unmerged path of the in-progress merge.
fn settle(
    request: &Request,
    target: &Path,
    auth: &Auth,
    strategy: &str,
) -> Result<Vec<String>, String> {
    let paths = unmerged(request, target, auth, &[])?;
    if strategy == "custom" {
        if let Some(path) = paths.keys().find(|p| !request.resolutions.contains_key(*p)) {
            return Err(format!("No resolution was given for {path}."));
        }
    }
    for (path, stages) in &paths {
        let choice = if strategy == "custom" {
            request.resolutions[path].clone()
        } else {
            Resolution {
                take: Some(strategy.to_string()),
                content: None,
            }
        };
        if let Some(content) = choice.content {
            write_content(request, target, auth, path, stages, content.as_bytes())?;
            continue;
        }
        match choice.take.as_deref() {
            Some("live") => take(request, target, auth, path, stages, 2)?,
            Some("remote") => take(request, target, auth, path, stages, 3)?,
            Some("both") => both(request, target, auth, path, stages)?,
            _ => return Err(format!("Invalid resolution for {path}.")),
        }
    }
    Ok(paths.into_keys().collect())
}

fn take(
    request: &Request,
    target: &Path,
    auth: &Auth,
    path: &str,
    stages: &Stages,
    stage: u8,
) -> Result<(), String> {
    if !stages.entries.contains_key(&stage) {
        // That side deleted the file.
        git(request, target, auth, &["rm", "-q", "-f", "--", path])?;
        return Ok(());
    }
    let side = if stage == 2 { "--ours" } else { "--theirs" };
    git(request, target, auth, &["checkout", side, "--", path])?;
    git(request, target, auth, &["add", "--", path])?;
    Ok(())
}

/// Keep both sides' lines; a deleted or binary file keeps the live version.
fn both(
    request: &Request,
    target: &Path,
    auth: &Auth,
    path: &str,
    stages: &Stages,
) -> Result<(), String> {
    let complete = (1..=3).all(|s| stages.entries.contains_key(&s));
    let is_binary = || -> Result<bool, String> {
        for (_, oid) in stages.entries.values() {
            if binary(&blob(request, target, auth, oid)?) {
                return Ok(true);
            }
        }
        Ok(false)
    };
    if !complete || is_binary()? {
        return take(request, target, auth, path, stages, 2);
    }
    let names = temp_stages(request, target, auth, &[], path)?;
    let merged = merge_file(request, target, auth, &names, true);
    remove_temp(target, &names);
    write_content(request, target, auth, path, stages, &merged?.0)
}

fn write_content(
    request: &Request,
    target: &Path,
    auth: &Auth,
    path: &str,
    stages: &Stages,
    content: &[u8],
) -> Result<(), String> {
    let oid = text(out(
        request,
        target,
        auth,
        &[],
        &["hash-object", "-w", "--stdin"],
        Some(content),
    )?);
    let mode = stages
        .entries
        .get(&2)
        .or_else(|| stages.entries.get(&3))
        .map(|(mode, _)| mode.as_str())
        .unwrap_or("100644");
    let info = format!("{mode},{oid},{path}");
    git(
        request,
        target,
        auth,
        &["update-index", "--add", "--cacheinfo", &info],
    )?;
    git(request, target, auth, &["checkout", "--", path])?;
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;

    fn sh(dir: &Path, args: &[&str]) {
        let status = std::process::Command::new("git")
            .arg("-C")
            .arg(dir)
            .args(["-c", "user.name=t", "-c", "user.email=t@t"])
            .args(args)
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .unwrap();
        assert!(status.success(), "git {args:?}");
    }

    /// A remote, the live site (shallow clone, as deploys are) and a
    /// developer clone that pushes a change to index.php.
    fn setup(live_text: &str, dev_text: &str) -> (tempfile::TempDir, PathBuf, PathBuf) {
        let root = tempfile::tempdir().unwrap();
        let remote = root.path().join("remote.git");
        let seed = root.path().join("seed");
        fs::create_dir_all(&seed).unwrap();
        sh(
            root.path(),
            &["init", "-q", "--bare", "-b", "main", "remote.git"],
        );
        sh(&seed, &["init", "-q", "-b", "main"]);
        fs::write(seed.join("index.php"), "one\ntwo\nthree\n").unwrap();
        fs::write(seed.join("about.txt"), "about\n").unwrap();
        sh(&seed, &["add", "-A"]);
        sh(&seed, &["commit", "-q", "-m", "seed"]);
        sh(
            &seed,
            &["remote", "add", "origin", remote.to_str().unwrap()],
        );
        sh(&seed, &["push", "-q", "origin", "main"]);

        let live = root.path().join("live");
        sh(
            root.path(),
            &[
                "clone",
                "-q",
                "--depth",
                "1",
                "--branch",
                "main",
                &format!("file://{}", remote.display()),
                "live",
            ],
        );
        fs::write(seed.join("index.php"), dev_text).unwrap();
        fs::write(seed.join("about.txt"), "about from dev\n").unwrap();
        sh(&seed, &["commit", "-q", "-am", "dev change"]);
        sh(&seed, &["push", "-q", "origin", "main"]);
        // Edited live on the server, uncommitted.
        fs::write(live.join("index.php"), live_text).unwrap();
        fs::write(live.join("uploads.txt"), "new upload\n").unwrap();
        (root, live, remote)
    }

    fn request(strategy: Option<&str>, resolutions: BTreeMap<String, Resolution>) -> Request {
        Request {
            action: String::new(),
            site_owner: "test".into(),
            target: String::new(),
            repository: String::new(),
            branch: "main".into(),
            username: None,
            token: None,
            message: None,
            strategy: strategy.map(str::to_string),
            resolutions,
        }
    }

    fn remote_file(remote: &Path, path: &str) -> String {
        let out = std::process::Command::new("git")
            .arg("-C")
            .arg(remote)
            .args(["show", &format!("main:{path}")])
            .output()
            .unwrap();
        String::from_utf8_lossy(&out.stdout).into_owned()
    }

    #[test]
    fn inspect_reports_conflicts_without_touching_the_site() {
        let (_root, live, _) = setup("one\nLIVE\nthree\n", "one\nDEV\nthree\n");
        let req = request(None, BTreeMap::new());
        let auth = Auth::new(&req);
        let head_before = text(out(&req, &live, &auth, &[], &["rev-parse", "HEAD"], None).unwrap());

        let report: serde_json::Value =
            serde_json::from_str(&inspect(&req, &live, &auth).unwrap()).unwrap();
        assert_eq!(report["behind"], true);
        assert_eq!(report["ahead"], true);
        let conflicts = report["conflicts"].as_array().unwrap();
        // about.txt changed only on the remote: it merges on its own.
        assert_eq!(conflicts.len(), 1);
        assert_eq!(conflicts[0]["path"], "index.php");
        assert_eq!(conflicts[0]["kind"], "text");
        assert_eq!(conflicts[0]["live"], "one\nLIVE\nthree\n");
        assert_eq!(conflicts[0]["remote"], "one\nDEV\nthree\n");
        assert!(
            conflicts[0]["merged"]
                .as_str()
                .unwrap()
                .contains("<<<<<<< live")
        );

        // Nothing moved: same HEAD, same files, no stray temp files.
        assert_eq!(
            text(out(&req, &live, &auth, &[], &["rev-parse", "HEAD"], None).unwrap()),
            head_before
        );
        assert_eq!(
            fs::read_to_string(live.join("index.php")).unwrap(),
            "one\nLIVE\nthree\n"
        );
        assert!(live.join("uploads.txt").is_file());
        assert!(!fs::read_dir(&live).unwrap().any(|e| {
            e.unwrap()
                .file_name()
                .to_string_lossy()
                .starts_with(".merge_file_")
        }));
    }

    #[test]
    fn each_strategy_settles_the_conflict_and_pushes() {
        for (strategy, expected) in [
            ("live", "one\nLIVE\nthree\n"),
            ("remote", "one\nDEV\nthree\n"),
            ("both", "one\nLIVE\nDEV\nthree\n"),
        ] {
            let (_root, live, remote) = setup("one\nLIVE\nthree\n", "one\nDEV\nthree\n");
            let req = request(Some(strategy), BTreeMap::new());
            let auth = Auth::new(&req);
            resolve(&req, &live, &auth).unwrap();
            assert_eq!(
                fs::read_to_string(live.join("index.php")).unwrap(),
                expected,
                "{strategy}"
            );
            // Non-conflicting changes from both sides survive every strategy.
            assert_eq!(
                fs::read_to_string(live.join("about.txt")).unwrap(),
                "about from dev\n"
            );
            assert_eq!(remote_file(&remote, "uploads.txt"), "new upload\n");
            assert_eq!(remote_file(&remote, "index.php"), expected);
            assert!(clean(&req, &live, &auth).unwrap());
        }
    }

    #[test]
    fn custom_content_is_applied_and_a_missing_choice_aborts() {
        let (_root, live, remote) = setup("one\nLIVE\nthree\n", "one\nDEV\nthree\n");
        let auth = Auth::new(&request(None, BTreeMap::new()));

        let missing = request(Some("custom"), BTreeMap::new());
        assert!(
            resolve(&missing, &live, &auth)
                .unwrap_err()
                .contains("index.php")
        );
        assert!(!live.join(".git/MERGE_HEAD").exists());
        assert_eq!(
            fs::read_to_string(live.join("index.php")).unwrap(),
            "one\nLIVE\nthree\n"
        );

        let mut resolutions = BTreeMap::new();
        resolutions.insert(
            "index.php".to_string(),
            Resolution {
                take: None,
                content: Some("one\nMERGED\nthree\n".into()),
            },
        );
        resolve(&request(Some("custom"), resolutions), &live, &auth).unwrap();
        assert_eq!(
            fs::read_to_string(live.join("index.php")).unwrap(),
            "one\nMERGED\nthree\n"
        );
        assert_eq!(remote_file(&remote, "index.php"), "one\nMERGED\nthree\n");
    }
}
