use sha1::{Digest, Sha1};
use std::env;
use std::fs;
use std::io::Write;
use std::path::{Path, PathBuf};
use std::process::{Command, ExitCode};
use std::time::{SystemTime, UNIX_EPOCH};

use crate::scripts;

pub fn run() -> ExitCode {
    let args: Vec<String> = env::args().skip(1).collect();

    // If first arg is "serve", start HTTP API mode
    if args.first().map(|s| s.as_str()) == Some("serve") {
        return crate::api::serve(args);
    }

    match execute() {
        Ok(code) => code,
        Err(err) => {
            eprintln!("[ERROR] {err}");
            ExitCode::from(1)
        }
    }
}

/// Public wrapper for API: run admin user creation
pub fn run_admin_user(args: Vec<String>) -> Result<(), String> {
    let opts = CreateAdminUserOptions::parse(args)?;
    create_admin_user(opts)
}

/// Public wrapper for API: disable root login
pub fn run_disable_root_login() -> Result<(), String> {
    disable_root_login()
}

/// Public wrapper for API: run laravel install
pub fn run_laravel_install(args: Vec<String>) -> Result<(), String> {
    let opts = LaravelInstallOptions::parse(args)?;
    laravel_install(opts)
}

fn execute() -> Result<ExitCode, String> {
    let mut args = env::args().skip(1).collect::<Vec<_>>();
    if args.is_empty() {
        print_help();
        return Ok(ExitCode::from(0));
    }

    let command = args.remove(0);
    match command.as_str() {
        "fix-web-stack" => {
            let apache_port = args.get(0).and_then(|v| parse_port(v)).unwrap_or(8080);
            let nginx_port = args.get(1).and_then(|v| parse_port(v)).unwrap_or(80);
            crate::vhost::run_fix_web_stack(apache_port, nginx_port)?;
            Ok(ExitCode::from(0))
        }
        "fix-panel-web-stack" => {
            crate::vhost::run_fix_panel_web_stack(args)?;
            Ok(ExitCode::from(0))
        }
        "sync-vhost" => {
            crate::vhost::run_sync_vhost(args)?;
            Ok(ExitCode::from(0))
        }
        "create-admin-user" => {
            let opts = CreateAdminUserOptions::parse(args)?;
            create_admin_user(opts)?;
            Ok(ExitCode::from(0))
        }
        "disable-root-login" => {
            disable_root_login()?;
            Ok(ExitCode::from(0))
        }
        "create-demo-site" => run_script("create-demo-site.sh", args),
        "database-request" => run_script("database-request.sh", args),
        "install-roundcube-dovecot-mysql" => run_script("install-roundcube-dovecot-mysql.sh", args),
        "reset-web-stack" => run_script("reset-web-stack.sh", args),
        "php-config-apply" => run_script("php-config-apply.sh", args),
        "php-detect-config" => run_script("php-detect-config.sh", args),
        "php-detect-extensions" => run_script("php-detect-extensions.sh", args),
        "php-detect-versions" => run_script("php-detect-versions.sh", args),
        "issue-ssl" => run_script("issue-ssl.sh", args),
        "fix-dpanel-root" => run_script("fix-dpanel-root.sh", args),
        "laravel-install" => {
            let opts = LaravelInstallOptions::parse(args)?;
            laravel_install(opts)?;
            Ok(ExitCode::from(0))
        }
        "filemanager" => {
            crate::filemanager::run(args)?;
            Ok(ExitCode::from(0))
        }
        "admin-user" => {
            sync_admin_user_module(args)?;
            Ok(ExitCode::from(0))
        }
        "ssh-root-login" => {
            ssh_root_login_module(args)?;
            Ok(ExitCode::from(0))
        }
        "help" | "-h" | "--help" => {
            print_help();
            Ok(ExitCode::from(0))
        }
        other => Err(format!("Unknown command: {other}")),
    }
}

