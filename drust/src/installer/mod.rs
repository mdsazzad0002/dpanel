//! PHP application installers. Each app lives in its own module and only
//! knows its package, input checks and install steps; the shared work
//! (account/path checks, PHP version pinning, running tools as the site
//! owner, composer/npm, permissions) is in [`service`].
//!
//! The panel drives one action per request (`POST /api/v1/filemanager/laravel`,
//! kept for compatibility): LaravelInstallJob runs create_project → artisan →
//! breeze or npm_build → finalize; AppInstallJob runs create_project
//! (codeigniter, drupal) or the app's own *_install action, then finalize with
//! the matching stack. WordPress has its own download-based endpoint.

mod codeigniter;
mod drupal;
mod joomla;
mod laravel;
mod service;
mod whmcs;
pub(crate) mod wordpress;

use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};

use service::{Permissions, SHORT_TIMEOUT, Site};

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    path: String,
    php_version: String,
    action: String,
    stack: Option<String>,
    version: Option<String>,
    command: Option<String>,
    joomla: Option<joomla::JoomlaInstall>,
    whmcs: Option<whmcs::WhmcsInstall>,
    drupal: Option<drupal::DrupalInstall>,
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match execute(&request).await {
        Ok(output) => ApiResponse::ok_data(
            "Installer step completed",
            serde_json::json!({ "output": output }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

async fn execute(request: &Request) -> Result<String, String> {
    let site = Site::open(&request.username, &request.path, &request.php_version)?;
    let stack = request.stack.as_deref().unwrap_or("");
    let version = request.version.as_deref().unwrap_or("");

    match request.action.as_str() {
        "create_project" => {
            let (package, constraint, run_scripts) = match stack {
                "codeigniter" => {
                    let (package, constraint) = codeigniter::package();
                    (package, constraint, false)
                }
                "drupal" => {
                    let (package, constraint) = drupal::package(version)?;
                    (package, constraint, drupal::RUNS_COMPOSER_SCRIPTS)
                }
                _ => {
                    let (package, constraint) = laravel::package(stack, version)?;
                    (package, constraint, false)
                }
            };
            site.create_project(package, constraint, run_scripts).await
        }
        "artisan" => laravel::artisan(&site, request.command.as_deref().unwrap_or("")).await,
        "breeze" => laravel::breeze(&site, stack).await,
        "npm_build" => site.npm_build().await,
        "drupal_drush" => drupal::require_drush(&site, version).await,
        "drupal_install" => {
            let input = request
                .drupal
                .as_ref()
                .ok_or("Drupal install answers are missing.")?;
            drupal::site_install(&site, input).await
        }
        "joomla_install" => {
            let input = request
                .joomla
                .as_ref()
                .ok_or("Joomla install answers are missing.")?;
            joomla::install(&site, input).await
        }
        "whmcs_install" => {
            let input = request
                .whmcs
                .as_ref()
                .ok_or("WHMCS install values are missing.")?;
            whmcs::install(&site, input).await
        }
        "php_modules" => site.php(&["-m"], SHORT_TIMEOUT).await,
        "finalize" => site.finalize(permissions_for(request.stack.as_deref())),
        _ => Err("Unsupported installer action.".into()),
    }
}

fn permissions_for(stack: Option<&str>) -> &'static Permissions {
    match stack {
        Some("codeigniter") => &codeigniter::PERMISSIONS,
        Some("joomla") => &joomla::PERMISSIONS,
        Some("whmcs") => &whmcs::PERMISSIONS,
        Some("drupal") => &drupal::PERMISSIONS,
        _ => &laravel::PERMISSIONS,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn finalize_uses_each_apps_permissions() {
        assert_eq!(permissions_for(Some("codeigniter")).writable, ["writable"]);
        assert_eq!(
            permissions_for(Some("drupal")).secret_config,
            Some("web/sites/default/settings.php")
        );
        assert_eq!(permissions_for(Some("whmcs")).secret_config, Some("configuration.php"));
        assert_eq!(permissions_for(None).writable, ["storage", "bootstrap/cache"]);
        assert_eq!(permissions_for(Some("vue")).secret_config, None);
    }

    #[test]
    fn codeigniter_uses_appstarter() {
        assert_eq!(codeigniter::package(), ("codeigniter4/appstarter", "^4.0"));
    }
}
