//! `POST /api/v1/wordpress` (backlog W1, W2, W4): wp-cli as the site's
//! own user, limited to a fixed set of commands. Plugins and themes are not
//! loaded (`--skip-plugins --skip-themes`), so a broken plugin cannot stop
//! its own update, and nothing the site sends becomes a free-form argument.

use std::{path::Path, sync::Arc};

use axum::{
    Router,
    extract::{Json, State},
    response::{IntoResponse, Response},
    routing::post,
};
use serde::Deserialize;

use crate::api::{ApiResponse, ApiState, check_token};
use crate::installer::find_tool;
use crate::platform::paths::Tool;

pub fn routes() -> Router<Arc<ApiState>> {
    Router::new().route("/api/v1/wordpress", post(handle))
}

#[derive(Deserialize)]
struct Request {
    site_owner: String,
    root: String,
    action: String,
    #[serde(default)]
    php_version: Option<String>,
    /// Plugin or theme slugs; empty means all of them.
    #[serde(default)]
    names: Vec<String>,
    #[serde(default)]
    fix: Option<String>,
    /// `search_replace` only: the site address before and after a copy.
    #[serde(default)]
    from: Option<String>,
    #[serde(default)]
    to: Option<String>,
}

/// One wp-cli run; `may_fail` keeps going when it exits non-zero, because
/// the answer is in whether it failed (a checksum check, a missing constant).
#[derive(Debug, PartialEq, Eq)]
pub struct Step {
    pub key: &'static str,
    pub args: Vec<String>,
    pub may_fail: bool,
}

fn step(key: &'static str, args: &[&str], may_fail: bool) -> Step {
    Step { key, args: args.iter().map(|arg| arg.to_string()).collect(), may_fail }
}

fn valid_slug(slug: &str) -> bool {
    !slug.is_empty()
        && slug.len() <= 100
        && slug.chars().next().is_some_and(|c| c.is_ascii_alphanumeric())
        && slug.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.'))
        && !slug.contains("..")
}

fn update(kind: &'static str, key: &'static str, names: &[String]) -> Result<Vec<Step>, String> {
    if let Some(bad) = names.iter().find(|name| !valid_slug(name)) {
        return Err(format!("Invalid {kind} name: {bad}"));
    }
    let mut args = vec![kind.to_string(), "update".to_string()];
    if names.is_empty() {
        args.push("--all".to_string());
    } else {
        args.extend(names.iter().cloned());
    }
    Ok(vec![Step { key, args, may_fail: false }])
}

/// A site address: `http(s)://host[:port][/path]`, nothing a shell or SQL could read more into.
pub fn valid_site_url(url: &str) -> bool {
    let Some(rest) = url.strip_prefix("https://").or_else(|| url.strip_prefix("http://")) else { return false };
    let (host, path) = rest.split_once('/').map_or((rest, ""), |(host, path)| (host, path));
    let (name, port) = host.split_once(':').map_or((host, None), |(name, port)| (name, Some(port)));
    url.len() <= 255
        && !name.is_empty()
        && name.chars().all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '.')
        && port.is_none_or(|port| !port.is_empty() && port.len() <= 5 && port.chars().all(|c| c.is_ascii_digit()))
        && path.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.' | '~' | '/'))
}

fn search_replace(from: Option<&str>, to: Option<&str>) -> Result<Vec<Step>, String> {
    let (Some(from), Some(to)) = (from, to) else { return Err("Both addresses are needed.".into()) };
    let (from, to) = (from.trim_end_matches('/'), to.trim_end_matches('/'));
    if !valid_site_url(from) || !valid_site_url(to) {
        return Err("Invalid site address.".into());
    }
    if from == to {
        return Ok(vec![]);
    }
    Ok(vec![
        Step {
            key: "search_replace",
            args: ["search-replace", from, to, "--all-tables-with-prefix", "--skip-columns=guid", "--precise"].iter().map(|a| a.to_string()).collect(),
            may_fail: false,
        },
        step("cache", &["cache", "flush"], true),
    ])
}

/// The wp-cli runs an action is made of.
pub fn steps(action: &str, names: &[String], fix: Option<&str>) -> Result<Vec<Step>, String> {
    steps_with_urls(action, names, fix, None, None)
}

