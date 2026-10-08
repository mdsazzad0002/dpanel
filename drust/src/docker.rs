//! Docker containers and images for the panel's Docker manager.
//!
//! Only the docker CLI runs here, always with an argument list and never a
//! shell. Every name, image, port, variable and mount is validated first, and
//! nothing user-supplied may start with `-`, so it cannot be read as a flag.
//! Published ports bind to 127.0.0.1 unless the panel asks for a public port:
//! Docker writes its own iptables rules, so ufw does not guard them.

pub mod inspect;
pub mod network;
pub mod stack;
pub mod system;
pub mod volume;

use serde::{Deserialize, Serialize};
use serde_json::{Value, json};
use std::{path::Path, process::Command};

const CLIENT_CANDIDATES: &[&str] = &["/usr/bin/docker", "/usr/local/bin/docker"];
const RESTART_POLICIES: &[&str] = &["no", "always", "unless-stopped", "on-failure"];
pub const DEFAULT_LOG_LINES: u32 = 200;
const MAX_LOG_LINES: u32 = 5000;
const MAX_PORTS: usize = 20;
const MAX_ENV: usize = 100;
const MAX_VOLUMES: usize = 20;
const MAX_ALIASES: usize = 10;
const MAX_COMMAND_ARGS: usize = 64;
const MAX_CPUS: f64 = 256.0;

#[derive(Deserialize, Serialize, Default, Debug, PartialEq)]
pub struct PortSpec {
    pub host: u32,
    pub container: u32,
    #[serde(default)]
    pub protocol: String,
    #[serde(default)]
    pub public: bool,
}

#[derive(Deserialize, Serialize, Default, Debug, PartialEq)]
pub struct EnvSpec {
    pub key: String,
    #[serde(default)]
    pub value: String,
}

#[derive(Deserialize, Serialize, Default, Debug, PartialEq)]
pub struct VolumeSpec {
    pub source: String,
    pub target: String,
    #[serde(default)]
    pub read_only: bool,
}

#[derive(Deserialize, Serialize, Default, Debug, PartialEq)]
pub struct RunSpec {
    pub image: String,
    #[serde(default)]
    pub name: String,
    #[serde(default)]
    pub restart: String,
    #[serde(default)]
    pub ports: Vec<PortSpec>,
    #[serde(default)]
    pub env: Vec<EnvSpec>,
    #[serde(default)]
    pub volumes: Vec<VolumeSpec>,
    /// A user-defined network to join; containers on it reach each other by name.
    #[serde(default)]
    pub network: String,
    /// Extra names this container answers to on `network`.
    #[serde(default)]
    pub aliases: Vec<String>,
    #[serde(default)]
    pub hostname: String,
    /// Memory limit such as `512m` or `2g`; empty means no limit.
    #[serde(default)]
    pub memory: String,
    /// CPU limit such as `0.5` or `2`; empty means no limit.
    #[serde(default)]
    pub cpus: String,
    #[serde(default)]
    pub entrypoint: String,
    /// Arguments passed to the image after its name, replacing its default command.
    #[serde(default)]
    pub command: Vec<String>,
    /// Pull the image again before running, so `:latest` really is the latest.
    #[serde(default)]
    pub pull: bool,
}

pub(crate) fn client() -> Option<&'static str> {
    CLIENT_CANDIDATES
        .iter()
        .copied()
        .find(|path| Path::new(path).is_file())
}

pub(crate) fn run(args: &[String]) -> Result<String, String> {
    let client = client().ok_or("Docker is not installed on this server.")?;
    let output = Command::new(client)
        .args(args)
        .output()
        .map_err(|e| format!("Cannot run docker: {e}"))?;
    let stdout = String::from_utf8_lossy(&output.stdout).trim().to_string();
    if output.status.success() {
        Ok(stdout)
    } else {
        let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
        Err(if stderr.is_empty() { stdout } else { stderr })
    }
}

pub(crate) fn args(list: &[&str]) -> Vec<String> {
    list.iter().map(|arg| arg.to_string()).collect()
}

/// A container ID or name: `[A-Za-z0-9][A-Za-z0-9_.-]*`, as Docker allows.
pub(crate) fn validate_container(value: &str) -> Result<String, String> {
    let value = value.trim();
    let mut chars = value.chars();
    let valid = value.len() <= 128
        && chars.next().is_some_and(|c| c.is_ascii_alphanumeric())
        && chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '.' | '-'));
    if valid {
        Ok(value.to_string())
    } else {
        Err(format!("'{value}' is not a valid container name or ID."))
    }
}

