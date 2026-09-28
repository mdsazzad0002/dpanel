use std::{
    fs::{self, OpenOptions},
    path::{Path, PathBuf},
    process::{Command, Output},
    sync::Arc,
};

use axum::{
    Router,
    extract::{Json, State},
    response::{IntoResponse, Response},
    routing::post,
};
use base64::{Engine as _, engine::general_purpose::STANDARD};
use serde::Deserialize;
use sha1::{Digest, Sha1};

use crate::api::{ApiResponse, ApiState, check_token};
use crate::app::{run_status, user_group};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/git-deploy", post(handle))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    site_owner: String,
    target: String,
    repository: String,
    branch: String,
    username: Option<String>,
    token: Option<String>,
    message: Option<String>,
}

async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match execute(&request) {
        Ok(output) => ApiResponse::ok_data(
            "Git operation completed",
            serde_json::json!({"output": output}),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

struct SiteLock(PathBuf);
impl Drop for SiteLock {
    fn drop(&mut self) {
        let _ = fs::remove_file(&self.0);
    }
}

fn execute(request: &Request) -> Result<String, String> {
    validate(request)?;
    let target = PathBuf::from(&request.target);
    let lock_name = format!("{:x}", Sha1::digest(request.target.as_bytes()));
    let lock_path = PathBuf::from(format!("/run/lock/dpanel-git-{lock_name}.lock"));
    OpenOptions::new()
        .write(true)
        .create_new(true)
        .open(&lock_path)
        .map_err(|error| {
            if error.kind() == std::io::ErrorKind::AlreadyExists {
                "Another Git operation is already running for this website.".into()
            } else {
                format!("Unable to create Git operation lock: {error}")
            }
        })?;
    let _lock = SiteLock(lock_path);
    let auth = Auth::new(request);

    match request.action.as_str() {
        "clone" => clone_repository(request, &target, &auth),
        "init" => init_push(request, &target, &auth),
        "probe" => probe(request, &target, &auth),
        "status" => git(request, &target, &auth, &["status", "--short", "--branch"]),
        "pull" => pull(request, &target, &auth),
        "push" => push(request, &target, &auth),
        "sync" => sync(request, &target, &auth),
        "checkout" => checkout(request, &target, &auth),
        _ => Err("Unsupported Git action.".into()),
    }
}

struct Auth {
    header: Option<String>,
    token: String,
}
impl Auth {
    fn new(request: &Request) -> Self {
        let token = request.token.clone().unwrap_or_default();
        let username = request
            .username
            .as_deref()
            .filter(|value| !value.is_empty())
            .unwrap_or("x-access-token");
        let header = (!token.is_empty()).then(|| {
            format!(
                "Authorization: Basic {}",
                STANDARD.encode(format!("{username}:{token}"))
            )
        });
        Self { header, token }
    }
}

fn validate(request: &Request) -> Result<(), String> {
    let valid_owner = request
        .site_owner
        .chars()
        .enumerate()
        .all(|(index, value)| {
            value.is_ascii_lowercase()
                || value.is_ascii_digit()
                || value == '_'
                || value == '-' && index > 0
        });
    if request.site_owner.is_empty() || !valid_owner {
        return Err("Invalid website owner.".into());
    }
    let target = Path::new(&request.target);
    if !target.starts_with(format!("/home/{}/", request.site_owner))
        || target
            .components()
            .any(|part| matches!(part, std::path::Component::ParentDir))
    {
        return Err("Deployment path is outside the website owner's home.".into());
    }
    if !request.repository.starts_with("https://github.com/")
        && !request.repository.starts_with("https://gitlab.com/")
        && !request.repository.starts_with("https://bitbucket.org/")
    {
        return Err("Only HTTPS GitHub, GitLab, or Bitbucket repositories are allowed.".into());
    }
    if request.branch.is_empty()
        || request.branch.starts_with('-')
        || request.branch.contains("..")
        || !request
            .branch
            .chars()
            .all(|value| value.is_ascii_alphanumeric() || "._/-".contains(value))
    {
        return Err("Invalid Git branch.".into());
    }
    Ok(())
}

fn command(request: &Request, auth: &Auth) -> Command {
    let mut command = Command::new("runuser");
    command
        .args([
            "--preserve-environment",
            "-u",
            &request.site_owner,
            "--",
            "git",
        ])
        .env("HOME", format!("/home/{}", request.site_owner))
        .env("GIT_TERMINAL_PROMPT", "0");
    if let Some(header) = &auth.header {
        command
            .env("GIT_CONFIG_COUNT", "1")
            .env("GIT_CONFIG_KEY_0", "http.extraHeader")
            .env("GIT_CONFIG_VALUE_0", header);
    }
    command
}

fn finish(output: Output, auth: &Auth) -> Result<String, String> {
    let combined = format!(
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    )
    .trim()
    .to_string();
    // Replacing an empty token would insert "***" between every character.
    let combined = if auth.token.is_empty() {
        combined
    } else {
        combined.replace(&auth.token, "***")
    };
    if output.status.success() {
        return Ok(if combined.is_empty() {
            "Operation completed successfully.".into()
        } else {
            combined
        });
    }
    if combined.contains("refusing to allow") && combined.contains("workflow") {
        return Err(format!(
            "{WORKFLOW_DENIED} GitHub rejected changes under .github/workflows because the connected GitHub App lacks the \"Workflows: Read and write\" repository permission (an OAuth App token needs the \"workflow\" scope). Add it in the App settings, approve the updated permissions, then reconnect the account.\n\n{combined}"
        ));
    }
    if combined.contains("403") || combined.contains("Write access to repository not granted") {
        return Err("Repository access denied (403). Verify the repository owner, token expiry, and that the token has Contents: Read and write permission.".into());
    }
    Err(if combined.is_empty() {
        format!("Git exited with {}.", output.status)
    } else {
        combined
    })
}

const WORKFLOW_DENIED: &str = "Workflows permission missing.";

fn git(request: &Request, target: &Path, auth: &Auth, args: &[&str]) -> Result<String, String> {
    if !target.join(".git").is_dir() {
        return Err("Repository is not connected yet.".into());
    }
    let output = command(request, auth)
        .arg("-C")
        .arg(target)
        .args([
            "-c",
            "user.name=dPanel Deployment",
            "-c",
            "user.email=deploy@localhost",
        ])
        .args(args)
        .output()
        .map_err(|error| format!("Unable to start Git: {error}"))?;
    finish(output, auth)
}

fn clone_repository(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    if target.join(".git").is_dir() {
        return Ok("Repository is already connected.".into());
    }
    let existed = target.is_dir();
    fs::create_dir_all(target)
        .map_err(|error| format!("Unable to prepare deployment folder: {error}"))?;
    if !existed {
        // create_dir_all runs as root (this process's euid); the site owner
        // then runs `git clone` into it via runuser and needs write access,
        // or clone fails with "<target>/.git: Permission denied" the moment
        // it tries to create the repo's .git folder.
        let group = user_group(&request.site_owner).unwrap_or_else(|_| request.site_owner.clone());
        run_status(
            "chown",
            &[
                &format!("{}:{group}", request.site_owner),
                &target.to_string_lossy(),
            ],
        )?;
    }
    if fs::read_dir(target)
        .map_err(|error| error.to_string())?
        .next()
        .is_some()
    {
        return Err(
            "Target folder is not empty. Move or back up existing files before deploying.".into(),
        );
    }
    let output = command(request, auth)
        .args([
            "clone",
            "--branch",
            &request.branch,
            "--single-branch",
            "--depth",
            "1",
            "--",
            &request.repository,
            &request.target,
        ])
        .output()
        .map_err(|error| format!("Unable to start Git clone: {error}"))?;
    finish(output, auth)
}

/// What the first action for this repository + website root should be:
/// a remote with commits is deployed (clone), an empty remote is fed from
/// the website's files (init). Returned as JSON for the panel.
fn probe(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    let remote = command(request, auth)
        .args(["ls-remote", "--heads", "--", &request.repository])
        .output()
        .map_err(|error| format!("Unable to start Git: {error}"))?;
    if !remote.status.success() {
        return finish(remote, auth);
    }
    let heads = String::from_utf8_lossy(&remote.stdout);
    let branch_ref = format!("refs/heads/{}", request.branch);
    let remote_empty = heads.trim().is_empty();
    let branch_exists = heads
        .lines()
        .any(|line| line.split_whitespace().nth(1) == Some(branch_ref.as_str()));
    let local_files = target.is_dir()
        && fs::read_dir(target)
            .map(|mut entries| entries.next().is_some())
            .unwrap_or(false);
    Ok(serde_json::json!({
        "remote_empty": remote_empty,
        "branch_exists": branch_exists,
        "local_files": local_files,
        "local_repo": target.join(".git").exists(),
    })
    .to_string())
}

/// Publish an existing website root (e.g. a fresh Laravel install) to a
/// connected repository that has no commits yet: git init → commit → push.
/// Refuses a non-empty remote (use clone) and a root that is already a repo.
fn init_push(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    if target.join(".git").exists() {
        return Err("The website root is already a Git repository; use Push instead.".into());
    }
    if !target.is_dir()
        || fs::read_dir(target)
            .map_err(|error| error.to_string())?
            .next()
            .is_none()
    {
        return Err("The website root is empty; there is nothing to push.".into());
    }

    let remote = command(request, auth)
        .args(["ls-remote", "--heads", "--", &request.repository])
        .output()
        .map_err(|error| format!("Unable to start Git: {error}"))?;
    if !remote.status.success() {
        return finish(remote, auth);
    }
    if !remote.stdout.iter().all(u8::is_ascii_whitespace) {
        return Err(
            "The remote repository already has commits. Use Deploy (clone) to pull it, or connect an empty repository.".into(),
        );
    }

    let result = init_commit_push(request, target, auth);
    if result.is_err() {
        // Leave the root as it was so the action can simply be retried.
        let _ = fs::remove_dir_all(target.join(".git"));
    }
    result
}

fn init_commit_push(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    let output = command(request, auth)
        .arg("-C")
        .arg(target)
        .args(["init", "-b", &request.branch])
        .output()
        .map_err(|error| format!("Unable to start Git: {error}"))?;
    finish(output, auth)?;

    // Never publish credentials: without a .gitignore covering .env (every
    // Laravel skeleton has one) the push stops here.
    if target.join(".env").exists() {
        let ignored = command(request, auth)
            .arg("-C")
            .arg(target)
            .args(["check-ignore", "-q", ".env"])
            .status()
            .map(|status| status.success())
            .unwrap_or(false);
        if !ignored {
            return Err(".env is not listed in .gitignore; push stopped so credentials are not published.".into());
        }
    }

    git(request, target, auth, &["remote", "add", "origin", &request.repository])?;
    git(request, target, auth, &["add", "-A"])?;
    git(
        request,
        target,
        auth,
        &[
            "commit",
            "-m",
            request.message.as_deref().unwrap_or("Initial commit"),
        ],
    )?;
    let refspec = format!("HEAD:{}", request.branch);
    match git(request, target, auth, &["push", "-u", "origin", &refspec]) {
        Ok(pushed) => Ok(format!("Initialized the repository and pushed the first commit.\n{pushed}")),
        Err(error) if error.starts_with(WORKFLOW_DENIED) && target.join(".github/workflows").is_dir() => {
            // Publish everything else; the workflow files stay on the server,
            // untracked and locally excluded, so later pushes skip them too.
            git(request, target, auth, &["rm", "-r", "--cached", "--quiet", ".github/workflows"])?;
            let mut exclude = OpenOptions::new()
                .append(true)
                .create(true)
                .open(target.join(".git/info/exclude"))
                .map_err(|e| format!("Unable to update .git/info/exclude: {e}"))?;
            std::io::Write::write_all(&mut exclude, b"\n/.github/workflows/\n")
                .map_err(|e| format!("Unable to update .git/info/exclude: {e}"))?;
            git(request, target, auth, &["commit", "--amend", "--no-edit"])?;
            let pushed = git(request, target, auth, &["push", "-u", "origin", &refspec])?;
            Ok(format!(
                "Initialized the repository and pushed the first commit without .github/workflows: the GitHub App has no \"Workflows\" permission. The workflow files are still on the server; grant the permission and add them later if you need CI.\n{pushed}"
            ))
        }
        Err(error) => Err(error),
    }
}

// Reads raw stdout: git() swaps empty output for a success message, which
// would make a clean tree look dirty.
fn clean(request: &Request, target: &Path, auth: &Auth) -> Result<bool, String> {
    if !target.join(".git").is_dir() {
        return Err("Repository is not connected yet.".into());
    }
    let output = command(request, auth)
        .arg("-C")
        .arg(target)
        .args(["status", "--porcelain"])
        .output()
        .map_err(|error| format!("Unable to start Git: {error}"))?;
    if !output.status.success() {
        return finish(output, auth).map(|_| false);
    }
    Ok(output.stdout.iter().all(u8::is_ascii_whitespace))
}
fn ancestor(request: &Request, target: &Path, auth: &Auth, older: &str, newer: &str) -> bool {
    command(request, auth)
        .arg("-C")
        .arg(target)
        .args(["merge-base", "--is-ancestor", older, newer])
        .status()
        .map(|status| status.success())
        .unwrap_or(false)
}
fn pull(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    if !clean(request, target, auth)? {
        return Err("Local files have uncommitted changes; pull stopped.".into());
    }
    git(
        request,
        target,
        auth,
        &["fetch", "--prune", "origin", &request.branch],
    )?;
    let remote = format!("origin/{}", request.branch);
    if !ancestor(request, target, auth, "HEAD", &remote) {
        return Err("Local and remote history diverged; manual resolution is required.".into());
    }
    git(request, target, auth, &["merge", "--ff-only", &remote])
}
fn push(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    git(request, target, auth, &["fetch", "origin", &request.branch])?;
    let remote = format!("origin/{}", request.branch);
    if !ancestor(request, target, auth, &remote, "HEAD") {
        return Err("Remote contains changes not present locally; push stopped.".into());
    }
    git(request, target, auth, &["add", "-A"])?;
    let _ = git(
        request,
        target,
        auth,
        &[
            "commit",
            "-m",
            request.message.as_deref().unwrap_or("Website update"),
        ],
    );
    git(
        request,
        target,
        auth,
        &["push", "origin", &format!("HEAD:{}", request.branch)],
    )
}
fn sync(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    git(
        request,
        target,
        auth,
        &["fetch", "--prune", "origin", &request.branch],
    )?;
    let remote = format!("origin/{}", request.branch);
    if ancestor(request, target, auth, &remote, "HEAD") {
        return push(request, target, auth);
    }
    if ancestor(request, target, auth, "HEAD", &remote) {
        if !clean(request, target, auth)? {
            return Err(
                "Remote and local files both changed; sync stopped before conflict.".into(),
            );
        }
        git(request, target, auth, &["merge", "--ff-only", &remote])?;
        return push(request, target, auth);
    }
    Err("Local and remote history diverged; manual resolution is required.".into())
}

/// Switch the working tree to another branch of the same repository. The
/// clone is `--single-branch --depth 1`, so the branch is added to the
/// remote's fetch refspec (later pulls then track it) and fetched shallowly.
fn checkout(request: &Request, target: &Path, auth: &Auth) -> Result<String, String> {
    if !clean(request, target, auth)? {
        return Err("Local files have uncommitted changes; branch switch stopped.".into());
    }
    let remote = format!("origin/{}", request.branch);
    git(
        request,
        target,
        auth,
        &["remote", "set-branches", "--add", "origin", &request.branch],
    )?;
    git(
        request,
        target,
        auth,
        &[
            "fetch",
            "--depth",
            "1",
            "origin",
            &format!("+refs/heads/{}:refs/remotes/{remote}", request.branch),
        ],
    )?;
    git(
        request,
        target,
        auth,
        &["checkout", "-B", &request.branch, &remote],
    )?;
    git(
        request,
        target,
        auth,
        &["branch", "--set-upstream-to", &remote, &request.branch],
    )?;
    Ok(format!("Switched to branch {}.", request.branch))
}
