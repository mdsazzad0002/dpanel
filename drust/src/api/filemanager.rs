use std::sync::Arc;

use axum::{Router, routing::post};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route(
            "/api/v1/filemanager/create",
            post(crate::filemanager::create::handle),
        )
        .route(
            "/api/v1/filemanager/remove",
            post(crate::filemanager::remove::handle),
        )
        .route(
            "/api/v1/filemanager/delete",
            post(crate::filemanager::delete::handle),
        )
        .route(
            "/api/v1/filemanager/exists",
            post(crate::filemanager::exists::handle),
        )
        .route(
            "/api/v1/filemanager/user",
            post(crate::filemanager::user::handle),
        )
        .route(
            "/api/v1/filemanager/write",
            post(crate::filemanager::write::handle),
        )
        .route(
            "/api/v1/filemanager/unzip",
            post(crate::filemanager::unzip::handle),
        )
        .route(
            "/api/v1/filemanager/upload",
            post(crate::filemanager::upload::handle)
                .layer(axum::extract::DefaultBodyLimit::disable()),
        )
        .route(
            "/api/v1/filemanager/move",
            post(crate::filemanager::filemove::handle),
        )
}
