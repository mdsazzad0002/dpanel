use axum::{
    Router,
    extract::{Json, State},
    http::StatusCode,
    response::IntoResponse,
    routing::{get, post},
};
use serde::{Deserialize, Serialize};
use std::process::ExitCode;
use std::sync::Arc;

use crate::app;
use crate::filemanager;
use crate::vhost;

#[derive(Clone)]
pub struct ApiState {
    pub api_token: String,
}

#[derive(Serialize)]
pub struct ApiResponse {
    pub success: bool,
    pub message: String,
    #[serde(skip_serializing_if = "Option::is_none")]
    pub data: Option<serde_json::Value>,
}

impl ApiResponse {
    pub fn ok(message: &str) -> Self {
        Self {
            success: true,
            message: message.to_string(),
            data: None,
        }
    }

    pub fn ok_data(message: &str, data: serde_json::Value) -> Self {
        Self {
            success: true,
            message: message.to_string(),
            data: Some(data),
        }
    }

    pub fn error(message: &str) -> Self {
        Self {
            success: false,
            message: message.to_string(),
            data: None,
        }
    }
}

impl IntoResponse for ApiResponse {
    fn into_response(self) -> axum::response::Response {
        let status = if self.success {
            StatusCode::OK
        } else {
            StatusCode::BAD_REQUEST
        };
        (status, Json(serde_json::to_value(&self).unwrap())).into_response()
    }
}

pub fn build_router(state: ApiState) -> Router {
    Router::new()
        .route("/health", get(health))
        .route("/api/v1/health-checker", get(health_checker))
        .route("/api/v1/fix-web-stack", post(fix_web_stack))
        .route("/api/v1/fix-panel-web-stack", post(fix_panel_web_stack))
        .route("/api/v1/sync-vhost", post(sync_vhost))
        .route("/api/v1/create-admin-user", post(create_admin_user))
        .route("/api/v1/disable-root-login", post(disable_root_login))
        .route("/api/v1/filemanager/create", post(filemanager_create))
        .route("/api/v1/filemanager/remove", post(filemanager_remove))
        .route("/api/v1/filemanager/delete", post(filemanager_delete))
        .route("/api/v1/filemanager/exists", post(filemanager_exists))
        .route("/api/v1/filemanager/user", post(filemanager_user))
        .route("/api/v1/filemanager/write", post(filemanager_write))
        .route("/api/v1/php/config", post(php_config_apply))
        .route("/api/v1/filemanager/move", post(filemanager_move))
        .route("/api/v1/ssl/ensure", post(ssl_ensure))
        .route("/api/v1/script/run", post(run_script))
        .route("/api/v1/laravel-install", post(laravel_install))
        .with_state(Arc::new(state))
}

async fn health() -> impl IntoResponse {
    Json(serde_json::json!({
        "status": "ok",
        "service": "drust",
        "version": env!("CARGO_PKG_VERSION")
    }))
}

async fn health_checker(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    ApiResponse::ok_data(
        "Health check passed",
        serde_json::json!({
            "status": "ok",
            "service": "drust",
            "version": env!("CARGO_PKG_VERSION")
        }),
    )
    .into_response()
}

fn check_token(state: &ApiState, headers: &axum::http::HeaderMap) -> Result<(), ApiResponse> {
    let token = headers
        .get("authorization")
        .and_then(|v| v.to_str().ok())
        .and_then(|v| v.strip_prefix("Bearer "))
        .unwrap_or("");

    if token != state.api_token {
        return Err(ApiResponse::error("Unauthorized"));
    }
    Ok(())
}

// --- Request types ---

#[derive(Deserialize)]
pub struct FixWebStackRequest {
    pub apache_backend_port: Option<u16>,
    pub nginx_frontend_port: Option<u16>,
}

#[derive(Deserialize)]
pub struct FixPanelWebStackRequest {
    pub domain: Option<String>,
    pub backend_port: Option<u16>,
    pub frontend_port: Option<u16>,
    pub app_dir: Option<String>,
    pub conf_name: Option<String>,
    pub aliases: Option<Vec<String>>,
    pub no_www: Option<bool>,
    pub client_max_body_size: Option<String>,
}

