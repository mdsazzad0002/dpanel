use std::sync::Arc;

use axum::{Router, routing::post};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/php/config", post(crate::php::config::handle))
}
