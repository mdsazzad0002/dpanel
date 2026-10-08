//! Docker networks. Containers on the same user-defined network reach each
//! other by container name (or alias), e.g. Kibana → http://elasticsearch:9200,
//! which the default bridge network does not allow.

use serde::Deserialize;
use serde_json::{Value, json};

use super::{args, parse_json_lines, run, text, validate_container};

/// Networks Docker makes itself; they can be neither removed nor recreated.
pub(crate) const BUILT_IN: &[&str] = &["bridge", "host", "none"];

#[derive(Deserialize, Default)]
pub struct NetworkSpec {
    pub name: String,
    /// No route out of the network: containers on it reach only each other.
    #[serde(default)]
    pub internal: bool,
    #[serde(default)]
    pub subnet: String,
}

fn validate_network(name: &str) -> Result<String, String> {
    validate_container(name).map_err(|_| format!("'{}' is not a valid network name.", name.trim()))
}

/// `10.20.0.0/16`: an IPv4 address and a prefix length.
pub(crate) fn validate_subnet(value: &str) -> Result<String, String> {
    let value = value.trim();
    let invalid = || format!("'{value}' is not a valid IPv4 subnet such as 10.20.0.0/16.");
    let (address, prefix) = value.split_once('/').ok_or_else(invalid)?;
    let prefix: u8 = prefix.parse().map_err(|_| invalid())?;
    if prefix > 30 || address.parse::<std::net::Ipv4Addr>().is_err() {
        return Err(invalid());
    }
    Ok(value.to_string())
}

pub fn list() -> Result<Value, String> {
    let rows = parse_json_lines(&run(&args(&["network", "ls", "--format", "{{json .}}"]))?);
    let ids: Vec<String> = rows.iter().map(|row| text(row, "ID")).filter(|id| !id.is_empty()).collect();
    let mut details: Vec<Value> = Vec::new();
    if !ids.is_empty() {
        let mut list = args(&["network", "inspect", "--"]);
        list.extend(ids);
        details = serde_json::from_str(&run(&list)?).unwrap_or_default();
    }

    let networks: Vec<Value> = details
        .iter()
        .map(|net| {
            let name = text(net, "Name");
            let containers: Vec<Value> = net["Containers"]
                .as_object()
                .map(|map| {
                    map.iter()
                        .map(|(id, c)| {
                            json!({
                                "id": id.chars().take(12).collect::<String>(),
                                "name": text(c, "Name"),
                                "ip": text(c, "IPv4Address"),
                            })
                        })
                        .collect()
                })
                .unwrap_or_default();
            let subnets: Vec<String> = net["IPAM"]["Config"]
                .as_array()
                .into_iter()
                .flatten()
                .map(|cfg| text(cfg, "Subnet"))
                .filter(|s| !s.is_empty())
                .collect();
            let labels = &net["Labels"];
            json!({
                "id": text(net, "Id").chars().take(12).collect::<String>(),
                "name": name,
                "driver": text(net, "Driver"),
                "scope": text(net, "Scope"),
                "internal": net["Internal"].as_bool().unwrap_or(false),
                "subnets": subnets,
                "created": text(net, "Created"),
                "built_in": BUILT_IN.contains(&name.as_str()),
                "project": labels["com.docker.compose.project"].as_str().unwrap_or(""),
                "containers": containers,
            })
        })
        .collect();
    Ok(json!({ "networks": networks }))
}

pub fn create(spec: &NetworkSpec) -> Result<String, String> {
    let name = validate_network(&spec.name)?;
    if BUILT_IN.contains(&name.as_str()) {
        return Err(format!("'{name}' is a built-in Docker network."));
    }
    let mut list = args(&["network", "create", "--driver", "bridge"]);
    if spec.internal {
        list.push("--internal".into());
    }
    if !spec.subnet.trim().is_empty() {
        list.push("--subnet".into());
        list.push(validate_subnet(&spec.subnet)?);
    }
    list.push("--".into());
    list.push(name.clone());
    run(&list)?;
    Ok(format!("Network {name} created. Containers on it can reach each other by name."))
}

pub fn remove(name: &str) -> Result<String, String> {
    let name = validate_network(name)?;
    if BUILT_IN.contains(&name.as_str()) {
        return Err(format!("'{name}' is a built-in Docker network and cannot be removed."));
    }
    run(&args(&["network", "rm", "--", &name]))?;
    Ok(format!("Network {name} removed."))
}

pub fn connect(network: &str, container: &str, aliases: &[String]) -> Result<String, String> {
    let network = validate_network(network)?;
    let container = validate_container(container)?;
    let mut list = args(&["network", "connect"]);
    for alias in aliases.iter().map(|a| a.trim()).filter(|a| !a.is_empty()).take(10) {
        if BUILT_IN.contains(&network.as_str()) {
            return Err("Aliases only work on networks you create, not the built-in ones.".into());
        }
        list.push("--alias".into());
        list.push(validate_container(alias).map_err(|_| format!("'{alias}' is not a valid alias."))?);
    }
    list.extend(["--".to_string(), network.clone(), container.clone()]);
    run(&list)?;
    Ok(format!("{container} joined {network}."))
}

pub fn disconnect(network: &str, container: &str) -> Result<String, String> {
    let network = validate_network(network)?;
    let container = validate_container(container)?;
    run(&args(&["network", "disconnect", "--", &network, &container]))?;
    Ok(format!("{container} left {network}."))
}

pub fn prune() -> Result<String, String> {
    let output = run(&args(&["network", "prune", "-f"]))?;
    let removed = output.lines().skip_while(|l| !l.starts_with("Deleted")).skip(1).filter(|l| !l.trim().is_empty()).count();
    Ok(format!("{removed} unused network(s) removed."))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn validates_subnets() {
        assert!(validate_subnet("10.20.0.0/16").is_ok());
        assert!(validate_subnet("172.30.1.0/24").is_ok());
        assert!(validate_subnet("10.20.0.0").is_err());
        assert!(validate_subnet("10.20.0.0/31").is_err());
        assert!(validate_subnet("--x/16").is_err());
        assert!(validate_subnet("300.1.1.1/16").is_err());
    }

    #[test]
    fn refuses_built_in_networks() {
        assert!(create(&NetworkSpec { name: "bridge".into(), ..Default::default() }).is_err());
        assert!(remove("host").is_err());
        assert!(create(&NetworkSpec { name: "-x".into(), ..Default::default() }).is_err());
    }
}
