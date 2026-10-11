//! dPanel single sign-on into mongo-express.
//!
//! mongo-express only knows HTTP basic auth, with one login written by the
//! dscript mongodb module to [`ENV_FILE`]. The browser never learns it:
//!
//! 1. The panel calls the drust API ([`issue`]) for a one-time ticket.
//! 2. The browser opens [`SSO_PATH`]`?ticket=…` on the panel domain.
//! 3. The edge gateway [`redeem`]s the ticket for a session cookie, and on
//!    every request with a live session adds the basic-auth header itself.
//!    It strips any `Authorization` header the browser sends.
//!
//! Sessions live in the edge gateway's memory, so restarting it signs
//! everyone out; they open mongo-express from dPanel again.

use std::{
    collections::HashMap,
    fs,
    path::Path,
    sync::Mutex,
    time::{Duration, Instant, SystemTime},
};

use base64::Engine;

pub const BASE_PATH: &str = "/mongo-express";
pub const SSO_PATH: &str = "/mongo-express/dpanel-sso";
pub const SESSION_COOKIE: &str = "dpanel_mongo_express";
pub const UPSTREAM: ([u8; 4], u16) = ([127, 0, 0, 1], 8081);

/// Written by the dscript mongodb module; the systemd unit reads it too.
const ENV_FILE: &str = "/etc/dpanel/mongo-express.env";
const TICKET_DIR: &str = "/run/dpanel-mongo-express-sso";
const TICKET_TTL: Duration = Duration::from_secs(60);
pub const SESSION_TTL: Duration = Duration::from_secs(8 * 60 * 60);

static SESSIONS: Mutex<Option<HashMap<String, Instant>>> = Mutex::new(None);

/// A one-time login ticket for the panel admin.
pub fn issue() -> Result<String, String> {
    if basic_auth_header().is_none() {
        return Err("mongo-express is not installed. Run: sudo dpanel mongodb install".into());
    }
    crate::sso_ticket::write(Path::new(TICKET_DIR), "admin", SystemTime::now(), TICKET_TTL)
}

/// Swaps a valid ticket for a new session id.
pub fn redeem(ticket: &str) -> Option<String> {
    crate::sso_ticket::consume(Path::new(TICKET_DIR), ticket, SystemTime::now(), TICKET_TTL)?;
    let session = crate::sso_ticket::random_hex(32).ok()?;
    let now = Instant::now();
    let mut sessions = SESSIONS.lock().ok()?;
    let sessions = sessions.get_or_insert_with(HashMap::new);
    sessions.retain(|_, expires| *expires > now);
    sessions.insert(session.clone(), now + SESSION_TTL);
    Some(session)
}

/// True when any `Cookie` header value carries a live session.
pub fn has_session<'a>(cookie_headers: impl Iterator<Item = &'a str>) -> bool {
    let Some(session) = cookie_headers
        .flat_map(|value| value.split(';'))
        .find_map(|pair| pair.trim().strip_prefix(SESSION_COOKIE)?.strip_prefix('='))
    else {
        return false;
    };
    let Ok(sessions) = SESSIONS.lock() else {
        return false;
    };
    sessions
        .as_ref()
        .and_then(|sessions| sessions.get(session))
        .is_some_and(|expires| *expires > Instant::now())
}

/// `Basic …` for mongo-express's own login, read fresh so a reinstall that
/// rotates it needs no gateway restart.
pub fn basic_auth_header() -> Option<String> {
    basic_auth_from(&fs::read_to_string(ENV_FILE).ok()?)
}

fn basic_auth_from(env: &str) -> Option<String> {
    let value = |key: &str| {
        env.lines()
            .find_map(|line| line.trim().strip_prefix(key)?.strip_prefix('='))
            .map(|value| value.trim().trim_matches('"').to_string())
            .filter(|value| !value.is_empty())
    };
    let username = value("ME_CONFIG_BASICAUTH_USERNAME")?;
    let password = value("ME_CONFIG_BASICAUTH_PASSWORD")?;
    let encoded = base64::engine::general_purpose::STANDARD.encode(format!("{username}:{password}"));
    Some(format!("Basic {encoded}"))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn reads_the_basic_auth_login_from_the_env_file() {
        let env = "PORT=8081\nME_CONFIG_BASICAUTH_USERNAME=dpanel\nME_CONFIG_BASICAUTH_PASSWORD=\"s3cret\"\n";
        assert_eq!(basic_auth_from(env).as_deref(), Some("Basic ZHBhbmVsOnMzY3JldA=="));
        assert_eq!(basic_auth_from("ME_CONFIG_BASICAUTH_USERNAME=dpanel\n"), None);
    }

    #[test]
    fn only_a_redeemed_session_cookie_counts() {
        let session = "a".repeat(64);
        SESSIONS
            .lock()
            .unwrap()
            .get_or_insert_with(HashMap::new)
            .insert(session.clone(), Instant::now() + SESSION_TTL);
        assert!(has_session([format!("x=1; {SESSION_COOKIE}={session}").as_str()].into_iter()));
        assert!(!has_session([format!("{SESSION_COOKIE}={}", "b".repeat(64)).as_str()].into_iter()));
        assert!(!has_session(["other=1"].into_iter()));
        // A look-alike cookie name is not ours.
        assert!(!has_session([format!("{SESSION_COOKIE}x={session}").as_str()].into_iter()));
    }
}
