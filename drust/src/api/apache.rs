use std::sync::Arc;

use axum::{Router, routing::post};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/apache/action", post(crate::apache::action::handle))
        .route(
            "/api/v1/apache/shared-vhost",
            post(crate::apache::shared_vhost::handle),
        )
}