fn print_help() {
    println!(
        "\
dscript - Rust replacement for the dpanel helper scripts

Usage:
  dscript fix-web-stack [apache-backend-port] [nginx-frontend-port]
  dscript fix-panel-web-stack [options] [domain] [backend-port] [frontend-port] [alias...]
  dscript sync-vhost [options] <action> <domain> <root-path> [php-version] [old-domain] [alias...]
  dscript create-admin-user --username <name> [options]
  dscript disable-root-login
  dscript create-demo-site <root-path> <domain> [php-version] [start-directory]
  dscript database-request <create|upsert> <db-name> <db-user> <db-password> [host] [port] [charset] [collation]
  dscript install-roundcube-dovecot-mysql [options]
  dscript reset-web-stack [--yes]
  dscript php-config-apply --version <php-version>
  dscript php-detect-config --version <php-version>
  dscript php-detect-extensions --version <php-version>
  dscript php-detect-versions
  dscript issue-ssl [options]
  dscript laravel-install <root-path> <domain> [php-version] [start-directory]
  dscript filemanager <create|remove|exists|file-exists|user> ...
  dscript admin-user <install|update|remove> [args...]
  dscript ssh-root-login <install|update|remove> [args...]
"
    );
}

fn run_script(script_name: &str, args: Vec<String>) -> Result<ExitCode, String> {
    scripts::run_script(script_name, &args)?;
    Ok(ExitCode::from(0))
}

#[derive(Default)]
struct LaravelInstallOptions {
    root_path: String,
    domain: String,
    php_version: String,
    start_directory: String,
    db_name: Option<String>,
    db_user: Option<String>,
    db_password: Option<String>,
    db_host: String,
    db_port: String,
    db_charset: String,
    db_collation: String,
    site_user: Option<String>,
    site_group: Option<String>,
    no_demo: bool,
    no_db: bool,
    no_vhost: bool,
}

impl LaravelInstallOptions {
    fn parse(args: Vec<String>) -> Result<Self, String> {
        let mut opts = LaravelInstallOptions {
            php_version: "auto".into(),
            start_directory: String::new(),
            db_host: "127.0.0.1".into(),
            db_port: "3306".into(),
            db_charset: "utf8mb4".into(),
            db_collation: "utf8mb4_unicode_ci".into(),
            ..Default::default()
        };
        let mut positional = Vec::new();
        let mut iter = args.into_iter();
        while let Some(arg) = iter.next() {
            match arg.as_str() {
                "--root" | "--root-path" => {
                    opts.root_path = iter
                        .next()
                        .ok_or_else(|| "Missing value for --root-path".to_string())?;
                }
                "--domain" => {
                    opts.domain = iter
                        .next()
                        .ok_or_else(|| "Missing value for --domain".to_string())?;
                }
                "--php-version" => {
                    opts.php_version = iter
                        .next()
                        .ok_or_else(|| "Missing value for --php-version".to_string())?;
                }
                "--start-directory" => {
                    opts.start_directory = iter
                        .next()
                        .ok_or_else(|| "Missing value for --start-directory".to_string())?;
                }
                "--db-name" => {
                    opts.db_name = Some(
                        iter.next()
                            .ok_or_else(|| "Missing value for --db-name".to_string())?,
                    );
                }
                "--db-user" => {
                    opts.db_user = Some(
                        iter.next()
                            .ok_or_else(|| "Missing value for --db-user".to_string())?,
                    );
                }
                "--db-password" => {
                    opts.db_password = Some(
                        iter.next()
                            .ok_or_else(|| "Missing value for --db-password".to_string())?,
                    );
                }
                "--db-host" => {
                    opts.db_host = iter
                        .next()
                        .ok_or_else(|| "Missing value for --db-host".to_string())?;
                }
                "--db-port" => {
                    opts.db_port = iter
                        .next()
                        .ok_or_else(|| "Missing value for --db-port".to_string())?;
                }
                "--db-charset" => {
                    opts.db_charset = iter
                        .next()
                        .ok_or_else(|| "Missing value for --db-charset".to_string())?;
                }
                "--db-collation" => {
                    opts.db_collation = iter
                        .next()
                        .ok_or_else(|| "Missing value for --db-collation".to_string())?;
                }
                "--user" => {
                    opts.site_user = Some(
                        iter.next()
                            .ok_or_else(|| "Missing value for --user".to_string())?,
                    );
                }
                "--group" => {
                    opts.site_group = Some(
                        iter.next()
                            .ok_or_else(|| "Missing value for --group".to_string())?,
                    );
                }
                "--no-demo" => opts.no_demo = true,
                "--no-db" => opts.no_db = true,
                "--no-vhost" => opts.no_vhost = true,
                other if other.starts_with('-') => {
                    return Err(format!("Unknown option: {other}"));
                }
                other => positional.push(other.to_string()),
            }
        }

        if opts.root_path.is_empty() {
            opts.root_path = positional.first().cloned().unwrap_or_default();
        }
        if opts.domain.is_empty() {
            opts.domain = positional.get(1).cloned().unwrap_or_default();
        }
        if opts.php_version == "auto" && positional.get(2).is_some() {
            opts.php_version = positional[2].clone();
        }
        if opts.start_directory.is_empty() && positional.get(3).is_some() {
            opts.start_directory = positional[3].clone();
        }

        if opts.root_path.trim().is_empty() || opts.domain.trim().is_empty() {
            return Err(
                "Usage: laravel-install <root-path> <domain> [php-version] [start-directory]"
                    .into(),
            );
        }

        Ok(opts)
    }
}

