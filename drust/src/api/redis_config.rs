use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    Router,
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;
use std::{
    fs,
    path::PathBuf,
    sync::Arc,
    time::{SystemTime, UNIX_EPOCH},
};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/website/redis-config", post(handle))
}

#[derive(Deserialize)]
struct Request {
    action: String,
    site_owner: String,
    framework: String,
    config_path: String,
    prefix: Option<String>,
    host: Option<String>,
    port: Option<u16>,
    database: Option<u8>,
    backup_path: Option<String>,
}

async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(req): Json<Request>,
) -> Response {
    if let Err(e) = check_token(&state, &headers) {
        return e.into_response();
    }
    match execute(&req) {
        Ok(data) => ApiResponse::ok_data("Redis configuration updated", data).into_response(),
        Err(e) => ApiResponse::error(&e).into_response(),
    }
}

fn safe_path(value: &str, owner: &str) -> Result<PathBuf, String> {
    if owner.is_empty()
        || !owner
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '_' || c == '-')
    {
        return Err("invalid site owner".into());
    }
    let path = PathBuf::from(value);
    if !path.starts_with(format!("/home/{owner}/"))
        || path
            .components()
            .any(|c| matches!(c, std::path::Component::ParentDir))
    {
        return Err("config path is outside website home".into());
    }
    Ok(path)
}

fn execute(req: &Request) -> Result<serde_json::Value, String> {
    let path = safe_path(&req.config_path, &req.site_owner)?;
    if req.action == "rollback" {
        let backup = safe_path(
            req.backup_path.as_deref().ok_or("missing backup path")?,
            &req.site_owner,
        )?;
        if !backup.is_file() {
            return Err("backup file not found".into());
        }
        fs::copy(&backup, &path).map_err(|e| format!("restore failed: {e}"))?;
        return Ok(serde_json::json!({"config_path": path, "backup_path": backup}));
    }
    if req.action != "apply" {
        return Err("invalid action".into());
    }
    if !path.is_file() {
        return Err("configuration file not found".into());
    }
    let expected = if matches!(req.framework.as_str(), "laravel" | "codeigniter4") {
        ".env"
    } else if req.framework == "wordpress" {
        "wp-config.php"
    } else if req.framework == "codeigniter3" {
        "config.php"
    } else {
        return Err("unsupported framework".into());
    };
    if path.file_name().and_then(|v| v.to_str()) != Some(expected) {
        return Err("configuration filename does not match framework".into());
    }
    let original = fs::read_to_string(&path).map_err(|e| format!("read failed: {e}"))?;
    let stamp = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map_err(|e| e.to_string())?
        .as_secs();
    let backup = path.with_file_name(format!("{expected}.dpanel-redis-{stamp}.bak"));
    fs::copy(&path, &backup).map_err(|e| format!("backup failed: {e}"))?;
    let prefix = req.prefix.as_deref().ok_or("missing prefix")?;
    let host = req.host.as_deref().unwrap_or("127.0.0.1");
    let port = req.port.unwrap_or(6379);
    let db = req.database.unwrap_or(1);
    let updated = match req.framework.as_str() {
        "laravel" => update_env(original, prefix, host, port, db),
        "codeigniter4" => update_codeigniter_env(original, prefix, host, port, db),
        "codeigniter3" => update_codeigniter3(original, prefix, host, port, db),
        _ => update_wordpress(original, prefix, host, port, db)?,
    };
    fs::write(&path, updated).map_err(|e| format!("write failed: {e}"))?;
    Ok(serde_json::json!({"config_path": path, "backup_path": backup}))
}

