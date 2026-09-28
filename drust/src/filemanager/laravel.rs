use std::{
    fs,
    os::unix::fs::PermissionsExt,
    path::{Path, PathBuf},
    process::Stdio,
    sync::Arc,
    time::Duration,
};

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};
use crate::app::run_status;

use super::common::{ensure_directory_inside_home, validate_account, validate_user_path};

const CREATE_PROJECT_TIMEOUT: Duration = Duration::from_secs(900);
const NPM_TIMEOUT: Duration = Duration::from_secs(900);
const ARTISAN_TIMEOUT: Duration = Duration::from_secs(600);
const MAX_OUTPUT_CHARS: usize = 20_000;

/// Laravel installer steps, run as the site owner with the website's own PHP
/// version first on PATH (composer, artisan and the Vite build's wayfinder
/// plugin all shell out to `php`). The panel's LaravelInstallJob calls the
/// actions in order: create_project → artisan (env/key/migrate) → breeze
/// (Laravel 10 frontend stacks) or npm_build → finalize.
#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    php_version: String,
    action: String,
    stack: Option<String>,
    version: Option<String>,
    command: Option<String>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match execute(&request).await {
        Ok(output) => ApiResponse::ok_data(
            "Laravel installer step completed",
            serde_json::json!({ "output": output }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

async fn execute(request: &Request) -> Result<String, String> {
    let php = php_binary(&request.php_version)?;
    let (home, canonical_home, group) = validate_account(&request.username)?;
    let target = validate_user_path(&request.username, &request.path)?;
    if target == home {
        return Err("Laravel cannot be installed directly into the account home.".into());
    }
    let target = ensure_directory_inside_home(
        &request.username,
        &group,
        &home,
        &canonical_home,
        &target,
        "Laravel project root",
    )?;
    let runtime = prepare_runtime(&request.username, &group, &home, &canonical_home, &php)?;

    match request.action.as_str() {
        "create_project" => {
            let (package, constraint) = package_for(
                request.stack.as_deref().unwrap_or(""),
                request.version.as_deref().unwrap_or(""),
            )?;
            if fs::read_dir(&target)
                .map_err(|e| format!("Cannot inspect project root: {e}"))?
                .next()
                .is_some()
            {
                return Err("Laravel install requires an empty project root.".into());
            }
            let target_arg = target.to_string_lossy().to_string();
            run_as_owner(
                &request.username,
                &runtime,
                &target,
                &php,
                &[
                    "/usr/bin/composer",
                    "create-project",
                    package,
                    &target_arg,
                    constraint,
                    "--prefer-dist",
                    "--no-interaction",
                    "--no-scripts",
                    "--no-progress",
                    "--no-ansi",
                    "--remove-vcs",
                ],
                CREATE_PROJECT_TIMEOUT,
            )
            .await
        }
        "artisan" => {
            const ALLOWED: &[&str] = &[
                "package:discover",
                "key:generate --force",
                "migrate:fresh --force",
                "storage:link",
                "optimize",
                "optimize:clear",
                "install:api --without-migration-prompt",
            ];
            let command = request.command.as_deref().unwrap_or("").trim();
            if !ALLOWED.contains(&command) {
                return Err("Artisan command is not allowed.".into());
            }
            if !target.join("artisan").is_file() {
                return Err("Laravel artisan file was not found in the project root.".into());
            }
            let mut args = vec!["artisan"];
            args.extend(command.split_whitespace());
            args.extend(["--no-interaction", "--no-ansi"]);
            run_as_owner(&request.username, &runtime, &target, &php, &args, ARTISAN_TIMEOUT).await
        }
        "breeze" => {
            // Laravel 10 has no starter kits; Breeze scaffolds the same stacks
            // and runs its own npm install + build.
            let stack = request.stack.as_deref().unwrap_or("");
            if !matches!(stack, "vue" | "react" | "livewire") {
                return Err("Unsupported Breeze stack.".into());
            }
            if !target.join("artisan").is_file() {
                return Err("Laravel artisan file was not found in the project root.".into());
            }
            let require_output = run_as_owner(
                &request.username,
                &runtime,
                &target,
                &php,
                &[
                    "/usr/bin/composer",
                    "require",
                    "laravel/breeze:^1.29",
                    "--dev",
                    "--no-interaction",
                    "--no-progress",
                    "--no-ansi",
                ],
                CREATE_PROJECT_TIMEOUT,
            )
            .await?;
            let install_output = run_as_owner(
                &request.username,
                &runtime,
                &target,
                &php,
                &["artisan", "breeze:install", stack, "--no-interaction", "--no-ansi"],
                NPM_TIMEOUT,
            )
            .await?;
            Ok(format!("{require_output}\n\n{install_output}"))
        }
        "finalize" => {
            // The site normally runs in its own PHP-FPM pool as the owner, but
            // the gateway falls back to the shared www-data pool when that
            // pool can't be provisioned — keep Laravel's writable dirs usable
            // by both.
            let owner = format!("{}:www-data", request.username);
            for dir in ["storage", "bootstrap/cache"] {
                let path = target.join(dir);
                if !path.is_dir() {
                    continue;
                }
                let path = path.to_string_lossy();
                run_status("chown", &["-R", &owner, path.as_ref()])?;
                run_status("chmod", &["-R", "u+rwX,g+rwX,o-rwx", path.as_ref()])?;
            }
            Ok("Laravel storage permissions prepared.".into())
        }
        "npm_build" => {
            if !target.join("package.json").is_file() {
                return Ok("No package.json found; asset build skipped.".into());
            }
            let npm = "/usr/bin/npm";
            let install = if target.join("package-lock.json").is_file() {
                "ci"
            } else {
                "install"
            };
            let install_output = run_as_owner(
                &request.username,
                &runtime,
                &target,
                npm,
                &[install, "--no-audit", "--no-fund"],
                NPM_TIMEOUT,
            )
            .await?;
            let build_output = run_as_owner(
                &request.username,
                &runtime,
                &target,
                npm,
                &["run", "build"],
                NPM_TIMEOUT,
            )
            .await?;
            Ok(format!("{install_output}\n\n{build_output}"))
        }
        _ => Err("Unsupported Laravel installer action.".into()),
    }
}

/// Whitelisted create-project sources. Starter kits only publish tagged
/// releases for Laravel 12; their main branch tracks the current major.
/// Laravel 11 has neither, and Laravel 10 gets its stacks from Breeze.
fn package_for(stack: &str, version: &str) -> Result<(&'static str, &'static str), String> {
    let base = match version {
        "13" => "^13.0",
        "12" => "^12.0",
        "11" => "^11.0",
        "10" => "^10.0",
        _ => return Err("Unsupported Laravel version.".into()),
    };
    let kit = match version {
        "13" => Some("dev-main"),
        "12" => Some("^1.0"),
        _ => None,
    };
    match stack {
        "blank" | "api" => Ok(("laravel/laravel", base)),
        // Laravel 10 frontend stacks: plain skeleton now, Breeze afterwards.
        "vue" | "react" | "livewire" if version == "10" => Ok(("laravel/laravel", base)),
        "vue" | "react" | "livewire" | "svelte" => {
            let constraint = kit.ok_or("Starter kits require Laravel 12 or newer.")?;
            if stack == "svelte" && version != "13" {
                return Err("The Svelte starter kit requires Laravel 13.".into());
            }
            Ok((
                match stack {
                    "vue" => "laravel/vue-starter-kit",
                    "react" => "laravel/react-starter-kit",
                    "livewire" => "laravel/livewire-starter-kit",
                    _ => "laravel/svelte-starter-kit",
                },
                constraint,
            ))
        }
        _ => Err("Unsupported Laravel stack.".into()),
    }
}

fn php_binary(version: &str) -> Result<String, String> {
    let valid = version.len() == 3
        && version.as_bytes()[1] == b'.'
        && version.as_bytes()[0].is_ascii_digit()
        && version.as_bytes()[2].is_ascii_digit();
    if !valid {
        return Err("Invalid PHP version.".into());
    }
    let binary = format!("/usr/bin/php{version}");
    if !Path::new(&binary).is_file() {
        return Err(format!("PHP {version} CLI is not installed on this server."));
    }
    Ok(binary)
}

struct Runtime {
    home: PathBuf,
    bin: PathBuf,
}

/// A per-account scratch HOME under ~/.dpanel (account homes are not always
/// writable by their owner) holding composer/npm caches and a bin/ directory
/// whose `php` and `composer` pin the website's PHP version.
fn prepare_runtime(
    username: &str,
    group: &str,
    home: &Path,
    canonical_home: &Path,
    php: &str,
) -> Result<Runtime, String> {
    let base = home.join(".dpanel/laravel-installer");
    let mut dirs = Vec::new();
    for sub in ["", "bin", "composer", "composer-cache", "npm-cache"] {
        let path = if sub.is_empty() { base.clone() } else { base.join(sub) };
        dirs.push(ensure_directory_inside_home(
            username,
            group,
            home,
            canonical_home,
            &path,
            "Laravel installer runtime",
        )?);
    }
    let runtime_home = dirs[0].clone();
    let bin = dirs[1].clone();

    let php_link = bin.join("php");
    if fs::symlink_metadata(&php_link).is_ok() {
        fs::remove_file(&php_link).map_err(|e| format!("Cannot reset php shim: {e}"))?;
    }
    std::os::unix::fs::symlink(php, &php_link)
        .map_err(|e| format!("Cannot create php shim: {e}"))?;

    let composer = bin.join("composer");
    if fs::symlink_metadata(&composer).is_ok() {
        fs::remove_file(&composer).map_err(|e| format!("Cannot reset composer shim: {e}"))?;
    }
    fs::write(
        &composer,
        format!("#!/bin/sh\nexec {php} /usr/bin/composer \"$@\"\n"),
    )
    .map_err(|e| format!("Cannot create composer shim: {e}"))?;
    fs::set_permissions(&composer, fs::Permissions::from_mode(0o755))
        .map_err(|e| format!("Cannot chmod composer shim: {e}"))?;

    Ok(Runtime {
        home: runtime_home,
        bin,
    })
}

async fn run_as_owner(
    username: &str,
    runtime: &Runtime,
    cwd: &Path,
    program: &str,
    args: &[&str],
    timeout: Duration,
) -> Result<String, String> {
    let home = runtime.home.display();
    let mut command = tokio::process::Command::new("/usr/sbin/runuser");
    command
        .args(["-u", username, "--", "/usr/bin/env", "-i"])
        .arg(format!("HOME={home}"))
        .arg(format!("USER={username}"))
        .arg(format!("LOGNAME={username}"))
        .arg(format!(
            "PATH={}:/usr/local/bin:/usr/bin:/bin",
            runtime.bin.display()
        ))
        .arg(format!("COMPOSER_HOME={home}/composer"))
        .arg(format!("COMPOSER_CACHE_DIR={home}/composer-cache"))
        .arg("COMPOSER_NO_INTERACTION=1")
        .arg(format!("npm_config_cache={home}/npm-cache"))
        .arg("CI=1")
        .arg(program)
        .args(args)
        .current_dir(cwd)
        .stdin(Stdio::null())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .process_group(0)
        .kill_on_drop(true);

    let child = command
        .spawn()
        .map_err(|e| format!("Cannot start {program}: {e}"))?;
    let pid = child.id();
    let output = match tokio::time::timeout(timeout, child.wait_with_output()).await {
        Ok(result) => result.map_err(|e| format!("{program} failed: {e}"))?,
        Err(_) => {
            // kill_on_drop only reaches runuser; take down the whole group.
            if let Some(pid) = pid {
                let _ = tokio::process::Command::new("kill")
                    .args(["-KILL", "--", &format!("-{pid}")])
                    .status()
                    .await;
            }
            return Err(format!(
                "{program} timed out after {} seconds.",
                timeout.as_secs()
            ));
        }
    };

    let combined = format!(
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
    let trimmed = combined.trim();
    let count = trimmed.chars().count();
    let tail: String = if count > MAX_OUTPUT_CHARS {
        trimmed.chars().skip(count - MAX_OUTPUT_CHARS).collect()
    } else {
        trimmed.to_string()
    };
    if output.status.success() {
        Ok(tail)
    } else if tail.is_empty() {
        Err(format!("{program} exited with {}.", output.status))
    } else {
        Err(tail)
    }
}
