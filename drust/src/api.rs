use axum::{Router, extract::Json, http::StatusCode, response::IntoResponse};
use serde::Serialize;
use std::process::ExitCode;
use std::sync::Arc;

use crate::app;
use crate::platform::PlatformError;

mod accounts;
mod admin;
mod backup;
mod cron;
mod database;
mod database_config;
mod dependencies;
mod dns;
mod docker;
mod fail2ban;
mod file_sync;
mod filemanager;
mod firewall;
mod ftp;
mod git_deploy;
mod laravel_queue;
mod local_llm;
mod login_users;
mod health;
mod mailbox_storage;
mod media;
mod migration;
mod migration_archive;
mod migration_ssh;
mod node;
mod rust;
mod php;
mod mongo_admin;
mod mongodb;
mod postgresql;
mod python;
mod redis_config;
mod script;
mod security;
mod ssh_key;
mod ssl;
mod terminal;
mod vhost_ops;
mod website;

#[derive(Clone)]
pub struct ApiState {
    pub api_token: String,
}

#[derive(Serialize)]
pub struct ApiResponse {
    pub success: bool,
    pub message: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub data: Option<serde_json::Value>,
}

impl ApiResponse {
    pub fn ok(message: &str) -> Self {
        Self {
            success: true,
            message: message.to_string(),
            data: None,
        }
    }

    pub fn ok_data(message: &str, data: serde_json::Value) -> Self {
        Self {
            success: true,
            message: message.to_string(),
            data: Some(data),
        }
    }

    pub fn error(message: &str) -> Self {
        Self {
            success: false,
            message: message.to_string(),
            data: None,
        }
    }
}

impl IntoResponse for ApiResponse {
    fn into_response(self) -> axum::response::Response {
        let status = if self.success {
            StatusCode::OK
        } else {
            StatusCode::BAD_REQUEST
        };
        (status, Json(serde_json::to_value(&self).unwrap())).into_response()
    }
}

/// `501` tells the panel the feature does not exist on this OS, as opposed to a
/// request that failed.
impl IntoResponse for PlatformError {
    fn into_response(self) -> axum::response::Response {
        match self {
            Self::Unsupported { capability } => (
                StatusCode::NOT_IMPLEMENTED,
                Json(serde_json::json!({"error": "unsupported", "capability": capability.as_str()})),
            )
                .into_response(),
            other => ApiResponse::error(&other.to_string()).into_response(),
        }
    }
}

pub fn build_router(state: ApiState) -> Router {
    Router::new()
        .merge(health::routes())
        .merge(accounts::routes())
        .merge(vhost_ops::routes())
        .merge(website::routes())
        .merge(admin::routes())
        .merge(backup::routes())
        .merge(filemanager::routes())
        .merge(file_sync::routes())
        .merge(ftp::routes())
        .merge(firewall::routes())
        .merge(git_deploy::routes())
        .merge(php::routes())
        .merge(mongo_admin::routes())
        .merge(local_llm::routes())
        .merge(mongodb::routes())
        .merge(postgresql::routes())
        .merge(redis_config::routes())
        .merge(ssl::routes())
        .merge(script::routes())
        .merge(security::routes())
        .merge(login_users::routes())
        .merge(fail2ban::routes())
        .merge(dns::routes())
        .merge(docker::routes())
        .merge(ssh_key::routes())
        .merge(terminal::routes())
        .merge(database::routes())
        .merge(database_config::routes())
        .merge(dependencies::routes())
        .merge(cron::routes())
        .merge(mailbox_storage::routes())
        .merge(media::routes())
        .merge(migration::routes())
        .merge(migration_archive::routes())
        .merge(migration_ssh::routes())
        .merge(node::routes())
        .merge(rust::routes())
        .merge(python::routes())
        .merge(laravel_queue::routes())
        .with_state(Arc::new(state))
}

pub(crate) fn check_token(
    state: &ApiState,
    headers: &axum::http::HeaderMap,
) -> Result<(), ApiResponse> {
    let token = headers
        .get("authorization")
        .and_then(|value| value.to_str().ok())
        .and_then(|value| value.strip_prefix("Bearer "))
        .unwrap_or("");
    if !constant_time_eq(token.as_bytes(), state.api_token.as_bytes()) {
        return Err(ApiResponse::error("Unauthorized"));
    }
    Ok(())
}

