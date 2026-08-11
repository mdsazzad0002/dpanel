use std::sync::Arc;

use axum::{Router, routing::post};

use super::ApiState;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new()
        .route(
            "/api/v1/filemanager/artisan",
            post(crate::filemanager::artisan::handle),
        )
        .route(
            "/api/v1/filemanager/browse",
            post(crate::filemanager::browse::handle),
        )
        .route(
            "/api/v1/filemanager/read",
            post(crate::filemanager::read::handle),
        )
        .route(
            "/api/v1/filemanager/inspect",
            post(crate::filemanager::inspect::handle),
        )
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
            "/api/v1/filemanager/chmod",
            post(crate::filemanager::chmod::handle),
        )
        .route(
            "/api/v1/filemanager/unzip",
            post(crate::filemanager::unzip::handle),
        )
        .route(
            "/api/v1/filemanager/zip",
            post(crate::filemanager::zip::handle),
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
        .route(
            "/api/v1/filemanager/fix-permissions",
            post(crate::filemanager::fix_permissions::handle),
        )
        .route(
            "/api/v1/filemanager/wordpress-install",
            post(crate::filemanager::wordpress::handle),
        )
}
