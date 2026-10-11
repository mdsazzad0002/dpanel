//! dPanel single sign-on into pgAdmin.
//!
//! pgAdmin runs with `AUTHENTICATION_SOURCES = ['webserver']` (written by the
//! dscript postgresql module) and accepts the user named in [`USER_HEADER`]
//! only from loopback and only together with the shared secret. The flow:
//!
//! 1. The panel calls the drust API ([`issue`]): drust makes sure the pgAdmin
//!    user exists, registers exactly one server for it with a pgpass file, and
//!    writes a one-time ticket.
//! 2. The browser opens [`SSO_PATH`]`?ticket=…` on the panel domain.
//! 3. The edge gateway [`consume`]s the ticket and forwards a login request to
//!    pgAdmin with both headers. It strips both headers from every other
//!    request, so a browser can never assert an identity itself.
//!
//! Each database gets its own pgAdmin identity, so a site owner only ever sees
//! the one server that belongs to that database.

use std::{
    fs,
    io::Write,
    os::unix::fs::{PermissionsExt, chown},
    path::{Path, PathBuf},
    process::Command,
    time::{Duration, SystemTime},
};

pub const USER_HEADER: &str = "x-dpanel-pgadmin-user";
pub const SECRET_HEADER: &str = "x-pgadmin-webserver-secret";
pub const SSO_PATH: &str = "/pgadmin4/dpanel-sso";

const SECRET_FILE: &str = "/etc/pgadmin/dpanel-sso.secret";
const TICKET_DIR: &str = "/run/dpanel-pgadmin-sso";
const TICKET_TTL: Duration = Duration::from_secs(60);
const PGADMIN_HOME: &str = "/opt/dpanel/pgadmin4";
const PGADMIN_OS_USER: &str = "pgadmin";
const PGADMIN_STORAGE_DIR: &str = "/var/lib/pgadmin/storage";
const ADMIN_IDENTITY: &str = "dpanel-admin";

pub enum Target {
    /// The panel admin, connected as the PostgreSQL superuser.
    Admin { username: String, password: String },
    /// One panel database, connected as its own role.
    Database {
        name: String,
        username: String,
        password: String,
    },
}

impl Target {
    fn identity(&self) -> String {
        match self {
            Target::Admin { .. } => ADMIN_IDENTITY.to_string(),
            Target::Database { name, .. } => format!("dpanel-db-{name}"),
        }
    }

    fn validate(&self) -> Result<(), String> {
        let (db, user, password) = match self {
            Target::Admin { username, password } => ("postgres", username, password),
            Target::Database {
                name,
                username,
                password,
            } => (name.as_str(), username, password),
        };
        for (value, label) in [(db, "database name"), (user.as_str(), "database user")] {
            if value.is_empty()
                || value.len() > 63
                || !value.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_')
            {
                return Err(format!("Invalid {label}."));
            }
        }
        if password.is_empty() || password.contains(['\n', '\r']) {
            return Err("Invalid database password.".into());
        }
        Ok(())
    }
}

/// Prepares pgAdmin for `target` and returns a one-time login ticket.
pub fn issue(target: &Target, port: u16) -> Result<String, String> {
    target.validate()?;
    if shared_secret().is_none() {
        return Err(
            "pgAdmin sign-on is not configured. Run: sudo dpanel postgresql configure".into(),
        );
    }
    let identity = target.identity();
    let package_dir = pgadmin_package_dir()?;

    ensure_pgadmin_user(&package_dir, &identity)?;
    let storage = user_storage_dir(&identity)?;
    write_private_file(&storage.join(".pgpass"), &pgpass_line(target, port))?;
    let servers = storage.join(".dpanel-servers.json");
    write_private_file(&servers, &servers_json(target, port))?;
    let loaded = setup_py(
        &package_dir,
        &[
            "load-servers",
            &servers.to_string_lossy(),
            "--user",
            &identity,
            "--auth-source",
            "webserver",
            "--replace",
        ],
    );
    let _ = fs::remove_file(&servers);
    let output = loaded?;
    if !output.contains("Added") {
        return Err(format!("pgAdmin could not register the server: {}", last_line(&output)));
    }

    write_ticket(&identity)
}