fn laravel_install(opts: LaravelInstallOptions) -> Result<(), String> {
    ensure_root()?;

    if !opts.no_demo {
        let mut script_args = vec![
            opts.root_path.clone(),
            opts.domain.clone(),
            opts.php_version.clone(),
        ];
        if !opts.start_directory.is_empty() {
            script_args.push(opts.start_directory.clone());
        }
        scripts::run_script("create-demo-site.sh", &script_args)?;
    }

    if !opts.no_db {
        if let (Some(db_name), Some(db_user), Some(db_password)) = (
            opts.db_name.as_ref(),
            opts.db_user.as_ref(),
            opts.db_password.as_ref(),
        ) {
            scripts::run_script(
                "database-request.sh",
                &vec![
                    "create".into(),
                    db_name.clone(),
                    db_user.clone(),
                    db_password.clone(),
                    opts.db_host.clone(),
                    opts.db_port.clone(),
                    opts.db_charset.clone(),
                    opts.db_collation.clone(),
                ],
            )?;
        }
    }

    if !opts.no_vhost {
        scripts::run_script(
            "sync-vhost.sh",
            &vec![
                "create".into(),
                opts.domain.clone(),
                opts.root_path.clone(),
                opts.php_version.clone(),
            ],
        )?;
    }

    if let Some(user) = opts.site_user.as_ref() {
        let group = opts.site_group.clone().unwrap_or_else(|| user.clone());
        run_status(
            "chown",
            &["-R", &format!("{user}:{group}"), &opts.root_path],
        )?;
        let storage = Path::new(&opts.root_path).join("storage");
        let cache_dir = Path::new(&opts.root_path).join("bootstrap/cache");
        if storage.exists() {
            run_status(
                "chown",
                &[
                    "-R",
                    &format!("{user}:{group}"),
                    storage.to_string_lossy().as_ref(),
                ],
            )?;
            run_status("chmod", &["-R", "0775", storage.to_string_lossy().as_ref()])?;
        }
        if cache_dir.exists() {
            run_status(
                "chown",
                &[
                    "-R",
                    &format!("{user}:{group}"),
                    cache_dir.to_string_lossy().as_ref(),
                ],
            )?;
            run_status(
                "chmod",
                &["-R", "0775", cache_dir.to_string_lossy().as_ref()],
            )?;
        }
        info(&format!("Applied site ownership for {user}:{group}."));
    }

    info(&format!(
        "Laravel install flow completed for {} at {}.",
        opts.domain, opts.root_path
    ));
    Ok(())
}

pub fn info(message: &str) {
    println!("[INFO] {message}");
}

pub fn warn(message: &str) {
    eprintln!("[WARN] {message}");
}

