//! `GET`, `POST /firewall/rules`, `DELETE /firewall/rules/{id}` and
//! `POST /firewall/confirm`: spec section 8, over `platform::firewall`.
//!
//! Lock-out protection (spec 6.9): every add or remove answers with a
//! `confirm_token`. Unless `POST /firewall/confirm` names it within
//! `CONFIRM_WINDOW`, the change is undone. The panel's page confirms from the
//! browser, so a rule that cuts the admin off is taken back on its own.

use std::{
    collections::HashMap,
    sync::{Arc, Mutex, OnceLock, atomic::{AtomicU64, Ordering}},
    time::{Duration, SystemTime, UNIX_EPOCH},
};

use axum::{
    Json, Router,
    extract::{Path, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::{delete, get, post},
};
use sha2::{Digest, Sha256};

use super::{ApiResponse, ApiState, check_token};
use crate::platform::firewall::{self, FirewallBackend, FirewallRule};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/firewall/rules", get(list).post(add))
        .route("/firewall/rules/{id}", delete(remove))
        .route("/firewall/confirm", post(confirm))
}

pub const CONFIRM_WINDOW: Duration = Duration::from_secs(60);

/// What puts the firewall back as it was.
#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Undo {
    Remove(String),
    Add(FirewallRule),
}

impl Undo {
    fn apply(&self, backend: &dyn FirewallBackend) -> Result<(), crate::platform::PlatformError> {
        match self {
            Undo::Remove(id) => match backend.remove(id) {
                Err(crate::platform::PlatformError::NotFound { .. }) => Ok(()),
                other => other,
            },
            Undo::Add(rule) => backend.add(rule),
        }
    }
}

/// Changes waiting for a confirm, by token.
#[derive(Default)]
pub struct Lockout {
    pending: Mutex<HashMap<String, Undo>>,
}

impl Lockout {
    pub fn global() -> &'static Lockout {
        static LOCKOUT: OnceLock<Lockout> = OnceLock::new();
        LOCKOUT.get_or_init(Lockout::default)
    }

    /// Keeps `undo` and starts the timer; the token is what confirm takes.
    pub fn watch(
        &'static self,
        undo: Undo,
        window: Duration,
        backend: fn() -> Box<dyn FirewallBackend>,
    ) -> String {
        let token = new_token();
        self.pending.lock().unwrap().insert(token.clone(), undo);
        let key = token.clone();
        tokio::spawn(async move {
            tokio::time::sleep(window).await;
            if let Some(undo) = self.take(&key) {
                let result = tokio::task::spawn_blocking(move || undo.apply(backend().as_ref())).await;
                match result {
                    Ok(Ok(())) => tracing::warn!("Firewall change {key} was not confirmed and has been undone"),
                    Ok(Err(error)) => tracing::error!("Firewall change {key} was not confirmed and could not be undone: {error}"),
                    Err(error) => tracing::error!("Firewall undo {key} stopped: {error}"),
                }
            }
        });
        token
    }

    /// `true` when the change was still waiting: it stays.
    pub fn confirm(&self, token: &str) -> bool {
        self.take(token).is_some()
    }

    fn take(&self, token: &str) -> Option<Undo> {
        self.pending.lock().unwrap().remove(token)
    }
}

fn new_token() -> String {
    static COUNTER: AtomicU64 = AtomicU64::new(0);
    let nanos = SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |d| d.as_nanos());
    let seed = format!("{nanos}-{}-{}", COUNTER.fetch_add(1, Ordering::Relaxed), std::process::id());
    Sha256::digest(seed.as_bytes()).iter().take(12).map(|byte| format!("{byte:02x}")).collect()
}

#[derive(serde::Deserialize)]
struct ConfirmBody {
    token: String,
}

async fn blocking<T: Send + 'static>(
    work: impl FnOnce() -> Result<T, crate::platform::PlatformError> + Send + 'static,
) -> Result<T, Response> {
    match tokio::task::spawn_blocking(work).await {
        Ok(Ok(value)) => Ok(value),
        Ok(Err(error)) => Err(error.into_response()),
        Err(error) => Err(ApiResponse::error(&format!("Firewall change stopped: {error}")).into_response()),
    }
}

