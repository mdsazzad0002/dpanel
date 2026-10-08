//! Compose stacks: a docker-compose file run as one unit, so apps made of
//! several containers (WordPress + MySQL, Elasticsearch + Kibana) work from
//! one file. Stacks the panel made live in their own folder with a
//! `compose.yaml` and a `.env`; stacks started from the shell are listed too
//! and can be started, stopped and taken down by project name.

use serde_json::{Value, json};
use std::{
    fs,
    os::unix::fs::{DirBuilderExt, OpenOptionsExt},
    path::{Path, PathBuf},
    process::Command,
};

use super::{args, client, parse_json_lines, run, text};

const STACKS_DIR: &str = "/opt/dpanel/docker/stacks";
const COMPOSE_FILE: &str = "compose.yaml";
const ENV_FILE: &str = ".env";
const MAX_COMPOSE_BYTES: usize = 256 * 1024;
const MAX_ENV_BYTES: usize = 64 * 1024;
const MAX_LOG_LINES: u32 = 5000;

pub(crate) fn stacks_dir() -> PathBuf {
    std::env::var("DPANEL_DOCKER_STACKS_DIR")
        .map(PathBuf::from)
        .unwrap_or_else(|_| PathBuf::from(STACKS_DIR))
}

/// Whether the compose plugin is installed next to the docker CLI.
pub fn compose_available() -> bool {
    client().is_some_and(|docker| {
        Command::new(docker)
            .args(["compose", "version", "--short"])
            .output()
            .is_ok_and(|out| out.status.success())
    })
}

/// Compose project names: lowercase letters, digits, `-` and `_`.
pub(crate) fn validate_name(name: &str) -> Result<String, String> {
    let name = name.trim();
    let mut chars = name.chars();
    let valid = name.len() <= 63
        && chars.next().is_some_and(|c| c.is_ascii_lowercase() || c.is_ascii_digit())
        && chars.all(|c| c.is_ascii_lowercase() || c.is_ascii_digit() || matches!(c, '-' | '_'));
    if valid {
        Ok(name.to_string())
    } else {
        Err(format!("'{name}' is not a valid stack name; use lowercase letters, digits, '-' and '_'."))
    }
}

pub(crate) fn validate_service(service: &str) -> Result<String, String> {
    let service = service.trim();
    let mut chars = service.chars();
    let valid = service.len() <= 63
        && chars.next().is_some_and(|c| c.is_ascii_alphanumeric())
        && chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '-' | '_' | '.'));
    if valid {
        Ok(service.to_string())
    } else {
        Err(format!("'{service}' is not a valid service name."))
    }
}

fn stack_dir(name: &str) -> PathBuf {
    stacks_dir().join(name)
}

fn managed(name: &str) -> bool {
    stack_dir(name).join(COMPOSE_FILE).is_file()
}

/// `docker compose` with the project, and its files when the panel owns them.
fn compose(name: &str, dir: Option<&Path>) -> Vec<String> {
    let mut list = args(&["compose", "--project-name", name]);
    if let Some(dir) = dir {
        let dir_text = dir.to_string_lossy().to_string();
        list.extend([
            "--project-directory".to_string(),
            dir_text.clone(),
            "-f".into(),
            format!("{dir_text}/{COMPOSE_FILE}"),
            "--env-file".into(),
            format!("{dir_text}/{ENV_FILE}"),
        ]);
    }
    list
}

fn compose_for(name: &str) -> Vec<String> {
    let dir = stack_dir(name);
    compose(name, managed(name).then_some(dir.as_path()))
}

/// `running(2), exited(1)` → `{"running": 2, "exited": 1}`.
pub(crate) fn parse_status(status: &str) -> Value {
    let mut counts = serde_json::Map::new();
    for part in status.split(',') {
        let part = part.trim();
        if let Some((state, rest)) = part.split_once('(') {
            let count: i64 = rest.trim_end_matches(')').parse().unwrap_or(0);
            counts.insert(state.trim().to_string(), json!(count));
        }
    }
    Value::Object(counts)
}

/// `compose ps --format json` prints JSON lines on new versions and one
/// array on older ones.
fn parse_ps(output: &str) -> Vec<Value> {
    let trimmed = output.trim();
    if trimmed.starts_with('[') {
        serde_json::from_str(trimmed).unwrap_or_default()
    } else {
        parse_json_lines(trimmed)
    }
}

