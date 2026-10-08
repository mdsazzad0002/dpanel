use std::sync::Arc;

use axum::{
    Router,
    extract::{Json, State},
    response::{IntoResponse, Response},
    routing::{get, post},
};
use serde::Deserialize;
use serde_json::Value;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    docker::{self, inspect, network, stack, system, volume},
};

pub(crate) fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/docker", get(status).post(control))
        .route("/api/v1/docker/logs", post(logs))
        .route("/api/v1/docker/inspect", post(inspect_container))
        .route("/api/v1/docker/stats", get(stats))
        .route("/api/v1/docker/exec", post(exec))
        .route("/api/v1/docker/networks", get(networks).post(network_control))
        .route("/api/v1/docker/volumes", get(volumes).post(volume_control))
        .route("/api/v1/docker/system", get(overview).post(system_control))
        .route("/api/v1/docker/stacks", get(stacks).post(stack_control))
        .route("/api/v1/docker/stacks/show", post(stack_show))
        .route("/api/v1/docker/stacks/logs", post(stack_logs))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    #[serde(default)]
    id: String,
    #[serde(default)]
    image: String,
    #[serde(default)]
    name: String,
    #[serde(default)]
    force: bool,
    #[serde(default)]
    all: bool,
    #[serde(default)]
    spec: Option<docker::RunSpec>,
}

#[derive(Deserialize)]
struct LogsRequest {
    id: String,
    #[serde(default)]
    lines: Option<u32>,
}

#[derive(Deserialize)]
struct IdRequest {
    id: String,
}

#[derive(Deserialize)]
struct ExecRequest {
    id: String,
    command: String,
    #[serde(default)]
    user: String,
    #[serde(default)]
    workdir: String,
}

#[derive(Deserialize)]
struct NetworkRequest {
    action: String,
    #[serde(default)]
    name: String,
    #[serde(default)]
    container: String,
    #[serde(default)]
    aliases: Vec<String>,
    #[serde(default)]
    spec: Option<network::NetworkSpec>,
}

#[derive(Deserialize)]
struct VolumeRequest {
    action: String,
    #[serde(default)]
    name: String,
    #[serde(default)]
    all: bool,
}

#[derive(Deserialize)]
struct SystemRequest {
    action: String,
    #[serde(default)]
    all: bool,
}

#[derive(Deserialize)]
struct StackRequest {
    action: String,
    name: String,
    #[serde(default)]
    service: String,
    #[serde(default)]
    compose: String,
    #[serde(default)]
    env: String,
    #[serde(default)]
    remove_volumes: bool,
}

#[derive(Deserialize)]
struct StackLogsRequest {
    name: String,
    #[serde(default)]
    service: String,
    #[serde(default)]
    lines: Option<u32>,
}