/// An image reference such as `nginx`, `ghcr.io/org/app:1.2` or `sha256:…`.
pub(crate) fn validate_image(value: &str) -> Result<String, String> {
    let value = value.trim();
    let mut chars = value.chars();
    let valid = value.len() <= 255
        && chars.next().is_some_and(|c| c.is_ascii_alphanumeric())
        && chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '.' | '-' | '/' | ':' | '@'));
    if valid {
        Ok(value.to_string())
    } else {
        Err(format!("'{value}' is not a valid image name."))
    }
}

pub(crate) fn validate_port(port: u32, label: &str) -> Result<u16, String> {
    u16::try_from(port)
        .ok()
        .filter(|port| *port > 0)
        .ok_or(format!("{label} port {port} must be between 1 and 65535."))
}

fn validate_env_key(key: &str) -> Result<String, String> {
    let key = key.trim();
    let mut chars = key.chars();
    // Dots and dashes too: Elasticsearch documents `discovery.type=single-node`.
    let valid = key.len() <= 255
        && chars.next().is_some_and(|c| c.is_ascii_alphabetic() || c == '_')
        && chars.all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '.' | '-'));
    if valid {
        Ok(key.to_string())
    } else {
        Err(format!("'{key}' is not a valid environment variable name."))
    }
}

/// A mount source is a named volume or an absolute host path; `:` and `,`
/// would change how Docker splits the `-v` value.
fn validate_mount_source(source: &str) -> Result<String, String> {
    let source = source.trim();
    let invalid = || format!("'{source}' must be a volume name or an absolute host path.");
    if source.contains([':', ',', '\0']) || source.split('/').any(|part| part == "..") {
        return Err(invalid());
    }
    if source.starts_with('/') {
        return Ok(source.to_string());
    }
    validate_container(source).map_err(|_| invalid())
}

fn validate_mount_target(target: &str) -> Result<String, String> {
    let target = target.trim();
    if !target.starts_with('/') || target.contains([':', ',', '\0']) || target.split('/').any(|part| part == "..") {
        return Err(format!("'{target}' must be an absolute path inside the container."));
    }
    Ok(target.to_string())
}