/// Returns the pgAdmin identity for a valid, unexpired ticket and makes sure
/// it can never be used again.
pub fn consume(ticket: &str) -> Option<String> {
    crate::sso_ticket::consume(Path::new(TICKET_DIR), ticket, SystemTime::now(), TICKET_TTL)
}

pub fn shared_secret() -> Option<String> {
    fs::read_to_string(SECRET_FILE)
        .ok()
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
}

fn write_ticket(identity: &str) -> Result<String, String> {
    crate::sso_ticket::write(Path::new(TICKET_DIR), identity, SystemTime::now(), TICKET_TTL)
}

trait OpenOptionsMode {
    fn mode_0600(&mut self) -> &mut Self;
}

impl OpenOptionsMode for fs::OpenOptions {
    fn mode_0600(&mut self) -> &mut Self {
        use std::os::unix::fs::OpenOptionsExt;
        self.mode(0o600)
    }
}

/// pgpass fields escape `\` and `:` with a backslash.
fn pgpass_escape(value: &str) -> String {
    value.replace('\\', "\\\\").replace(':', "\\:")
}

fn pgpass_line(target: &Target, port: u16) -> String {
    let (db, user, password) = match target {
        Target::Admin { username, password } => ("*", username.as_str(), password.as_str()),
        Target::Database {
            name,
            username,
            password,
        } => (name.as_str(), username.as_str(), password.as_str()),
    };
    format!(
        "127.0.0.1:{port}:{}:{}:{}\n",
        pgpass_escape(db),
        pgpass_escape(user),
        pgpass_escape(password)
    )
}

fn servers_json(target: &Target, port: u16) -> String {
    let server = match target {
        Target::Admin { username, .. } => serde_json::json!({
            "Name": "PostgreSQL (superuser)",
            "Group": "dPanel",
            "Host": "127.0.0.1",
            "Port": port,
            "MaintenanceDB": "postgres",
            "Username": username,
            "SSLMode": "prefer",
            // Relative to this user's pgAdmin storage directory.
            "PassFile": "/.pgpass",
        }),
        Target::Database { name, username, .. } => serde_json::json!({
            "Name": name,
            "Group": "dPanel",
            "Host": "127.0.0.1",
            "Port": port,
            "MaintenanceDB": name,
            "Username": username,
            "SSLMode": "prefer",
            "PassFile": "/.pgpass",
            "DBRestriction": name,
        }),
    };
    serde_json::json!({ "Servers": { "1": server } }).to_string()
}

fn pgadmin_package_dir() -> Result<PathBuf, String> {
    let lib = Path::new(PGADMIN_HOME).join("venv/lib");
    fs::read_dir(&lib)
        .map_err(|_| "pgAdmin is not installed. Run: dpanel postgresql install".to_string())?
        .flatten()
        .map(|entry| entry.path().join("site-packages/pgadmin4"))
        .find(|dir| dir.join("setup.py").is_file())
        .ok_or_else(|| "pgAdmin package not found.".to_string())
}

