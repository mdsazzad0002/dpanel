//! Shared installer service. Every app installer (laravel.rs, drupal.rs, …)
//! works through a [`Site`]: the project root is validated to sit inside the
//! owner's home, tools run as the site owner with the website's own PHP
//! version first on PATH (composer, artisan and Vite's wayfinder plugin all
//! shell out to `php`), and output is capped so a noisy build can't flood the
//! panel.

use crate::platform::paths::Tool;
use std::{
    fs,
    path::{Path, PathBuf},
    process::Stdio,
    time::Duration,
};

use crate::platform::{self, fs::{FileMode, ModeChange}, process::new_process_group};
use crate::filemanager::common::{ensure_directory_inside_home, validate_account, validate_user_path};

/// composer create-project/require and npm install/build.
pub(super) const LONG_TIMEOUT: Duration = Duration::from_secs(900);
/// artisan, drush and the CMS CLI installers.
pub(super) const SHORT_TIMEOUT: Duration = Duration::from_secs(600);
const MAX_OUTPUT_CHARS: usize = 20_000;
// dscript installs the official composer and Node.js 20+ into /usr/local/bin.
// Distro packages in /usr/bin are only the fallback: Ubuntu 22.04 ships
// composer 2.2 and Node.js 12, which modern Laravel/Vite projects reject.

pub(crate) fn find_tool(name: &str, tool: Tool) -> Result<String, String> {
    crate::platform::paths()
        .binary(tool)
        .map(|path| path.display().to_string())
        .map_err(|_| {
            format!("{name} is not installed on this server. Run `sudo dpanel chain update` to install it.")
        })
}

/// On a PC every site runs as the logged-in user, so where the OS has no
/// Unix modes there is nothing to open up. A server without them (Windows,
/// until its ACLs, backlog J1) still refuses rather than pretend.
fn no_modes_to_set(profile: platform::Profile, file_permissions: bool) -> bool {
    profile == platform::Profile::Local && !file_permissions
}

/// The composer that PHP runs as a script. Windows' Composer installs
/// `composer.bat` with `composer.phar` beside it; PHP given the .bat prints
/// it and exits 0, so an install "succeeded" with no files.
pub(crate) fn composer_script() -> Result<String, String> {
    let found = find_tool("composer", Tool::Composer)?;
    phar_for(Path::new(&found), Path::is_file)
        .map(|path| path.display().to_string())
        .ok_or_else(|| format!("{found} is a launcher, and no composer.phar is beside it."))
}

fn phar_for(found: &Path, is_file: impl Fn(&Path) -> bool) -> Option<PathBuf> {
    let launcher = found
        .extension()
        .is_some_and(|extension| extension.eq_ignore_ascii_case("bat") || extension.eq_ignore_ascii_case("cmd"));
    if !launcher {
        return Some(found.to_path_buf());
    }
    let phar = found.with_file_name("composer.phar");
    is_file(&phar).then_some(phar)
}

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
            // Canonical on Windows is `\\?\C:\…`, which PHP and composer refuse.
            target: platform::paths::plain_path(&target),
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

    /// Runs the PHP CLI script `script` (relative to `cwd`) as if started as
    /// `argv0 args…`, with the arguments read from stdin so passwords never
    /// appear in the process list.
    pub async fn php_script(
        &self,
        cwd: &Path,
        argv0: &str,
        script: &str,
        args: &[String],
    ) -> Result<String, String> {
        let bootstrap = cli_bootstrap(argv0, script)?;
        let stdin = serde_json::to_vec(args).map_err(|e| e.to_string())?;
        self.php_with_input(cwd, &["-r", &bootstrap], SHORT_TIMEOUT, &stdin)
            .await
    }

    /// A folder the app keeps outside the web root: inside the account home
    /// but not inside the project root. Created for the owner if missing.
    pub fn prepare_outside_dir(&self, path: &str, label: &str) -> Result<PathBuf, String> {
        let wanted = validate_user_path(&self.username, path)?;
        if wanted.starts_with(&self.target) {
            return Err(format!("{label} must be outside the project root."));
        }
        let (home, canonical_home, group) = validate_account(&self.username)?;
        if wanted == home {
            return Err(format!("{label} cannot be the account home."));
        }
        ensure_directory_inside_home(&self.username, &group, &home, &canonical_home, &wanted, label)
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
        let composer = composer_script()?;
        let mut full = vec![composer.as_str()];
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
        let npm = find_tool("npm", Tool::Npm)?;
        let install = if self.has_file("package-lock.json") {
            "ci"
        } else {
            "install"
        };
        let install_output = self
            .run(&self.target, &npm, &[install, "--no-audit", "--no-fund"], LONG_TIMEOUT, None)
            .await?;
        let build_output = self
            .run(&self.target, &npm, &["run", "build"], LONG_TIMEOUT, None)
            .await?;
        Ok(format!("{install_output}\n\n{build_output}"))
    }

    /// Makes the app's writable directories usable by the owner and web
    /// server. The site normally runs in its own PHP-FPM pool as the owner, but
    /// the gateway falls back to the shared www-data pool when that pool can't
    /// be provisioned, so both need access.
    pub fn finalize(&self, permissions: &Permissions) -> Result<String, String> {
        if no_modes_to_set(platform::current().profile(), platform::capabilities::report().capabilities.file_permissions) {
            return Ok("This PC's sites are its user's own files; no permissions to change.".into());
        }
        let files = platform::files();
        let owner = platform::fs::owner_named(&self.username, "www-data").map_err(|e| e.to_string())?;
        if let Some(config) = permissions
            .secret_config
            .map(|relative| self.target.join(relative))
            .filter(|path| path.is_file())
        {
            files.set_owner(&config, &owner).map_err(platform::fs::error_at(&config))?;
            platform::fs::change_mode(files, &config, ModeChange::Exact(0o640)).map_err(platform::fs::error_at(&config))?;
        }
        // u+rwX,g+rwX,o-rwx
        let writable = ModeChange::Adjust { add: 0o660, add_exec: 0o110, remove: 0o007 };
        for dir in permissions.writable {
            let path = self.target.join(dir);
            if !path.is_dir() {
                continue;
            }
            platform::fs::set_owner_tree(files, &path, &owner).map_err(platform::fs::error_at(&path))?;
            platform::fs::change_mode_tree(files, &path, writable).map_err(platform::fs::error_at(&path))?;
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

/// Domain names: letters, digits, dots and hyphens.
pub(super) fn is_hostname(value: &str) -> bool {
    !value.is_empty()
        && value.len() <= 253
        && !value.starts_with(['.', '-'])
        && value
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '.' | '-'))
}

