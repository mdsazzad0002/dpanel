use axum::{extract::Json, response::IntoResponse};

pub(crate) async fn handle() -> impl IntoResponse {
    Json(serde_json::json!({
        "status": "ok",
        "service": "drust",
        "version": env!("CARGO_PKG_VERSION")
    }))
}
