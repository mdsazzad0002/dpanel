use crate::platform::paths::Tool;
use std::process::Command;

pub(crate) struct Options {
    pub action: String,
    pub db_name: String,
    pub db_user: String,
    pub db_password: String,
    pub db_host: String,
    pub db_port: u16,
    pub charset: String,
    pub collation: String,
}

pub(crate) fn run(opts: Options) -> Result<String, String> {
    if !matches!(opts.action.as_str(), "create" | "upsert" | "drop" | "drop_database") {
        return Err(format!(
            "Unsupported action: {}. Allowed: create|upsert|drop|drop_database",
            opts.action
        ));
    }

    validate_name(&opts.db_name, "database name")?;
    if opts.action == "drop_database" {
        return drop(&opts, false);
    }
    validate_name(&opts.db_user, "database user")?;
    if opts.action == "drop" {
        return drop(&opts, true);
    }
    validate_charset(&opts.charset)?;
    validate_collation(&opts.collation)?;

    if opts.db_password.is_empty() {
        return Err("Database password is required.".into());
    }

    let db_cli = find_cli()?;
    let host = normalize_host(&opts.db_host);
    let admin = DatabaseAdmin::from_env();

    // Create the schema before assigning its user. Keeping this first makes
    // the resulting grants immediately visible and avoids provisioning a user
    // whose target database was never created.
    sql_exec(
        &db_cli,
        &admin,
        "127.0.0.1",
        opts.db_port,
        &format!(
            "CREATE DATABASE IF NOT EXISTS `{}` CHARACTER SET {} COLLATE {};",
            opts.db_name, opts.charset, opts.collation
        ),
    )?;

    grant_for_host(&db_cli, &admin, &opts, &host)?;
    if host == "127.0.0.1" {
        grant_for_host(&db_cli, &admin, &opts, "localhost")?;
    }
    if host == "localhost" {
        grant_for_host(&db_cli, &admin, &opts, "127.0.0.1")?;
    }

    sql_exec(
        &db_cli,
        &admin,
        "127.0.0.1",
        opts.db_port,
        "FLUSH PRIVILEGES;",
    )?;

    Ok(format!(
        "Database/user synced successfully: {} / {}@{}",
        opts.db_name, opts.db_user, host
    ))
}

/// Databases and accounts a panel request may never remove.
const PROTECTED_DATABASES: [&str; 5] = ["mysql", "information_schema", "performance_schema", "sys", "phpmyadmin"];
const PROTECTED_USERS: [&str; 4] = ["root", "mysql", "mariadb.sys", "debian-sys-maint"];

/// drop: the database and its user (every host); drop_database: only the
/// database, for a user another database still uses. Both are idempotent.
fn drop(opts: &Options, with_user: bool) -> Result<String, String> {
    if PROTECTED_DATABASES.contains(&opts.db_name.to_ascii_lowercase().as_str()) {
        return Err(format!("{} is a system database and is never dropped.", opts.db_name));
    }
    if panel_env_value("DB_DATABASE").is_some_and(|panel| panel.eq_ignore_ascii_case(&opts.db_name)) {
        return Err("The panel's own database is never dropped.".into());
    }
    if with_user
        && (PROTECTED_USERS.contains(&opts.db_user.to_ascii_lowercase().as_str())
            || panel_env_value("DB_USERNAME").is_some_and(|panel| panel.eq_ignore_ascii_case(&opts.db_user)))
    {
        return Err(format!("{} is a system account and is never dropped.", opts.db_user));
    }

    let db_cli = find_cli()?;
    let admin = DatabaseAdmin::from_env();
    sql_exec(&db_cli, &admin, "127.0.0.1", opts.db_port, &drop_sql(&opts.db_name, &opts.db_user, with_user))?;

    Ok(if with_user {
        format!("Database and user dropped: {} / {}", opts.db_name, opts.db_user)
    } else {
        format!("Database dropped: {}", opts.db_name)
    })
}

fn drop_sql(database: &str, user: &str, with_user: bool) -> String {
    let mut sql = format!("DROP DATABASE IF EXISTS `{database}`;");
    if with_user {
        let user = escape_sql_string(user);
        // Users are created for the requested host and both loopback names.
        for host in ["127.0.0.1", "localhost", "%"] {
            sql.push_str(&format!(" DROP USER IF EXISTS '{user}'@'{host}';"));
        }
        sql.push_str(" FLUSH PRIVILEGES;");
    }
    sql
}

