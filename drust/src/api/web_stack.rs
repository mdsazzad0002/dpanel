use std::sync::Arc;

use axum::{Router, routing::post};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route(
            "/api/v1/fix-web-stack",
            post(crate::web_stack::fix_web_stack::handle),
        )
        .route(
            "/api/v1/fix-panel-web-stack",
            post(crate::web_stack::fix_panel_web_stack::handle),
        )
}
