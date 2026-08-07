use std::{path::Path, process::Command, sync::Arc};

use axum::{
    Router,
    extract::{Json, State},
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/project-dependencies", post(handle))
}

#[derive(Deserialize)]
struct Request {
    site_owner: String,
    project_root: String,
    action: String,
}

async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: axum::http::HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match execute(&request) {
        Ok(output) => ApiResponse::ok_data(
            "Dependencies installed.",
            serde_json::json!({"output": output}),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&error).into_response(),
    }
}

fn execute(request: &Request) -> Result<String, String> {
    validate(request)?;
    let root = Path::new(&request.project_root);
    if request.action == "npm_install" {
        let install_args = if root.join("package-lock.json").is_file() {
            vec!["ci"]
        } else {
            vec!["install"]
        };
        let install_output = run_as_site_owner(request, root, "npm", &install_args)?;
        let build_output = run_as_site_owner(request, root, "npm", &["run", "build"])?;
        return Ok(format!("{install_output}\n\n{build_output}"));
    }
    let (program, args): (&str, Vec<&str>) = match request.action.as_str() {
        "composer_install" => (
            "composer",
            vec![
                "install",
                "--no-interaction",
                "--prefer-dist",
                "--optimize-autoloader",
            ],
        ),
        "npm_build" => ("npm", vec!["run", "build"]),
        _ => return Err("Unsupported dependency action.".into()),
    };
    run_as_site_owner(request, root, program, &args)
}

fn run_as_site_owner(
    request: &Request,
    root: &Path,
    program: &str,
    args: &[&str],
) -> Result<String, String> {
    let output = Command::new("runuser")
        .args(["-u", &request.site_owner, "--", program])
        .args(args)
        .current_dir(root)
        .env("HOME", format!("/home/{}", request.site_owner))
        .env("COMPOSER_NO_INTERACTION", "1")
        .output()
        .map_err(|error| format!("Unable to start {program}: {error}"))?;
    let combined = format!(
        "{}{}",
        String::from_utf8_lossy(&output.stdout),
        String::from_utf8_lossy(&output.stderr)
    );
    let trimmed = combined.trim();
    let safe_output = if trimmed.chars().count() > 20_000 {
        trimmed
            .chars()
            .rev()
            .take(20_000)
            .collect::<String>()
            .chars()
            .rev()
            .collect::<String>()
    } else {
        trimmed.to_string()
    };
    if output.status.success() {
        Ok(if safe_output.is_empty() {
            "Installation completed successfully.".into()
        } else {
            safe_output
        })
    } else {
        Err(if safe_output.is_empty() {
            format!("{program} exited with {}.", output.status)
        } else {
            safe_output
        })
    }
}

fn validate(request: &Request) -> Result<(), String> {
    let valid_owner = request
        .site_owner
        .chars()
        .enumerate()
        .all(|(index, value)| {
            value.is_ascii_lowercase()
                || value.is_ascii_digit()
                || value == '_'
                || (value == '-' && index > 0)
        });
    if request.site_owner.is_empty() || !valid_owner {
        return Err("Invalid website owner.".into());
    }
    let root = Path::new(&request.project_root);
    if !root.starts_with(format!("/home/{}/", request.site_owner))
        || root
            .components()
            .any(|part| matches!(part, std::path::Component::ParentDir))
        || !root.is_dir()
    {
        return Err("Invalid project root.".into());
    }
    let manifest = if request.action == "composer_install" {
        "composer.json"
    } else {
        "package.json"
    };
    if !root.join(manifest).is_file() {
        return Err(format!("{manifest} was not found."));
    }
    Ok(())
}