fn find_cli() -> Result<String, String> {
    let paths = crate::platform::paths();
    [Tool::Mariadb, Tool::Mysql]
        .into_iter()
        .find_map(|tool| paths.binary(tool).ok())
        .map(|path| path.to_string_lossy().into_owned())
        .ok_or_else(|| "No database CLI found (mariadb/mysql).".into())
}

fn normalize_host(host: &str) -> String {
    let trimmed = host.trim();
    if trimmed.is_empty() || trimmed.eq_ignore_ascii_case("localhost") {
        "127.0.0.1".to_string()
    } else {
        trimmed.to_string()
    }
}

fn validate_name(value: &str, label: &str) -> Result<(), String> {
    if value.is_empty()
        || value.len() > 64
        || !value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b == b'_')
    {
        return Err(format!(
            "Invalid {label}. Use only letters, numbers, underscore (max 64)."
        ));
    }
    Ok(())
}

fn validate_charset(value: &str) -> Result<(), String> {
    if value.is_empty()
        || value.len() > 32
        || !value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b == b'_')
    {
        return Err("Invalid charset value.".into());
    }
    Ok(())
}

fn validate_collation(value: &str) -> Result<(), String> {
    if value.is_empty()
        || value.len() > 64
        || !value
            .bytes()
            .all(|b| b.is_ascii_alphanumeric() || b == b'_')
    {
        return Err("Invalid collation value.".into());
    }
    Ok(())
}

fn is_local(host: &str) -> bool {
    host == "127.0.0.1" || host.eq_ignore_ascii_case("localhost")
}

#[derive(Clone, Debug)]
struct DatabaseAdmin {
    user: Option<String>,
    password: Option<String>,
    host: Option<String>,
    port: Option<u16>,
}

impl DatabaseAdmin {
    fn from_env() -> Self {
        let user = env_first(&[
            "DRUST_DATABASE_ADMIN_USER",
            "dPanel_DATABASE_ADMIN_USER",
            "MARIADB_ADMIN_USER",
            "MYSQL_ADMIN_USER",
        ])
        .or_else(|| panel_env_value("DB_USERNAME"));
        let password = env_first(&[
            "DRUST_DATABASE_ADMIN_PASSWORD",
            "dPanel_DATABASE_ADMIN_PASSWORD",
            "MARIADB_ADMIN_PASSWORD",
            "MYSQL_ADMIN_PASSWORD",
        ])
        .or_else(|| panel_env_value("DB_PASSWORD"));
        let host = env_first(&[
            "DRUST_DATABASE_ADMIN_HOST",
            "dPanel_DATABASE_ADMIN_HOST",
            "MARIADB_ADMIN_HOST",
            "MYSQL_ADMIN_HOST",
        ])
        .or_else(|| panel_env_value("DB_HOST"));
        let port = env_first(&[
            "DRUST_DATABASE_ADMIN_PORT",
            "dPanel_DATABASE_ADMIN_PORT",
            "MARIADB_ADMIN_PORT",
            "MYSQL_ADMIN_PORT",
        ])
        .or_else(|| panel_env_value("DB_PORT"))
        .and_then(|value| value.parse::<u16>().ok());

        Self {
            user,
            password,
            host,
            port,
        }
    }

    fn is_configured(&self) -> bool {
        self.user
            .as_ref()
            .is_some_and(|value| !value.trim().is_empty())
    }
}

fn env_first(names: &[&str]) -> Option<String> {
    names
        .iter()
        .find_map(|name| std::env::var(name).ok())
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
}

fn panel_env_value(key: &str) -> Option<String> {
    let path = std::env::var_os("DRUST_PANEL_ENV_PATH")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(|| crate::platform::paths().panel_root().join(".env"));
    let content = std::fs::read_to_string(path).ok()?;
    content
        .lines()
        .find_map(|line| {
            let line = line.trim();
            if line.is_empty() || line.starts_with('#') {
                return None;
            }
            let (name, value) = line.split_once('=')?;
            if name.trim() != key {
                return None;
            }
            Some(unquote_env_value(value.trim()))
        })
        .filter(|value| !value.is_empty())
}

fn unquote_env_value(value: &str) -> String {
    let bytes = value.as_bytes();
    if bytes.len() >= 2
        && ((bytes[0] == b'\'' && bytes[bytes.len() - 1] == b'\'')
            || (bytes[0] == b'"' && bytes[bytes.len() - 1] == b'"'))
    {
        value[1..value.len() - 1].to_string()
    } else {
        value.to_string()
    }
}

