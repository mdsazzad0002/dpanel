use crate::platform::{self, fs::FileMode};
use std::{fs, path::Path, process::Command, sync::Arc};

use axum::{
    Router,
    extract::{Json, State},
    response::IntoResponse,
    routing::post,
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/mailbox-storage", post(handle))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    mail_home: Option<String>,
    site_owner: Option<String>,
    previous_mail_home: Option<String>,
    mail_homes: Option<Vec<String>>,
    /// For `autoresponder`: the reply, or nothing to turn it off.
    autoresponder: Option<Autoresponder>,
}

/// An out-of-office reply (backlog M1), sent once per sender per `days`.
#[derive(Deserialize, Debug, Clone)]
struct Autoresponder {
    email: String,
    subject: String,
    body: String,
    #[serde(default = "one_day")]
    days: u32,
    /// `YYYY-MM-DD`, both optional, both inclusive, in the server's time.
    starts_on: Option<String>,
    ends_on: Option<String>,
}

fn one_day() -> u32 {
    1
}

async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let result = match request.action.as_str() {
        "create" => create(
            request.mail_home.as_deref().unwrap_or(""),
            request.site_owner.as_deref().unwrap_or(""),
        ),
        "move" => move_maildir(
            request.previous_mail_home.as_deref().unwrap_or(""),
            request.mail_home.as_deref().unwrap_or(""),
            request.site_owner.as_deref().unwrap_or(""),
        ),
        "remove" => remove(
            request.mail_home.as_deref().unwrap_or(""),
            request.site_owner.as_deref().unwrap_or(""),
        ),
        "autoresponder" => set_autoresponder(
            request.mail_home.as_deref().unwrap_or(""),
            request.site_owner.as_deref().unwrap_or(""),
            request.autoresponder.as_ref(),
        ),
        "usage" => {
            return match usage(request.mail_homes.as_deref().unwrap_or(&[])) {
                Ok(bytes) => ApiResponse::ok_data(
                    "Mailbox storage usage measured.",
                    serde_json::json!({"bytes": bytes}),
                )
                .into_response(),
                Err(error) => ApiResponse::error(&error).into_response(),
            };
        }
        _ => Err("Unsupported mailbox storage action.".into()),
    };
    match result {
        Ok(()) => ApiResponse::ok("Mailbox storage synchronized.").into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn valid(owner: &str, path: &str) -> bool {
    !owner.is_empty()
        && owner.chars().enumerate().all(|(i, c)| {
            c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || (c == '-' && i > 0)
        })
        && path.starts_with(&format!("{}/mail/", crate::platform::paths().site_home(owner).display()))
        && path.ends_with("/Maildir")
}

fn owner_exists(owner: &str) -> Result<(), String> {
    if platform::accounts().exists(owner).map_err(|e| e.to_string())? {
        Ok(())
    } else {
        Err("Website system user does not exist.".into())
    }
}

fn own(path: &str, owner: &str) -> Result<(), String> {
    if platform::current().one_user() {
        return Ok(());
    }
    let output = Command::new("chown")
        .args(["-R", &format!("{owner}:{owner}"), path])
        .output()
        .map_err(|e| e.to_string())?;
    if output.status.success() {
        Ok(())
    } else {
        Err(String::from_utf8_lossy(&output.stderr).trim().to_string())
    }
}

fn create(path: &str, owner: &str) -> Result<(), String> {
    if !valid(owner, path) {
        return Err("Invalid mailbox storage path or owner.".into());
    }
    owner_exists(owner)?;
    for folder in ["", "cur", "new", "tmp"] {
        let directory = Path::new(path).join(folder);
        fs::create_dir_all(&directory).map_err(|e| e.to_string())?;
        platform::files().set_mode(&directory, FileMode::PrivateDir)
            .map_err(|e| e.to_string())?;
    }
    own(path, owner)?;
    Ok(())
}

fn move_maildir(source: &str, target: &str, owner: &str) -> Result<(), String> {
    if !valid(owner, source) || !valid(owner, target) {
        return Err("Invalid mailbox storage path or owner.".into());
    }
    owner_exists(owner)?;
    if Path::new(source).is_dir() && !Path::new(target).exists() {
        fs::create_dir_all(Path::new(target).parent().ok_or("Invalid target path.")?)
            .map_err(|e| e.to_string())?;
        fs::rename(source, target).map_err(|e| format!("Unable to move Maildir: {e}"))?;
    }
    create(target, owner)
}

fn remove(path: &str, owner: &str) -> Result<(), String> {
    if !valid(owner, path) {
        return Err("Invalid mailbox storage path or owner.".into());
    }
    if Path::new(path).exists() {
        fs::remove_dir_all(path).map_err(|e| format!("Unable to remove Maildir: {e}"))?;
    }
    Ok(())
}

fn usage(paths: &[String]) -> Result<u64, String> {
    let mut total = 0u64;
    for path in paths {
        let parts: Vec<&str> = path.split('/').collect();
        let owner = parts.get(2).copied().unwrap_or("");
        if !valid(owner, path) {
            return Err("Invalid mailbox storage path.".into());
        }
        if !Path::new(path).is_dir() {
            continue;
        }
        let output = Command::new("du")
            .args(["-sb", "--", path])
            .output()
            .map_err(|e| e.to_string())?;
        if !output.status.success() {
            return Err(String::from_utf8_lossy(&output.stderr).trim().to_string());
        }
        let bytes = String::from_utf8_lossy(&output.stdout)
            .split_whitespace()
            .next()
            .unwrap_or("0")
            .parse::<u64>()
            .map_err(|_| "Invalid disk-usage result.".to_string())?;
        total = total.saturating_add(bytes);
    }
    Ok(total)
}

/// Dovecot's user Sieve script: `~/.dovecot.sieve`, where the mailbox home
/// is its Maildir (see the installer's user_query). The spam script runs
/// before it and stops on spam, so spam never gets a reply.
const ACTIVE_SCRIPT: &str = ".dovecot.sieve";

fn set_autoresponder(path: &str, owner: &str, reply: Option<&Autoresponder>) -> Result<(), String> {
    if !valid(owner, path) {
        return Err("Invalid mailbox storage path or owner.".into());
    }
    owner_exists(owner)?;
    let script = Path::new(path).join(ACTIVE_SCRIPT);
    let compiled = Path::new(path).join(".dovecot.svbin");
    let Some(reply) = reply else {
        for file in [&script, &compiled] {
            if fs::symlink_metadata(file).is_ok() {
                platform::files().remove_file(file).map_err(|e| e.to_string())?;
            }
        }
        return Ok(());
    };
    let text = vacation_script(reply)?;
    if !Path::new(path).is_dir() {
        create(path, owner)?;
    }
    // Never write through a link the site user may have planted.
    if fs::symlink_metadata(&script).map(|m| m.file_type().is_symlink()).unwrap_or(false) {
        platform::files().remove_file(&script).map_err(|e| e.to_string())?;
    }
    let mut file = platform::files()
        .replace_private(&script, platform::fs::LinkPolicy::Refuse)
        .map_err(|e| e.to_string())?;
    std::io::Write::write_all(&mut file, text.as_bytes()).map_err(|e| e.to_string())?;
    // Dovecot compiles it again as the user on the next delivery.
    if fs::symlink_metadata(&compiled).is_ok() {
        platform::files().remove_file(&compiled).map_err(|e| e.to_string())?;
    }
    if !platform::current().one_user() {
        let ids = platform::accounts().lookup(owner).map_err(|e| e.to_string())?;
        platform::files().set_owner(&script, &ids).map_err(|e| e.to_string())?;
    }
    Ok(())
}

fn sieve_string(value: &str) -> String {
    format!("\"{}\"", value.replace('\\', "\\\\").replace('"', "\\\""))
}

fn valid_date(value: &str) -> bool {
    let bytes = value.as_bytes();
    bytes.len() == 10
        && bytes[4] == b'-'
        && bytes[7] == b'-'
        && bytes.iter().enumerate().all(|(i, b)| i == 4 || i == 7 || b.is_ascii_digit())
}

fn vacation_script(reply: &Autoresponder) -> Result<String, String> {
    let subject: String = reply.subject.chars().filter(|c| *c != '\r' && *c != '\n').collect();
    let subject = subject.trim();
    if subject.is_empty() || subject.len() > 200 {
        return Err("The reply needs a subject of at most 200 characters.".into());
    }
    if reply.body.trim().is_empty() || reply.body.len() > 10_000 {
        return Err("The reply needs a message of at most 10000 characters.".into());
    }
    if !crate::app::valid_email(&reply.email) {
        return Err("Invalid mailbox address.".into());
    }
    let days = reply.days.clamp(1, 30);
    let mut tests = Vec::new();
    for (bound, value) in [("ge", &reply.starts_on), ("le", &reply.ends_on)] {
        if let Some(value) = value.as_deref().filter(|v| !v.is_empty()) {
            if !valid_date(value) {
                return Err("Dates must be YYYY-MM-DD.".into());
            }
            tests.push(format!("currentdate :value \"{bound}\" \"date\" \"{value}\""));
        }
    }
    let body = reply.body.replace("\r\n", "\n");
    let vacation = format!(
        "vacation :days {days} :addresses [{}] :subject {} {};",
        sieve_string(&reply.email.to_ascii_lowercase()),
        sieve_string(subject),
        sieve_string(&body)
    );
    let mut script = String::from("# Managed by dPanel: the mailbox's automatic reply.\n");
    if tests.is_empty() {
        script.push_str("require [\"vacation\"];\n");
        script.push_str(&vacation);
        script.push('\n');
    } else {
        script.push_str("require [\"vacation\", \"date\", \"relational\"];\n");
        script.push_str(&format!("if allof({}) {{\n  {vacation}\n}}\n", tests.join(", ")));
    }
    Ok(script)
}

#[cfg(test)]
mod autoresponder_tests {
    use super::*;

    fn reply() -> Autoresponder {
        Autoresponder {
            email: "Info@Shop.test".into(),
            subject: "Away\r\nBcc: evil@x.test".into(),
            body: "Back \"soon\" \\ thanks".into(),
            days: 99,
            starts_on: None,
            ends_on: None,
        }
    }

    #[test]
    fn the_script_escapes_text_and_drops_header_breaks() {
        let script = vacation_script(&reply()).unwrap();
        assert!(script.contains("require [\"vacation\"];"));
        assert!(script.contains(":days 30 "), "days are capped");
        assert!(script.contains(":addresses [\"info@shop.test\"]"));
        assert!(script.contains(":subject \"AwayBcc: evil@x.test\""), "{script}");
        assert!(script.contains("\"Back \\\"soon\\\" \\\\ thanks\""), "{script}");
    }

    #[test]
    fn dates_become_a_range_and_bad_ones_are_refused() {
        let mut dated = reply();
        dated.starts_on = Some("2026-12-20".into());
        dated.ends_on = Some("2027-01-02".into());
        let script = vacation_script(&dated).unwrap();
        assert!(script.contains("require [\"vacation\", \"date\", \"relational\"];"));
        assert!(script.contains("currentdate :value \"ge\" \"date\" \"2026-12-20\""));
        assert!(script.contains("currentdate :value \"le\" \"date\" \"2027-01-02\""));
        dated.ends_on = Some("2027-1-2\" ; discard".into());
        assert!(vacation_script(&dated).is_err());
    }

    #[test]
    fn an_empty_reply_or_a_path_outside_mail_is_refused() {
        let mut empty = reply();
        empty.body = "  ".into();
        assert!(vacation_script(&empty).is_err());
        assert!(set_autoresponder("/etc/passwd", "bob", Some(&reply())).is_err());
    }
}
