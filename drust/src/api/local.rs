//! `/api/v1/local/*` (F6): the hosts file and the local CA on a PC. Every
//! route is `404` on a server, where the hosts file and trusted roots are
//! not the panel's to change.

use std::{collections::BTreeSet, sync::Arc};

use axum::{
    Json, Router,
    extract::State,
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
};
use serde::Deserialize;
use serde_json::json;

use super::{ApiResponse, ApiState, check_token};
use crate::{
    local_dev::{HostsOutcome, apply_hosts},
    platform::{self, Profile, hosts, local_ca},
};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/local/hosts", get(list_hosts).put(set_hosts))
        .route("/api/v1/local/ca", get(ca_status))
        .route("/api/v1/local/ca/trust", post(trust_ca))
}

fn guard(state: &ApiState, headers: &HeaderMap) -> Result<(), Response> {
    check_token(state, headers).map_err(IntoResponse::into_response)?;
    if platform::current().profile() != Profile::Local {
        return Err((StatusCode::NOT_FOUND, Json(ApiResponse::error("only on a local install"))).into_response());
    }
    Ok(())
}

fn blocking_error(error: tokio::task::JoinError) -> Response {
    ApiResponse::error(&format!("stopped: {error}")).into_response()
}

async fn list_hosts(State(state): State<Arc<ApiState>>, headers: HeaderMap) -> Response {
    if let Err(response) = guard(&state, &headers) {
        return response;
    }
    match tokio::task::spawn_blocking(hosts::current).await {
        Ok(Ok(names)) => Json(json!({ "success": true, "file": hosts::file(), "names": names })).into_response(),
        Ok(Err(error)) => error.into_response(),
        Err(error) => blocking_error(error),
    }
}

#[derive(Deserialize)]
struct HostsRequest {
    names: Vec<String>,
}

/// Makes the hosts file point exactly `names` at 127.0.0.1. Windows asks
/// the person at the screen for an administrator when something changes;
/// nothing is asked when the names are already there.
async fn set_hosts(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<HostsRequest>) -> Response {
    if let Err(response) = guard(&state, &headers) {
        return response;
    }
    let names: BTreeSet<String> = request.names.iter().map(|name| name.trim().to_ascii_lowercase()).collect();
    if let Some(bad) = names.iter().find(|name| !hosts::valid_name(name)) {
        let message = format!("not a host name: {bad}");
        return (StatusCode::UNPROCESSABLE_ENTITY, Json(ApiResponse::error(&message))).into_response();
    }
    let count = names.len();
    match tokio::task::spawn_blocking(move || apply_hosts(&names, true)).await {
        Ok(Ok(outcome)) => Json(json!({
            "success": outcome != HostsOutcome::Declined,
            "outcome": outcome.as_str(),
            "names": count,
        }))
        .into_response(),
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => blocking_error(error),
    }
}

async fn ca_status(State(state): State<Arc<ApiState>>, headers: HeaderMap) -> Response {
    if let Err(response) = guard(&state, &headers) {
        return response;
    }
    let result = tokio::task::spawn_blocking(|| {
        let ca = local_ca::LocalCa::for_system(platform::paths());
        let pem = ca.ensure()?;
        let trusted = local_ca::trusted(&ca)?;
        Ok::<_, platform::PlatformError>(json!({
            "success": true,
            "trusted": trusted,
            "certificate_path": ca.certificate_path(),
            "certificate": pem,
            "names": local_ca::LOCAL_SUFFIXES,
        }))
    })
    .await;
    match result {
        Ok(Ok(body)) => Json(body).into_response(),
        Ok(Err(error)) => error.into_response(),
        Err(error) => blocking_error(error),
    }
}

/// Windows shows its own confirmation on this PC's screen.
async fn trust_ca(State(state): State<Arc<ApiState>>, headers: HeaderMap) -> Response {
    if let Err(response) = guard(&state, &headers) {
        return response;
    }
    let result = tokio::task::spawn_blocking(|| local_ca::trust(&local_ca::LocalCa::for_system(platform::paths()))).await;
    match result {
        Ok(Ok(trusted)) => Json(json!({ "success": trusted, "trusted": trusted })).into_response(),
        Ok(Err(error)) => error.into_response(),
        Err(error) => blocking_error(error),
    }
}
