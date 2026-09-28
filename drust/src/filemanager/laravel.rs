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

/// PHP application installer steps, run as the site owner with the website's
/// own PHP version first on PATH (composer, artisan and the Vite build's
/// wayfinder plugin all shell out to `php`). The panel's LaravelInstallJob
/// calls the actions in order: create_project → artisan (env/key/migrate) →
/// breeze (Laravel 10 frontend stacks) or npm_build → finalize. AppInstallJob
/// uses create_project (stack "codeigniter") or joomla_install, then finalize
/// with the matching stack.
#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    php_version: String,
    action: String,
    stack: Option<String>,
    version: Option<String>,
    command: Option<String>,
    joomla: Option<JoomlaInstall>,
}

/// Answers for Joomla's `installation/joomla.php install` command.
#[derive(Deserialize)]
pub(crate) struct JoomlaInstall {
    site_name: String,
    admin_user: String,
    admin_username: String,
    admin_password: String,
    admin_email: String,
    db_host: String,
    db_user: String,
    db_pass: String,
    db_name: String,
    db_prefix: String,
}

/// Loads the Joomla CLI installer with its arguments read from stdin, so the
/// admin and database passwords never appear in the process list (argv is
/// readable by every local user; stdin is not).
const JOOMLA_CLI_BOOTSTRAP: &str = r#"$a = json_decode(stream_get_contents(STDIN), true);
if (!is_array($a)) { fwrite(STDERR, "Invalid installer input.\n"); exit(1); }
$_SERVER['argv'] = $argv = array_merge(['installation/joomla.php'], $a);
$_SERVER['argc'] = $argc = count($argv);
require 'installation/joomla.php';"#;

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
                return Err("The installer requires an empty project root.".into());
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
            run_as_owner(
                &request.username,
                &runtime,
                &target,
                &php,
                &args,
                ARTISAN_TIMEOUT,
            )
            .await
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
                &[
                    "artisan",
                    "breeze:install",
                    stack,
                    "--no-interaction",
                    "--no-ansi",
                ],
                NPM_TIMEOUT,
            )
            .await?;
            Ok(format!("{require_output}\n\n{install_output}"))
        }
        "joomla_install" => {
            let input = request
                .joomla
                .as_ref()
                .ok_or("Joomla install answers are missing.")?;
            if !target.join("installation/joomla.php").is_file() {
                return Err(
                    "Joomla installation/joomla.php was not found in the site root.".into(),
                );
            }
            let args = joomla_arguments(input)?;
            let stdin = serde_json::to_vec(&args).map_err(|e| e.to_string())?;
            run_as_owner_with_input(
                &request.username,
                &runtime,
                &target,
                &php,
                &["-r", JOOMLA_CLI_BOOTSTRAP],
                ARTISAN_TIMEOUT,
                Some(&stdin),
            )
            .await
        }
        "finalize" => {
            // The site normally runs in its own PHP-FPM pool as the owner, but
            // the gateway falls back to the shared www-data pool when that
            // pool can't be provisioned — keep the app's writable dirs usable
            // by both.
            let owner = format!("{}:www-data", request.username);
            let writable: &[&str] = match request.stack.as_deref().unwrap_or("laravel") {
                "codeigniter" => &["writable"],
                "joomla" => &[
                    "cache",
                    "administrator/cache",
                    "administrator/logs",
                    "tmp",
                    "images",
                ],
                _ => &["storage", "bootstrap/cache"],
            };
            // Joomla's configuration.php holds the database password.
            let config = target.join("configuration.php");
            if request.stack.as_deref() == Some("joomla") && config.is_file() {
                let config = config.to_string_lossy();
                run_status("chown", &[&owner, config.as_ref()])?;
                run_status("chmod", &["640", config.as_ref()])?;
            }
            for dir in writable {
                let path = target.join(dir);
                if !path.is_dir() {
                    continue;
                }
                let path = path.to_string_lossy();
                run_status("chown", &["-R", &owner, path.as_ref()])?;
                run_status("chmod", &["-R", "u+rwX,g+rwX,o-rwx", path.as_ref()])?;
            }
            Ok("Writable directory permissions prepared.".into())
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
        _ => Err("Unsupported installer action.".into()),
    }
}

