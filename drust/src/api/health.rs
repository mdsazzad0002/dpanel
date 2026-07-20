use std::sync::Arc;

use axum::{Router, routing::get};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/health", get(crate::health::status::handle))
        .route(
            "/api/v1/health-checker",
            get(crate::health::checker::handle),
        )
}
