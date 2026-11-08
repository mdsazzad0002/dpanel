//! `GET /sites/logs` (backlog S4): the end of a site's access log or its
//! account's PHP error log. Only the two fixed places are read, so neither
//! name can point anywhere else.

use std::sync::Arc;

use axum::{
    Json, Router,
    extract::{Query, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
    routing::get,
};
use serde::Deserialize;

use super::{ApiResponse, ApiState, check_token};
use crate::edge_gateway::site_logs;

const MAX_LINES: usize = 2000;
const MAX_READ: u64 = 4 * 1024 * 1024;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/sites/logs", get(read))
}

#[derive(Deserialize)]
struct LogQuery {
    kind: String,
    domain: Option<String>,
    owner: Option<String>,
    lines: Option<usize>,
}

fn valid_owner(owner: &str) -> bool {
    let mut chars = owner.chars();
    owner.len() <= 32
        && chars.next().is_some_and(|c| c.is_ascii_lowercase() || c == '_')
        && chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-')
}

/// The file a query names, or why it names none.
fn chosen(query: &LogQuery) -> Result<std::path::PathBuf, &'static str> {
    match query.kind.as_str() {
        "access" => {
            let domain = query.domain.as_deref().unwrap_or_default();
            site_logs::access_log_path(&site_logs::logs_root(), domain).ok_or("Invalid domain.")
        }
        "php" => {
            let owner = query.owner.as_deref().unwrap_or_default();
            if !valid_owner(owner) {
                return Err("Invalid account.");
            }
            Ok(site_logs::php_error_log(owner))
        }
        _ => Err("Unknown log kind."),
    }
}

async fn read(State(state): State<Arc<ApiState>>, headers: HeaderMap, Query(query): Query<LogQuery>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    let path = match chosen(&query) {
        Ok(path) => path,
        Err(message) => return ApiResponse::error(message).into_response(),
    };
    let lines = query.lines.unwrap_or(200).clamp(1, MAX_LINES);
    let result = tokio::task::spawn_blocking(move || {
        if !path.is_file() {
            return Ok((Vec::new(), false));
        }
        site_logs::tail(&path, lines, MAX_READ).map(|lines| (lines, true))
    })
    .await;
    match result {
        Ok(Ok((lines, exists))) => Json(serde_json::json!({ "success": true, "exists": exists, "lines": lines })).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Log could not be read: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Log read stopped: {error}")).into_response(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn query(kind: &str, domain: Option<&str>, owner: Option<&str>) -> LogQuery {
        LogQuery { kind: kind.into(), domain: domain.map(Into::into), owner: owner.map(Into::into), lines: None }
    }

    #[test]
    fn only_the_two_fixed_places_can_be_named() {
        assert!(chosen(&query("access", Some("shop.test"), None)).unwrap().ends_with("shop.test/access.log"));
        assert!(chosen(&query("php", None, Some("site1"))).unwrap().ends_with("site1/logs/php-error.log"));
        assert!(chosen(&query("access", Some("../../etc"), None)).is_err());
        assert!(chosen(&query("php", None, Some("../root"))).is_err());
        assert!(chosen(&query("php", None, Some("Root"))).is_err());
        assert!(chosen(&query("shadow", None, None)).is_err());
    }
}