/// Builds `joomla.php install` options. Values are validated here as well as
/// in the panel because they end up in Joomla's configuration.php.
fn joomla_arguments(input: &JoomlaInstall) -> Result<Vec<String>, String> {
    let no_control = |value: &str| !value.chars().any(char::is_control);
    if input.site_name.trim().is_empty()
        || !no_control(&input.site_name)
        || input.site_name.len() > 200
    {
        return Err("Invalid Joomla site name.".into());
    }
    if input.admin_user.trim().is_empty()
        || !no_control(&input.admin_user)
        || input.admin_user.len() > 100
    {
        return Err("Invalid Joomla admin name.".into());
    }
    let username_ok = !input.admin_username.is_empty()
        && input.admin_username.len() <= 150
        && input
            .admin_username
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.' | '@'));
    if !username_ok {
        return Err("Invalid Joomla admin username.".into());
    }
    if input.admin_password.chars().count() < 12 || !no_control(&input.admin_password) {
        return Err("The Joomla admin password must be at least 12 characters.".into());
    }
    if !input.admin_email.contains('@') || !no_control(&input.admin_email) {
        return Err("Invalid Joomla admin email.".into());
    }
    let identifier = |value: &str| {
        !value.is_empty()
            && value.len() <= 64
            && value.chars().all(|c| c.is_ascii_alphanumeric() || c == '_')
    };
    if !identifier(&input.db_name) || !identifier(&input.db_user) {
        return Err("Invalid Joomla database name or user.".into());
    }
    let prefix_ok = input.db_prefix.len() <= 15
        && input.db_prefix.ends_with('_')
        && input
            .db_prefix
            .chars()
            .next()
            .is_some_and(|c| c.is_ascii_alphabetic())
        && input
            .db_prefix
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_');
    if !prefix_ok {
        return Err("Invalid Joomla table prefix.".into());
    }
    if input.db_host.is_empty()
        || !input
            .db_host
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-' | ':' | '_'))
    {
        return Err("Invalid Joomla database host.".into());
    }
    if !no_control(&input.db_pass) {
        return Err("Invalid Joomla database password.".into());
    }

    Ok(vec![
        "install".into(),
        format!("--site-name={}", input.site_name.trim()),
        format!("--admin-user={}", input.admin_user.trim()),
        format!("--admin-username={}", input.admin_username),
        format!("--admin-password={}", input.admin_password),
        format!("--admin-email={}", input.admin_email.trim()),
        "--db-type=mysqli".into(),
        format!("--db-host={}", input.db_host),
        format!("--db-user={}", input.db_user),
        format!("--db-pass={}", input.db_pass),
        format!("--db-name={}", input.db_name),
        format!("--db-prefix={}", input.db_prefix),
        "--db-encryption=0".into(),
        "--no-interaction".into(),
        "--no-ansi".into(),
    ])
}

/// Whitelisted create-project sources. Starter kits only publish tagged
/// releases for Laravel 12; their main branch tracks the current major.
/// Laravel 11 has neither, and Laravel 10 gets its stacks from Breeze.
fn package_for(stack: &str, version: &str) -> Result<(&'static str, &'static str), String> {
    if stack == "codeigniter" {
        return Ok(("codeigniter4/appstarter", "^4.0"));
    }
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
        return Err(format!(
            "PHP {version} CLI is not installed on this server."
        ));
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
        let path = if sub.is_empty() {
            base.clone()
        } else {
            base.join(sub)
        };
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
    run_as_owner_with_input(username, runtime, cwd, program, args, timeout, None).await
}

async fn run_as_owner_with_input(
    username: &str,
    runtime: &Runtime,
    cwd: &Path,
    program: &str,
    args: &[&str],
    timeout: Duration,
    input: Option<&[u8]>,
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
        .stdin(if input.is_some() {
            Stdio::piped()
        } else {
            Stdio::null()
        })
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .process_group(0)
        .kill_on_drop(true);

    let mut child = command
        .spawn()
        .map_err(|e| format!("Cannot start {program}: {e}"))?;
    let pid = child.id();
    if let (Some(bytes), Some(mut stdin)) = (input, child.stdin.take()) {
        use tokio::io::AsyncWriteExt;
        stdin
            .write_all(bytes)
            .await
            .map_err(|e| format!("Cannot send input to {program}: {e}"))?;
        // Dropping stdin closes it so the child sees EOF.
    }
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

#[cfg(test)]
mod tests {
    use super::*;

    fn joomla() -> JoomlaInstall {
        JoomlaInstall {
            site_name: "Example Site".into(),
            admin_user: "Site Admin".into(),
            admin_username: "admin".into(),
            admin_password: "correct horse battery".into(),
            admin_email: "admin@example.com".into(),
            db_host: "127.0.0.1".into(),
            db_user: "ex_user".into(),
            db_pass: "p@ss'word\"$x".into(),
            db_name: "ex_db".into(),
            db_prefix: "jx4_".into(),
        }
    }

    #[test]
    fn joomla_arguments_keep_values_verbatim() {
        let args = joomla_arguments(&joomla()).unwrap();
        assert_eq!(args[0], "install");
        assert!(args.contains(&"--db-pass=p@ss'word\"$x".to_string()));
        assert!(args.contains(&"--db-prefix=jx4_".to_string()));
        assert!(args.contains(&"--no-interaction".to_string()));
    }

    #[test]
    fn joomla_arguments_reject_bad_input() {
        let mut input = joomla();
        input.admin_password = "short".into();
        assert!(joomla_arguments(&input).is_err());
        let mut input = joomla();
        input.db_prefix = "1x_".into();
        assert!(joomla_arguments(&input).is_err());
        let mut input = joomla();
        input.db_name = "db; drop".into();
        assert!(joomla_arguments(&input).is_err());
        let mut input = joomla();
        input.site_name = "a\nb".into();
        assert!(joomla_arguments(&input).is_err());
    }

    #[test]
    fn codeigniter_uses_appstarter() {
        assert_eq!(
            package_for("codeigniter", "4").unwrap(),
            ("codeigniter4/appstarter", "^4.0")
        );
        assert_eq!(
            package_for("blank", "12").unwrap(),
            ("laravel/laravel", "^12.0")
        );
    }
}
