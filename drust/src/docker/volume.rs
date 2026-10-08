//! Named Docker volumes: where containers keep data that must survive a
//! recreate. Sizes come from `docker system df -v`, which walks every
//! volume, so they are only fetched for the Volumes page itself.

use serde_json::{Value, json};

use super::{args, parse_json_lines, reclaimed, run, text, validate_container};

fn validate_volume(name: &str) -> Result<String, String> {
    validate_container(name).map_err(|_| format!("'{}' is not a valid volume name.", name.trim()))
}

/// Anonymous volumes are named by 64 hex characters.
fn anonymous(name: &str) -> bool {
    name.len() == 64 && name.chars().all(|c| c.is_ascii_hexdigit())
}

pub fn list() -> Result<Value, String> {
    let volumes = parse_json_lines(&run(&args(&["volume", "ls", "--format", "{{json .}}"]))?);
    let sizes: Value = run(&args(&["system", "df", "-v", "--format", "{{json .}}"]))
        .ok()
        .and_then(|out| serde_json::from_str(&out).ok())
        .unwrap_or(Value::Null);
    let containers = parse_json_lines(&run(&args(&["ps", "-a", "--format", "{{json .}}"]))?);

    let rows: Vec<Value> = volumes
        .iter()
        .map(|volume| {
            let name = text(volume, "Name");
            let size = sizes["Volumes"]
                .as_array()
                .into_iter()
                .flatten()
                .find(|v| text(v, "Name") == name)
                .map(|v| text(v, "Size"))
                .unwrap_or_default();
            // `ps` lists a container's volume names (bind mounts show as paths).
            let used_by: Vec<String> = containers
                .iter()
                .filter(|c| text(c, "Mounts").split(',').any(|m| m.trim() == name))
                .map(|c| text(c, "Names"))
                .collect();
            let labels = text(volume, "Labels");
            let project = labels
                .split(',')
                .find_map(|pair| pair.strip_prefix("com.docker.compose.project="))
                .unwrap_or("");
            json!({
                "name": name,
                "driver": text(volume, "Driver"),
                "mountpoint": text(volume, "Mountpoint"),
                "size": size,
                "anonymous": anonymous(&name),
                "project": project,
                "used_by": used_by,
            })
        })
        .collect();
    Ok(json!({ "volumes": rows }))
}

pub fn create(name: &str) -> Result<String, String> {
    let name = validate_volume(name)?;
    run(&args(&["volume", "create", "--", &name]))?;
    Ok(format!("Volume {name} created. Mount it in a container to keep its data."))
}

pub fn remove(name: &str) -> Result<String, String> {
    let name = validate_volume(name)?;
    run(&args(&["volume", "rm", "--", &name]))?;
    Ok(format!("Volume {name} and its data were removed."))
}

/// `all` also removes named volumes no container uses; otherwise only
/// anonymous ones go, which is Docker's own default.
pub fn prune(all: bool) -> Result<String, String> {
    let output = run(&args(if all { &["volume", "prune", "-a", "-f"] } else { &["volume", "prune", "-f"] }))?;
    Ok(format!("Unused volumes removed; {} freed.", reclaimed(&output)))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn spots_anonymous_volumes() {
        assert!(anonymous("0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef"));
        assert!(!anonymous("my-data"));
        assert!(validate_volume("-rf").is_err());
        assert!(validate_volume("pg_data").is_ok());
    }
}
