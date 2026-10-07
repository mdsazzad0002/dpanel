use std::sync::Arc;

use axum::{
    Router,
    extract::{Json, State},
    response::IntoResponse,
    routing::post,
};
use serde::Deserialize;
use serde_json::json;

use crate::api::{ApiResponse, ApiState, check_token};
use crate::laravel_queue::{self, QueueSite, WorkerSpec};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/laravel/queue", post(handle))
}

#[derive(Deserialize)]
pub(crate) struct Request {
    pub site_id: String,
    pub action: String,
    pub site_owner: Option<String>,
    pub project_root: Option<String>,
    pub php_version: Option<String>,
    #[serde(default)]
    pub workers: Vec<WorkerSpec>,
    pub worker_id: Option<String>,
    pub lines: Option<u16>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    // systemctl and journalctl block; keep them off the async workers.
    let result = tokio::task::spawn_blocking(move || run(request))
        .await
        .unwrap_or_else(|error| Err(format!("queue worker task failed: {error}")));

    match result {
        Ok((message, data)) => match data {
            Some(data) => ApiResponse::ok_data(&message, data).into_response(),
            None => ApiResponse::ok(&message).into_response(),
        },
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn run(request: Request) -> Result<(String, Option<serde_json::Value>), String> {
    match request.action.as_str() {
        "apply" => {
            // With no workers left there is nothing to run, so the account
            // and project root need not be valid (the site may be half set up).
            if request.workers.is_empty() {
                laravel_queue::remove_all(&request.site_id)?;
                return Ok(("Queue workers removed".into(), None));
            }
            let (Some(owner), Some(project_root)) =
                (request.site_owner.as_deref(), request.project_root.as_deref())
            else {
                return Err("site_owner and project_root are required to apply queue workers".into());
            };
            let site = QueueSite::open(
                &request.site_id,
                owner,
                project_root,
                request.php_version.as_deref().unwrap_or(""),
            )?;
            laravel_queue::apply(&site, &request.workers)?;
            Ok(("Queue workers applied".into(), None))
        }
        "restart" => {
            laravel_queue::restart(&request.site_id)?;
            Ok(("Queue workers restarted".into(), None))
        }
        "remove" => {
            laravel_queue::remove_all(&request.site_id)?;
            Ok(("Queue workers removed".into(), None))
        }
        "status" => {
            let workers = laravel_queue::status(&request.site_id)?;
            Ok(("Queue worker status".into(), Some(json!({ "workers": workers }))))
        }
        "logs" => {
            let worker_id = request.worker_id.as_deref().unwrap_or("");
            let output = laravel_queue::logs(&request.site_id, worker_id, request.lines.unwrap_or(100))?;
            Ok(("Queue worker logs".into(), Some(json!({ "output": output }))))
        }
        other => Err(format!("unknown action: {other}")),
    }
}
