use std::fs;
use std::os::unix::fs::PermissionsExt;
use std::sync::Arc;
use std::time::UNIX_EPOCH;

use crate::api::{ApiResponse, ApiState, check_token};
use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::{Deserialize, Serialize};

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    base_path: String,
    path: Option<String>,
    show_hidden: Option<bool>,
}

#[derive(Serialize)]
struct Item {
    name: String,
    path: String,
    #[serde(rename = "type")]
    kind: String,
    size: Option<u64>,
    modified_at: Option<u64>,
    permissions: String,
}

#[derive(Serialize)]
struct TreeNode {
    name: String,
    path: String,
    #[serde(rename = "hasChildren")]
    has_children: bool,
    children: Vec<TreeNode>,
}

fn directory_tree(
    base: &std::path::Path,
    relative: &str,
    active: &str,
    show_hidden: bool,
) -> Result<Vec<TreeNode>, String> {
    let directory = if relative.is_empty() {
        base.to_path_buf()
    } else {
        base.join(relative)
    };
    let mut nodes = Vec::new();
    for entry in fs::read_dir(directory).map_err(|e| format!("Cannot build folder tree: {e}"))? {
        let entry = entry.map_err(|e| format!("Cannot read folder tree entry: {e}"))?;
        let name = entry.file_name().to_string_lossy().to_string();
        if (!show_hidden && name.starts_with('.'))
            || !entry.file_type().map_err(|e| e.to_string())?.is_dir()
        {
            continue;
        }
        let path = if relative.is_empty() {
            name.clone()
        } else {
            format!("{relative}/{name}")
        };
        let has_children = fs::read_dir(entry.path())
            .ok()
            .map(|entries| {
                entries
                    .filter_map(Result::ok)
                    .any(|child| child.file_type().map(|kind| kind.is_dir()).unwrap_or(false))
            })
            .unwrap_or(false);
        let active_branch = active == path || active.starts_with(&(path.clone() + "/"));
        let children = if active_branch {
            directory_tree(base, &path, active, show_hidden)?
        } else {
            Vec::new()
        };
        nodes.push(TreeNode {
            name,
            path,
            has_children,
            children,
        });
    }
    nodes.sort_by(|a, b| a.name.to_lowercase().cmp(&b.name.to_lowercase()));
    Ok(nodes)
}

fn browse(request: &Request) -> Result<serde_json::Value, String> {
    let (_, canonical_home, _) = validate_account(&request.username)?;
    let base = validate_user_path(&request.username, &request.base_path)?;
    let base = ensure_canonical_inside_home(&canonical_home, &base, "File manager root")?;
    let relative = request.path.as_deref().unwrap_or("").trim_matches('/');
    let requested = if relative.is_empty() {
        base.clone()
    } else {
        base.join(relative)
    };
    let directory = ensure_canonical_inside_home(&base, &requested, "Directory")?;
    if !directory.is_dir() {
        return Err("Requested path is not a directory.".into());
    }

    let show_hidden = request.show_hidden.unwrap_or(false);
    let mut items = Vec::new();
    for entry in fs::read_dir(&directory).map_err(|e| format!("Cannot read directory: {e}"))? {
        let entry = entry.map_err(|e| format!("Cannot read directory entry: {e}"))?;
        let name = entry.file_name().to_string_lossy().to_string();
        if !show_hidden && name.starts_with('.') {
            continue;
        }
        let metadata = fs::symlink_metadata(entry.path())
            .map_err(|e| format!("Cannot inspect {name}: {e}"))?;
        if metadata.file_type().is_symlink() {
            continue;
        }
        let is_dir = metadata.is_dir();
        let item_path = if relative.is_empty() {
            name.clone()
        } else {
            format!("{relative}/{name}")
        };
        let modified_at = metadata
            .modified()
            .ok()
            .and_then(|time| time.duration_since(UNIX_EPOCH).ok())
            .map(|duration| duration.as_secs());
        items.push(Item {
            name,
            path: item_path,
            kind: if is_dir { "dir".into() } else { "file".into() },
            size: if is_dir { None } else { Some(metadata.len()) },
            modified_at,
            permissions: format!("{:04o}", metadata.permissions().mode() & 0o7777),
        });
    }
    items.sort_by(|a, b| match (a.kind.as_str(), b.kind.as_str()) {
        ("dir", "file") => std::cmp::Ordering::Less,
        ("file", "dir") => std::cmp::Ordering::Greater,
        _ => a.name.to_lowercase().cmp(&b.name.to_lowercase()),
    });

    serde_json::to_value(serde_json::json!({
        "current_path": relative,
        "items": items,
        "directory_tree": directory_tree(&base, "", relative, show_hidden)?,
    }))
    .map_err(|e| e.to_string())
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match browse(&request) {
        Ok(data) => ApiResponse::ok_data("Directory listed", data).into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}
