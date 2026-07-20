use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    scripts,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    pub script: String,
    pub args: Option<Vec<String>>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> impl IntoResponse {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    let args = request.args.unwrap_or_default();
    match scripts::run_script(&request.script, &args) {
        Ok(output) => {
            ApiResponse::ok_data("Script executed", serde_json::json!({"output": output}))
                .into_response()
        }
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
