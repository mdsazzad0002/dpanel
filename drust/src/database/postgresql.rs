use std::{
    io::Write,
    process::Stdio,
};

/// Creates (or updates) a PostgreSQL role and a database it owns. Mirrors the
/// MariaDB `create|upsert` request: running it again resets the password.
pub(crate) fn run(action: &str, db_name: &str, db_user: &str, db_password: &str) -> Result<String, String> {
    if !matches!(action, "create" | "upsert" | "drop" | "drop_database") {
        return Err(format!("Unsupported action: {action}. Allowed: create|upsert|drop|drop_database"));
    }
    validate_identifier(db_name, "database name")?;
    if action == "drop_database" || action == "drop" {
        let with_user = action == "drop";
        if with_user {
            validate_identifier(db_user, "database user")?;
        }
        if ["postgres", "template0", "template1"].contains(&db_name.to_ascii_lowercase().as_str())
            || (with_user && db_user.eq_ignore_ascii_case("postgres"))
        {
            return Err("PostgreSQL's own databases and the postgres superuser are never dropped.".into());
        }
        psql(&drop_sql(db_name, db_user, with_user))?;

        return Ok(if with_user {
            format!("PostgreSQL database and user dropped: {db_name} / {db_user}")
        } else {
            format!("PostgreSQL database dropped: {db_name}")
        });
    }
    validate_identifier(db_user, "database user")?;
    if db_password.is_empty() {
        return Err("Database password is required.".into());
    }
    if db_user.eq_ignore_ascii_case("postgres") {
        return Err("The postgres superuser cannot be assigned to a database.".into());
    }

    psql(&provision_sql(db_name, db_user, db_password))?;

    Ok(format!(
        "PostgreSQL database/user synced successfully: {db_name} / {db_user}"
    ))
}

fn validate_identifier(value: &str, label: &str) -> Result<(), String> {
    // PostgreSQL truncates identifiers at 63 bytes (NAMEDATALEN - 1).
    if value.is_empty()
        || value.len() > 63
        || !value.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
        || value.as_bytes()[0].is_ascii_digit()
    {
        return Err(format!(
            "Invalid {label}. Use letters, numbers and underscore, not starting with a number (max 63)."
        ));
    }
    Ok(())
}

fn literal(value: &str) -> String {
    format!("'{}'", value.replace('\'', "''"))
}

/// Identifiers are validated to [A-Za-z0-9_], so quoting only preserves case.
fn provision_sql(db_name: &str, db_user: &str, db_password: &str) -> String {
    let db = format!("\"{db_name}\"");
    let user = format!("\"{db_user}\"");
    // CREATE DATABASE cannot run inside a DO block or transaction, so the
    // "if not exists" checks go through psql's \gexec.
    format!(
        "SELECT format('CREATE ROLE %I LOGIN', {user_lit}) WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = {user_lit})\\gexec\n\
         ALTER ROLE {user} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE PASSWORD {password};\n\
         SELECT format('CREATE DATABASE %I OWNER %I ENCODING ''UTF8'' TEMPLATE template0', {db_lit}, {user_lit}) WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = {db_lit})\\gexec\n\
         ALTER DATABASE {db} OWNER TO {user};\n\
         REVOKE CONNECT, TEMPORARY ON DATABASE {db} FROM PUBLIC;\n\
         GRANT CONNECT, TEMPORARY ON DATABASE {db} TO {user};\n",
        user_lit = literal(db_user),
        db_lit = literal(db_name),
        password = literal(db_password),
    )
}

/// WITH (FORCE) ends the site's open connections first (PostgreSQL 13+).
fn drop_sql(db_name: &str, db_user: &str, with_user: bool) -> String {
    let mut sql = format!("DROP DATABASE IF EXISTS \"{db_name}\" WITH (FORCE);\n");
    if with_user {
        sql.push_str(&format!("DROP ROLE IF EXISTS \"{db_user}\";\n"));
    }
    sql
}

fn psql(sql: &str) -> Result<(), String> {
    let psql = crate::platform::paths()
        .binary(crate::platform::paths::Tool::Psql)
        .map_err(|_| "psql is not installed. Install PostgreSQL first: dpanel postgresql install")?;

    // Peer auth as the postgres OS user over the local socket; the SQL (and the
    // password in it) goes through stdin so it never appears in the process list.
    let mut child = crate::platform::run_as("postgres", &psql)?
        .args(["-X", "-q", "-v", "ON_ERROR_STOP=1", "-d", "postgres", "-f", "-"])
        .current_dir("/")
        .stdin(Stdio::piped())
        .stdout(Stdio::null())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| format!("Failed to run psql: {e}"))?;
    child
        .stdin
        .take()
        .ok_or("psql stdin unavailable")?
        .write_all(sql.as_bytes())
        .map_err(|e| format!("Failed to send SQL to psql: {e}"))?;
    let output = child
        .wait_with_output()
        .map_err(|e| format!("psql failed: {e}"))?;
    if !output.status.success() {
        let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
        let hint = if stderr.contains("No such file or directory") || stderr.contains("Connection refused") {
            " Is PostgreSQL turned on? (Database Management > PostgreSQL)"
        } else {
            ""
        };
        return Err(format!("psql failed: {stderr}{hint}"));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn drop_forces_off_connections_and_can_keep_the_role() {
        assert_eq!(drop_sql("shop", "shop_user", true), "DROP DATABASE IF EXISTS \"shop\" WITH (FORCE);\nDROP ROLE IF EXISTS \"shop_user\";\n");
        assert_eq!(drop_sql("shop", "shop_user", false), "DROP DATABASE IF EXISTS \"shop\" WITH (FORCE);\n");
        assert!(run("drop", "postgres", "x", "").is_err());
        assert!(run("drop", "shop", "postgres", "").is_err());
    }

    #[test]
    fn rejects_unsafe_identifiers() {
        assert!(validate_identifier("site_db", "x").is_ok());
        assert!(validate_identifier("Site_DB1", "x").is_ok());
        assert!(validate_identifier("1db", "x").is_err());
        assert!(validate_identifier("db\"; DROP", "x").is_err());
        assert!(validate_identifier("db-name", "x").is_err());
        assert!(validate_identifier(&"a".repeat(64), "x").is_err());
    }

    #[test]
    fn refuses_the_superuser_and_unknown_actions() {
        assert!(run("create", "db", "postgres", "pw").is_err());
        assert!(run("drop", "db", "user", "pw").is_err());
    }

    #[test]
    fn escapes_the_password_literal() {
        let sql = provision_sql("site_db", "site_user", "p'w");
        assert!(sql.contains("PASSWORD 'p''w'"));
        assert!(sql.contains("CREATE DATABASE %I OWNER %I"));
        assert!(sql.contains("REVOKE CONNECT, TEMPORARY ON DATABASE \"site_db\" FROM PUBLIC"));
    }
}
