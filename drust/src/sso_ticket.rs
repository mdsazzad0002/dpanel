//! One-time sign-in tickets that the drust API hands the panel and the edge
//! gateway redeems. Each ticket is a file named by 32 random bytes (hex) in a
//! root-only directory; its contents say who it signs in. Used by pgAdmin
//! sign-on.

use std::{
    fs,
    io::{Read, Write},
    os::unix::fs::{OpenOptionsExt, PermissionsExt},
    path::Path,
    time::{Duration, SystemTime},
};

/// Writes a ticket carrying `contents` and returns it.
pub(crate) fn write(dir: &Path, contents: &str, now: SystemTime, ttl: Duration) -> Result<String, String> {
    fs::create_dir_all(dir).map_err(|e| format!("cannot create ticket dir: {e}"))?;
    fs::set_permissions(dir, fs::Permissions::from_mode(0o700))
        .map_err(|e| format!("cannot secure ticket dir: {e}"))?;
    purge_stale(dir, now, ttl);

    let ticket = random_hex(32)?;
    let mut file = fs::OpenOptions::new()
        .write(true)
        .create_new(true)
        .mode(0o600)
        .open(dir.join(&ticket))
        .map_err(|e| format!("cannot write ticket: {e}"))?;
    file.write_all(contents.as_bytes())
        .map_err(|e| format!("cannot write ticket: {e}"))?;
    Ok(ticket)
}

/// Returns the contents of a valid, unexpired ticket and makes sure it can
/// never be used again.
pub(crate) fn consume(dir: &Path, ticket: &str, now: SystemTime, ttl: Duration) -> Option<String> {
    if ticket.len() != 64 || !ticket.bytes().all(|b| b.is_ascii_hexdigit()) {
        return None;
    }
    let path = dir.join(ticket);
    // Renaming is atomic: of two concurrent requests with the same ticket only
    // one wins, and the file is gone before its contents are trusted.
    let claimed = dir.join(format!("{ticket}.claimed"));
    fs::rename(&path, &claimed).ok()?;
    let issued = fs::metadata(&claimed).and_then(|m| m.modified()).ok();
    let contents = fs::read_to_string(&claimed).ok();
    let _ = fs::remove_file(&claimed);

    // A timestamp slightly ahead of `now` just means the ticket is brand new.
    let age = now.duration_since(issued?).unwrap_or_default();
    if age > ttl {
        return None;
    }
    contents
        .map(|value| value.trim().to_string())
        .filter(|value| !value.is_empty())
}

pub(crate) fn random_hex(bytes: usize) -> Result<String, String> {
    let mut buffer = vec![0u8; bytes];
    fs::File::open("/dev/urandom")
        .and_then(|mut file| file.read_exact(&mut buffer))
        .map_err(|e| format!("cannot read random bytes: {e}"))?;
    Ok(buffer.iter().map(|b| format!("{b:02x}")).collect())
}

fn purge_stale(dir: &Path, now: SystemTime, ttl: Duration) {
    let Ok(entries) = fs::read_dir(dir) else {
        return;
    };
    for entry in entries.flatten() {
        let stale = entry
            .metadata()
            .and_then(|m| m.modified())
            .map(|modified| now.duration_since(modified).unwrap_or_default() > ttl * 5)
            .unwrap_or(true);
        if stale {
            let _ = fs::remove_file(entry.path());
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::path::PathBuf;

    const TTL: Duration = Duration::from_secs(60);

    fn temp_dir(name: &str) -> PathBuf {
        let dir = std::env::temp_dir().join(format!("sso-ticket-{name}-{}", std::process::id()));
        let _ = fs::remove_dir_all(&dir);
        dir
    }

    #[test]
    fn a_ticket_works_exactly_once() {
        let dir = temp_dir("once");
        let now = SystemTime::now();
        let ticket = write(&dir, "dpanel-db-shop", now, TTL).unwrap();
        assert_eq!(ticket.len(), 64);
        assert_eq!(consume(&dir, &ticket, now, TTL).as_deref(), Some("dpanel-db-shop"));
        assert_eq!(consume(&dir, &ticket, now, TTL), None);
        let _ = fs::remove_dir_all(&dir);
    }

    #[test]
    fn expired_and_malformed_tickets_are_rejected() {
        let dir = temp_dir("expired");
        let now = SystemTime::now();
        let ticket = write(&dir, "dpanel-admin", now, TTL).unwrap();
        assert_eq!(consume(&dir, &ticket, now + Duration::from_secs(61), TTL), None);
        // An expired ticket is still burned.
        assert!(!dir.join(&ticket).exists());

        assert_eq!(consume(&dir, "../../etc/passwd", now, TTL), None);
        assert_eq!(consume(&dir, &"g".repeat(64), now, TTL), None);
        let _ = fs::remove_dir_all(&dir);
    }
}
