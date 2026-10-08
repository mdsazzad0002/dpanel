//! One container in depth: its settings, live usage, a one-shot console, and
//! recreating it with changed settings (Docker cannot change most of them in
//! place). `inspect` also rebuilds the `RunSpec` that would create the same
//! container, so the panel can offer "edit and recreate", "duplicate" and
//! "update image" without having stored how it was first run.

use serde_json::{Value, json};
use std::process::Command;

use super::{EnvSpec, PortSpec, RunSpec, VolumeSpec, args, client, parse_json_lines, run, run_args, text, validate_container};

const EXEC_TIMEOUT_SECS: u32 = 60;
const MAX_EXEC_OUTPUT: usize = 256 * 1024;
const MAX_EXEC_COMMAND: usize = 8192;
const DEFAULT_NETWORKS: &[&str] = &["bridge", "host", "none"];

fn inspect_raw(kind: &str, id: &str) -> Result<Value, String> {
    let output = run(&args(&[kind, "inspect", "--", id]))?;
    let mut list: Vec<Value> = serde_json::from_str(&output).map_err(|e| format!("Cannot read docker inspect: {e}"))?;
    if list.is_empty() {
        return Err(format!("'{id}' was not found."));
    }
    Ok(list.remove(0))
}

fn strings(value: &Value) -> Vec<String> {
    value
        .as_array()
        .map(|items| items.iter().filter_map(Value::as_str).map(str::to_string).collect())
        .unwrap_or_default()
}

/// Settings and state of one container, plus the spec that would recreate it.
pub fn inspect(id: &str) -> Result<Value, String> {
    let id = validate_container(id)?;
    let raw = inspect_raw("container", &id)?;
    let image_config = inspect_raw("image", raw["Image"].as_str().unwrap_or(""))
        .map(|image| image["Config"].clone())
        .unwrap_or(Value::Null);
    let spec = rebuild_spec(&raw, &image_config);
    let state = &raw["State"];
    let config = &raw["Config"];
    let host = &raw["HostConfig"];

    let networks: Vec<Value> = raw["NetworkSettings"]["Networks"]
        .as_object()
        .map(|networks| {
            networks
                .iter()
                .map(|(name, net)| {
                    json!({
                        "name": name,
                        "ip": text(net, "IPAddress"),
                        "gateway": text(net, "Gateway"),
                        "aliases": strings(&net["Aliases"]),
                        "dns_names": strings(&net["DNSNames"]),
                    })
                })
                .collect()
        })
        .unwrap_or_default();
    let mounts: Vec<Value> = raw["Mounts"]
        .as_array()
        .map(|mounts| {
            mounts
                .iter()
                .map(|m| {
                    json!({
                        "type": text(m, "Type"),
                        "name": text(m, "Name"),
                        "source": text(m, "Source"),
                        "target": text(m, "Destination"),
                        "read_only": !m["RW"].as_bool().unwrap_or(true),
                    })
                })
                .collect()
        })
        .unwrap_or_default();
    let labels = config["Labels"].as_object().cloned().unwrap_or_default();
    let command = [strings(&config["Entrypoint"]), strings(&config["Cmd"])].concat().join(" ");

    Ok(json!({
        "id": text(&raw, "Id").chars().take(12).collect::<String>(),
        "name": text(&raw, "Name").trim_start_matches('/'),
        "image": text(config, "Image"),
        "image_id": text(&raw, "Image"),
        "created": text(&raw, "Created"),
        "state": {
            "status": text(state, "Status"),
            "running": state["Running"].as_bool().unwrap_or(false),
            "paused": state["Paused"].as_bool().unwrap_or(false),
            "oom_killed": state["OOMKilled"].as_bool().unwrap_or(false),
            "exit_code": state["ExitCode"].as_i64().unwrap_or(0),
            "error": text(state, "Error"),
            "started_at": text(state, "StartedAt"),
            "finished_at": text(state, "FinishedAt"),
            "health": state["Health"]["Status"].as_str().unwrap_or(""),
        },
        "restart_count": raw["RestartCount"].as_i64().unwrap_or(0),
        "restart_policy": host["RestartPolicy"]["Name"].as_str().unwrap_or("no"),
        "command": command,
        "working_dir": text(config, "WorkingDir"),
        "user": text(config, "User"),
        "env": strings(&config["Env"]),
        "mounts": mounts,
        "networks": networks,
        "ports": spec.ports,
        "memory": spec.memory,
        "cpus": spec.cpus,
        "project": labels.get("com.docker.compose.project").and_then(Value::as_str).unwrap_or(""),
        "service": labels.get("com.docker.compose.service").and_then(Value::as_str).unwrap_or(""),
        "labels": labels,
        "spec": spec,
    }))
}

