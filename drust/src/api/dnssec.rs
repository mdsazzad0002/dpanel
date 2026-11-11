//! `GET` and `PUT /api/v1/dns/dnssec/{zone}`, `POST /api/v1/dns/dnssec/{zone}/rectify`
//! (backlog M6): signing a PowerDNS zone with `pdnsutil`.
//!
//! The panel writes zones straight into the gmysql tables, but keys and
//! rectifying need `pdnsutil`, which reads PowerDNS's root-only config. A
//! signed zone gets one ECDSA P-256 key (CSK) and narrow NSEC3 with no
//! salt and no extra iterations (RFC 9276), so no `ordername` has to be
//! kept: after a change only the `auth` fields and empty non-terminals
//! need fixing, which is what `rectify` does.

use std::{process::Command, sync::Arc};

use axum::{
    Router,
    extract::{Json, Path, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::{get, post},
};
use serde::Deserialize;

use super::{ApiResponse, ApiState, check_token};
use crate::platform::{PlatformError, capabilities::Capability, paths::Tool};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/dns/dnssec/{zone}", get(status).put(set))
        .route("/api/v1/dns/dnssec/{zone}/rectify", post(rectify))
}

#[derive(Deserialize)]
struct Change {
    enabled: bool,
}

/// NSEC3 hash 1 (SHA-1), no opt-out, 0 extra iterations, no salt.
const NSEC3_PARAM: &str = "1 0 0 -";

/// A zone name as PowerDNS stores it: lower case, no trailing dot, letters,
/// digits, `-` and `_` in each label, never an option for `pdnsutil`.
fn valid_zone(zone: &str) -> bool {
    zone.len() <= 253
        && zone.contains('.')
        && zone.split('.').all(|label| {
            !label.is_empty()
                && label.len() <= 63
                && !label.starts_with('-')
                && !label.ends_with('-')
                && label.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-' || b == b'_')
        })
}

/// PowerDNS is the DNS server only on a Linux server; elsewhere there is nothing to sign.
pub(crate) fn ensure_powerdns() -> Result<(), PlatformError> {
    if crate::platform::capabilities::report().capabilities.dns == "powerdns" {
        Ok(())
    } else {
        Err(PlatformError::Unsupported { capability: Capability::Dns })
    }
}

pub(crate) struct Run {
    pub(crate) ok: bool,
    pub(crate) text: String,
}

pub(crate) fn pdnsutil(args: &[&str]) -> Result<Run, PlatformError> {
    let binary = crate::platform::paths().binary(Tool::Pdnsutil)?;
    let output = Command::new(binary)
        .args(args)
        .output()
        .map_err(|error| PlatformError::from_io("run pdnsutil", error))?;
    let mut text = String::from_utf8_lossy(&output.stdout).into_owned();
    text.push('\n');
    text.push_str(&String::from_utf8_lossy(&output.stderr));
    Ok(Run { ok: output.status.success(), text })
}

fn failed(action: &str, run: &Run) -> PlatformError {
    PlatformError::Failed {
        action: action.to_string(),
        detail: run.text.trim().to_string(),
    }
}

/// What `pdnsutil export-zone-ds` says: the DS lines of a signed zone, `None`
/// for one without keys, or that there is no such zone.
fn parse_ds(run: &Run, zone: &str) -> Result<Option<Vec<String>>, PlatformError> {
    if run.text.contains("No such zone") || run.text.contains("Can't find a zone") {
        return Err(PlatformError::NotFound { what: format!("DNS zone {zone}") });
    }
    if !run.ok {
        if run.text.contains("not actively secured") || run.text.contains("No keys for zone") {
            return Ok(None);
        }
        return Err(failed("read the zone's DS records", run));
    }
    let ds: Vec<String> = run
        .text
        .lines()
        .filter(|line| line.contains(" IN DS "))
        .map(|line| line.split(';').next().unwrap_or_default().trim().to_string())
        .collect();
    Ok(if ds.is_empty() { None } else { Some(ds) })
}

fn current(zone: &str) -> Result<Option<Vec<String>>, PlatformError> {
    parse_ds(&pdnsutil(&["export-zone-ds", zone])?, zone)
}

fn step(args: &[&str], action: &str) -> Result<(), PlatformError> {
    let run = pdnsutil(args)?;
    if run.ok { Ok(()) } else { Err(failed(action, &run)) }
}

/// Signs or unsigns the zone; asking for what it already is changes nothing.
fn apply(zone: &str, enabled: bool) -> Result<Option<Vec<String>>, PlatformError> {
    ensure_powerdns()?;
    let signed = current(zone)?.is_some();
    if enabled && !signed {
        step(&["secure-zone", zone], "sign the zone")?;
        step(&["set-nsec3", zone, NSEC3_PARAM, "narrow"], "set NSEC3 on the zone")?;
    } else if !enabled && signed {
        step(&["disable-dnssec", zone], "remove the zone's keys")?;
    }
    step(&["rectify-zone", zone], "rectify the zone")?;
    current(zone)
}

fn answer(result: Result<Option<Vec<String>>, PlatformError>) -> Response {
    match result {
        Ok(ds) => Json(serde_json::json!({
            "success": true,
            "enabled": ds.is_some(),
            "ds": ds.unwrap_or_default(),
        }))
        .into_response(),
        Err(error) => error.into_response(),
    }
}

