use axum::{Router, extract::Json, http::StatusCode, response::IntoResponse};
use serde::Serialize;
use std::process::ExitCode;
use std::sync::Arc;

use crate::app;

mod admin;
mod database;
mod filemanager;
mod health;
mod laravel;
mod php;
mod script;
mod ssl;
mod vhost_ops;
mod web_stack;

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

pub fn build_router(state: ApiState) -> Router {
    Router::new()
        .merge(health::routes())
        .merge(web_stack::routes())
        .merge(vhost_ops::routes())
        .merge(admin::routes())
        .merge(filemanager::routes())
        .merge(php::routes())
        .merge(ssl::routes())
        .merge(script::routes())
        .merge(database::routes())
        .merge(laravel::routes())
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
    if token != state.api_token {
        return Err(ApiResponse::error("Unauthorized"));
    }
    Ok(())
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
    println!("[INFO] API token: {token}");

    let runtime = tokio::runtime::Runtime::new().expect("Failed to create tokio runtime");
    runtime.block_on(async {
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