/// The `RunSpec` that would make this container again. Values the image sets
/// itself (its variables, command, entrypoint) are left out, so a newer image
/// can bring its own.
pub(crate) fn rebuild_spec(raw: &Value, image_config: &Value) -> RunSpec {
    let config = &raw["Config"];
    let host = &raw["HostConfig"];
    let image_env = strings(&image_config["Env"]);

    let env = strings(&config["Env"])
        .into_iter()
        .filter(|pair| !image_env.contains(pair))
        .filter_map(|pair| pair.split_once('=').map(|(k, v)| EnvSpec { key: k.to_string(), value: v.to_string() }))
        .collect();

    let mut ports: Vec<PortSpec> = Vec::new();
    if let Some(bindings) = host["PortBindings"].as_object() {
        for (key, list) in bindings {
            let (container, protocol) = key.split_once('/').unwrap_or((key.as_str(), "tcp"));
            let Ok(container) = container.parse::<u32>() else { continue };
            for binding in list.as_array().into_iter().flatten() {
                let Ok(host_port) = text(binding, "HostPort").parse::<u32>() else { continue };
                let host_ip = text(binding, "HostIp");
                // Docker lists a public binding once per address family; keep one.
                if ports.iter().any(|p| p.host == host_port && p.container == container && p.protocol == protocol) {
                    continue;
                }
                ports.push(PortSpec {
                    host: host_port,
                    container,
                    protocol: protocol.to_string(),
                    public: !host_ip.starts_with("127."),
                });
            }
        }
    }
    ports.sort_by_key(|p| (p.container, p.host));

    let volumes = raw["Mounts"]
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(|m| {
            let source = match text(m, "Type").as_str() {
                "volume" => text(m, "Name"),
                "bind" => text(m, "Source"),
                _ => return None,
            };
            // Anonymous volumes have 64-hex names; a recreate gets a fresh one anyway.
            if text(m, "Type") == "volume" && source.len() == 64 && source.chars().all(|c| c.is_ascii_hexdigit()) {
                return None;
            }
            Some(VolumeSpec { source, target: text(m, "Destination"), read_only: !m["RW"].as_bool().unwrap_or(true) })
        })
        .collect();

    let network_mode = text(host, "NetworkMode");
    let (network, aliases) = if network_mode.is_empty() || DEFAULT_NETWORKS.contains(&network_mode.as_str()) || network_mode == "default" {
        (String::new(), Vec::new())
    } else {
        let aliases = strings(&raw["NetworkSettings"]["Networks"][&network_mode]["Aliases"])
            .into_iter()
            .filter(|alias| !raw["Id"].as_str().unwrap_or("").starts_with(alias.as_str()))
            .filter(|alias| alias != text(raw, "Name").trim_start_matches('/'))
            .collect();
        (network_mode, aliases)
    };

    let id = text(raw, "Id");
    let hostname = text(config, "Hostname");
    let hostname = if hostname.is_empty() || id.starts_with(&hostname) { String::new() } else { hostname };

    let memory = host["Memory"].as_i64().filter(|m| *m > 0).map(memory_text).unwrap_or_default();
    let cpus = host["NanoCpus"]
        .as_i64()
        .filter(|n| *n > 0)
        .map(|n| trim_float(n as f64 / 1e9))
        .unwrap_or_default();

    let cmd = strings(&config["Cmd"]);
    let entrypoint = strings(&config["Entrypoint"]);
    let command = if cmd != strings(&image_config["Cmd"]) { cmd } else { Vec::new() };
    let entrypoint = if entrypoint != strings(&image_config["Entrypoint"]) { entrypoint.join(" ") } else { String::new() };

    let restart = host["RestartPolicy"]["Name"].as_str().unwrap_or("no");
    RunSpec {
        image: text(config, "Image"),
        name: text(raw, "Name").trim_start_matches('/').to_string(),
        restart: if restart.is_empty() { "no".to_string() } else { restart.to_string() },
        ports,
        env,
        volumes,
        network,
        aliases,
        hostname,
        memory,
        cpus,
        entrypoint,
        command,
        pull: false,
    }
}

fn memory_text(bytes: i64) -> String {
    const MB: i64 = 1024 * 1024;
    if bytes % (1024 * MB) == 0 {
        format!("{}g", bytes / (1024 * MB))
    } else if bytes % MB == 0 {
        format!("{}m", bytes / MB)
    } else {
        format!("{}b", bytes)
    }
}