async fn blocking<T: Send + 'static>(
    work: impl FnOnce() -> Result<T, PlatformError> + Send + 'static,
) -> Result<T, PlatformError> {
    tokio::task::spawn_blocking(work).await.unwrap_or_else(|error| {
        Err(PlatformError::Failed { action: "run pdnsutil".into(), detail: error.to_string() })
    })
}

async fn status(State(state): State<Arc<ApiState>>, headers: HeaderMap, Path(zone): Path<String>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if !valid_zone(&zone) {
        return ApiResponse::error("Invalid zone name.").into_response();
    }
    answer(
        blocking(move || {
            ensure_powerdns()?;
            current(&zone)
        })
        .await,
    )
}

async fn set(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Path(zone): Path<String>,
    Json(change): Json<Change>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if !valid_zone(&zone) {
        return ApiResponse::error("Invalid zone name.").into_response();
    }
    answer(blocking(move || apply(&zone, change.enabled)).await)
}

/// After the panel changes a signed zone's records: `auth` and empty non-terminals.
async fn rectify(State(state): State<Arc<ApiState>>, headers: HeaderMap, Path(zone): Path<String>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if !valid_zone(&zone) {
        return ApiResponse::error("Invalid zone name.").into_response();
    }
    match blocking(move || {
        ensure_powerdns()?;
        step(&["rectify-zone", &zone], "rectify the zone")
    })
    .await
    {
        Ok(()) => ApiResponse::ok("Zone rectified.").into_response(),
        Err(error) => error.into_response(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn run(ok: bool, text: &str) -> Run {
        Run { ok, text: text.to_string() }
    }

    #[test]
    fn only_plain_zone_names_reach_pdnsutil() {
        for zone in ["example.com", "sub.example.co.uk", "xn--bcher-kva.example", "_dmarc.example.com"] {
            assert!(valid_zone(zone), "{zone}");
        }
        for zone in ["", "com", "--help.com", "a..com", "Example.com", "example.com.", "a b.com", "-a.com", "a-.com", "a/b.com"] {
            assert!(!valid_zone(zone), "{zone}");
        }
        assert!(!valid_zone(&format!("{}.com", "a".repeat(64))));
    }

    #[test]
    fn ds_lines_lose_their_comments() {
        let output = "m6test.example. IN DS 23119 13 2 eb25 ; ( SHA256 digest )\n\
                      m6test.example. IN DS 23119 13 4 ef0a ; ( SHA-384 digest )\n";
        let ds = parse_ds(&run(true, output), "m6test.example").unwrap().unwrap();
        assert_eq!(ds, vec!["m6test.example. IN DS 23119 13 2 eb25", "m6test.example. IN DS 23119 13 4 ef0a"]);
    }

    #[test]
    fn an_unsigned_zone_is_not_an_error_but_a_missing_one_is() {
        assert_eq!(parse_ds(&run(false, "Zone is not actively secured\n"), "a.example").unwrap(), None);
        let missing = parse_ds(&run(false, "No such zone in the database\n"), "a.example").unwrap_err();
        assert!(matches!(missing, PlatformError::NotFound { .. }));
        let other = parse_ds(&run(false, "Database connection failed"), "a.example").unwrap_err();
        assert!(matches!(other, PlatformError::Failed { .. }));
    }

    /// Needs root and a PowerDNS with `gmysql-dnssec=yes` serving the zone in
    /// `DNSSEC_TEST_ZONE`: `sudo DNSSEC_TEST_ZONE=m6test.example <test binary> --ignored dnssec`.
    #[test]
    #[ignore]
    fn signs_and_unsigns_a_real_zone() {
        let zone = std::env::var("DNSSEC_TEST_ZONE").expect("DNSSEC_TEST_ZONE");
        let ds = apply(&zone, true).unwrap().expect("signed");
        assert!(ds.iter().any(|line| line.contains(" IN DS ") && line.contains(" 13 2 ")), "{ds:?}");
        assert_eq!(apply(&zone, true).unwrap(), Some(ds), "signing twice keeps the key");
        assert_eq!(apply(&zone, false).unwrap(), None);
        assert_eq!(apply(&zone, false).unwrap(), None, "unsigning twice is fine");
        assert!(matches!(current("nosuch-zone.invalid"), Err(PlatformError::NotFound { .. })));
    }

    #[tokio::test]
    async fn a_bad_zone_name_is_refused_before_anything_runs() {
        use tower::ServiceExt;
        let router = super::super::build_router(ApiState { api_token: "secret".into() });
        let request = axum::http::Request::builder()
            .method("PUT")
            .uri("/api/v1/dns/dnssec/--help")
            .header("authorization", "Bearer secret")
            .header("content-type", "application/json")
            .body(axum::body::Body::from(r#"{"enabled":true}"#))
            .unwrap();
        let response = router.oneshot(request).await.unwrap();
        let body = axum::body::to_bytes(response.into_body(), 4096).await.unwrap();
        let body: serde_json::Value = serde_json::from_slice(&body).unwrap();
        assert_eq!(body["message"], "Invalid zone name.");
    }
}