pub fn list() -> Result<Value, String> {
    if !compose_available() {
        return Ok(json!({ "compose": false, "dir": stacks_dir(), "stacks": [] }));
    }
    let projects: Vec<Value> = serde_json::from_str(&run(&args(&["compose", "ls", "-a", "--format", "json"]))?).unwrap_or_default();
    let mut names: Vec<String> = projects.iter().map(|p| text(p, "Name")).collect();
    if let Ok(entries) = fs::read_dir(stacks_dir()) {
        for entry in entries.flatten() {
            let name = entry.file_name().to_string_lossy().to_string();
            if validate_name(&name).is_ok() && managed(&name) && !names.contains(&name) {
                names.push(name);
            }
        }
    }
    names.sort();

    let stacks: Vec<Value> = names
        .iter()
        .map(|name| {
            let project = projects.iter().find(|p| text(p, "Name") == *name);
            let status = project.map(|p| text(p, "Status")).unwrap_or_default();
            let counts = parse_status(&status);
            let total: i64 = counts.as_object().map(|m| m.values().filter_map(Value::as_i64).sum()).unwrap_or(0);
            json!({
                "name": name,
                "managed": managed(name),
                "status": if status.is_empty() { "not deployed".to_string() } else { status },
                "counts": counts,
                "running": counts["running"].as_i64().unwrap_or(0),
                "total": total,
                "config_files": project.map(|p| text(p, "ConfigFiles")).unwrap_or_default(),
            })
        })
        .collect();
    Ok(json!({ "compose": true, "dir": stacks_dir(), "stacks": stacks }))
}

fn read_limited(path: &Path, limit: usize) -> String {
    match fs::metadata(path) {
        Ok(meta) if meta.len() as usize <= limit => fs::read_to_string(path).unwrap_or_default(),
        _ => String::new(),
    }
}

fn services(name: &str) -> Vec<Value> {
    let mut list = compose_for(name);
    list.extend(args(&["ps", "-a", "--format", "json"]));
    parse_ps(&run(&list).unwrap_or_default())
        .iter()
        .map(|raw| {
            let publishers: Vec<Value> = raw["Publishers"]
                .as_array()
                .into_iter()
                .flatten()
                .filter(|p| p["PublishedPort"].as_u64().unwrap_or(0) > 0)
                .map(|p| {
                    json!({
                        "host_ip": text(p, "URL"),
                        "published": p["PublishedPort"],
                        "target": p["TargetPort"],
                        "protocol": text(p, "Protocol"),
                    })
                })
                .collect();
            json!({
                "id": text(raw, "ID"),
                "name": text(raw, "Name"),
                "service": text(raw, "Service"),
                "image": text(raw, "Image"),
                "state": text(raw, "State"),
                "status": text(raw, "Status"),
                "health": text(raw, "Health"),
                "ports": text(raw, "Ports"),
                "publishers": publishers,
            })
        })
        .collect()
}

/// The stack's file, variables, containers and safety warnings.
pub fn show(name: &str) -> Result<Value, String> {
    let name = validate_name(name)?;
    let dir = stack_dir(&name);
    let is_managed = managed(&name);
    let (compose_text, env_text, config_files) = if is_managed {
        (read_limited(&dir.join(COMPOSE_FILE), MAX_COMPOSE_BYTES), read_limited(&dir.join(ENV_FILE), MAX_ENV_BYTES), String::new())
    } else {
        let projects: Vec<Value> = serde_json::from_str(&run(&args(&["compose", "ls", "-a", "--format", "json"]))?).unwrap_or_default();
        let project = projects.iter().find(|p| text(p, "Name") == name).ok_or(format!("Stack {name} was not found."))?;
        let files = text(project, "ConfigFiles");
        let first = files.split(',').next().unwrap_or("").trim().to_string();
        (if first.is_empty() { String::new() } else { read_limited(Path::new(&first), MAX_COMPOSE_BYTES) }, String::new(), files)
    };
    let checked = if is_managed { check(&name, &dir).ok() } else { None };
    Ok(json!({
        "name": name,
        "managed": is_managed,
        "dir": if is_managed { dir.to_string_lossy().to_string() } else { String::new() },
        "config_files": config_files,
        "compose": compose_text,
        "env": env_text,
        "services": services(&name),
        "warnings": checked.as_ref().map(|c| c["warnings"].clone()).unwrap_or(json!([])),
        "ports": checked.as_ref().map(|c| c["ports"].clone()).unwrap_or(json!([])),
        "declared_services": checked.as_ref().map(|c| c["services"].clone()).unwrap_or(json!([])),
    }))
}

/// Runs `compose config` on a stack folder: errors if the file is invalid,
/// otherwise lists its services, published ports and what deserves a warning.
fn check(name: &str, dir: &Path) -> Result<Value, String> {
    let mut list = compose(name, Some(dir));
    list.extend(args(&["config", "--format", "json"]));
    let output = run(&list).map_err(|e| e.replace(&format!("{}/", dir.to_string_lossy()), ""))?;
    let config: Value = serde_json::from_str(&output).map_err(|e| format!("Cannot read the compose file: {e}"))?;
    Ok(review(&config))
}

