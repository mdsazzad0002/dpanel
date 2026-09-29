//! Shared installer service. Every app installer (laravel.rs, drupal.rs, …)
//! works through a [`Site`]: the project root is validated to sit inside the
//! owner's home, tools run as the site owner with the website's own PHP
//! version first on PATH (composer, artisan and Vite's wayfinder plugin all
//! shell out to `php`), and output is capped so a noisy build can't flood the
//! panel.

use std::{
    fs,
    os::unix::fs::PermissionsExt,
    path::{Path, PathBuf},
    process::Stdio,
    time::Duration,
};

use crate::app::run_status;
use crate::filemanager::common::{ensure_directory_inside_home, validate_account, validate_user_path};

/// composer create-project/require and npm install/build.
pub(super) const LONG_TIMEOUT: Duration = Duration::from_secs(900);
/// artisan, drush and the CMS CLI installers.
pub(super) const SHORT_TIMEOUT: Duration = Duration::from_secs(600);
const MAX_OUTPUT_CHARS: usize = 20_000;
const COMPOSER: &str = "/usr/bin/composer";

/// Directories an app writes to at runtime, and the config file that holds
/// its database password (tightened to 640).
pub(super) struct Permissions {
    pub writable: &'static [&'static str],
    pub secret_config: Option<&'static str>,
}

pub(super) struct Site {
    username: String,
    target: PathBuf,
    php: String,
    runtime: Runtime,
}

struct Runtime {
    home: PathBuf,
    bin: PathBuf,
}

impl Site {
    /// Validates the account and project root and prepares the owner's
    /// installer runtime. The project root is created if it doesn't exist.
    pub fn open(username: &str, path: &str, php_version: &str) -> Result<Self, String> {
        let php = php_binary(php_version)?;
        let (home, canonical_home, group) = validate_account(username)?;
        let target = validate_user_path(username, path)?;
        if target == home {
            return Err("Apps cannot be installed directly into the account home.".into());
        }
        let target = ensure_directory_inside_home(
            username,
            &group,
            &home,
            &canonical_home,
            &target,
            "Project root",
        )?;
        let runtime = prepare_runtime(username, &group, &home, &canonical_home, &php)?;
        Ok(Self {
            username: username.to_string(),
            target,
            php,
            runtime,
        })
    }

    pub fn target(&self) -> &Path {
        &self.target
    }

    /// True when `relative` exists as a file under the project root.
    pub fn has_file(&self, relative: &str) -> bool {
        self.target.join(relative).is_file()
    }

    pub async fn php(&self, args: &[&str], timeout: Duration) -> Result<String, String> {
        self.run(&self.target, &self.php, args, timeout, None).await
    }

    /// PHP fed `input` on stdin, so secrets stay out of argv (argv is readable
    /// by every local user; stdin is not).
    pub async fn php_with_input(
        &self,
        cwd: &Path,
        args: &[&str],
        timeout: Duration,
        input: &[u8],
    ) -> Result<String, String> {
        self.run(cwd, &self.php, args, timeout, Some(input)).await
    }

    pub async fn composer(&self, args: &[&str], timeout: Duration) -> Result<String, String> {
        let mut full = vec![COMPOSER];
        full.extend_from_slice(args);
        self.php(&full, timeout).await
    }

    /// `composer create-project` into the (empty) project root. Scripts are
    /// skipped unless the app needs them to scaffold itself.
    pub async fn create_project(
        &self,
        package: &str,
        constraint: &str,
        run_scripts: bool,
    ) -> Result<String, String> {
        if fs::read_dir(&self.target)
            .map_err(|e| format!("Cannot inspect project root: {e}"))?
            .next()
            .is_some()
        {
            return Err("The installer requires an empty project root.".into());
        }
        let target = self.target.to_string_lossy().to_string();
        let mut args = vec![
            "create-project",
            package,
            &target,
            constraint,
            "--prefer-dist",
            "--no-interaction",
            "--no-progress",
            "--no-ansi",
            "--remove-vcs",
        ];
        if !run_scripts {
            args.push("--no-scripts");
        }
        self.composer(&args, LONG_TIMEOUT).await
    }