fn trim_float(value: f64) -> String {
    let text = format!("{value:.3}");
    text.trim_end_matches('0').trim_end_matches('.').to_string()
}

/// CPU, memory, network and disk use of every running container.
pub fn stats() -> Result<Value, String> {
    let output = run(&args(&["stats", "--no-stream", "--format", "{{json .}}"]))?;
    let rows: Vec<Value> = parse_json_lines(&output)
        .iter()
        .map(|raw| {
            json!({
                "id": text(raw, "ID"),
                "name": text(raw, "Name"),
                "cpu": text(raw, "CPUPerc"),
                "memory": text(raw, "MemUsage"),
                "memory_percent": text(raw, "MemPerc"),
                "network": text(raw, "NetIO"),
                "disk": text(raw, "BlockIO"),
                "pids": text(raw, "PIDs"),
            })
        })
        .collect();
    Ok(json!({ "stats": rows }))
}

/// Runs one command inside a running container through `sh -c` and returns
/// its output. Not a terminal: nothing interactive, and it is killed after
/// a minute.
pub fn exec(id: &str, command: &str, user: &str, workdir: &str) -> Result<Value, String> {
    let id = validate_container(id)?;
    let command = command.trim();
    if command.is_empty() || command.len() > MAX_EXEC_COMMAND || command.contains('\0') {
        return Err("Enter a command of at most 8 KB.".into());
    }
    let client = client().ok_or("Docker is not installed on this server.")?;
    let mut list = vec!["--signal=KILL".to_string(), format!("{EXEC_TIMEOUT_SECS}s"), client.to_string(), "exec".into()];
    let user = user.trim();
    if !user.is_empty() {
        let valid = user.len() <= 64 && user.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '.' | '-' | ':'));
        if !valid || user.starts_with('-') {
            return Err(format!("'{user}' is not a valid user."));
        }
        list.push(format!("--user={user}"));
    }
    let workdir = workdir.trim();
    if !workdir.is_empty() {
        if !workdir.starts_with('/') || workdir.contains('\0') {
            return Err("The working directory must be an absolute path.".into());
        }
        list.push(format!("--workdir={workdir}"));
    }
    list.extend(["--".to_string(), id.clone(), "sh".into(), "-c".into(), command.to_string()]);

    let output = Command::new("timeout")
        .args(&list)
        .output()
        .map_err(|e| format!("Cannot run docker: {e}"))?;
    let mut text = String::from_utf8_lossy(&output.stdout).to_string();
    let stderr = String::from_utf8_lossy(&output.stderr);
    if !stderr.trim().is_empty() {
        if !text.is_empty() && !text.ends_with('\n') {
            text.push('\n');
        }
        text.push_str(&stderr);
    }
    let truncated = text.len() > MAX_EXEC_OUTPUT;
    if truncated {
        let mut cut = MAX_EXEC_OUTPUT;
        while !text.is_char_boundary(cut) {
            cut -= 1;
        }
        text.truncate(cut);
        text.push_str("\n… output cut at 256 KB.");
    }
    let code = output.status.code().unwrap_or(-1);
    Ok(json!({
        "output": text,
        "exit_code": code,
        // `timeout --signal=KILL` exits 137 when it had to kill the command.
        "timed_out": code == 137,
    }))
}

/// Replaces container `id` with one made from `spec`. The old one is stopped
/// and set aside under a temporary name first, so if the new one fails to
/// start the old one is put back as it was.
pub fn recreate(id: &str, spec: &RunSpec) -> Result<String, String> {
    let id = validate_container(id)?;
    let new_args = run_args(spec)?;
    let old = inspect_raw("container", &id)?;
    let old_name = text(&old, "Name").trim_start_matches('/').to_string();
    let was_running = old["State"]["Running"].as_bool().unwrap_or(false);
    let parked = format!("{old_name}-dpanel-old");

    // A leftover from an earlier failed attempt would block the rename.
    let _ = run(&args(&["rm", "-f", "--", &parked]));
    if was_running {
        run(&args(&["stop", "--", &id]))?;
    }
    run(&args(&["rename", "--", &id, &parked]))?;

    match run(&new_args) {
        Ok(new_id) => {
            let _ = run(&args(&["rm", "-f", "--", &parked]));
            let short: String = new_id.chars().take(12).collect();
            Ok(format!("Container {} recreated ({short}).", if spec.name.trim().is_empty() { &old_name } else { spec.name.trim() }))
        }
        Err(error) => {
            // `docker run` can leave a created-but-not-started container behind.
            if !spec.name.trim().is_empty() {
                let _ = run(&args(&["rm", "-f", "--", spec.name.trim()]));
            }
            let _ = run(&args(&["rename", "--", &parked, &old_name]));
            if was_running {
                let _ = run(&args(&["start", "--", &old_name]));
            }
            Err(format!("{error}\nThe previous container was kept and is back as it was."))
        }
    }
}

