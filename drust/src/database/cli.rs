//! The `mysql` and `mysqldump` clients, found through `Paths`, so callers
//! never build the process themselves. The password goes in `MYSQL_PWD`,
//! never on the command line where `ps` would show it.

use std::{
    fs,
    io::Write,
    path::Path,
    process::{Command, Stdio},
};

use crate::platform::paths::Tool;

/// A database account as a site's backup or restore knows it.
pub struct Login<'a> {
    pub host: &'a str,
    pub user: &'a str,
    pub password: &'a str,
}

fn tool(tool: Tool) -> Command {
    Command::new(crate::platform::paths().binary_or_name(tool))
}

/// `mysqldump` for `database`, logged in as `login`, with `flags` before the
/// login (for example `--single-transaction`). The caller adds stdout.
pub fn dump_command(login: &Login, database: &str, flags: &[&str]) -> Command {
    let mut command = tool(Tool::Mysqldump);
    command
        .env("MYSQL_PWD", login.password)
        .args(flags)
        .args(["--host", login.host, "--user", login.user, database]);
    command
}

/// `mysql` connected to `database` as `login`, reading SQL from stdin.
pub fn import_command(login: &Login, database: &str) -> Command {
    let mut command = tool(Tool::Mysql);
    command
        .env("MYSQL_PWD", login.password)
        .args(["--host", login.host, "--user", login.user, database]);
    command
}

/// The dump of `database` as bytes.
pub fn dump(login: &Login, database: &str, flags: &[&str]) -> Result<Vec<u8>, String> {
    let output = dump_command(login, database, flags)
        .output()
        .map_err(|e| format!("cannot run mysqldump for {database}: {e}"))?;
    if !output.status.success() {
        return Err(format!(
            "database dump failed for {database}: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    Ok(output.stdout)
}

/// The dump of `database`, written straight to `target`.
pub fn dump_to_file(login: &Login, database: &str, flags: &[&str], target: &Path) -> Result<(), String> {
    let file = fs::File::create(target).map_err(|e| format!("cannot create database dump file: {e}"))?;
    let output = dump_command(login, database, flags)
        .stdout(Stdio::from(file))
        .stderr(Stdio::piped())
        .output()
        .map_err(|e| format!("cannot run mysqldump for {database}: {e}"))?;
    if !output.status.success() {
        return Err(format!(
            "database dump failed for {database}: {}",
            String::from_utf8_lossy(&output.stderr).trim()
        ));
    }
    Ok(())
}

/// Feeds `sql` to `database` as `login`.
pub fn import(login: &Login, database: &str, sql: &[u8]) -> Result<(), String> {
    let mut child = import_command(login, database)
        .stdin(Stdio::piped())
        .stdout(Stdio::null())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| format!("cannot start mysql restore for {database}: {e}"))?;
    child
        .stdin
        .as_mut()
        .ok_or("mysql restore stdin unavailable")?
        .write_all(sql)
        .map_err(|e| format!("cannot send database dump: {e}"))?;
    let output = child
        .wait_with_output()
        .map_err(|e| format!("cannot finish database restore: {e}"))?;
    if !output.status.success() {
        return Err(format!(
            "database restore failed for {database}: {}",
            String::from_utf8_lossy(&output.stderr)
        ));
    }
    Ok(())
}

/// Runs `sql` as the server's admin, over the local socket (root's
/// `unix_socket` login), for creating databases and users.
pub fn admin_execute(sql: &str) -> Result<(), String> {
    let output = tool(Tool::Mysql)
        .args(["--protocol=socket", "--execute", sql])
        .output()
        .map_err(|e| e.to_string())?;
    if !output.status.success() {
        return Err(String::from_utf8_lossy(&output.stderr).trim().to_string());
    }
    Ok(())
}

/// Creates `database` and `user` if missing and gives the user every right
/// on it, with `password`. Names must already be checked by the caller.
pub fn provision(database: &str, user: &str, password: &str) -> Result<(), String> {
    admin_execute(&provision_sql(database, user, password))
        .map_err(|e| format!("cannot provision database {database}: {e}"))
}

fn provision_sql(database: &str, user: &str, password: &str) -> String {
    let password = password.replace('\\', "\\\\").replace('\'', "\\'");
    format!(
        "CREATE DATABASE IF NOT EXISTS `{database}` CHARACTER SET utf8mb4; CREATE USER IF NOT EXISTS '{user}'@'%' IDENTIFIED BY '{password}'; ALTER USER '{user}'@'%' IDENTIFIED BY '{password}'; GRANT ALL PRIVILEGES ON `{database}`.* TO '{user}'@'%'; FLUSH PRIVILEGES;"
    )
}

/// The file name a dump of `database` is stored under in an archive.
pub fn dump_file_name(database: &str) -> String {
    database
        .chars()
        .map(|c| if c.is_ascii_alphanumeric() || c == '_' || c == '-' { c } else { '_' })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn provision_sql_escapes_the_password() {
        let sql = provision_sql("shop", "shop_u", "it's\\x");
        assert!(sql.contains("IDENTIFIED BY 'it\\'s\\\\x'"));
        assert!(sql.starts_with("CREATE DATABASE IF NOT EXISTS `shop`"));
    }

    #[test]
    fn the_password_never_reaches_the_arguments() {
        let login = Login { host: "127.0.0.1", user: "u", password: "secret" };
        for command in [dump_command(&login, "db", &["--routines"]), import_command(&login, "db")] {
            let args: Vec<_> = command.get_args().map(|a| a.to_string_lossy().into_owned()).collect();
            assert!(!args.iter().any(|a| a.contains("secret")));
            assert!(command.get_envs().any(|(k, v)| k == "MYSQL_PWD" && v.map(|v| v == "secret").unwrap_or(false)));
            assert_eq!(args.last().unwrap(), "db");
        }
    }

    #[test]
    fn dump_names_keep_only_safe_characters() {
        assert_eq!(dump_file_name("wp$db.1"), "wp_db_1");
        assert_eq!(dump_file_name("shop_db-2"), "shop_db-2");
    }
}