fn setup_py(package_dir: &Path, args: &[&str]) -> Result<String, String> {
    let python = Path::new(PGADMIN_HOME).join("venv/bin/python");
    let output = Command::new("runuser")
        .args(["-u", PGADMIN_OS_USER, "--"])
        .arg(&python)
        .arg("setup.py")
        .args(args)
        .current_dir(package_dir)
        .output()
        .map_err(|e| format!("cannot run pgAdmin setup.py: {e}"))?;
    let text = format!(
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
    if !output.status.success() {
        return Err(format!("pgAdmin setup.py failed: {}", last_line(&text)));
    }
    Ok(text)
}

fn ensure_pgadmin_user(package_dir: &Path, identity: &str) -> Result<(), String> {
    // setup.py exits 0 either way and prints the new user as a fixed-width
    // table that wraps long names, so its output isn't worth parsing.
    // load-servers runs next and fails with "could not be found" if the user
    // really is missing, which is what issue() checks.
    setup_py(
        package_dir,
        &["add-external-user", identity, "--auth-source", "webserver", "--role", "User"],
    )?;
    Ok(())
}

/// pgAdmin resolves a relative PassFile against this directory
/// (`preprocess_username` leaves our `dpanel-…` identities unchanged).
fn user_storage_dir(identity: &str) -> Result<PathBuf, String> {
    let dir = Path::new(PGADMIN_STORAGE_DIR).join(identity);
    fs::create_dir_all(&dir).map_err(|e| format!("cannot create {}: {e}", dir.display()))?;
    let (uid, gid) = pgadmin_ids()?;
    chown(&dir, Some(uid), Some(gid)).map_err(|e| format!("cannot chown {}: {e}", dir.display()))?;
    fs::set_permissions(&dir, fs::Permissions::from_mode(0o700))
        .map_err(|e| format!("cannot secure {}: {e}", dir.display()))?;
    Ok(dir)
}

fn write_private_file(path: &Path, contents: &str) -> Result<(), String> {
    let (uid, gid) = pgadmin_ids()?;
    let tmp = path.with_extension("tmp");
    let _ = fs::remove_file(&tmp);
    let mut file = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode_0600()
        .open(&tmp)
        .map_err(|e| format!("cannot write {}: {e}", path.display()))?;
    file.write_all(contents.as_bytes())
        .map_err(|e| format!("cannot write {}: {e}", path.display()))?;
    chown(&tmp, Some(uid), Some(gid)).map_err(|e| format!("cannot chown {}: {e}", path.display()))?;
    fs::rename(&tmp, path).map_err(|e| format!("cannot write {}: {e}", path.display()))
}

fn pgadmin_ids() -> Result<(u32, u32), String> {
    let passwd = fs::read_to_string("/etc/passwd").map_err(|e| e.to_string())?;
    passwd
        .lines()
        .find_map(|line| {
            let fields: Vec<&str> = line.split(':').collect();
            (fields.first() == Some(&PGADMIN_OS_USER) && fields.len() > 3)
                .then(|| Some((fields[2].parse().ok()?, fields[3].parse().ok()?)))
                .flatten()
        })
        .ok_or_else(|| format!("system user {PGADMIN_OS_USER} not found"))
}

fn last_line(text: &str) -> String {
    text.lines()
        .map(str::trim)
        .filter(|line| !line.is_empty())
        .last()
        .unwrap_or("no output")
        .to_string()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn each_database_gets_its_own_identity() {
        let target = Target::Database {
            name: "shop_db".into(),
            username: "shop_user".into(),
            password: "pw".into(),
        };
        assert_eq!(target.identity(), "dpanel-db-shop_db");
        let admin = Target::Admin {
            username: "postgres".into(),
            password: "pw".into(),
        };
        assert_eq!(admin.identity(), "dpanel-admin");
    }

    #[test]
    fn rejects_unsafe_targets() {
        let bad = Target::Database {
            name: "db/../x".into(),
            username: "u".into(),
            password: "pw".into(),
        };
        assert!(bad.validate().is_err());
        let newline = Target::Database {
            name: "db".into(),
            username: "u".into(),
            password: "a\n*:*:*:postgres:x".into(),
        };
        assert!(newline.validate().is_err());
    }

    #[test]
    fn pgpass_escapes_separators() {
        let target = Target::Database {
            name: "db".into(),
            username: "u".into(),
            password: r"a:b\c".into(),
        };
        assert_eq!(pgpass_line(&target, 5432), "127.0.0.1:5432:db:u:a\\:b\\\\c\n");
    }

    #[test]
    fn database_servers_are_restricted_to_their_database() {
        let target = Target::Database {
            name: "shop_db".into(),
            username: "shop_user".into(),
            password: "pw".into(),
        };
        let json: serde_json::Value = serde_json::from_str(&servers_json(&target, 5432)).unwrap();
        let server = &json["Servers"]["1"];
        assert_eq!(server["DBRestriction"], "shop_db");
        assert_eq!(server["Username"], "shop_user");
        assert!(server.get("Password").is_none());
    }
}