/// The `docker run` arguments for a validated spec.
pub(crate) fn run_args(spec: &RunSpec) -> Result<Vec<String>, String> {
    let image = validate_image(&spec.image)?;
    if spec.ports.len() > MAX_PORTS || spec.env.len() > MAX_ENV || spec.volumes.len() > MAX_VOLUMES {
        return Err(format!(
            "Use at most {MAX_PORTS} ports, {MAX_ENV} environment variables and {MAX_VOLUMES} volumes."
        ));
    }
    let restart = if spec.restart.trim().is_empty() { "unless-stopped" } else { spec.restart.trim() };
    if !RESTART_POLICIES.contains(&restart) {
        return Err(format!("Unsupported restart policy '{restart}'."));
    }

    let mut list = args(&["run", "-d", "--restart", restart]);
    if !spec.name.trim().is_empty() {
        list.push("--name".into());
        list.push(validate_container(&spec.name)?);
    }
    for port in &spec.ports {
        let host = validate_port(port.host, "Host")?;
        let container = validate_port(port.container, "Container")?;
        let protocol = match port.protocol.trim() {
            "" | "tcp" => "tcp",
            "udp" => "udp",
            other => return Err(format!("Unsupported protocol '{other}'.")),
        };
        let bind = if port.public { String::new() } else { "127.0.0.1:".to_string() };
        list.push("-p".into());
        list.push(format!("{bind}{host}:{container}/{protocol}"));
    }
    for env in &spec.env {
        if env.value.contains('\0') {
            return Err("Environment values cannot contain NUL bytes.".into());
        }
        list.push("-e".into());
        list.push(format!("{}={}", validate_env_key(&env.key)?, env.value));
    }
    for volume in &spec.volumes {
        let mode = if volume.read_only { ":ro" } else { "" };
        list.push("-v".into());
        list.push(format!(
            "{}:{}{mode}",
            validate_mount_source(&volume.source)?,
            validate_mount_target(&volume.target)?
        ));
    }
    let network = spec.network.trim();
    if !network.is_empty() {
        list.push("--network".into());
        list.push(validate_container(network).map_err(|_| format!("'{network}' is not a valid network name."))?);
        if spec.aliases.len() > MAX_ALIASES {
            return Err(format!("Use at most {MAX_ALIASES} network aliases."));
        }
        for alias in spec.aliases.iter().map(|a| a.trim()).filter(|a| !a.is_empty()) {
            list.push("--network-alias".into());
            list.push(validate_container(alias).map_err(|_| format!("'{alias}' is not a valid network alias."))?);
        }
    } else if spec.aliases.iter().any(|a| !a.trim().is_empty()) {
        return Err("Network aliases need a network; the default bridge has none.".into());
    }
    if !spec.hostname.trim().is_empty() {
        list.push("--hostname".into());
        list.push(validate_container(&spec.hostname).map_err(|_| format!("'{}' is not a valid hostname.", spec.hostname.trim()))?);
    }
    if !spec.memory.trim().is_empty() {
        list.push("--memory".into());
        list.push(validate_memory(&spec.memory)?);
    }
    if !spec.cpus.trim().is_empty() {
        list.push("--cpus".into());
        list.push(validate_cpus(&spec.cpus)?);
    }
    if !spec.entrypoint.trim().is_empty() {
        let entrypoint = spec.entrypoint.trim();
        if entrypoint.contains('\0') {
            return Err("The entrypoint cannot contain NUL bytes.".into());
        }
        // Joined with `=`, so a value starting with `-` is still the value.
        list.push(format!("--entrypoint={entrypoint}"));
    }
    if spec.pull {
        list.push("--pull".into());
        list.push("always".into());
    }
    if spec.command.len() > MAX_COMMAND_ARGS {
        return Err(format!("Use at most {MAX_COMMAND_ARGS} command arguments."));
    }
    if spec.command.iter().any(|arg| arg.contains('\0')) {
        return Err("Command arguments cannot contain NUL bytes.".into());
    }
    list.push("--".into());
    list.push(image);
    // After the image every argument belongs to the container, never to docker.
    list.extend(spec.command.iter().cloned());
    Ok(list)
}

/// `512m`, `1.5g`, `1048576`: a number with an optional b/k/m/g unit.
pub(crate) fn validate_memory(value: &str) -> Result<String, String> {
    let value = value.trim().to_ascii_lowercase();
    let digits = value.trim_end_matches(['b', 'k', 'm', 'g']);
    let unit_len = value.len() - digits.len();
    let valid = unit_len <= 1
        && !digits.is_empty()
        && digits.parse::<f64>().is_ok_and(|n| n > 0.0 && n.is_finite())
        && digits.chars().all(|c| c.is_ascii_digit() || c == '.');
    if valid {
        Ok(value)
    } else {
        Err(format!("'{value}' is not a valid memory limit; use a value such as 512m or 2g."))
    }
}

pub(crate) fn validate_cpus(value: &str) -> Result<String, String> {
    let value = value.trim();
    match value.parse::<f64>() {
        Ok(n) if n > 0.0 && n <= MAX_CPUS && value.chars().all(|c| c.is_ascii_digit() || c == '.') => Ok(value.to_string()),
        _ => Err(format!("'{value}' is not a valid CPU limit; use a value such as 0.5 or 2.")),
    }
}

/// `docker … --format '{{json .}}'` prints one JSON object per line.
pub(crate) fn parse_json_lines(text: &str) -> Vec<Value> {
    text.lines()
        .filter_map(|line| serde_json::from_str::<Value>(line.trim()).ok())
        .collect()
}

pub(crate) fn text(value: &Value, key: &str) -> String {
    value.get(key).and_then(Value::as_str).unwrap_or("").to_string()
}

/// One label's value from `ps`'s `a=b,c=d` label list.
fn label(labels: &str, key: &str) -> String {
    labels
        .split(',')
        .find_map(|pair| pair.strip_prefix(key).and_then(|rest| rest.strip_prefix('=')))
        .unwrap_or("")
        .to_string()
}

fn list_field(value: &Value, key: &str) -> Vec<String> {
    text(value, key)
        .split(',')
        .map(str::trim)
        .filter(|item| !item.is_empty())
        .map(str::to_string)
        .collect()
}

