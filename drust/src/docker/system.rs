//! Engine facts and disk use for the Docker overview, and the one-click
//! clean-up. Clean-up never touches volumes: those hold data, and are only
//! removed from the Volumes page, one by one or on purpose.

use serde_json::{Value, json};

use super::{args, parse_json_lines, reclaimed, run, text};

pub fn overview() -> Result<Value, String> {
    let info: Value = serde_json::from_str(&run(&args(&["info", "--format", "{{json .}}"]))?)
        .map_err(|e| format!("Cannot read docker info: {e}"))?;
    let disk: Vec<Value> = parse_json_lines(&run(&args(&["system", "df", "--format", "{{json .}}"]))?)
        .iter()
        .map(|row| {
            json!({
                "type": text(row, "Type"),
                "total": text(row, "TotalCount"),
                "active": text(row, "Active"),
                "size": text(row, "Size"),
                "reclaimable": text(row, "Reclaimable"),
            })
        })
        .collect();
    let number = |key: &str| info[key].as_i64().unwrap_or(0);
    Ok(json!({
        "version": text(&info, "ServerVersion"),
        "os": text(&info, "OperatingSystem"),
        "kernel": text(&info, "KernelVersion"),
        "architecture": text(&info, "Architecture"),
        "cpus": number("NCPU"),
        "memory": number("MemTotal"),
        "storage_driver": text(&info, "Driver"),
        "logging_driver": text(&info, "LoggingDriver"),
        "root_dir": text(&info, "DockerRootDir"),
        "containers": {
            "total": number("Containers"),
            "running": number("ContainersRunning"),
            "paused": number("ContainersPaused"),
            "stopped": number("ContainersStopped"),
        },
        "images": number("Images"),
        "warnings": info["Warnings"].clone(),
        "disk": disk,
    }))
}

/// Removes stopped containers, unused networks, dangling images and build
/// cache; `all_images` also removes every image no container uses.
pub fn prune(all_images: bool) -> Result<String, String> {
    let output = run(&args(if all_images { &["system", "prune", "-a", "-f"] } else { &["system", "prune", "-f"] }))?;
    Ok(format!("Clean-up finished; {} freed. Volumes were not touched.", reclaimed(&output)))
}

/// Build cache only: safe to drop any time, rebuilt on the next build.
pub fn prune_build_cache() -> Result<String, String> {
    let output = run(&args(&["builder", "prune", "-a", "-f"]))?;
    Ok(format!("Build cache cleared; {} freed.", reclaimed(&output)))
}

pub fn prune_containers() -> Result<String, String> {
    let output = run(&args(&["container", "prune", "-f"]))?;
    Ok(format!("Stopped containers removed; {} freed.", reclaimed(&output)))
}