async fn list(State(state): State<Arc<ApiState>>, headers: HeaderMap) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match blocking(|| {
        let backend = firewall::backend();
        Ok((backend.status()?, backend.list()?))
    })
    .await
    {
        Ok((status, rules)) => Json(serde_json::json!({ "success": true, "status": status, "rules": rules })).into_response(),
        Err(response) => response,
    }
}

async fn add(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(rule): Json<FirewallRule>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let rule = FirewallRule::new(rule.action, rule.port, rule.source);
    let id = rule.id.clone();
    let undo_id = id.clone();
    match blocking(move || {
        let backend = firewall::backend();
        // A rule that is already there is not ours to take back.
        let existed = backend.list()?.iter().any(|existing| existing.id == rule.id);
        backend.add(&rule)?;
        Ok(existed)
    })
    .await
    {
        Ok(existed) => {
            let token = (!existed).then(|| Lockout::global().watch(Undo::Remove(undo_id), CONFIRM_WINDOW, firewall::backend));
            changed("Firewall rule added.", &id, token)
        }
        Err(response) => response,
    }
}

fn changed(message: &str, id: &str, token: Option<String>) -> Response {
    Json(serde_json::json!({
        "success": true,
        "message": message,
        "id": id,
        "confirm_token": token,
        "confirm_within_seconds": token.as_ref().map(|_| CONFIRM_WINDOW.as_secs()),
    }))
    .into_response()
}

async fn confirm(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(body): Json<ConfirmBody>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if Lockout::global().confirm(&body.token) {
        ApiResponse::ok("Firewall change confirmed.").into_response()
    } else {
        (
            axum::http::StatusCode::NOT_FOUND,
            ApiResponse::error("Nothing waits for this confirm; the change may already have been undone."),
        )
            .into_response()
    }
}