    pub async fn composer_require(&self, package: &str, dev: bool) -> Result<String, String> {
        let mut args = vec!["require", package];
        if dev {
            args.push("--dev");
        }
        args.extend(["--no-interaction", "--no-progress", "--no-ansi"]);
        self.composer(&args, LONG_TIMEOUT).await
    }

    /// `npm ci` (or `install` without a lockfile) then `npm run build`.
    pub async fn npm_build(&self) -> Result<String, String> {
        if !self.has_file("package.json") {
            return Ok("No package.json found; asset build skipped.".into());
        }
        let npm = "/usr/bin/npm";
        let install = if self.has_file("package-lock.json") {
            "ci"
        } else {
            "install"
        };
        let install_output = self
            .run(&self.target, npm, &[install, "--no-audit", "--no-fund"], LONG_TIMEOUT, None)
            .await?;
        let build_output = self
            .run(&self.target, npm, &["run", "build"], LONG_TIMEOUT, None)
            .await?;
        Ok(format!("{install_output}\n\n{build_output}"))
    }

    /// Makes the app's writable directories usable by the owner and web
    /// server. The site normally runs in its own PHP-FPM pool as the owner, but
    /// the gateway falls back to the shared www-data pool when that pool can't
    /// be provisioned, so both need access.
    pub fn finalize(&self, permissions: &Permissions) -> Result<String, String> {
        let owner = format!("{}:www-data", self.username);
        if let Some(config) = permissions
            .secret_config
            .map(|relative| self.target.join(relative))
            .filter(|path| path.is_file())
        {
            let config = config.to_string_lossy();
            run_status("chown", &[&owner, config.as_ref()])?;
            run_status("chmod", &["640", config.as_ref()])?;
        }
        for dir in permissions.writable {
            let path = self.target.join(dir);
            if !path.is_dir() {
                continue;
            }
            let path = path.to_string_lossy();
            run_status("chown", &["-R", &owner, path.as_ref()])?;
            run_status("chmod", &["-R", "u+rwX,g+rwX,o-rwx", path.as_ref()])?;
        }
        Ok("Writable directory permissions prepared.".into())
    }

    async fn run(
        &self,
        cwd: &Path,
        program: &str,
        args: &[&str],
        timeout: Duration,
        input: Option<&[u8]>,
    ) -> Result<String, String> {
        run_as_owner(&self.username, &self.runtime, cwd, program, args, timeout, input).await
    }
}

/// Input checks shared by the CMS installers. Values are validated here as
/// well as in the panel because they end up in the apps' config files.
pub(super) fn has_no_control_chars(value: &str) -> bool {
    !value.chars().any(char::is_control)
}

/// Database/user names: letters, digits, underscore, up to 64.
pub(super) fn is_db_identifier(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 64
        && value.chars().all(|c| c.is_ascii_alphanumeric() || c == '_')
}

pub(super) fn is_db_host(value: &str) -> bool {
    !value.is_empty()
        && value
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-' | ':' | '_'))
}

/// Admin passwords: at least 12 characters, no control characters.
pub(super) fn is_strong_password(value: &str) -> bool {
    value.chars().count() >= 12 && has_no_control_chars(value)
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
    // Kept at its original path so existing composer/npm caches are reused.
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
            "Installer runtime",
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
        format!("#!/bin/sh\nexec {php} {COMPOSER} \"$@\"\n"),
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

    #[test]
    fn php_version_must_look_like_major_dot_minor() {
        assert!(php_binary("8").is_err());
        assert!(php_binary("8.3; rm").is_err());
        assert!(php_binary("../../bin/sh").is_err());
    }

    #[test]
    fn shared_input_checks() {
        assert!(is_db_identifier("site_db1"));
        assert!(!is_db_identifier("db; drop"));
        assert!(!is_db_identifier(&"a".repeat(65)));
        assert!(is_db_host("127.0.0.1:3306"));
        assert!(!is_db_host("host name"));
        assert!(is_strong_password("correct horse battery"));
        assert!(!is_strong_password("short"));
        assert!(!is_strong_password("twelve chars\nplus"));
    }
}
