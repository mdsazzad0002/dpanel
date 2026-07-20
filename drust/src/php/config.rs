use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    php_config,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    pub version: String,
    pub memory_limit: String,
    pub upload_max_filesize: String,
    pub post_max_size: String,
    pub max_execution_time: u32,
    pub max_input_vars: u32,
    pub display_errors: String,
    pub log_errors: String,
    pub allow_url_fopen: String,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let values = php_config::PhpConfigValues {
        version: &request.version,
        memory_limit: &request.memory_limit,
        upload_max_filesize: &request.upload_max_filesize,
        post_max_size: &request.post_max_size,
        max_execution_time: request.max_execution_time,
        max_input_vars: request.max_input_vars,
        display_errors: &request.display_errors,
        log_errors: &request.log_errors,
        allow_url_fopen: &request.allow_url_fopen,
    };

    match php_config::apply(values) {
        Ok(paths) => {
            let version = request.version.clone();
            tokio::spawn(async move {
                // Give Laravel time to flush the response before recycling FPM.
                tokio::time::sleep(std::time::Duration::from_secs(3)).await;
                let _ = tokio::task::spawn_blocking(move || php_config::reload_fpm(&version)).await;
            });

            ApiResponse::ok_data(
                "PHP configuration applied; PHP-FPM reload scheduled",
                serde_json::json!({"paths": paths}),
            )
            .into_response()
        }
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