fn sql_exec(
    cli: &str,
    admin: &DatabaseAdmin,
    host: &str,
    port: u16,
    sql: &str,
) -> Result<(), String> {
    let mut cmd = Command::new(cli);
    if admin.is_configured() {
        let admin_host = admin
            .host
            .as_deref()
            .filter(|value| !value.trim().is_empty())
            .unwrap_or(host);
        cmd.arg(format!("--host={admin_host}"));
        cmd.arg(format!("--port={}", admin.port.unwrap_or(port)));
        if let Some(user) = &admin.user {
            cmd.arg(format!("--user={user}"));
        }
        if let Some(password) = &admin.password {
            cmd.env("MYSQL_PWD", password);
        }
    } else if is_local(host) {
        // Local connection: use unix socket (default), don't pass --host/--port
        // so MariaDB root unix_socket auth works.
    } else {
        cmd.arg(format!("--host={host}"));
        cmd.arg(format!("--port={port}"));
    }
    cmd.arg("-e").arg(sql);

    let output = cmd
        .output()
        .map_err(|e| format!("Failed to run {cli}: {e}"))?;

    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
        let hint = if !admin.is_configured()
            && (stderr.contains("Access denied") || stderr.contains("ERROR 1698"))
        {
            " Configure DRUST_DATABASE_ADMIN_USER and DRUST_DATABASE_ADMIN_PASSWORD in /etc/drust/drust.env, then restart drust."
        } else {
            ""
        };
        return Err(format!("{cli} failed: {stderr}{hint}"));
    }
    Ok(())
}

fn escape_sql_string(value: &str) -> String {
    value.replace('\'', "''")
}

fn grant_for_host(
    cli: &str,
    admin: &DatabaseAdmin,
    opts: &Options,
    target_host: &str,
) -> Result<String, String> {
    let user = escape_sql_string(&opts.db_user);
    let pass = escape_sql_string(&opts.db_password);
    let h = escape_sql_string(target_host);

    // Always connect locally for admin operations (CREATE USER, GRANT)
    sql_exec(
        cli,
        admin,
        "127.0.0.1",
        opts.db_port,
        &format!("CREATE USER IF NOT EXISTS '{user}'@'{h}' IDENTIFIED BY '{pass}';"),
    )?;
    sql_exec(
        cli,
        admin,
        "127.0.0.1",
        opts.db_port,
        &format!("ALTER USER '{user}'@'{h}' IDENTIFIED BY '{pass}';"),
    )?;
    sql_exec(
        cli,
        admin,
        "127.0.0.1",
        opts.db_port,
        &database_grant_sql(&opts.db_name, &user, &h),
    )?;
    Ok(format!("{user}@{h}"))
}

fn database_grant_sql(database: &str, user: &str, host: &str) -> String {
    format!("GRANT ALL PRIVILEGES ON `{database}`.* TO '{user}'@'{host}';")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn drop_removes_the_database_and_the_user_on_every_host() {
        assert_eq!(
            drop_sql("site_db", "site_user", true),
            "DROP DATABASE IF EXISTS `site_db`; DROP USER IF EXISTS 'site_user'@'127.0.0.1'; DROP USER IF EXISTS 'site_user'@'localhost'; DROP USER IF EXISTS 'site_user'@'%'; FLUSH PRIVILEGES;"
        );
        assert_eq!(drop_sql("site_db", "site_user", false), "DROP DATABASE IF EXISTS `site_db`;");
    }

    #[test]
    fn system_databases_and_users_are_never_dropped() {
        let request = |name: &str, user: &str, action: &str| Options {
            action: action.into(), db_name: name.into(), db_user: user.into(), db_password: String::new(),
            db_host: "127.0.0.1".into(), db_port: 3306, charset: "utf8mb4".into(), collation: "utf8mb4_unicode_ci".into(),
        };
        assert!(run(request("mysql", "x", "drop_database")).unwrap_err().contains("system database"));
        assert!(run(request("site_db", "root", "drop")).unwrap_err().contains("system account"));
    }

    #[test]
    fn new_database_users_receive_all_database_scoped_privileges() {
        assert_eq!(
            database_grant_sql("site_db", "site_user", "localhost"),
            "GRANT ALL PRIVILEGES ON `site_db`.* TO 'site_user'@'localhost';"
        );
    }
}