pub(crate) fn container_row(raw: &Value) -> Value {
    let labels = text(raw, "Labels");
    json!({
        "id": text(raw, "ID"),
        "name": text(raw, "Names"),
        "image": text(raw, "Image"),
        "state": text(raw, "State"),
        "status": text(raw, "Status"),
        "ports": text(raw, "Ports"),
        "created": text(raw, "RunningFor"),
        "command": text(raw, "Command").trim_matches('"').to_string(),
        "networks": list_field(raw, "Networks"),
        "mounts": list_field(raw, "Mounts"),
        // Containers a compose stack made carry its project and service name.
        "project": label(&labels, "com.docker.compose.project"),
        "service": label(&labels, "com.docker.compose.service"),
    })
}

pub(crate) fn image_row(raw: &Value) -> Value {
    json!({
        "id": text(raw, "ID"),
        "repository": text(raw, "Repository"),
        "tag": text(raw, "Tag"),
        "size": text(raw, "Size"),
        "created": text(raw, "CreatedSince"),
    })
}

pub fn status() -> Result<Value, String> {
    if client().is_none() {
        return Ok(json!({ "installed": false, "running": false, "version": null, "compose": false, "containers": [], "images": [], "networks": [] }));
    }
    let Ok(version) = run(&args(&["version", "--format", "{{.Server.Version}}"])) else {
        return Ok(json!({ "installed": true, "running": false, "version": null, "compose": false, "containers": [], "images": [], "networks": [] }));
    };
    let containers = run(&args(&["ps", "-a", "--format", "{{json .}}"]))?;
    let images = run(&args(&["images", "--format", "{{json .}}"]))?;
    // Names only, for the run form's network picker; the Networks page has the detail.
    let networks = run(&args(&["network", "ls", "--format", "{{.Name}}"])).unwrap_or_default();
    Ok(json!({
        "installed": true,
        "running": true,
        "version": version,
        "compose": stack::compose_available(),
        "containers": parse_json_lines(&containers).iter().map(container_row).collect::<Vec<_>>(),
        "images": parse_json_lines(&images).iter().map(image_row).collect::<Vec<_>>(),
        "networks": networks.lines().map(str::trim).filter(|n| !n.is_empty()).collect::<Vec<_>>(),
    }))
}

pub fn container_action(action: &str, id: &str) -> Result<String, String> {
    let id = validate_container(id)?;
    let (command, done): (&[&str], &str) = match action {
        "start" => (&["start"], "started"),
        "stop" => (&["stop"], "stopped"),
        "restart" => (&["restart"], "restarted"),
        "remove" => (&["rm", "-f"], "removed"),
        "pause" => (&["pause"], "paused"),
        "unpause" => (&["unpause"], "resumed"),
        "kill" => (&["kill"], "killed"),
        _ => return Err("Unsupported container action.".into()),
    };
    let mut list = args(command);
    list.push("--".into());
    list.push(id.clone());
    run(&list)?;
    Ok(format!("Container {id} {done}."))
}

pub fn rename(id: &str, name: &str) -> Result<String, String> {
    let id = validate_container(id)?;
    let name = validate_container(name)?;
    run(&args(&["rename", "--", &id, &name]))?;
    Ok(format!("Container renamed to {name}."))
}

pub fn run_container(spec: &RunSpec) -> Result<String, String> {
    let id = run(&run_args(spec)?)?;
    let short: String = id.chars().take(12).collect();
    Ok(format!("Container {short} is running {}.", spec.image.trim()))
}

pub fn logs(id: &str, lines: u32) -> Result<Value, String> {
    let id = validate_container(id)?;
    let lines = lines.clamp(1, MAX_LOG_LINES);
    let client = client().ok_or("Docker is not installed on this server.")?;
    // A container's stderr comes back on docker's stderr, so both are logs here.
    let output = Command::new(client)
        .args(["logs", "--timestamps", "--tail", &lines.to_string(), "--", &id])
        .output()
        .map_err(|e| format!("Cannot run docker: {e}"))?;
    let stderr = String::from_utf8_lossy(&output.stderr).to_string();
    if !output.status.success() {
        return Err(stderr.trim().to_string());
    }
    let stdout = String::from_utf8_lossy(&output.stdout).to_string();
    // Every line starts with its RFC 3339 timestamp, so sorting merges the two streams in order.
    let mut all: Vec<&str> = stdout.lines().chain(stderr.lines()).collect();
    all.sort();
    Ok(json!({ "id": id, "lines": lines, "logs": all.join("\n") }))
}

