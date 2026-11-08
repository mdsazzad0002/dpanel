//! `POST /accounts/{name}/suspend` and `/unsuspend`: `plans/platform-spec.md` section 8.
//!
//! The panel keeps everything it can stop itself (sessions, websites, cron,
//! queue workers); this blocks the system account's own logins, SSH and SFTP.

use std::sync::Arc;

use axum::{
    Router,
    extract::{Path, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::post,
};

use super::{ApiResponse, ApiState, check_token};
use crate::app::valid_username;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/accounts/{name}/suspend", post(suspend))
        .route("/accounts/{name}/unsuspend", post(unsuspend))
}

async fn suspend(state: State<Arc<ApiState>>, headers: HeaderMap, name: Path<String>) -> Response {
    change(state, headers, name, true).await
}

async fn unsuspend(state: State<Arc<ApiState>>, headers: HeaderMap, name: Path<String>) -> Response {
    change(state, headers, name, false).await
}

async fn change(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Path(name): Path<String>,
    suspended: bool,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    if !valid_username(&name) {
        return ApiResponse::error("Invalid account name.").into_response();
    }
    let result = tokio::task::spawn_blocking(move || {
        let accounts = crate::platform::accounts();
        if !accounts.exists(&name)? {
            return Err(crate::platform::PlatformError::NotFound {
                what: format!("User does not exist: {name}"),
            });
        }
        accounts.suspend(&name, suspended).map(|_| name)
    })
    .await;
    match result {
        Ok(Ok(name)) if suspended => ApiResponse::ok(&format!("{name} can no longer log in.")).into_response(),
        Ok(Ok(name)) => ApiResponse::ok(&format!("{name} can log in again.")).into_response(),
        Ok(Err(error)) => error.into_response(),
        Err(error) => ApiResponse::error(&format!("Account change stopped: {error}")).into_response(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tower::ServiceExt;

    async fn post(path: &str, token: &str) -> (axum::http::StatusCode, serde_json::Value) {
        let router = super::super::build_router(ApiState { api_token: "secret".into() });
        let request = axum::http::Request::builder()
            .method("POST")
            .uri(path)
            .header("authorization", format!("Bearer {token}"))
            .body(axum::body::Body::empty())
            .unwrap();
        let response = router.oneshot(request).await.unwrap();
        let status = response.status();
        let body = axum::body::to_bytes(response.into_body(), 4096).await.unwrap();
        (status, serde_json::from_slice(&body).unwrap_or_default())
    }

    #[tokio::test]
    async fn needs_the_token() {
        let (status, _) = post("/accounts/shop/suspend", "wrong").await;
        assert_eq!(status, axum::http::StatusCode::BAD_REQUEST);
    }

    #[tokio::test]
    async fn refuses_a_name_that_is_not_an_account_name() {
        let (_, body) = post("/accounts/Shop%20x/suspend", "secret").await;
        assert_eq!(body["success"], false);
        assert_eq!(body["message"], "Invalid account name.");
    }

    #[tokio::test]
    async fn an_unknown_account_is_an_error_not_a_success() {
        // A system without accounts answers 501 instead, which is no success either.
        let (_, body) = post("/accounts/dpanel-no-such-user/unsuspend", "secret").await;
        assert_ne!(body["success"], true);
    }
}