/// Services, published ports and warnings from a normalised compose config.
pub(crate) fn review(config: &Value) -> Value {
    let mut warnings: Vec<String> = Vec::new();
    let mut ports: Vec<Value> = Vec::new();
    let mut services: Vec<String> = Vec::new();
    for (service, def) in config["services"].as_object().into_iter().flatten() {
        services.push(service.clone());
        for port in def["ports"].as_array().into_iter().flatten() {
            let published = match &port["published"] {
                Value::String(s) => s.clone(),
                Value::Number(n) => n.to_string(),
                _ => String::new(),
            };
            if published.is_empty() {
                continue;
            }
            let host_ip = text(port, "host_ip");
            let public = host_ip.is_empty() || host_ip == "0.0.0.0" || host_ip == "::";
            if public {
                warnings.push(format!(
                    "Service '{service}' publishes port {published} to the internet. Docker bypasses the firewall; write it as \"127.0.0.1:{published}:{}\" to keep it local and reach it through a domain.",
                    port["target"]
                ));
            }
            ports.push(json!({
                "service": service,
                "published": published,
                "target": port["target"],
                "protocol": text(port, "protocol"),
                "host_ip": host_ip,
                "public": public,
            }));
        }
        if def["privileged"].as_bool() == Some(true) {
            warnings.push(format!("Service '{service}' runs privileged, which gives it full root access to the server."));
        }
        if def["network_mode"].as_str() == Some("host") {
            warnings.push(format!("Service '{service}' uses the host network, so every port it opens is on the server itself."));
        }
        for volume in def["volumes"].as_array().into_iter().flatten() {
            let source = text(volume, "source");
            if text(volume, "type") == "bind" && (source == "/var/run/docker.sock" || source == "/run/docker.sock") {
                warnings.push(format!("Service '{service}' mounts the Docker socket, so it can control every container on the server."));
            } else if text(volume, "type") == "bind" && (source == "/" || source == "/etc" || source == "/root") {
                warnings.push(format!("Service '{service}' mounts {source} from the server."));
            }
        }
    }
    json!({ "warnings": warnings, "ports": ports, "services": services })
}

fn write_private(path: &Path, content: &str) -> Result<(), String> {
    use std::io::Write;
    let mut file = fs::OpenOptions::new()
        .write(true)
        .create(true)
        .truncate(true)
        .mode(0o600)
        .open(path)
        .map_err(|e| format!("Cannot write {}: {e}", path.display()))?;
    file.write_all(content.as_bytes()).map_err(|e| format!("Cannot write {}: {e}", path.display()))
}

fn make_dir(path: &Path) -> Result<(), String> {
    fs::DirBuilder::new()
        .recursive(true)
        .mode(0o700)
        .create(path)
        .map_err(|e| format!("Cannot create {}: {e}", path.display()))
}

/// Saves a stack's compose file and variables after `compose config`
/// accepts them; a file it rejects never replaces a working one.
pub fn save(name: &str, compose_text: &str, env_text: &str, create: bool) -> Result<Value, String> {
    let name = validate_name(name)?;
    if compose_text.trim().is_empty() {
        return Err("The compose file is empty.".into());
    }
    if compose_text.len() > MAX_COMPOSE_BYTES || env_text.len() > MAX_ENV_BYTES {
        return Err("The compose file may be at most 256 KB and the variables 64 KB.".into());
    }
    if compose_text.contains('\0') || env_text.contains('\0') {
        return Err("The files cannot contain NUL bytes.".into());
    }
    let dir = stack_dir(&name);
    if create {
        if dir.exists() {
            return Err(format!("A stack named {name} already exists."));
        }
        let projects: Vec<Value> = serde_json::from_str(&run(&args(&["compose", "ls", "-a", "--format", "json"])).unwrap_or_default()).unwrap_or_default();
        if projects.iter().any(|p| text(p, "Name") == name) {
            return Err(format!("A stack named {name} is already running from outside the panel."));
        }
    } else if !managed(&name) {
        return Err(format!("Stack {name} is not managed by the panel."));
    }

    make_dir(&stacks_dir())?;
    let staging = stacks_dir().join(format!(".check-{name}"));
    let _ = fs::remove_dir_all(&staging);
    make_dir(&staging)?;
    let staged = (|| {
        write_private(&staging.join(COMPOSE_FILE), compose_text)?;
        write_private(&staging.join(ENV_FILE), env_text)?;
        check(&name, &staging)
    })();
    let _ = fs::remove_dir_all(&staging);
    let review = staged?;

    make_dir(&dir)?;
    write_private(&dir.join(COMPOSE_FILE), compose_text)?;
    write_private(&dir.join(ENV_FILE), env_text)?;
    Ok(review)
}