#[derive(Deserialize)]
pub struct SyncVhostRequest {
    pub action: String,
    pub domain: String,
    pub root_path: String,
    pub php_version: Option<String>,
    pub old_domain: Option<String>,
    pub aliases: Option<Vec<String>>,
    pub no_www: Option<bool>,
    pub client_max_body_size: Option<String>,
}

#[derive(Deserialize)]
pub struct CreateAdminUserRequest {
    pub username: String,
    pub password: Option<String>,
    pub email: Option<String>,
    pub ssh_key: Option<String>,
    pub shell: Option<String>,
    pub disable_root: Option<bool>,
}

#[derive(Deserialize)]
pub struct FilemanagerCreateRequest {
    pub paths: Vec<String>,
}

#[derive(Deserialize)]
pub struct FilemanagerRemoveRequest {
    pub paths: Vec<String>,
}

#[derive(Deserialize)]
pub struct FilemanagerDeleteRequest {
    pub username: String,
    pub path: String,
}

#[derive(Deserialize)]
pub struct FilemanagerExistsRequest {
    pub paths: Vec<String>,
    pub check_file: Option<bool>,
}

#[derive(Deserialize)]
pub struct FilemanagerUserRequest {
    pub action: String,
    pub username: String,
    pub home: Option<String>,
    pub shell: Option<String>,
    pub site_directory: Option<String>,
}

#[derive(Deserialize)]
pub struct FilemanagerWriteRequest {
    pub username: String,
    pub path: String,
    pub content: String,
    pub must_exist: Option<bool>,
}

#[derive(Deserialize)]
pub struct FilemanagerMoveRequest {
    pub username: String,
    pub source: String,
    pub destination: String,
}

#[derive(Deserialize)]
pub struct SslEnsureRequest {
    pub domain: String,
    pub root_path: String,
    pub include_www: Option<bool>,
    pub renew_before_days: Option<u64>,
}

#[derive(Deserialize)]
pub struct PhpConfigRequest {
    pub version: String,
    pub memory_limit: String,
    pub upload_max_filesize: String,
    pub post_max_size: String,
    pub max_execution_time: u32,
    pub max_input_vars: u32,
    pub display_errors: String,
    pub log_errors: String,
    pub allow_url_fopen: String,
}

#[derive(Deserialize)]
pub struct RunScriptRequest {
    pub script: String,
    pub args: Option<Vec<String>>,
}

#[derive(Deserialize)]
pub struct LaravelInstallRequest {
    pub root_path: String,
    pub domain: String,
    pub php_version: Option<String>,
    pub start_directory: Option<String>,
    pub db_name: Option<String>,
    pub db_user: Option<String>,
    pub db_password: Option<String>,
    pub db_host: Option<String>,
    pub db_port: Option<String>,
    pub no_demo: Option<bool>,
    pub no_db: Option<bool>,
    pub no_vhost: Option<bool>,
}

// --- Handlers ---

