use std::sync::Arc;

use axum::{Router, routing::post};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route(
        "/api/v1/laravel-install",
        post(crate::laravel::install::handle),
    )
}
