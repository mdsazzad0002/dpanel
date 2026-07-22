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
    if opts.action != "create" && opts.action != "upsert" {
        return Err(format!(
            "Unsupported action: {}. Allowed: create|upsert",
            opts.action
        ));
    }

    validate_name(&opts.db_name, "database name")?;
    validate_name(&opts.db_user, "database user")?;
    validate_charset(&opts.charset)?;
    validate_collation(&opts.collation)?;

    if opts.db_password.is_empty() {
        return Err("Database password is required.".into());
    }

    let db_cli = find_cli()?;
    let host = normalize_host(&opts.db_host);
    let admin = DatabaseAdmin::from_env();

    grant_for_host(&db_cli, &admin, &opts, &host)?;
    if host == "127.0.0.1" {
        grant_for_host(&db_cli, &admin, &opts, "localhost")?;
    }
    if host == "localhost" {
        grant_for_host(&db_cli, &admin, &opts, "127.0.0.1")?;
    }

    // Connect locally for CREATE DATABASE
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

fn find_cli() -> Result<String, String> {
    for name in ["mariadb", "mysql"] {
        let output = Command::new("bash")
            .arg("-c")
            .arg(format!("command -v {name}"))
            .output();
        if let Ok(o) = output {
            if o.status.success() {
                return Ok(name.to_string());
            }
        }
    }
    Err("No database CLI found (mariadb/mysql).".into())
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
            "SERVERPANEL_DATABASE_ADMIN_USER",
            "MARIADB_ADMIN_USER",
            "MYSQL_ADMIN_USER",
        ])
        .or_else(|| panel_env_value("DB_USERNAME"));
        let password = env_first(&[
            "DRUST_DATABASE_ADMIN_PASSWORD",
            "SERVERPANEL_DATABASE_ADMIN_PASSWORD",
            "MARIADB_ADMIN_PASSWORD",
            "MYSQL_ADMIN_PASSWORD",
        ])
        .or_else(|| panel_env_value("DB_PASSWORD"));
        let host = env_first(&[
            "DRUST_DATABASE_ADMIN_HOST",
            "SERVERPANEL_DATABASE_ADMIN_HOST",
            "MARIADB_ADMIN_HOST",
            "MYSQL_ADMIN_HOST",
        ])
        .or_else(|| panel_env_value("DB_HOST"));
        let port = env_first(&[
            "DRUST_DATABASE_ADMIN_PORT",
            "SERVERPANEL_DATABASE_ADMIN_PORT",
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
    let path =
        std::env::var("DRUST_PANEL_ENV_PATH").unwrap_or_else(|_| "/var/www/dpanel/.env".into());
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
        &format!(
            "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, EXECUTE, CREATE VIEW, SHOW VIEW, TRIGGER, EVENT ON `{}`.* TO '{user}'@'{h}';",
            opts.db_name
        ),
    )?;
    Ok(format!("{user}@{h}"))
}
