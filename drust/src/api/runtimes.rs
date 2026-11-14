//! `GET /api/v1/runtimes` and `POST /api/v1/runtimes/install` (backlog F3):
//! the PHP and MariaDB packages drust can install on this system, and a
//! background install of one of them.

use std::sync::Arc;

use axum::{
    Json, Router,
    extract::State,
    http::{HeaderMap, StatusCode},
    response::{IntoResponse, Response},
    routing::{get, post},
};
use serde::Deserialize;

use super::{ApiResponse, ApiState, check_token};
use crate::runtimes::{self, Manifest, StartError};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/runtimes", get(list))
        .route("/api/v1/runtimes/install", post(install))
}

async fn list(State(state): State<Arc<ApiState>>, headers: HeaderMap) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match Manifest::load() {
        Ok(manifest) => {
            let statuses = runtimes::statuses(&manifest, crate::platform::paths().runtime_dir());
            Json(serde_json::json!({ "success": true, "runtimes": statuses })).into_response()
        }
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

#[derive(Deserialize)]
struct InstallRequest {
    name: String,
    version: String,
}

/// `202` once started; follow it in `GET /api/v1/runtimes`.
async fn install(State(state): State<Arc<ApiState>>, headers: HeaderMap, Json(request): Json<InstallRequest>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let manifest = match Manifest::load() {
        Ok(manifest) => manifest,
        Err(error) => return ApiResponse::error(&error).into_response(),
    };
    let Some(package) = manifest.find(&request.name, &request.version) else {
        let message = format!("no {} {} for this system", request.name, request.version);
        return (StatusCode::NOT_FOUND, Json(ApiResponse::error(&message))).into_response();
    };
    let runtime_dir = crate::platform::paths().runtime_dir().to_path_buf();
    match runtimes::start(runtime_dir, package.clone()) {
        Ok(()) => (
            StatusCode::ACCEPTED,
            Json(ApiResponse::ok(&format!("Installing {} {}", package.name, package.release))),
        )
            .into_response(),
        Err(StartError::Running) => {
            let message = format!("{} {} is already being installed", package.name, package.version);
            (StatusCode::CONFLICT, Json(ApiResponse::error(&message))).into_response()
        }
    }
}
