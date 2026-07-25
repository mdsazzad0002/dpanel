use std::process::Command;
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;
use serde_json::json;

use crate::api::{ApiResponse, ApiState, check_token};

#[derive(Deserialize)]
pub(crate) struct Request {
    action: String,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(response) = check_token(&state, &headers) {
        return response.into_response();
    }

    match run_action(request.action.trim()) {
        Ok(output) => ApiResponse::ok_data("Apache action completed.", json!({ "output": output }))
            .into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn run_action(action: &str) -> Result<String, String> {
    match action {
        "test" => run_capture("apache2ctl", &["-t"]),
        "status" => run_capture("systemctl", &["status", "apache2", "--no-pager"]),
        "start" => run_capture("systemctl", &["start", "apache2"]),
        "stop" => run_capture("systemctl", &["stop", "apache2"]),
        "restart" => {
            let test = run_capture("apache2ctl", &["-t"])?;
            let reload = run_capture("systemctl", &["reload-or-restart", "apache2"])?;
            Ok(join_output(&[test, reload]))
        }
        "reload" => {
            let test = run_capture("apache2ctl", &["-t"])?;
            let reload = run_capture("systemctl", &["reload", "apache2"])?;
            Ok(join_output(&[test, reload]))
        }
        "renew_ssl" => run_capture("certbot", &["renew"]),
        _ => Err("Unsupported Apache action.".into()),
    }
}

fn run_capture(program: &str, args: &[&str]) -> Result<String, String> {
    let output = Command::new(program)
        .args(args)
        .output()
        .map_err(|error| format!("failed to run {program}: {error}"))?;

    let text = join_output(&[
        String::from_utf8_lossy(&output.stdout).trim().to_string(),
        String::from_utf8_lossy(&output.stderr).trim().to_string(),
    ]);

    if output.status.success() {
        Ok(if text.is_empty() {
            "Command completed.".into()
        } else {
            text
        })
    } else {
        Err(if text.is_empty() {
            format!("{program} {:?} failed with status {}", args, output.status)
        } else {
            text
        })
    }
}

fn join_output(parts: &[String]) -> String {
    parts
        .iter()
        .map(|part| part.trim())
        .filter(|part| !part.is_empty())
        .collect::<Vec<_>>()
        .join("\n")
}
