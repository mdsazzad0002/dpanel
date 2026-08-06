use std::sync::Arc;

use axum::{
    extract::{Json, State},
    response::IntoResponse,
};
use serde::Deserialize;
use std::collections::HashMap;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    scripts,
};

#[derive(Deserialize)]
pub(crate) struct Request {
    pub script: String,
    pub args: Option<Vec<String>>,
    pub environment: Option<HashMap<String, String>>,
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
    let environment = request
        .environment
        .unwrap_or_default()
        .into_iter()
        .collect::<Vec<_>>();
    match scripts::run_script_with_env(&request.script, &args, &environment) {
        Ok(output) => {
            ApiResponse::ok_data("Script executed", serde_json::json!({"output": output}))
                .into_response()
        }
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