pub fn steps_with_urls(action: &str, names: &[String], fix: Option<&str>, from: Option<&str>, to: Option<&str>) -> Result<Vec<Step>, String> {
    let list_fields = "--fields=name,title,status,version,update,update_version";
    Ok(match action {
        "info" => vec![
            step("core_version", &["core", "version"], false),
            step("core_updates", &["core", "check-update", "--format=json"], true),
            step("plugins", &["plugin", "list", "--format=json", list_fields], false),
            step("themes", &["theme", "list", "--format=json", list_fields], false),
        ],
        "home_url" => vec![step("home", &["option", "get", "home"], false)],
        "search_replace" => search_replace(from, to)?,
        "update_core" => vec![step("core", &["core", "update"], false), step("database", &["core", "update-db"], false)],
        "update_plugins" => update("plugin", "plugins", names)?,
        "update_themes" => update("theme", "themes", names)?,
        "security" => vec![
            step("core_checksums", &["core", "verify-checksums"], true),
            step("plugin_checksums", &["plugin", "verify-checksums", "--all", "--format=json"], true),
            step("admins", &["user", "list", "--role=administrator", "--field=user_login"], true),
            step("registration", &["option", "get", "users_can_register"], true),
            step("debug", &["config", "get", "WP_DEBUG"], true),
            step("file_edit", &["config", "get", "DISALLOW_FILE_EDIT"], true),
            step("core_updates", &["core", "check-update", "--format=json"], true),
            step("plugins", &["plugin", "list", "--format=json", list_fields], true),
        ],
        "fix" => match fix {
            Some("disallow_file_edit") => vec![step("fix", &["config", "set", "DISALLOW_FILE_EDIT", "true", "--raw", "--type=constant"], false)],
            Some("disable_debug") => vec![step("fix", &["config", "set", "WP_DEBUG", "false", "--raw", "--type=constant"], false)],
            Some("close_registration") => vec![step("fix", &["option", "update", "users_can_register", "0"], false)],
            // Puts back changed core files: the same version, fresh, content untouched.
            Some("reinstall_core") => vec![step("fix", &["core", "download", "--force", "--skip-content"], false)],
            _ => return Err("Unknown fix.".into()),
        },
        _ => return Err("Unsupported WordPress action.".into()),
    })
}

async fn handle(State(state): State<Arc<ApiState>>, headers: axum::http::HeaderMap, Json(request): Json<Request>) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }
    match tokio::task::spawn_blocking(move || execute(&request)).await {
        Ok(Ok(results)) => ApiResponse::ok_data("Done.", results).into_response(),
        Ok(Err(error)) => ApiResponse::error(&error).into_response(),
        Err(error) => ApiResponse::error(&format!("wp-cli stopped: {error}")).into_response(),
    }
}

fn execute(request: &Request) -> Result<serde_json::Value, String> {
    let root = validate(request)?;
    let steps = steps_with_urls(&request.action, &request.names, request.fix.as_deref(), request.from.as_deref(), request.to.as_deref())?;
    let wp = find_tool("wp-cli", Tool::WpCli)?;
    let (_php_dir, path) = crate::php::cli::host_pin(request.php_version.as_deref());
    let mut results = serde_json::Map::new();
    for step in steps {
        let output = crate::platform::run_as(&request.site_owner, &wp)?
            .args(&step.args)
            .arg(format!("--path={}", root.display()))
            .args(["--skip-plugins", "--skip-themes", "--no-color"])
            .current_dir(&root)
            .env("HOME", crate::platform::paths().site_home(&request.site_owner))
            .env("PATH", &path)
            .output()
            .map_err(|error| format!("Unable to start wp-cli: {error}"))?;
        let stdout = last_chars(String::from_utf8_lossy(&output.stdout).trim(), 200_000);
        let stderr = last_chars(String::from_utf8_lossy(&output.stderr).trim(), 20_000);
        if !output.status.success() && !step.may_fail {
            return Err(if stderr.is_empty() { format!("wp-cli exited with {}.", output.status) } else { stderr });
        }
        results.insert(step.key.to_string(), serde_json::json!({ "ok": output.status.success(), "output": stdout, "error": stderr }));
    }
    Ok(serde_json::Value::Object(results))
}