pub fn pull(image: &str) -> Result<String, String> {
    let image = validate_image(image)?;
    run(&args(&["pull", "--", &image]))?;
    Ok(format!("Image {image} pulled."))
}

pub fn remove_image(image: &str, force: bool) -> Result<String, String> {
    let image = validate_image(image)?;
    let mut list = args(if force { &["rmi", "-f"] } else { &["rmi"] });
    list.push("--".into());
    list.push(image.clone());
    run(&list)?;
    Ok(format!("Image {image} removed."))
}

/// `all` also removes tagged images no container uses, not just dangling layers.
pub fn prune_images(all: bool) -> Result<String, String> {
    let output = run(&args(if all { &["image", "prune", "-a", "-f"] } else { &["image", "prune", "-f"] }))?;
    let reclaimed = reclaimed(&output);
    Ok(if all {
        format!("Images no container uses were removed; {reclaimed} freed.")
    } else {
        format!("Unused image layers removed; {reclaimed} freed.")
    })
}

/// The "Total reclaimed space: 1.2GB" line every prune prints.
pub(crate) fn reclaimed(output: &str) -> String {
    output
        .lines()
        .find_map(|line| line.strip_prefix("Total reclaimed space:"))
        .map(|size| size.trim().to_string())
        .unwrap_or_else(|| "0B".to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn validates_container_names() {
        assert_eq!(validate_container(" web-1 ").unwrap(), "web-1");
        assert!(validate_container("3f2a9c1b0d4e").is_ok());
        assert!(validate_container("-f").is_err());
        assert!(validate_container("a b").is_err());
        assert!(validate_container("x;rm").is_err());
        assert!(validate_container("").is_err());
    }

    #[test]
    fn validates_images() {
        assert!(validate_image("nginx").is_ok());
        assert!(validate_image("ghcr.io/org/app:1.2").is_ok());
        assert!(validate_image("redis@sha256:abc123").is_ok());
        assert!(validate_image("--privileged").is_err());
        assert!(validate_image("nginx latest").is_err());
        assert!(validate_image("$(id)").is_err());
    }

    #[test]
    fn builds_run_arguments() {
        let spec = RunSpec {
            image: "nginx:alpine".into(),
            name: "web".into(),
            restart: String::new(),
            ports: vec![
                PortSpec { host: 8080, container: 80, protocol: String::new(), public: false },
                PortSpec { host: 5353, container: 53, protocol: "udp".into(), public: true },
            ],
            env: vec![EnvSpec { key: "MODE".into(), value: "a=b c".into() }],
            volumes: vec![
                VolumeSpec { source: "data".into(), target: "/data".into(), read_only: false },
                VolumeSpec { source: "/srv/site".into(), target: "/usr/share/nginx/html".into(), read_only: true },
            ],
            ..Default::default()
        };
        assert_eq!(
            run_args(&spec).unwrap(),
            vec![
                "run", "-d", "--restart", "unless-stopped", "--name", "web",
                "-p", "127.0.0.1:8080:80/tcp", "-p", "5353:53/udp",
                "-e", "MODE=a=b c",
                "-v", "data:/data", "-v", "/srv/site:/usr/share/nginx/html:ro",
                "--", "nginx:alpine",
            ]
        );
    }

    #[test]
    fn builds_advanced_run_arguments() {
        let spec = RunSpec {
            image: "elasticsearch:8.15.0".into(),
            name: "es".into(),
            restart: "always".into(),
            network: "search".into(),
            aliases: vec!["elasticsearch".into(), " ".into()],
            hostname: "es1".into(),
            memory: "1G".into(),
            cpus: "1.5".into(),
            entrypoint: "-weird".into(),
            command: vec!["--flag".into(), "a b".into()],
            pull: true,
            ..Default::default()
        };
        assert_eq!(
            run_args(&spec).unwrap(),
            vec![
                "run", "-d", "--restart", "always", "--name", "es",
                "--network", "search", "--network-alias", "elasticsearch",
                "--hostname", "es1", "--memory", "1g", "--cpus", "1.5",
                "--entrypoint=-weird", "--pull", "always",
                "--", "elasticsearch:8.15.0", "--flag", "a b",
            ]
        );
    }

    #[test]
    fn rejects_bad_limits_and_aliases() {
        let base = || RunSpec { image: "nginx".into(), ..Default::default() };
        assert!(run_args(&RunSpec { memory: "lots".into(), ..base() }).is_err());
        assert!(run_args(&RunSpec { memory: "1gb".into(), ..base() }).is_err());
        assert!(run_args(&RunSpec { memory: "-1m".into(), ..base() }).is_err());
        assert!(run_args(&RunSpec { cpus: "0".into(), ..base() }).is_err());
        assert!(run_args(&RunSpec { cpus: "1e3".into(), ..base() }).is_err());
        assert!(run_args(&RunSpec { aliases: vec!["db".into()], ..base() }).is_err());
        assert!(run_args(&RunSpec { network: "--host".into(), ..base() }).is_err());
        assert!(run_args(&RunSpec { command: vec!["a\0b".into()], ..base() }).is_err());
        assert!(validate_memory("512m").is_ok());
        assert!(validate_env_key("discovery.type").is_ok());
        assert!(validate_env_key("A=B").is_err());
        assert!(validate_env_key("-x").is_err());
        assert!(validate_memory("1.5g").is_ok());
        assert!(validate_cpus("0.25").is_ok());
    }

    #[test]
    fn reads_compose_labels_from_ps() {
        let ps = "{\"ID\":\"1\",\"Names\":\"wp-db-1\",\"Labels\":\"com.docker.compose.project=wp,com.docker.compose.service=db\",\"Networks\":\"wp_default,shared\",\"Mounts\":\"wp_db\"}";
        let row = container_row(&parse_json_lines(ps)[0]);
        assert_eq!(row["project"], "wp");
        assert_eq!(row["service"], "db");
        assert_eq!(row["networks"], json!(["wp_default", "shared"]));
        assert_eq!(row["mounts"], json!(["wp_db"]));
    }

    #[test]
    fn rejects_unsafe_run_specs() {
        let base = || RunSpec { image: "nginx".into(), ..Default::default() };
        assert!(run_args(&RunSpec { restart: "sometimes".into(), ..base() }).is_err());
        assert!(run_args(&RunSpec { ports: vec![PortSpec { host: 70000, container: 80, ..Default::default() }], ..base() }).is_err());
        assert!(run_args(&RunSpec { ports: vec![PortSpec { host: 0, container: 80, ..Default::default() }], ..base() }).is_err());
        assert!(run_args(&RunSpec { env: vec![EnvSpec { key: "1BAD".into(), value: String::new() }], ..base() }).is_err());
        let mount = |source: &str, target: &str| RunSpec {
            volumes: vec![VolumeSpec { source: source.into(), target: target.into(), read_only: false }],
            ..base()
        };
        assert!(run_args(&mount("/etc:/x", "/y")).is_err());
        assert!(run_args(&mount("/srv/../etc", "/y")).is_err());
        assert!(run_args(&mount("relative/path", "/y")).is_err());
        assert!(run_args(&mount("data", "relative")).is_err());
    }

    #[test]
    fn parses_ps_and_images_output() {
        let ps = "{\"ID\":\"3f2a\",\"Names\":\"web\",\"Image\":\"nginx\",\"State\":\"running\",\"Status\":\"Up 2 hours\",\"Ports\":\"127.0.0.1:8080->80/tcp\",\"RunningFor\":\"2 hours ago\"}\nnot json\n";
        let rows: Vec<Value> = parse_json_lines(ps).iter().map(container_row).collect();
        assert_eq!(rows.len(), 1);
        assert_eq!(rows[0]["name"], "web");
        assert_eq!(rows[0]["state"], "running");
        assert_eq!(rows[0]["ports"], "127.0.0.1:8080->80/tcp");

        let images = "{\"ID\":\"a1b2\",\"Repository\":\"nginx\",\"Tag\":\"alpine\",\"Size\":\"43MB\",\"CreatedSince\":\"3 weeks ago\"}\n";
        let image = image_row(&parse_json_lines(images)[0]);
        assert_eq!(image["repository"], "nginx");
        assert_eq!(image["tag"], "alpine");
    }
}
