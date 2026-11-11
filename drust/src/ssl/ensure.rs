use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

use super::certificate;

#[derive(Deserialize)]
pub(crate) struct Request {
    pub domain: String,
    pub root_path: String,
    pub include_www: Option<bool>,
    pub renew_before_days: Option<u64>,
    pub force: Option<bool>,
    /// Present: the certificate also covers `*.<domain>`, proved in this zone.
    pub wildcard: Option<super::dns01::DnsTarget>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    // Issuing takes as long as the CA does, so it stays off the async workers.
    let result = tokio::task::spawn_blocking(move || {
        certificate::ensure(
            &request.domain,
            &request.root_path,
            request.include_www.unwrap_or(false),
            request.renew_before_days.unwrap_or(30),
            request.force.unwrap_or(false),
            request.wildcard.as_ref(),
        )
    })
    .await
    .unwrap_or_else(|error| Err(format!("the certificate task stopped: {error}")));
    match result {
        Ok(data) => ApiResponse::ok_data("SSL certificate is valid", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

/// `POST /api/v1/ssl/renew`: renews what drust's ACME client issued and is due.
pub(crate) async fn renew(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let result = tokio::task::spawn_blocking(certificate::renew_due)
        .await
        .unwrap_or_else(|error| Err(format!("the certificate task stopped: {error}")));
    match result {
        Ok(data) => ApiResponse::ok_data("Due certificates renewed", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

#[derive(Deserialize)]
pub(crate) struct RemoveRequest {
    pub domain: String,
}

pub(crate) async fn remove(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<RemoveRequest>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let result = tokio::task::spawn_blocking(move || certificate::remove(&request.domain))
        .await
        .unwrap_or_else(|error| Err(format!("the certificate task stopped: {error}")));
    match result {
        Ok(data) => ApiResponse::ok_data("SSL certificate removed", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

#[derive(Deserialize)]
pub(crate) struct InstallRequest {
    pub domain: String,
    pub certificate: String,
    pub private_key: String,
}

/// `POST /api/v1/ssl/install`: a certificate the customer uploaded.
pub(crate) async fn install(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<InstallRequest>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let result = tokio::task::spawn_blocking(move || {
        certificate::install_custom(&request.domain, &request.certificate, &request.private_key)
    })
    .await
    .unwrap_or_else(|error| Err(format!("the certificate task stopped: {error}")));
    match result {
        Ok(data) => ApiResponse::ok_data("SSL certificate installed", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
