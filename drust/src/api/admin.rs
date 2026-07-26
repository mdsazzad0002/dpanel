use std::sync::Arc;

use axum::{Router, routing::post};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route(
            "/api/v1/create-admin-user",
            post(crate::admin::create_user::handle),
        )
        .route(
            "/api/v1/disable-root-login",
            post(crate::admin::disable_root_login::handle),
        )
}