/// Pulls the container's image again and recreates it with the same
/// settings, which is how a container gets a newer image.
pub fn update(id: &str) -> Result<String, String> {
    let id = validate_container(id)?;
    let raw = inspect_raw("container", &id)?;
    let image_config = inspect_raw("image", raw["Image"].as_str().unwrap_or("")).map(|i| i["Config"].clone()).unwrap_or(Value::Null);
    let spec = rebuild_spec(&raw, &image_config);
    let before = text(&raw, "Image");
    run(&args(&["pull", "--", &spec.image]))?;
    let after = inspect_raw("image", &spec.image).map(|i| text(&i, "Id")).unwrap_or_default();
    if !after.is_empty() && after == before {
        return Ok(format!("{} already runs the newest {}; nothing to update.", spec.name, spec.image));
    }
    recreate(&id, &spec).map(|_| format!("{} now runs the newest {}.", spec.name, spec.image))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn sample() -> (Value, Value) {
        let raw = json!({
            "Id": "753dcebea047ce4a1671021f629606a9624cf54ed40156b68b3161aadfc50982",
            "Name": "/web",
            "Image": "sha256:abc",
            "Config": {
                "Hostname": "753dcebea047",
                "Image": "nginx:alpine",
                "Env": ["FOO=bar", "PATH=/usr/bin"],
                "Cmd": ["nginx", "-g", "daemon off;"],
                "Entrypoint": ["/docker-entrypoint.sh"],
            },
            "HostConfig": {
                "RestartPolicy": { "Name": "unless-stopped" },
                "PortBindings": {
                    "80/tcp": [{ "HostIp": "127.0.0.1", "HostPort": "8080" }],
                    "53/udp": [{ "HostIp": "", "HostPort": "5353" }, { "HostIp": "::", "HostPort": "5353" }],
                },
                "Memory": 536870912,
                "NanoCpus": 500000000,
                "NetworkMode": "app-net",
            },
            "Mounts": [
                { "Type": "volume", "Name": "data", "Destination": "/data", "RW": true },
                { "Type": "bind", "Source": "/home/u/site", "Destination": "/srv", "RW": false },
                { "Type": "volume", "Name": "0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef", "Destination": "/tmp/x", "RW": true },
            ],
            "NetworkSettings": { "Networks": { "app-net": { "Aliases": ["api", "753dcebea047", "web"] } } },
        });
        let image = json!({ "Env": ["PATH=/usr/bin"], "Cmd": ["nginx", "-g", "daemon off;"], "Entrypoint": ["/docker-entrypoint.sh"] });
        (raw, image)
    }

    #[test]
    fn rebuilds_the_run_spec_from_inspect() {
        let (raw, image) = sample();
        let spec = rebuild_spec(&raw, &image);
        assert_eq!(spec.image, "nginx:alpine");
        assert_eq!(spec.name, "web");
        assert_eq!(spec.restart, "unless-stopped");
        assert_eq!(spec.env, vec![EnvSpec { key: "FOO".into(), value: "bar".into() }]);
        assert_eq!(spec.ports.len(), 2);
        assert!(spec.ports.iter().any(|p| p.container == 53 && p.public && p.protocol == "udp"));
        assert!(spec.ports.iter().any(|p| p.container == 80 && !p.public && p.host == 8080));
        assert_eq!(spec.volumes.len(), 2);
        assert!(spec.volumes[1].read_only);
        assert_eq!(spec.network, "app-net");
        assert_eq!(spec.aliases, vec!["api".to_string()]);
        assert_eq!(spec.hostname, "");
        assert_eq!(spec.memory, "512m");
        assert_eq!(spec.cpus, "0.5");
        assert!(spec.command.is_empty());
        assert_eq!(spec.entrypoint, "");
        // What comes back must be runnable again.
        assert!(run_args(&spec).is_ok());
    }

    #[test]
    fn keeps_a_changed_command() {
        let (mut raw, image) = sample();
        raw["Config"]["Cmd"] = json!(["sleep", "60"]);
        raw["HostConfig"]["NetworkMode"] = json!("bridge");
        let spec = rebuild_spec(&raw, &image);
        assert_eq!(spec.command, vec!["sleep".to_string(), "60".to_string()]);
        assert_eq!(spec.network, "");
        assert!(spec.aliases.is_empty());
    }
}