pub fn ensure_root() -> Result<(), String> {
    let output = Command::new("id")
        .arg("-u")
        .output()
        .map_err(|e| format!("failed to check uid: {e}"))?;
    if !output.status.success() {
        return Err("failed to determine effective uid".into());
    }
    let uid = String::from_utf8_lossy(&output.stdout).trim().to_string();
    if uid != "0" {
        return Err("This command must run as root.".into());
    }
    Ok(())
}

pub fn run_status(program: &str, args: &[&str]) -> Result<(), String> {
    let status = Command::new(program)
        .args(args)
        .status()
        .map_err(|e| format!("failed to run {program}: {e}"))?;
    if status.success() {
        Ok(())
    } else {
        Err(format!("{program} {:?} failed with status {status}", args))
    }
}

pub fn run_output(program: &str, args: &[&str]) -> Result<String, String> {
    let output = Command::new(program)
        .args(args)
        .output()
        .map_err(|e| format!("failed to run {program}: {e}"))?;
    if !output.status.success() {
        return Err(format!("{program} {:?} failed", args));
    }
    Ok(String::from_utf8_lossy(&output.stdout).trim().to_string())
}

fn program_exists(program: &str) -> bool {
    Command::new("sh")
        .arg("-c")
        .arg(format!("command -v {program} >/dev/null 2>&1"))
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
}

fn current_epoch() -> u64 {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

pub fn backup_file(path: &Path) -> Result<(), String> {
    if path.exists() {
        let backup = format!("{}.bak.{}", path.display(), current_epoch());
        fs::copy(path, &backup).map_err(|e| format!("failed to backup {}: {e}", path.display()))?;
    }
    Ok(())
}

pub fn write_string(path: &Path, contents: &str) -> Result<(), String> {
    if let Some(parent) = path.parent() {
        fs::create_dir_all(parent)
            .map_err(|e| format!("failed to create {}: {e}", parent.display()))?;
    }
    fs::write(path, contents).map_err(|e| format!("failed to write {}: {e}", path.display()))
}

pub fn read_to_string(path: &Path) -> Result<String, String> {
    fs::read_to_string(path).map_err(|e| format!("failed to read {}: {e}", path.display()))
}

fn sanitize_domain(value: &str) -> String {
    let mut out = value.trim().to_lowercase();
    out = out
        .chars()
        .map(|c| {
            if c.is_ascii_alphanumeric() || c == '.' || c == '-' {
                c
            } else {
                '-'
            }
        })
        .collect::<String>();
    out.trim_matches('-').to_string()
}

fn short_hash(value: &str) -> String {
    let hash = Sha1::digest(value.as_bytes());
    format!("{hash:x}")[..12].to_string()
}

fn domain_token(domain: &str) -> String {
    let mut token = sanitize_domain(domain);
    if token.is_empty() {
        token = "site".to_string();
    }
    if token.len() > 110 {
        token.truncate(110);
    }
    token
}

pub fn conf_basename(domain: &str) -> String {
    format!("{}-{}", domain_token(domain), short_hash(domain))
}

pub fn split_aliases(raw: &str) -> Vec<String> {
    raw.replace(';', ",")
        .split(',')
        .map(|item| item.trim().to_string())
        .filter(|item| !item.is_empty())
        .collect()
}

fn os_release_value(key: &str) -> Option<String> {
    let data = fs::read_to_string("/etc/os-release").ok()?;
    for line in data.lines() {
        let line = line.trim();
        if line.starts_with('#') || line.is_empty() {
            continue;
        }
        if let Some(rest) = line.strip_prefix(&format!("{key}=")) {
            return Some(rest.trim_matches('"').to_string());
        }
    }
    None
}

pub fn distro_family() -> String {
    match os_release_value("ID").as_deref() {
        Some("ubuntu") | Some("debian") => "debian".to_string(),
        Some("rocky") | Some("almalinux") | Some("rhel") | Some("centos") | Some("fedora") => {
            "rpm".to_string()
        }
        _ => "unknown".to_string(),
    }
}

fn apache_service_name() -> Option<&'static str> {
    if Command::new("systemctl")
        .args(["cat", "apache2.service"])
        .status()
        .ok()
        .map(|s| s.success())
        .unwrap_or(false)
    {
        Some("apache2")
    } else if Command::new("systemctl")
        .args(["cat", "httpd.service"])
        .status()
        .ok()
        .map(|s| s.success())
        .unwrap_or(false)
    {
        Some("httpd")
    } else {
        None
    }
}