/// Compares two byte strings in time independent of where they first differ,
/// so a network attacker timing responses can't infer the token byte-by-byte.
fn constant_time_eq(a: &[u8], b: &[u8]) -> bool {
    if a.len() != b.len() {
        return false;
    }
    let mut diff: u8 = 0;
    for (x, y) in a.iter().zip(b.iter()) {
        diff |= x ^ y;
    }
    diff == 0
}

pub(crate) fn operation_response(
    result: Result<(), String>,
    success: &str,
) -> axum::response::Response {
    match result {
        Ok(()) => ApiResponse::ok(success).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

pub fn serve(args: Vec<String>) -> ExitCode {
    let mut port: u16 = 9500;
    let mut token = String::new();

    // Keep the legacy `drust serve ...` service command working while also
    // allowing the API-only binary to start directly with `drust --port ...`.
    let mut args = args;
    if args.first().map(String::as_str) == Some("serve") {
        args.remove(0);
    }
    let mut iter = args.into_iter();
    while let Some(arg) = iter.next() {
        match arg.as_str() {
            "--port" | "-p" => {
                if let Some(val) = iter.next() {
                    port = val.parse().unwrap_or(9500);
                }
            }
            "--token" | "-t" => {
                if let Some(val) = iter.next() {
                    token = val;
                }
            }
            other => {
                eprintln!("Unknown serve option: {other}");
                return ExitCode::from(1);
            }
        }
    }

    if token.is_empty() {
        token = std::env::var("DRUST_API_TOKEN").unwrap_or_else(|_| {
            eprintln!("[WARN] No API token set. Generating random token.");
            app::random_hex(32).unwrap_or_else(|_| "insecure-token".into())
        });
    }

    let state = ApiState {
        api_token: token.clone(),
    };

    println!("[INFO] drust API server starting on port {port}");
    std::thread::spawn(crate::fail2ban::ensure_default_policy);

    let runtime = tokio::runtime::Runtime::new().expect("Failed to create tokio runtime");
    runtime.block_on(async {
        if let Some(store) = crate::platform::scheduler().timer_store() {
            tokio::spawn(crate::platform::scheduler::run_timer(store.to_path_buf()));
        }
        let router = build_router(state);
        let addr = format!("127.0.0.1:{port}");
        let listener = tokio::net::TcpListener::bind(&addr)
            .await
            .unwrap_or_else(|_| panic!("Failed to bind to {addr}"));

        println!("[INFO] drust API listening on {addr}");
        if let Err(error) = axum::serve(listener, router).await {
            eprintln!("[ERROR] Server error: {error}");
            return ExitCode::from(1);
        }
        ExitCode::from(0)
    })
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::platform::capabilities::Capability;

    #[tokio::test]
    async fn unsupported_is_a_501_that_names_the_capability() {
        let response = PlatformError::Unsupported { capability: Capability::Docker }.into_response();
        assert_eq!(response.status(), StatusCode::NOT_IMPLEMENTED);
        let body = axum::body::to_bytes(response.into_body(), 1024).await.unwrap();
        let body: serde_json::Value = serde_json::from_slice(&body).unwrap();
        assert_eq!(body, serde_json::json!({"error": "unsupported", "capability": "docker"}));
    }

    #[tokio::test]
    async fn capabilities_need_the_token_and_report_this_system() {
        use tower::ServiceExt;

        let request = |token: &str| {
            axum::http::Request::builder()
                .uri("/capabilities")
                .header("authorization", format!("Bearer {token}"))
                .body(axum::body::Body::empty())
                .unwrap()
        };
        let router = build_router(ApiState { api_token: "secret".into() });

        let refused = router.clone().oneshot(request("wrong")).await.unwrap();
        assert_eq!(refused.status(), StatusCode::BAD_REQUEST);

        let response = router.oneshot(request("secret")).await.unwrap();
        assert_eq!(response.status(), StatusCode::OK);
        let body = axum::body::to_bytes(response.into_body(), 4096).await.unwrap();
        let body: serde_json::Value = serde_json::from_slice(&body).unwrap();
        assert_eq!(body["os"], crate::platform::current().os().as_str());
        assert_eq!(body["capabilities"]["websites"], true);
    }

    #[test]
    fn other_platform_errors_stay_ordinary_failures() {
        let response = PlatformError::NotFound { what: "php".into() }.into_response();
        assert_eq!(response.status(), StatusCode::BAD_REQUEST);
    }
}