/// `php -r` code that sets argv from stdin JSON and runs `script`.
fn cli_bootstrap(argv0: &str, script: &str) -> Result<String, String> {
    let safe = |value: &str| {
        !value.is_empty()
            && !value.contains("..")
            && value
                .chars()
                .all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.' | '/'))
    };
    if !safe(argv0) || !safe(script) {
        return Err("Invalid installer script.".into());
    }
    Ok(format!(
        r#"$a = json_decode(stream_get_contents(STDIN), true);
if (!is_array($a)) {{ fwrite(STDERR, "Invalid installer input.\n"); exit(1); }}
$_SERVER['argv'] = $argv = array_merge(['{argv0}'], $a);
$_SERVER['argc'] = $argc = count($argv);
require '{script}';"#
    ))
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
    let binary = platform::paths().php_cli(Some(version));
    if !binary.is_file() {
        return Err(format!(
            "PHP {version} CLI is not installed on this server."
        ));
    }
    Ok(binary.to_string_lossy().into_owned())
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
    let runtime_home = platform::paths::plain_path(&dirs[0]);
    let bin = platform::paths::plain_path(&dirs[1]);

    if platform::current().os() == platform::Os::Windows {
        // A symlink needs a privilege there, and cmd runs .bat files, not
        // shebang scripts: `php` and `composer` are batch shims instead.
        for (name, text) in windows_shims(php, &composer_script()?) {
            fs::write(bin.join(name), text).map_err(|e| format!("Cannot create {name} shim: {e}"))?;
        }
        return Ok(Runtime { home: runtime_home, bin });
    }

    let php_link = bin.join("php");
    if fs::symlink_metadata(&php_link).is_ok() {
        fs::remove_file(&php_link).map_err(|e| format!("Cannot reset php shim: {e}"))?;
    }
    platform::files()
        .symlink(Path::new(php), &php_link)
        .map_err(|e| format!("Cannot create php shim: {e}"))?;

    let composer = bin.join("composer");
    if fs::symlink_metadata(&composer).is_ok() {
        fs::remove_file(&composer).map_err(|e| format!("Cannot reset composer shim: {e}"))?;
    }
    fs::write(
        &composer,
        format!(
            "#!/bin/sh\nexec {php} {} \"$@\"\n",
            find_tool("composer", Tool::Composer)?
        ),
    )
    .map_err(|e| format!("Cannot create composer shim: {e}"))?;
    platform::files().set_mode(&composer, FileMode::Executable)
        .map_err(|e| format!("Cannot chmod composer shim: {e}"))?;

    Ok(Runtime {
        home: runtime_home,
        bin,
    })
}

