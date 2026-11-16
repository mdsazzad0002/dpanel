use std::sync::Arc;

use axum::{Router, routing::{get, post}};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route("/api/v1/php/config", post(crate::php::config::handle))
        .route("/api/v1/php/info", post(crate::php::info::handle))
        .route(
            "/api/v1/php/workers",
            get(crate::php::workers::list).post(crate::php::workers::update),
        )
}