fn last_chars(text: &str, limit: usize) -> String {
    let count = text.chars().count();
    text.chars().skip(count.saturating_sub(limit)).collect()
}

fn validate(request: &Request) -> Result<std::path::PathBuf, String> {
    let owner = &request.site_owner;
    let valid_owner = owner.len() <= 32
        && owner.chars().next().is_some_and(|c| c.is_ascii_lowercase() || c == '_')
        && owner.chars().all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || c == '_' || c == '-');
    if !valid_owner {
        return Err("Invalid website owner.".into());
    }
    let root = Path::new(&request.root);
    if !root.is_absolute()
        || !root.starts_with(crate::platform::paths().site_home(owner))
        || root.components().any(|part| matches!(part, std::path::Component::ParentDir))
    {
        return Err("Invalid WordPress folder.".into());
    }
    if !root.join("wp-includes").join("version.php").is_file() {
        return Err("No WordPress was found in this folder.".into());
    }
    Ok(root.to_path_buf())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn updates_take_only_slugs_and_no_names_means_all() {
        let all = steps("update_plugins", &[], None).unwrap();
        assert_eq!(all[0].args, vec!["plugin", "update", "--all"]);
        let some = steps("update_themes", &["twentytwentyfive".into(), "astra".into()], None).unwrap();
        assert_eq!(some[0].args, vec!["theme", "update", "twentytwentyfive", "astra"]);
        assert!(steps("update_plugins", &["--exec=system('id')".into()], None).is_err());
        assert!(steps("update_plugins", &["../x".into()], None).is_err());
        assert!(steps("update_plugins", &["a b".into()], None).is_err());
    }

    #[test]
    fn only_known_actions_and_fixes_run() {
        assert!(steps("eval", &[], None).is_err());
        assert!(steps("fix", &[], Some("rm")).is_err());
        assert!(steps("fix", &[], None).is_err());
        assert_eq!(steps("fix", &[], Some("close_registration")).unwrap()[0].args, vec!["option", "update", "users_can_register", "0"]);
    }

    #[test]
    fn a_core_update_also_updates_the_database() {
        let keys: Vec<_> = steps("update_core", &[], None).unwrap().iter().map(|s| s.key).collect();
        assert_eq!(keys, vec!["core", "database"]);
    }

    #[test]
    fn the_security_check_keeps_going_past_a_failed_check() {
        assert!(steps("security", &[], None).unwrap().iter().all(|s| s.may_fail));
    }

    #[test]
    fn a_folder_outside_the_owners_home_is_refused() {
        let request = |root: &str, owner: &str| Request {
            site_owner: owner.into(), root: root.into(), action: "info".into(), php_version: None, names: vec![], fix: None, from: None, to: None,
        };
        let home = crate::platform::paths().site_home("shop");
        assert!(validate(&request("/etc", "shop")).is_err());
        assert!(validate(&request(&format!("{}/../other", home.display()), "shop")).is_err());
        assert!(validate(&request(&home.display().to_string(), "Shop")).is_err());
        assert_eq!(validate(&request(&home.display().to_string(), "shop")).unwrap_err(), "No WordPress was found in this folder.");
    }

    #[test]
    fn search_replace_takes_only_plain_site_addresses() {
        let run = |from: &str, to: &str| steps_with_urls("search_replace", &[], None, Some(from), Some(to));
        let ok = run("https://shop.test/", "https://staging.shop.test").unwrap();
        assert_eq!(ok[0].args[..3], ["search-replace", "https://shop.test", "https://staging.shop.test"]);
        assert!(run("https://shop.test", "https://shop.test").unwrap().is_empty());
        assert!(run("https://shop.test", "https://x.test' OR 1=1").is_err());
        assert!(run("https://shop.test", "--dry-run").is_err());
        assert!(run("ftp://shop.test", "https://x.test").is_err());
        assert!(run("https://shop.test:8443/blog", "http://localhost:8080/blog").is_ok());
        assert!(steps_with_urls("search_replace", &[], None, Some("https://a.test"), None).is_err());
    }
}