fn nginx_service_available() -> bool {
    Command::new("systemctl")
        .args(["cat", "nginx.service"])
        .status()
        .ok()
        .map(|s| s.success())
        .unwrap_or(false)
        || program_exists("nginx")
}

pub fn restart_services_for_web_stack() -> Result<(), String> {
    match apache_service_name() {
        Some("apache2") => {
            run_status("apache2ctl", &["-t"])?;
            let _ = run_status("systemctl", &["enable", "apache2"]);
            run_status("systemctl", &["restart", "apache2"])?;
        }
        Some("httpd") => {
            run_status("httpd", &["-t"])?;
            let _ = run_status("systemctl", &["enable", "httpd"]);
            run_status("systemctl", &["restart", "httpd"])?;
        }
        _ => {}
    }

    if nginx_service_available() {
        run_status("nginx", &["-t"])?;
        let _ = run_status("systemctl", &["enable", "nginx"]);
        run_status("systemctl", &["restart", "nginx"])?;
    }

    Ok(())
}

pub fn ensure_listen_line(content: &str, port: u16) -> String {
    let needle = format!("Listen {port}");
    if content.lines().any(|line| line.trim() == needle) {
        return content.to_string();
    }

    let mut out = content.to_string();
    if !out.ends_with('\n') {
        out.push('\n');
    }
    out.push_str(&needle);
    out.push('\n');
    out
}

pub fn ensure_comment_listen(content: &str, port: u16) -> String {
    content
        .lines()
        .map(|line| {
            let trimmed = line.trim_start();
            if trimmed == format!("Listen {port}") {
                format!("# Listen {port}")
            } else {
                line.to_string()
            }
        })
        .collect::<Vec<_>>()
        .join("\n")
        + "\n"
}

pub fn parse_port(value: &str) -> Option<u16> {
    value.parse::<u16>().ok().filter(|port| *port >= 1)
}

pub fn detect_app_root(explicit: Option<&str>) -> Result<PathBuf, String> {
    if let Some(candidate) = explicit {
        let path = PathBuf::from(candidate);
        if path.join("public/index.php").exists() {
            return Ok(path);
        }
    }

    for candidate in [
        "/var/www/dpanel",
        "/home/dpanel/likesoftbd_com/dpanel",
        "/var/www/ServerPanel",
        "/opt/likesoft/dpanel",
    ] {
        let path = PathBuf::from(candidate);
        if path.join("public/index.php").exists() {
            return Ok(path);
        }
    }

    Err(
        "Unable to detect panel app root. Set PANEL_APP_DIR to the Laravel project directory."
            .into(),
    )
}

pub fn remove_legacy_panel_vhosts() {
    for path in [
        "/etc/apache2/sites-available/dpanel.conf",
        "/etc/apache2/sites-enabled/dpanel.conf",
        "/etc/nginx/sites-available/dpanel.conf",
        "/etc/nginx/sites-enabled/dpanel.conf",
    ] {
        let _ = fs::remove_file(path);
    }
    let _ = run_status("a2dissite", &["dpanel.conf"]);
}

fn should_add_www_alias(domain: &str, no_www: bool) -> bool {
    if no_www || domain.starts_with("www.") {
        return false;
    }
    domain.matches('.').count() >= 1
}

pub fn panel_aliases_for(domain: &str, aliases: &[String], no_www: bool) -> String {
    let mut all = Vec::new();
    if should_add_www_alias(domain, no_www) {
        all.push(format!("www.{domain}"));
    }
    for alias in aliases {
        if !alias.trim().is_empty() && alias.trim() != domain {
            all.push(alias.trim().to_string());
        }
    }
    all.join(" ")
}