async fn fix_web_stack(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FixWebStackRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let apache_port = req.apache_backend_port.unwrap_or(8080);
    let nginx_port = req.nginx_frontend_port.unwrap_or(80);

    match vhost::run_fix_web_stack(apache_port, nginx_port) {
        Ok(()) => ApiResponse::ok("Web stack repaired successfully").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn fix_panel_web_stack(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FixPanelWebStackRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let mut args = Vec::new();
    if let Some(d) = &req.domain {
        args.push(d.clone());
    }
    if let Some(p) = req.backend_port {
        args.push(p.to_string());
    }
    if let Some(p) = req.frontend_port {
        args.push(p.to_string());
    }
    if let Some(dir) = &req.app_dir {
        args.push("--app-dir".into());
        args.push(dir.clone());
    }
    if let Some(name) = &req.conf_name {
        args.push("--conf-name".into());
        args.push(name.clone());
    }
    if let Some(aliases) = &req.aliases {
        for a in aliases {
            args.push("--alias".into());
            args.push(a.clone());
        }
    }
    if req.no_www.unwrap_or(false) {
        args.push("--no-www".into());
    }
    if let Some(limit) = &req.client_max_body_size {
        args.push("--client-max-body-size".into());
        args.push(limit.clone());
    }

    match vhost::run_fix_panel_web_stack(args) {
        Ok(()) => ApiResponse::ok("Panel web stack fixed successfully").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn sync_vhost(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<SyncVhostRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let mut args = vec![
        req.action,
        req.domain,
        req.root_path,
        req.php_version.unwrap_or_else(|| "8.3".into()),
    ];
    if let Some(old) = &req.old_domain {
        args.push(old.clone());
    }
    if let Some(aliases) = &req.aliases {
        for a in aliases {
            args.push("--alias".into());
            args.push(a.clone());
        }
    }
    if req.no_www.unwrap_or(false) {
        args.push("--no-www".into());
    }
    if let Some(limit) = &req.client_max_body_size {
        args.push("--client-max-body-size".into());
        args.push(limit.clone());
    }

    match vhost::run_sync_vhost(args) {
        Ok(()) => ApiResponse::ok("Vhost synchronized successfully").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn create_admin_user(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<CreateAdminUserRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let mut args = vec!["--username".into(), req.username];
    if let Some(p) = &req.password {
        args.push("--password".into());
        args.push(p.clone());
    }
    if let Some(e) = &req.email {
        args.push("--email".into());
        args.push(e.clone());
    }
    if let Some(k) = &req.ssh_key {
        args.push("--ssh-key".into());
        args.push(k.clone());
    }
    if let Some(s) = &req.shell {
        args.push("--shell".into());
        args.push(s.clone());
    }
    if req.disable_root.unwrap_or(true) {
        args.push("--disable-root".into());
    }

    match app::run_admin_user(args) {
        Ok(()) => ApiResponse::ok("Admin user created successfully").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn disable_root_login(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    match app::run_disable_root_login() {
        Ok(()) => ApiResponse::ok("Root SSH login disabled").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn filemanager_create(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FilemanagerCreateRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    match filemanager::run_filemanager_create(&req.paths) {
        Ok(()) => ApiResponse::ok("Directories created").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn filemanager_remove(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FilemanagerRemoveRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    match filemanager::run_filemanager_remove(&req.paths) {
        Ok(()) => ApiResponse::ok("Paths removed").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn filemanager_delete(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FilemanagerDeleteRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    match filemanager::delete_user_path(&req.username, &req.path) {
        Ok(()) => ApiResponse::ok("Path deleted").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn filemanager_exists(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FilemanagerExistsRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let check_file = req.check_file.unwrap_or(false);
    match filemanager::run_filemanager_exists(&req.paths, !check_file) {
        Ok(()) => ApiResponse::ok("All targets exist").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn filemanager_user(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FilemanagerUserRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let mut args = vec![req.action, req.username];
    if let Some(h) = &req.home {
        args.push("--home".into());
        args.push(h.clone());
    }
    if let Some(s) = &req.shell {
        args.push("--shell".into());
        args.push(s.clone());
    }
    if let Some(d) = &req.site_directory {
        args.push("--site-directory".into());
        args.push(d.clone());
    }

    match filemanager::run_filemanager_user(args) {
        Ok(()) => ApiResponse::ok("User operation completed").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn filemanager_write(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FilemanagerWriteRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    match filemanager::write_user_file(
        &req.username,
        &req.path,
        req.content.as_bytes(),
        req.must_exist.unwrap_or(false),
    ) {
        Ok(()) => ApiResponse::ok("File written").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn filemanager_move(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<FilemanagerMoveRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    match filemanager::move_user_path(&req.username, &req.source, &req.destination) {
        Ok(()) => ApiResponse::ok("Path moved").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn ssl_ensure(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<SslEnsureRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    match crate::ssl::ensure_certificate(
        &req.domain,
        &req.root_path,
        req.include_www.unwrap_or(false),
        req.renew_before_days.unwrap_or(30),
    ) {
        Ok(data) => ApiResponse::ok_data("SSL certificate is valid", data).into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn php_config_apply(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<PhpConfigRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    let values = crate::php_config::PhpConfigValues {
        version: &req.version,
        memory_limit: &req.memory_limit,
        upload_max_filesize: &req.upload_max_filesize,
        post_max_size: &req.post_max_size,
        max_execution_time: req.max_execution_time,
        max_input_vars: req.max_input_vars,
        display_errors: &req.display_errors,
        log_errors: &req.log_errors,
        allow_url_fopen: &req.allow_url_fopen,
    };
    match crate::php_config::apply(values) {
        Ok(paths) => {
            let version = req.version.clone();
            tokio::spawn(async move {
                // Allow Laravel to flush this response before the FPM workers
                // serving it are gracefully recycled.
                tokio::time::sleep(std::time::Duration::from_secs(3)).await;
                let _ =
                    tokio::task::spawn_blocking(move || crate::php_config::reload_fpm(&version))
                        .await;
            });
            ApiResponse::ok_data(
                "PHP configuration applied; PHP-FPM reload scheduled",
                serde_json::json!({"paths": paths}),
            )
            .into_response()
        }
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn run_script(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<RunScriptRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let args = req.args.unwrap_or_default();
    match crate::scripts::run_script(&req.script, &args) {
        Ok(output) => {
            ApiResponse::ok_data("Script executed", serde_json::json!({"output": output}))
                .into_response()
        }
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

async fn laravel_install(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(req): Json<LaravelInstallRequest>,
) -> impl IntoResponse {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }

    let mut args = vec![req.root_path, req.domain];
    if let Some(v) = &req.php_version {
        args.push(v.clone());
    }
    if let Some(d) = &req.start_directory {
        args.push(d.clone());
    }
    if let Some(n) = &req.db_name {
        args.push("--db-name".into());
        args.push(n.clone());
    }
    if let Some(u) = &req.db_user {
        args.push("--db-user".into());
        args.push(u.clone());
    }
    if let Some(p) = &req.db_password {
        args.push("--db-password".into());
        args.push(p.clone());
    }
    if let Some(h) = &req.db_host {
        args.push("--db-host".into());
        args.push(h.clone());
    }
    if let Some(p) = &req.db_port {
        args.push("--db-port".into());
        args.push(p.clone());
    }
    if req.no_demo.unwrap_or(false) {
        args.push("--no-demo".into());
    }
    if req.no_db.unwrap_or(false) {
        args.push("--no-db".into());
    }
    if req.no_vhost.unwrap_or(false) {
        args.push("--no-vhost".into());
    }

    match app::run_laravel_install(args) {
        Ok(()) => ApiResponse::ok("Laravel install completed").into_response(),
        Err(e) => ApiResponse::error(&format!("Failed: {e}")).into_response(),
    }
}

pub fn serve(args: Vec<String>) -> ExitCode {
    let mut port: u16 = 9500;
    let mut token = String::new();

    let mut iter = args.into_iter().skip(1);
    while let Some(arg) = iter.next() {
        match arg.as_str() {
            "--port" | "-p" => {
                if let Some(val) = iter.next() {
                    port = val.parse().unwrap_or(9500);
                }
            }
            "--token" | "-t" => {
                if let Some(val) = iter.next() {
                    token = val;
                }
            }
            other => {
                eprintln!("Unknown serve option: {other}");
                return ExitCode::from(1);
            }
        }
    }

    if token.is_empty() {
        token = std::env::var("DRUST_API_TOKEN").unwrap_or_else(|_| {
            eprintln!("[WARN] No API token set. Generating random token.");
            app::random_hex(32).unwrap_or_else(|_| "insecure-token".into())
        });
    }

    let state = ApiState {
        api_token: token.clone(),
    };

    println!("[INFO] drust API server starting on port {port}");
    println!("[INFO] API token: {token}");

    let rt = tokio::runtime::Runtime::new().expect("Failed to create tokio runtime");
    rt.block_on(async {
        let router = build_router(state);
        let addr = format!("127.0.0.1:{port}");
        let listener = tokio::net::TcpListener::bind(&addr)
            .await
            .unwrap_or_else(|_| panic!("Failed to bind to {addr}"));

        println!("[INFO] drust API listening on {addr}");
        if let Err(e) = axum::serve(listener, router).await {
            eprintln!("[ERROR] Server error: {e}");
            return ExitCode::from(1);
        }
        ExitCode::from(0)
    })
}