async fn remove(State(state): State<Arc<ApiState>>, headers: HeaderMap, Path(id): Path<String>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if id.len() != 12 || !id.chars().all(|c| c.is_ascii_hexdigit()) {
        return ApiResponse::error("Invalid rule id.").into_response();
    }
    let removed_id = id.clone();
    match blocking(move || {
        let backend = firewall::backend();
        let rule = backend.list()?.into_iter().find(|rule| rule.id == id);
        backend.remove(&id)?;
        Ok(rule)
    })
    .await
    {
        Ok(rule) => {
            let token = rule.map(|rule| Lockout::global().watch(Undo::Add(rule), CONFIRM_WINDOW, firewall::backend));
            changed("Firewall rule removed.", &removed_id, token)
        }
        Err(response) => response,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tower::ServiceExt;

    async fn send(method: &str, path: &str, body: &str, token: &str) -> (axum::http::StatusCode, serde_json::Value) {
        let router = super::super::build_router(ApiState { api_token: "secret".into() });
        let request = axum::http::Request::builder()
            .method(method)
            .uri(path)
            .header("authorization", format!("Bearer {token}"))
            .header("content-type", "application/json")
            .body(axum::body::Body::from(body.to_string()))
            .unwrap();
        let response = router.oneshot(request).await.unwrap();
        let status = response.status();
        let body = axum::body::to_bytes(response.into_body(), 65536).await.unwrap();
        (status, serde_json::from_slice(&body).unwrap_or_default())
    }

    #[tokio::test]
    async fn needs_the_token() {
        let (status, _) = send("GET", "/firewall/rules", "", "wrong").await;
        assert_eq!(status, axum::http::StatusCode::BAD_REQUEST);
    }

    #[tokio::test]
    async fn refuses_a_rule_id_that_is_not_one() {
        let (_, body) = send("DELETE", "/firewall/rules/abc;rm", "", "secret").await;
        assert_eq!(body["message"], "Invalid rule id.");
    }

    #[tokio::test]
    async fn refuses_an_address_that_is_not_one() {
        let (status, _) = send("POST", "/firewall/rules", r#"{"action":"deny","port":null,"source":"1.2.3.4; rm -rf /"}"#, "secret").await;
        assert!(status.is_client_error(), "{status}");
    }

    // ---- lock-out --------------------------------------------------------

    use crate::platform::firewall::{Action, FirewallStatus};
    use crate::platform::PlatformError;

    static FAKE_RULES: Mutex<Vec<FirewallRule>> = Mutex::new(Vec::new());
    static FAKE_LOCK: tokio::sync::Mutex<()> = tokio::sync::Mutex::const_new(());

    #[derive(Debug)]
    struct Fake;

    impl FirewallBackend for Fake {
        fn status(&self) -> Result<FirewallStatus, PlatformError> {
            Ok(FirewallStatus { backend: "fake", active: true })
        }
        fn list(&self) -> Result<Vec<FirewallRule>, PlatformError> {
            Ok(FAKE_RULES.lock().unwrap().clone())
        }
        fn add(&self, rule: &FirewallRule) -> Result<(), PlatformError> {
            FAKE_RULES.lock().unwrap().push(rule.clone());
            Ok(())
        }
        fn remove(&self, id: &str) -> Result<(), PlatformError> {
            let mut rules = FAKE_RULES.lock().unwrap();
            let before = rules.len();
            rules.retain(|rule| rule.id != id);
            if rules.len() == before { Err(PlatformError::NotFound { what: id.into() }) } else { Ok(()) }
        }
        fn ban(&self, _ip: std::net::IpAddr, _ttl: Option<Duration>) -> Result<(), PlatformError> {
            Ok(())
        }
        fn unban(&self, _ip: std::net::IpAddr) -> Result<(), PlatformError> {
            Ok(())
        }
    }

    fn fake() -> Box<dyn FirewallBackend> {
        Box::new(Fake)
    }

    fn deny_all_ssh() -> FirewallRule {
        FirewallRule::new(Action::Deny, Some(firewall::PortSpec { from: 22, to: None, protocol: firewall::Protocol::Tcp }), None)
    }

    const WINDOW: Duration = Duration::from_millis(40);

    async fn after_the_window() {
        tokio::time::sleep(WINDOW * 5).await;
    }

    #[tokio::test]
    async fn an_added_rule_nobody_confirms_is_taken_back() {
        let _guard = FAKE_LOCK.lock().await;
        FAKE_RULES.lock().unwrap().clear();
        let lockout: &'static Lockout = Box::leak(Box::default());
        let rule = deny_all_ssh();
        Fake.add(&rule).unwrap();
        lockout.watch(Undo::Remove(rule.id.clone()), WINDOW, fake);
        after_the_window().await;
        assert!(FAKE_RULES.lock().unwrap().is_empty());
    }

    #[tokio::test]
    async fn a_confirmed_rule_stays() {
        let _guard = FAKE_LOCK.lock().await;
        FAKE_RULES.lock().unwrap().clear();
        let lockout: &'static Lockout = Box::leak(Box::default());
        let rule = deny_all_ssh();
        Fake.add(&rule).unwrap();
        let token = lockout.watch(Undo::Remove(rule.id.clone()), WINDOW, fake);
        assert!(lockout.confirm(&token));
        after_the_window().await;
        assert_eq!(FAKE_RULES.lock().unwrap().len(), 1);
        assert!(!lockout.confirm(&token), "a token confirms once");
    }

    #[tokio::test]
    async fn a_removed_rule_nobody_confirms_comes_back() {
        let _guard = FAKE_LOCK.lock().await;
        FAKE_RULES.lock().unwrap().clear();
        let lockout: &'static Lockout = Box::leak(Box::default());
        let rule = FirewallRule::new(Action::Allow, Some(firewall::PortSpec { from: 22, to: None, protocol: firewall::Protocol::Tcp }), None);
        lockout.watch(Undo::Add(rule.clone()), WINDOW, fake);
        after_the_window().await;
        assert_eq!(*FAKE_RULES.lock().unwrap(), vec![rule]);
    }

    #[tokio::test]
    async fn a_confirm_after_the_window_is_refused() {
        let _guard = FAKE_LOCK.lock().await;
        FAKE_RULES.lock().unwrap().clear();
        let lockout: &'static Lockout = Box::leak(Box::default());
        let token = lockout.watch(Undo::Remove("000000000000".into()), WINDOW, fake);
        after_the_window().await;
        assert!(!lockout.confirm(&token));
    }

    #[tokio::test]
    async fn confirm_with_an_unknown_token_is_not_found() {
        let (status, _) = send("POST", "/firewall/confirm", r#"{"token":"nope"}"#, "secret").await;
        assert_eq!(status, axum::http::StatusCode::NOT_FOUND);
    }
}