#[derive(Default)]
struct CreateAdminUserOptions {
    username: String,
    password: Option<String>,
    email: Option<String>,
    ssh_key: Option<String>,
    shell_path: String,
    disable_root: bool,
}

impl CreateAdminUserOptions {
    fn parse(args: Vec<String>) -> Result<Self, String> {
        let mut opts = CreateAdminUserOptions {
            shell_path: "/bin/bash".into(),
            ..Default::default()
        };
        let mut positional_username = None;
        let mut iter = args.into_iter();
        while let Some(arg) = iter.next() {
            match arg.as_str() {
                "--username" => {
                    opts.username = iter
                        .next()
                        .ok_or_else(|| "Missing value for --username".to_string())?;
                }
                "--password" | "--panel-password" => {
                    opts.password = Some(
                        iter.next()
                            .ok_or_else(|| format!("Missing value for {arg}"))?,
                    );
                }
                "--email" | "--panel-email" => {
                    opts.email = Some(
                        iter.next()
                            .ok_or_else(|| format!("Missing value for {arg}"))?,
                    );
                }
                "--ssh-key" => {
                    opts.ssh_key = Some(
                        iter.next()
                            .ok_or_else(|| "Missing value for --ssh-key".to_string())?,
                    );
                }
                "--shell" => {
                    opts.shell_path = iter
                        .next()
                        .ok_or_else(|| "Missing value for --shell".to_string())?;
                }
                "--disable-root" => opts.disable_root = true,
                "--keep-root" | "--no-disable-root" => opts.disable_root = false,
                other if other.starts_with('-') => return Err(format!("Unknown option: {other}")),
                other => {
                    if positional_username.is_none() && opts.username.is_empty() {
                        positional_username = Some(other.to_string());
                    } else {
                        return Err(format!("Unknown argument: {other}"));
                    }
                }
            }
        }

        if opts.username.is_empty() {
            opts.username = positional_username.unwrap_or_default();
        }

        if opts.username.trim().is_empty() {
            return Err("Username is required.".into());
        }

        Ok(opts)
    }
}

pub fn valid_username(username: &str) -> bool {
    let mut chars = username.chars();
    match chars.next() {
        Some(c) if c.is_ascii_lowercase() || c == '_' => {}
        _ => return false,
    }
    chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-')
}

fn valid_email(email: &str) -> bool {
    let email = email.trim();
    let parts = email.split('@').collect::<Vec<_>>();
    if parts.len() != 2 {
        return false;
    }
    !parts[0].is_empty() && parts[1].contains('.')
}

pub fn random_hex(len: usize) -> Result<String, String> {
    if program_exists("openssl") {
        let output = run_output("openssl", &["rand", "-hex", "32"])?;
        return Ok(output.chars().take(len).collect());
    }

    let mut bytes = vec![0u8; len / 2 + 1];
    let mut file =
        fs::File::open("/dev/urandom").map_err(|e| format!("failed to open /dev/urandom: {e}"))?;
    use std::io::Read;
    file.read_exact(&mut bytes)
        .map_err(|e| format!("failed to read random bytes: {e}"))?;
    let mut out = String::new();
    for byte in bytes {
        out.push_str(&format!("{byte:02x}"));
    }
    Ok(out.chars().take(len).collect())
}

fn user_home(username: &str) -> Result<PathBuf, String> {
    let output = run_output("getent", &["passwd", username])?;
    let fields = output.split(':').collect::<Vec<_>>();
    if fields.len() >= 6 {
        Ok(PathBuf::from(fields[5]))
    } else {
        Err(format!("Unable to determine home directory for {username}"))
    }
}

pub fn user_group(username: &str) -> Result<String, String> {
    run_output("id", &["-gn", username])
}

