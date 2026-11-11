//! `PUT` and `DELETE /resource-limits/{group}`, `GET /resource-limits/{group}/usage`:
//! an account's CPU, memory and process limit (backlog P4, spec section 8).
//!
//! The group is the panel account (`a<user id>`); `owners` are its site
//! users. Their PHP moves to masters of their own inside the account's
//! slice (`edge_gateway::php_fpm_master`), and back when the limit goes.

use std::sync::Arc;

use axum::{
    Json, Router,
    extract::{Path, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::{get, put},
};
use serde::Deserialize;

use super::{ApiResponse, ApiState, accounts::limitable, check_token};
use crate::app::valid_username;
use crate::edge_gateway::php_fpm_master;
use crate::platform::{
    PlatformError,
    limits::{LimitLevel, ResourceProfile, valid_group},
};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/resource-limits/{group}", put(set_limits).delete(clear_limits))
        .route("/resource-limits/{group}/usage", get(usage))
}

#[derive(Deserialize)]
struct Limits {
    /// One core is 100; `0` means no limit, as for the other two.
    #[serde(default)]
    cpu_percent: u32,
    #[serde(default)]
    memory_mb: u32,
    #[serde(default)]
    max_processes: u32,
    owners: Vec<String>,
}

fn level_name(level: LimitLevel) -> &'static str {
    match level {
        LimitLevel::Hard => "hard",
        LimitLevel::Soft => "soft",
        LimitLevel::None => "none",
    }
}

fn failure(errors: Vec<String>) -> Response {
    ApiResponse::error(&errors.join("; ")).into_response()
}

/// Every site user back to the shared master, then the slice's limits off.
fn clear(group: &str) -> Result<Vec<String>, PlatformError> {
    let errors: Vec<String> = php_fpm_master::owners_of(group)
        .into_iter()
        .filter_map(|owner| php_fpm_master::release(&owner).err().map(|error| format!("{owner}: {error}")))
        .collect();
    crate::platform::resource_limiter().clear(group)?;
    Ok(errors)
}

async fn set_limits(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Path(group): Path<String>,
    Json(limits): Json<Limits>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if !valid_group(&group) {
        return ApiResponse::error("Invalid account group.").into_response();
    }
    if let Some(owner) = limits.owners.iter().find(|owner| !valid_username(owner)) {
        return ApiResponse::error(&format!("Invalid site user: {owner}")).into_response();
    }

    let result = tokio::task::spawn_blocking(move || -> Result<Vec<String>, PlatformError> {
        let profile = ResourceProfile {
            cpu_percent: limits.cpu_percent,
            memory_mb: limits.memory_mb,
            max_processes: limits.max_processes,
        };
        if profile.cpu_percent == 0 && profile.memory_mb == 0 && profile.max_processes == 0 {
            return clear(&group);
        }
        for owner in &limits.owners {
            limitable(owner)?;
        }
        crate::platform::resource_limiter().apply(&group, &profile)?;

        let mut errors = Vec::new();
        for owner in php_fpm_master::owners_of(&group) {
            if !limits.owners.contains(&owner) {
                if let Err(error) = php_fpm_master::release(&owner) {
                    errors.push(format!("{owner}: {error}"));
                }
            }
        }
        for owner in &limits.owners {
            if let Err(error) = php_fpm_master::dedicate(owner, &group) {
                errors.push(format!("{owner}: {error}"));
            }
        }
        Ok(errors)
    })
    .await;

    match result {
        Ok(Ok(errors)) if errors.is_empty() => ApiResponse::ok("Resource limits set.").into_response(),
        Ok(Ok(errors)) => failure(errors),
        Ok(Err(error)) => error.into_response(),
        Err(error) => ApiResponse::error(&format!("Limit change stopped: {error}")).into_response(),
    }
}

async fn clear_limits(State(state): State<Arc<ApiState>>, headers: HeaderMap, Path(group): Path<String>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if !valid_group(&group) {
        return ApiResponse::error("Invalid account group.").into_response();
    }
    match tokio::task::spawn_blocking(move || clear(&group)).await {
        Ok(Ok(errors)) if errors.is_empty() => ApiResponse::ok("Resource limits removed.").into_response(),
        Ok(Ok(errors)) => failure(errors),
        Ok(Err(error)) => error.into_response(),
        Err(error) => ApiResponse::error(&format!("Limit change stopped: {error}")).into_response(),
    }
}

async fn usage(State(state): State<Arc<ApiState>>, headers: HeaderMap, Path(group): Path<String>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if !valid_group(&group) {
        return ApiResponse::error("Invalid account group.").into_response();
    }
    let result = tokio::task::spawn_blocking(move || {
        let limiter = crate::platform::resource_limiter();
        let level = limiter.level();
        if level == LimitLevel::None {
            return Err(PlatformError::Unsupported {
                capability: crate::platform::capabilities::Capability::ResourceLimits,
            });
        }
        limiter.usage(&group).map(|sample| (sample, level_name(level)))
    })
    .await;
    match result {
        Ok(Ok((sample, level))) => Json(serde_json::json!({
            "success": true,
            "cpu_percent": (sample.cpu_percent * 10.0).round() / 10.0,
            "memory_mb": sample.memory_mb,
            "processes": sample.processes,
            "limit_level": level,
        }))
        .into_response(),
        Ok(Err(error)) => error.into_response(),
        Err(error) => ApiResponse::error(&format!("Usage check stopped: {error}")).into_response(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tower::ServiceExt;

    async fn send(method: &str, path: &str, body: &str) -> (axum::http::StatusCode, serde_json::Value) {
        let router = super::super::build_router(ApiState { api_token: "secret".into() });
        let request = axum::http::Request::builder()
            .method(method)
            .uri(path)
            .header("authorization", "Bearer secret")
            .header("content-type", "application/json")
            .body(axum::body::Body::from(body.to_string()))
            .unwrap();
        let response = router.oneshot(request).await.unwrap();
        let status = response.status();
        let body = axum::body::to_bytes(response.into_body(), 4096).await.unwrap();
        (status, serde_json::from_slice(&body).unwrap_or_default())
    }

    #[tokio::test]
    async fn an_odd_group_or_site_user_is_refused_before_anything_changes() {
        let (_, body) = send("PUT", "/resource-limits/root", r#"{"memory_mb":512,"owners":[]}"#).await;
        assert_eq!(body["success"], false);
        assert_eq!(body["message"], "Invalid account group.");

        let (_, body) = send("PUT", "/resource-limits/a12", r#"{"memory_mb":512,"owners":["../x"]}"#).await;
        assert_eq!(body["message"], "Invalid site user: ../x");

        let (_, body) = send("GET", "/resource-limits/a1%2F..%2Fx/usage", "").await;
        assert_eq!(body["success"], false);
    }

    #[tokio::test]
    async fn system_accounts_are_never_limited() {
        let (status, body) = send("PUT", "/resource-limits/a12", r#"{"memory_mb":512,"owners":["root"]}"#).await;
        assert_ne!(body["success"], true, "{body}");
        // Only Linux knows `root` as uid 0; elsewhere the account lookup is
        // unsupported (501) and the limit is refused all the same.
        if crate::platform::current().os() == crate::platform::Os::Linux {
            assert_eq!(body["success"], false);
            assert!(body["message"].as_str().unwrap_or_default().contains("not a site account"), "{body}");
        } else {
            assert!(!status.is_success(), "{status} {body}");
        }
    }
}