fn update_env(mut text: String, prefix: &str, host: &str, port: u16, db: u8) -> String {
    for (key, value) in [
        ("CACHE_STORE", "redis"),
        ("CACHE_DRIVER", "redis"),
        ("SESSION_DRIVER", "redis"),
        ("SESSION_CONNECTION", "default"),
        ("REDIS_CLIENT", "phpredis"),
        ("REDIS_HOST", host),
        ("REDIS_PORT", &port.to_string()),
        ("REDIS_DB", "0"),
        ("REDIS_CACHE_DB", &db.to_string()),
        ("CACHE_PREFIX", prefix),
        ("REDIS_PREFIX", prefix),
    ] {
        let line = format!("{key}={value}");
        let needle = format!("{key}=");
        if let Some(old) = text
            .lines()
            .find(|l| l.starts_with(&needle))
            .map(str::to_owned)
        {
            text = text.replacen(&old, &line, 1);
        } else {
            text.push_str(&format!("\n{line}"));
        }
    }
    text.trim_end().to_string() + "\n"
}

fn update_wordpress(
    text: String,
    prefix: &str,
    host: &str,
    port: u16,
    db: u8,
) -> Result<String, String> {
    let marker = "/* That's all, stop editing";
    let block = format!(
        "// dPanel Redis configuration\ndefine('WP_REDIS_HOST', '{host}');\ndefine('WP_REDIS_PORT', {port});\ndefine('WP_REDIS_DATABASE', {db});\ndefine('WP_REDIS_PREFIX', '{prefix}');\n"
    );
    let cleaned = text
        .lines()
        .filter(|line| {
            !line.contains("dPanel Redis configuration")
                && ![
                    "WP_REDIS_HOST",
                    "WP_REDIS_PORT",
                    "WP_REDIS_DATABASE",
                    "WP_REDIS_PREFIX",
                ]
                .iter()
                .any(|key| line.contains(key))
        })
        .collect::<Vec<_>>()
        .join("\n");
    let insert = cleaned
        .find(marker)
        .ok_or("WordPress stop-editing marker not found")?;
    Ok(format!(
        "{}{}\n{}\n",
        &cleaned[..insert],
        block,
        &cleaned[insert..]
    ))
}

fn update_codeigniter_env(mut text: String, prefix: &str, host: &str, port: u16, db: u8) -> String {
    for (key, value) in [
        ("cache.handler", "redis".to_string()),
        ("cache.backupHandler", "file".to_string()),
        ("cache.redis.host", host.to_string()),
        ("cache.redis.port", port.to_string()),
        ("cache.redis.database", db.to_string()),
        ("cache.redis.prefix", prefix.to_string()),
    ] {
        let line = format!("{key} = {value}");
        if let Some(old) = text
            .lines()
            .find(|candidate| {
                candidate
                    .trim_start_matches('#')
                    .trim_start()
                    .starts_with(&format!("{key} ="))
            })
            .map(str::to_owned)
        {
            text = text.replacen(&old, &line, 1);
        } else {
            text.push_str(&format!("\n{line}"));
        }
    }
    text.trim_end().to_string() + "\n"
}

fn update_codeigniter3(text: String, prefix: &str, host: &str, port: u16, db: u8) -> String {
    let begin = "// dPanel Redis configuration: begin";
    let end = "// dPanel Redis configuration: end";
    let mut cleaned = text.clone();
    if let (Some(start), Some(finish)) = (text.find(begin), text.find(end)) {
        let finish = finish + end.len();
        cleaned.replace_range(start..finish, "");
    }
    let escape = |value: &str| value.replace('\\', "\\\\").replace('\'', "\\'");
    let block = format!(
        "{begin}\n$config['cache_path'] = '';\n$config['redis_default']['host'] = '{}';\n$config['redis_default']['port'] = {port};\n$config['redis_default']['database'] = {db};\n$config['redis_default']['timeout'] = 2.0;\n$config['redis_default']['prefix'] = '{}';\n{end}\n",
        escape(host),
        escape(prefix)
    );
    if let Some(position) = cleaned.rfind("?>") {
        cleaned.insert_str(position, &block);
    } else {
        if !cleaned.ends_with('\n') {
            cleaned.push('\n');
        }
        cleaned.push_str(&block);
    }
    cleaned
}