fn create_admin_user(opts: CreateAdminUserOptions) -> Result<(), String> {
    ensure_root()?;
    if !valid_username(&opts.username) {
        return Err(format!("Invalid username: {}", opts.username));
    }
    if let Some(email) = &opts.email {
        if !valid_email(email) {
            return Err(format!("Invalid email address: {email}"));
        }
    }

    if Command::new("getent")
        .args(["passwd", &opts.username])
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
    {
        run_status("usermod", &["-s", &opts.shell_path, &opts.username])?;
        info(&format!(
            "Updated shell for existing user {}.",
            opts.username
        ));
    } else {
        run_status("useradd", &["-m", "-s", &opts.shell_path, &opts.username])?;
        info(&format!("Created user {}.", opts.username));
    }

    if Command::new("getent")
        .args(["group", "sudo"])
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
    {
        let _ = run_status("usermod", &["-aG", "sudo", &opts.username]);
    }
    if Command::new("getent")
        .args(["group", "wheel"])
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
    {
        let _ = run_status("usermod", &["-aG", "wheel", &opts.username]);
    }

    if let Some(email) = &opts.email {
        let comment = format!("panel-email={email}");
        run_status("usermod", &["-c", &comment, &opts.username])?;
        info(&format!("Panel email recorded for {}.", opts.username));
    }

    let mut password = opts.password.clone();
    if password.is_none() && opts.ssh_key.is_none() {
        let generated = random_hex(16)?;
        println!(
            "Generated temporary password for {}: {}",
            opts.username, generated
        );
        warn(&format!(
            "No password or SSH key provided. Generated temporary password for {}: {}",
            opts.username, generated
        ));
        password = Some(generated);
    }

    if let Some(pass) = password {
        let mut child = Command::new("chpasswd")
            .stdin(std::process::Stdio::piped())
            .spawn()
            .map_err(|e| format!("failed to start chpasswd: {e}"))?;
        if let Some(stdin) = child.stdin.as_mut() {
            writeln!(stdin, "{}:{}", opts.username, pass)
                .map_err(|e| format!("failed to write password: {e}"))?;
        }
        let status = child
            .wait()
            .map_err(|e| format!("failed waiting for chpasswd: {e}"))?;
        if !status.success() {
            return Err("Password configuration failed.".into());
        }
        info(&format!("Password configured for {}.", opts.username));
    } else {
        let _ = run_status("passwd", &["-l", &opts.username]);
    }

    if let Some(ssh_key) = &opts.ssh_key {
        let key_data = if Path::new(ssh_key).is_file() {
            read_to_string(Path::new(ssh_key))?
        } else {
            ssh_key.clone()
        };
        let home_dir = user_home(&opts.username)?;
        let primary_group = user_group(&opts.username).unwrap_or_else(|_| opts.username.clone());
        let ssh_dir = home_dir.join(".ssh");
        let auth_keys = ssh_dir.join("authorized_keys");
        fs::create_dir_all(&ssh_dir)
            .map_err(|e| format!("failed to create {}: {e}", ssh_dir.display()))?;
        let _ = run_status(
            "install",
            &[
                "-d",
                "-m",
                "0700",
                "-o",
                &opts.username,
                "-g",
                &primary_group,
                ssh_dir.to_string_lossy().as_ref(),
            ],
        );
        let _ = fs::write(&auth_keys, format!("{key_data}\n"));
        let _ = run_status(
            "chown",
            &[
                &format!("{}:{}", opts.username, primary_group),
                auth_keys.to_string_lossy().as_ref(),
            ],
        );
        let _ = run_status("chmod", &["0600", auth_keys.to_string_lossy().as_ref()]);
        info(&format!("SSH key installed for {}.", opts.username));
    }

    if opts.password.is_some() || opts.ssh_key.is_some() {
        let _ = run_status("passwd", &["-u", &opts.username]);
    }

    if opts.disable_root {
        disable_root_login()?;
    }

    println!("Admin user setup completed for {}", opts.username);
    info(&format!(
        "Admin user setup completed for {}.",
        opts.username
    ));
    Ok(())
}