/// `php.bat` and `composer.bat`, which pin the website's PHP for composer
/// scripts that call `php` or `composer` by name.
fn windows_shims(php: &str, composer_phar: &str) -> [(&'static str, String); 2] {
    [
        ("php.bat", format!("@\"{php}\" %*\r\n")),
        ("composer.bat", format!("@\"{php}\" \"{composer_phar}\" %*\r\n")),
    ]
}

/// The shims first. Linux gets the fixed system folders, as before; Windows
/// keeps drust's own PATH, without which nothing starts.
fn runtime_path(bin: &Path, os: platform::Os, inherited: Option<std::ffi::OsString>) -> std::ffi::OsString {
    if os != platform::Os::Windows {
        return format!("{}:/usr/local/bin:/usr/bin:/bin", bin.display()).into();
    }
    let rest = inherited.map(|path| std::env::split_paths(&path).collect::<Vec<_>>()).unwrap_or_default();
    std::env::join_paths(std::iter::once(bin.to_path_buf()).chain(rest)).unwrap_or_else(|_| bin.as_os_str().to_owned())
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
    let mut command = tokio::process::Command::from(crate::platform::run_as(username, program)?);
    command
        .env("HOME", &runtime.home)
        .env("USER", username)
        .env("LOGNAME", username)
        .env("PATH", runtime_path(&runtime.bin, platform::current().os(), std::env::var_os("PATH")))
        .env("COMPOSER_HOME", format!("{home}/composer"))
        .env("COMPOSER_CACHE_DIR", format!("{home}/composer-cache"))
        .env("COMPOSER_NO_INTERACTION", "1")
        .env("npm_config_cache", format!("{home}/npm-cache"))
        .env("CI", "1")
        .args(args)
        .current_dir(cwd)
        .stdin(if input.is_some() {
            Stdio::piped()
        } else {
            Stdio::null()
        })
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .kill_on_drop(true);
    new_process_group(&mut command);

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
    fn php_runs_composer_phar_never_the_windows_launcher() {
        let dir = Path::new(r"C:\Composer");
        let phar = dir.join("composer.phar");
        let has_phar = |path: &Path| path == phar;
        assert_eq!(phar_for(&dir.join("composer.bat"), has_phar), Some(phar.clone()));
        assert_eq!(phar_for(&dir.join("COMPOSER.CMD"), has_phar), Some(phar.clone()));
        assert_eq!(phar_for(&dir.join("composer.bat"), |_| false), None);
        assert_eq!(phar_for(Path::new("/usr/local/bin/composer"), |_| false), Some("/usr/local/bin/composer".into()));
    }

    #[test]
    fn only_a_pc_without_modes_skips_them() {
        assert!(no_modes_to_set(platform::Profile::Local, false));
        assert!(!no_modes_to_set(platform::Profile::Server, false));
        assert!(!no_modes_to_set(platform::Profile::Local, true));
    }

    #[test]
    fn windows_shims_quote_paths_and_pass_every_argument() {
        let [php, composer] = windows_shims(r"C:\php 8.3\php.exe", r"C:\Composer\composer.phar");
        assert_eq!(php, ("php.bat", "@\"C:\\php 8.3\\php.exe\" %*\r\n".to_string()));
        assert_eq!(composer.1, "@\"C:\\php 8.3\\php.exe\" \"C:\\Composer\\composer.phar\" %*\r\n");
    }

    #[test]
    fn the_shims_come_first_on_path() {
        let bin = Path::new("/home/a/.dpanel/laravel-installer/bin");
        assert_eq!(
            runtime_path(bin, platform::Os::Linux, Some("/evil".into())),
            std::ffi::OsString::from("/home/a/.dpanel/laravel-installer/bin:/usr/local/bin:/usr/bin:/bin")
        );
        // Joining uses this OS's separator, and `C:` holds Unix's.
        if std::path::MAIN_SEPARATOR != '\\' {
            return;
        }
        let windows = runtime_path(Path::new(r"C:\s\bin"), platform::Os::Windows, Some(r"C:\Windows\system32".into()));
        let parts: Vec<PathBuf> = std::env::split_paths(&windows).collect();
        assert_eq!(parts[0], Path::new(r"C:\s\bin"));
        assert!(parts.contains(&PathBuf::from(r"C:\Windows\system32")));
    }

    #[test]
    fn php_version_must_look_like_major_dot_minor() {
        assert!(php_binary("8").is_err());
        assert!(php_binary("8.3; rm").is_err());
        assert!(php_binary("../../bin/sh").is_err());
    }

    #[test]
    fn cli_bootstrap_only_takes_plain_script_names() {
        let code = cli_bootstrap("occ", "console.php").unwrap();
        assert!(code.contains("array_merge(['occ'], $a)"));
        assert!(code.contains("require 'console.php';"));
        assert!(cli_bootstrap("x", "../evil.php").is_err());
        assert!(cli_bootstrap("x'; system('id'); '", "a.php").is_err());
        assert!(is_hostname("shop.example.com"));
        assert!(!is_hostname("shop example.com"));
        assert!(!is_hostname("-x.com"));
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