/// Runs one compose command on a stack, or on one of its services.
pub fn action(name: &str, action: &str, service: &str, remove_volumes: bool) -> Result<String, String> {
    let name = validate_name(name)?;
    let is_managed = managed(&name);
    let service = if service.trim().is_empty() { None } else { Some(validate_service(service)?) };
    let needs_file = matches!(action, "up" | "pull" | "update");
    if needs_file && !is_managed {
        return Err(format!("Stack {name} was started outside the panel; only start, stop, restart and down work on it."));
    }
    let base = compose_for(&name);
    let with = |extra: &[&str]| {
        let mut list = base.clone();
        list.extend(args(extra));
        if let Some(service) = &service {
            list.push(service.clone());
        }
        list
    };
    let target = service.as_deref().map(|s| format!("Service {s} of stack {name}")).unwrap_or(format!("Stack {name}"));

    match action {
        "up" => {
            run(&with(&["up", "-d", "--remove-orphans"]))?;
            Ok(format!("{target} is up."))
        }
        "pull" => {
            run(&with(&["pull"]))?;
            Ok(format!("Images for {} pulled. Deploy to use them.", target.to_lowercase()))
        }
        "update" => {
            run(&with(&["pull"]))?;
            run(&with(&["up", "-d", "--remove-orphans"]))?;
            Ok(format!("{target} now runs the newest images."))
        }
        "start" | "stop" | "restart" => {
            run(&with(&[action]))?;
            Ok(format!("{target} {}.", match action { "start" => "started", "stop" => "stopped", _ => "restarted" }))
        }
        "down" | "remove" => {
            if service.is_some() {
                return Err("Down and remove apply to the whole stack.".into());
            }
            let mut list = base.clone();
            list.extend(args(&["down", "--remove-orphans"]));
            if remove_volumes {
                list.push("--volumes".into());
            }
            run(&list)?;
            if action == "remove" && is_managed {
                fs::remove_dir_all(stack_dir(&name)).map_err(|e| format!("Stack stopped, but its folder could not be removed: {e}"))?;
                return Ok(format!("Stack {name} removed{}.", if remove_volumes { " with its volumes" } else { "; its volumes were kept" }));
            }
            Ok(format!("Stack {name} is down{}.", if remove_volumes { " and its volumes were removed" } else { "; its volumes were kept" }))
        }
        _ => Err("Unsupported stack action.".into()),
    }
}

pub fn logs(name: &str, service: &str, lines: u32) -> Result<Value, String> {
    let name = validate_name(name)?;
    let lines = lines.clamp(1, MAX_LOG_LINES);
    let mut list = compose_for(&name);
    list.extend(args(&["logs", "--no-color", "--timestamps", "--tail", &lines.to_string()]));
    if !service.trim().is_empty() {
        list.push(validate_service(service)?);
    }
    let logs = run(&list)?;
    Ok(json!({ "name": name, "service": service.trim(), "lines": lines, "logs": logs }))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn validates_stack_and_service_names() {
        assert!(validate_name("wordpress").is_ok());
        assert!(validate_name("es-kibana_2").is_ok());
        assert!(validate_name("WordPress").is_err());
        assert!(validate_name("-x").is_err());
        assert!(validate_name("../etc").is_err());
        assert!(validate_name("").is_err());
        assert!(validate_service("web.1").is_ok());
        assert!(validate_service("--all").is_err());
    }

    #[test]
    fn parses_compose_status() {
        let counts = parse_status("running(2), exited(1)");
        assert_eq!(counts["running"], 2);
        assert_eq!(counts["exited"], 1);
        assert_eq!(parse_status("").as_object().unwrap().len(), 0);
    }

    #[test]
    fn parses_both_ps_formats() {
        assert_eq!(parse_ps("[{\"Name\":\"a\"},{\"Name\":\"b\"}]").len(), 2);
        assert_eq!(parse_ps("{\"Name\":\"a\"}\n{\"Name\":\"b\"}\n").len(), 2);
    }

    #[test]
    fn warns_about_public_ports_and_privileges() {
        let config = json!({
            "services": {
                "web": { "ports": [
                    { "target": 80, "published": "8080", "protocol": "tcp" },
                    { "target": 81, "published": "8081", "host_ip": "127.0.0.1", "protocol": "tcp" },
                ] },
                "agent": {
                    "privileged": true,
                    "volumes": [{ "type": "bind", "source": "/var/run/docker.sock", "target": "/var/run/docker.sock" }],
                },
                "db": {},
            }
        });
        let review = review(&config);
        let warnings: Vec<String> = serde_json::from_value(review["warnings"].clone()).unwrap();
        assert_eq!(warnings.len(), 3);
        assert!(warnings.iter().any(|w| w.contains("8080")));
        assert!(!warnings.iter().any(|w| w.contains("8081")));
        assert_eq!(review["ports"].as_array().unwrap().len(), 2);
        assert_eq!(review["services"].as_array().unwrap().len(), 3);
    }
}