fn disable_root_login() -> Result<(), String> {
    ensure_root()?;
    let config_path = Path::new("/etc/ssh/sshd_config");
    if !config_path.exists() {
        return Err(format!("SSH config not found: {}", config_path.display()));
    }

    let backup = format!(
        "{}.likesoft.backup.{}",
        config_path.display(),
        current_epoch()
    );
    fs::copy(config_path, &backup).map_err(|e| format!("failed to backup ssh config: {e}"))?;
    let dropin_dir = Path::new("/etc/ssh/sshd_config.d");
    let dropin_file = dropin_dir.join("99-likesoft-root-login.conf");
    fs::create_dir_all(dropin_dir)
        .map_err(|e| format!("failed to create {}: {e}", dropin_dir.display()))?;
    write_string(&dropin_file, "PermitRootLogin no\n")?;

    let mut content = read_to_string(config_path)?;
    let mut found = false;
    let mut lines = Vec::new();
    for line in content.lines() {
        let trimmed = line.trim_start();
        if trimmed.starts_with("PermitRootLogin") {
            lines.push("PermitRootLogin no".to_string());
            found = true;
        } else {
            lines.push(line.to_string());
        }
    }
    if !found {
        lines.push(String::from("PermitRootLogin no"));
    }
    content = lines.join("\n") + "\n";
    write_string(config_path, &content)?;

    let sshd_ok = if program_exists("sshd") {
        run_status("sshd", &["-t"]).is_ok()
    } else if Path::new("/usr/sbin/sshd").exists() {
        Command::new("/usr/sbin/sshd")
            .arg("-t")
            .status()
            .map(|s| s.success())
            .unwrap_or(false)
    } else {
        warn("sshd binary not found; skipping config syntax check.");
        true
    };

    if !sshd_ok {
        fs::copy(&backup, config_path).map_err(|e| format!("failed to restore ssh config: {e}"))?;
        let _ = fs::remove_file(&dropin_file);
        return Err("SSH config validation failed. Original file restored.".into());
    }

    let service_name = if Command::new("systemctl")
        .args(["list-unit-files", "ssh.service"])
        .status()
        .map(|s| s.success())
        .unwrap_or(false)
    {
        "ssh"
    } else {
        "sshd"
    };

    let _ = run_status("systemctl", &["restart", service_name]);
    println!("Root SSH login disabled.");
    info("Root SSH login disabled.");
    Ok(())
}

fn sync_admin_user_module(args: Vec<String>) -> Result<(), String> {
    let action = args.first().cloned().unwrap_or_else(|| "install".into());
    let rest = if args.is_empty() {
        Vec::new()
    } else {
        args[1..].to_vec()
    };

    match action.as_str() {
        "install" => {
            let mut forwarded = Vec::new();
            let mut disable_root = true;
            for arg in rest {
                match arg.as_str() {
                    "--keep-root" | "--no-disable-root" => disable_root = false,
                    "--disable-root" => {
                        disable_root = true;
                        forwarded.push(arg);
                    }
                    other => forwarded.push(other.to_string()),
                }
            }
            if disable_root && !forwarded.iter().any(|a| a == "--disable-root") {
                forwarded.push("--disable-root".into());
            }
            create_admin_user(CreateAdminUserOptions::parse(forwarded)?)?;
            info("admin-user module installed.");
            Ok(())
        }
        "update" => {
            if rest.is_empty() {
                warn("Admin user update is not automated.");
                return Ok(());
            }
            create_admin_user(CreateAdminUserOptions::parse(rest)?)?;
            info("admin-user module updated.");
            Ok(())
        }
        "remove" => {
            warn("Admin user removal is not automated.");
            Ok(())
        }
        _ => Err(format!("Unsupported admin-user action: {action}")),
    }
}

fn ssh_root_login_module(args: Vec<String>) -> Result<(), String> {
    let action = args.first().cloned().unwrap_or_else(|| "install".into());
    match action.as_str() {
        "install" | "update" => {
            disable_root_login()?;
            info(&format!("ssh-root-login module {action}."));
            Ok(())
        }
        "remove" => {
            warn("SSH root-login restore is not automated.");
            Ok(())
        }
        _ => Err(format!("Unsupported ssh-root-login action: {action}")),
    }
}

pub fn normalize_php_version(value: &str, fallback: &str) -> String {
    let s = value.trim();
    if s.chars().all(|c| c.is_ascii_digit() || c == '.') && s.contains('.') {
        s.to_string()
    } else {
        fallback.to_string()
    }
}
