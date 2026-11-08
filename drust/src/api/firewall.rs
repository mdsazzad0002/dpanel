//! `GET`, `POST /firewall/rules` and `DELETE /firewall/rules/{id}`: spec
//! section 8, over `platform::firewall`. Lock-out protection and
//! `POST /firewall/confirm` come with the firewall page (task S3).

use std::sync::Arc;

use axum::{
    Json, Router,
    extract::{Path, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::{delete, get},
};

use super::{ApiResponse, ApiState, check_token};
use crate::platform::firewall::{self, FirewallRule};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/firewall/rules", get(list).post(add))
        .route("/firewall/rules/{id}", delete(remove))
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
    match blocking(move || firewall::backend().add(&rule)).await {
        Ok(()) => Json(serde_json::json!({ "success": true, "message": "Firewall rule added.", "id": id })).into_response(),
        Err(response) => response,
    }
}

async fn remove(State(state): State<Arc<ApiState>>, headers: HeaderMap, Path(id): Path<String>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if id.len() != 12 || !id.chars().all(|c| c.is_ascii_hexdigit()) {
        return ApiResponse::error("Invalid rule id.").into_response();
    }
    match blocking(move || firewall::backend().remove(&id)).await {
        Ok(()) => ApiResponse::ok("Firewall rule removed.").into_response(),
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
}
