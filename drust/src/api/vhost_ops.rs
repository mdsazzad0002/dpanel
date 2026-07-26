use std::sync::Arc;

use axum::{Router, routing::post};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/sync-vhost", post(crate::vhost_ops::sync::handle))
}