/// Checks the token, runs `work` off the async runtime and wraps its result.
async fn respond<F>(state: Arc<ApiState>, headers: axum::http::HeaderMap, work: F) -> Response
where
    F: FnOnce() -> Result<(String, Value), String> + Send + 'static,
{
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(work).await {
        Ok(Ok((message, data))) => ApiResponse::ok_data(&message, data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

async fn status(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap) -> impl IntoResponse {
    respond(state, headers, || Ok(("Docker status loaded.".into(), docker::status()?))).await
}

async fn control(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    respond(state, headers, move || {
        let message = match request.action.as_str() {
            "start" | "stop" | "restart" | "remove" | "pause" | "unpause" | "kill" => {
                docker::container_action(&request.action, &request.id)
            }
            "rename" => docker::rename(&request.id, &request.name),
            "run" => match &request.spec {
                Some(spec) => docker::run_container(spec),
                None => Err("A container spec is required.".to_string()),
            },
            "recreate" => match &request.spec {
                Some(spec) => inspect::recreate(&request.id, spec),
                None => Err("A container spec is required.".to_string()),
            },
            "update" => inspect::update(&request.id),
            "pull" => docker::pull(&request.image),
            "remove_image" => docker::remove_image(&request.image, request.force),
            "prune_images" => docker::prune_images(request.all),
            "prune_containers" => system::prune_containers(),
            _ => Err("Unsupported Docker action.".to_string()),
        }?;
        docker::status().map(|data| (message, data))
    })
    .await
}

async fn logs(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<LogsRequest>,
) -> impl IntoResponse {
    let lines = request.lines.unwrap_or(docker::DEFAULT_LOG_LINES);
    respond(state, headers, move || Ok(("Container logs loaded.".into(), docker::logs(&request.id, lines)?))).await
}

async fn inspect_container(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<IdRequest>,
) -> impl IntoResponse {
    respond(state, headers, move || Ok(("Container details loaded.".into(), inspect::inspect(&request.id)?))).await
}

async fn stats(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap) -> impl IntoResponse {
    respond(state, headers, || Ok(("Container usage loaded.".into(), inspect::stats()?))).await
}

async fn exec(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<ExecRequest>,
) -> impl IntoResponse {
    respond(state, headers, move || {
        Ok(("Command finished.".into(), inspect::exec(&request.id, &request.command, &request.user, &request.workdir)?))
    })
    .await
}

async fn networks(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap) -> impl IntoResponse {
    respond(state, headers, || Ok(("Networks loaded.".into(), network::list()?))).await
}

async fn network_control(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<NetworkRequest>,
) -> impl IntoResponse {
    respond(state, headers, move || {
        let message = match request.action.as_str() {
            "create" => match &request.spec {
                Some(spec) => network::create(spec),
                None => Err("A network spec is required.".to_string()),
            },
            "remove" => network::remove(&request.name),
            "connect" => network::connect(&request.name, &request.container, &request.aliases),
            "disconnect" => network::disconnect(&request.name, &request.container),
            "prune" => network::prune(),
            _ => Err("Unsupported network action.".to_string()),
        }?;
        network::list().map(|data| (message, data))
    })
    .await
}

async fn volumes(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap) -> impl IntoResponse {
    respond(state, headers, || Ok(("Volumes loaded.".into(), volume::list()?))).await
}

async fn volume_control(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<VolumeRequest>,
) -> impl IntoResponse {
    respond(state, headers, move || {
        let message = match request.action.as_str() {
            "create" => volume::create(&request.name),
            "remove" => volume::remove(&request.name),
            "prune" => volume::prune(request.all),
            _ => Err("Unsupported volume action.".to_string()),
        }?;
        volume::list().map(|data| (message, data))
    })
    .await
}

async fn overview(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap) -> impl IntoResponse {
    respond(state, headers, || Ok(("Docker overview loaded.".into(), system::overview()?))).await
}

async fn system_control(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<SystemRequest>,
) -> impl IntoResponse {
    respond(state, headers, move || {
        let message = match request.action.as_str() {
            "prune" => system::prune(request.all),
            "prune_build_cache" => system::prune_build_cache(),
            _ => Err("Unsupported system action.".to_string()),
        }?;
        system::overview().map(|data| (message, data))
    })
    .await
}

async fn stacks(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap) -> impl IntoResponse {
    respond(state, headers, || Ok(("Stacks loaded.".into(), stack::list()?))).await
}

async fn stack_control(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<StackRequest>,
) -> impl IntoResponse {
    respond(state, headers, move || {
        let name = request.name.clone();
        let (message, review) = match request.action.as_str() {
            "create" | "save" => {
                let review = stack::save(&request.name, &request.compose, &request.env, request.action == "create")?;
                ("Stack saved.".to_string(), Some(review))
            }
            // Save and bring up in one step: the usual "Deploy" button.
            "deploy" | "deploy_new" => {
                let review = stack::save(&request.name, &request.compose, &request.env, request.action == "deploy_new")?;
                (stack::action(&request.name, "up", "", false)?, Some(review))
            }
            action => (stack::action(&request.name, action, &request.service, request.remove_volumes)?, None),
        };
        let mut data = if request.action == "remove" {
            serde_json::json!({ "name": name, "removed": true })
        } else {
            stack::show(&name)?
        };
        if let Some(review) = review {
            data["warnings"] = review["warnings"].clone();
        }
        Ok((message, data))
    })
    .await
}

async fn stack_show(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<StackLogsRequest>,
) -> impl IntoResponse {
    respond(state, headers, move || Ok(("Stack loaded.".into(), stack::show(&request.name)?))).await
}

async fn stack_logs(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<StackLogsRequest>,
) -> impl IntoResponse {
    let lines = request.lines.unwrap_or(docker::DEFAULT_LOG_LINES);
    respond(state, headers, move || Ok(("Stack logs loaded.".into(), stack::logs(&request.name, &request.service, lines)?))).await
}
