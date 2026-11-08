//! `FirewallBackend`: spec section 6.9. Linux has UFW and firewalld; the
//! other systems answer `Unsupported` until tasks J7 and K6.
//!
//! A rule's id is a hash of what it does, so the same rule always has the
//! same id on either backend and nothing extra has to be stored. Only rules
//! of the shapes below are listed; a hand-written rule stays untouched.
//! Lock-out protection (a rule undone unless the panel confirms it) is task S3.

use std::{fmt, net::IpAddr, path::Path, process::Command, str::FromStr, time::Duration};

use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};

use super::{PlatformError, capabilities::Capability, paths::Tool};

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Action {
    Allow,
    Deny,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
#[serde(rename_all = "lowercase")]
pub enum Protocol {
    Tcp,
    Udp,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq, Serialize, Deserialize)]
pub struct PortSpec {
    pub from: u16,
    /// The last port of a range; `None` is a single port.
    pub to: Option<u16>,
    pub protocol: Protocol,
}

/// An address with a prefix length, as in `203.0.113.0/24`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct IpNet {
    pub addr: IpAddr,
    pub prefix: u8,
}

impl FromStr for IpNet {
    type Err = String;

    fn from_str(value: &str) -> Result<Self, Self::Err> {
        let (addr, prefix) = match value.split_once('/') {
            Some((addr, prefix)) => (addr, Some(prefix)),
            None => (value, None),
        };
        let addr: IpAddr = addr.trim().parse().map_err(|_| format!("Invalid address: {value}"))?;
        let max = if addr.is_ipv4() { 32 } else { 128 };
        let prefix = match prefix {
            Some(prefix) => prefix.trim().parse::<u8>().ok().filter(|p| *p <= max).ok_or_else(|| format!("Invalid prefix: {value}"))?,
            None => max,
        };
        Ok(Self { addr, prefix })
    }
}

impl fmt::Display for IpNet {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let max = if self.addr.is_ipv4() { 32 } else { 128 };
        if self.prefix == max { write!(f, "{}", self.addr) } else { write!(f, "{}/{}", self.addr, self.prefix) }
    }
}

impl Serialize for IpNet {
    fn serialize<S: serde::Serializer>(&self, serializer: S) -> Result<S::Ok, S::Error> {
        serializer.collect_str(self)
    }
}

impl<'de> Deserialize<'de> for IpNet {
    fn deserialize<D: serde::Deserializer<'de>>(deserializer: D) -> Result<Self, D::Error> {
        String::deserialize(deserializer)?.parse().map_err(serde::de::Error::custom)
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct FirewallRule {
    /// Filled in from the rest; any id sent in is ignored.
    #[serde(default)]
    pub id: String,
    pub action: Action,
    pub port: Option<PortSpec>,
    pub source: Option<IpNet>,
}

impl FirewallRule {
    pub fn new(action: Action, port: Option<PortSpec>, source: Option<IpNet>) -> Self {
        let mut rule = Self { id: String::new(), action, port, source };
        rule.id = rule.computed_id();
        rule
    }

    /// A rule must name a port or a source: a bare allow or deny is the default policy.
    pub fn validate(&self) -> Result<(), PlatformError> {
        let invalid = |what: &str| Err(PlatformError::Conflict { what: what.to_string() });
        if self.port.is_none() && self.source.is_none() {
            return invalid("A rule needs a port or a source address.");
        }
        if let Some(port) = self.port {
            if port.from == 0 || port.to.is_some_and(|to| to <= port.from) {
                return invalid("Invalid port or port range.");
            }
        }
        Ok(())
    }

    fn computed_id(&self) -> String {
        let port = self.port.map_or(String::new(), |port| format!("{}-{:?}-{:?}", port.from, port.to, port.protocol));
        let source = self.source.map_or(String::new(), |source| source.to_string());
        let digest = Sha256::digest(format!("{:?}|{port}|{source}", self.action).as_bytes());
        digest.iter().take(6).map(|byte| format!("{byte:02x}")).collect()
    }
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct FirewallStatus {
    pub backend: &'static str,
    pub active: bool,
}

pub trait FirewallBackend: Send + Sync + fmt::Debug {
    fn status(&self) -> Result<FirewallStatus, PlatformError>;
    fn list(&self) -> Result<Vec<FirewallRule>, PlatformError>;
    fn add(&self, rule: &FirewallRule) -> Result<(), PlatformError>;
    fn remove(&self, id: &str) -> Result<(), PlatformError>;
    /// `ttl` is not kept yet: a ban stays until `unban` (the watcher is J7/K6).
    fn ban(&self, ip: IpAddr, ttl: Option<Duration>) -> Result<(), PlatformError>;
    fn unban(&self, ip: IpAddr) -> Result<(), PlatformError>;
}

/// The backend this machine has, looked up on each call, since the panel
/// can install a firewall while drust runs. UFW wins when both are present.
pub fn backend() -> Box<dyn FirewallBackend> {
    let paths = super::paths();
    if super::current().os() == super::Os::Linux {
        if let Ok(program) = paths.binary(Tool::Ufw) {
            return Box::new(Ufw { program: program.display().to_string() });
        }
        if let Ok(program) = paths.binary(Tool::FirewallCmd) {
            return Box::new(Firewalld { program: program.display().to_string() });
        }
    }
    Box::new(NoFirewall)
}

fn run(program: &str, args: &[String]) -> Result<String, PlatformError> {
    let output = Command::new(Path::new(program))
        .args(args)
        .output()
        .map_err(|error| PlatformError::from_io("run the firewall", error))?;
    if output.status.success() {
        return Ok(String::from_utf8_lossy(&output.stdout).into_owned());
    }
    let detail = String::from_utf8_lossy(&output.stderr).trim().to_string();
    Err(PlatformError::Failed {
        action: "change the firewall".into(),
        detail: if detail.is_empty() { format!("{program} exited unsuccessfully") } else { detail },
    })
}

fn owned(args: &[&str]) -> Vec<String> {
    args.iter().map(|arg| arg.to_string()).collect()
}

fn not_found(id: &str) -> PlatformError {
    PlatformError::NotFound { what: format!("No firewall rule {id}") }
}

fn ban_rule(ip: IpAddr) -> FirewallRule {
    FirewallRule::new(Action::Deny, None, Some(IpNet { addr: ip, prefix: if ip.is_ipv4() { 32 } else { 128 } }))
}

// ---- UFW ------------------------------------------------------------------

#[derive(Debug)]
pub struct Ufw {
    program: String,
}

/// The words after `ufw` for this rule, as `ufw show added` prints them back.
pub fn ufw_args(rule: &FirewallRule) -> Vec<String> {
    let mut args = vec![match rule.action { Action::Allow => "allow", Action::Deny => "deny" }.to_string()];
    match (rule.source, rule.port) {
        (None, Some(port)) => args.push(format!("{}/{}", ufw_port(port), protocol_name(port.protocol))),
        (source, port) => {
            args.extend(owned(&["from", &source.map_or("any".to_string(), |s| s.to_string())]));
            if let Some(port) = port {
                args.extend(owned(&["to", "any", "port", &ufw_port(port), "proto", protocol_name(port.protocol)]));
            }
        }
    }
    args
}

fn ufw_port(port: PortSpec) -> String {
    port.to.map_or(port.from.to_string(), |to| format!("{}:{to}", port.from))
}

fn protocol_name(protocol: Protocol) -> &'static str {
    match protocol {
        Protocol::Tcp => "tcp",
        Protocol::Udp => "udp",
    }
}

fn parse_port(value: &str, protocol: &str) -> Option<PortSpec> {
    let protocol = match protocol {
        "tcp" => Protocol::Tcp,
        "udp" => Protocol::Udp,
        _ => return None,
    };
    let (from, to) = match value.split_once([':', '-']) {
        Some((from, to)) => (from.parse().ok()?, Some(to.parse().ok()?)),
        None => (value.parse().ok()?, None),
    };
    Some(PortSpec { from, to, protocol })
}

/// Rules from `ufw show added`, only in the shapes `ufw_args` writes.
pub fn parse_ufw_added(text: &str) -> Vec<FirewallRule> {
    text.lines().filter_map(|line| {
        let words: Vec<&str> = line.split_whitespace().collect();
        let words = words.strip_prefix(&["ufw"])?;
        let action = match *words.first()? {
            "allow" => Action::Allow,
            "deny" => Action::Deny,
            _ => return None,
        };
        let rule = match &words[1..] {
            [spec] => {
                let (port, protocol) = spec.split_once('/')?;
                FirewallRule::new(action, Some(parse_port(port, protocol)?), None)
            }
            ["from", source] => FirewallRule::new(action, None, Some(source.parse().ok()?)),
            ["from", source, "to", "any", "port", port, "proto", protocol] => {
                let source = if *source == "any" { None } else { Some(source.parse().ok()?) };
                FirewallRule::new(action, Some(parse_port(port, protocol)?), source)
            }
            _ => return None,
        };
        Some(rule)
    }).collect()
}

impl FirewallBackend for Ufw {
    fn status(&self) -> Result<FirewallStatus, PlatformError> {
        let text = run(&self.program, &owned(&["status"]))?;
        Ok(FirewallStatus { backend: "ufw", active: text.to_ascii_lowercase().contains("status: active") })
    }

    fn list(&self) -> Result<Vec<FirewallRule>, PlatformError> {
        Ok(parse_ufw_added(&run(&self.program, &owned(&["show", "added"]))?))
    }

    fn add(&self, rule: &FirewallRule) -> Result<(), PlatformError> {
        rule.validate()?;
        // A deny goes first, or an earlier allow would still let it through.
        // `prepend`, not `insert 1`: that fails for IPv6 while IPv4 rules exist.
        let mut args = ufw_args(rule);
        if rule.action == Action::Deny {
            args.insert(0, "prepend".to_string());
        }
        run(&self.program, &args).map(|_| ())
    }

    fn remove(&self, id: &str) -> Result<(), PlatformError> {
        let rule = self.list()?.into_iter().find(|rule| rule.id == id).ok_or_else(|| not_found(id))?;
        let mut args = owned(&["--force", "delete"]);
        args.extend(ufw_args(&rule));
        run(&self.program, &args).map(|_| ())
    }

    fn ban(&self, ip: IpAddr, _ttl: Option<Duration>) -> Result<(), PlatformError> {
        let rule = ban_rule(ip);
        if self.list()?.iter().any(|existing| existing.id == rule.id) {
            return Ok(());
        }
        self.add(&rule)
    }

    fn unban(&self, ip: IpAddr) -> Result<(), PlatformError> {
        match self.remove(&ban_rule(ip).id) {
            Err(PlatformError::NotFound { .. }) => Ok(()),
            other => other,
        }
    }
}

// ---- firewalld ------------------------------------------------------------

#[derive(Debug)]
pub struct Firewalld {
    program: String,
}

/// A rich rule in the default zone; a rule with no source covers both families.
pub fn rich_rule(rule: &FirewallRule) -> String {
    let mut parts = vec!["rule".to_string()];
    if let Some(source) = rule.source {
        parts.push(format!("family=\"{}\"", if source.addr.is_ipv4() { "ipv4" } else { "ipv6" }));
        parts.push(format!("source address=\"{source}\""));
    }
    if let Some(port) = rule.port {
        let range = port.to.map_or(port.from.to_string(), |to| format!("{}-{to}", port.from));
        parts.push(format!("port port=\"{range}\" protocol=\"{}\"", protocol_name(port.protocol)));
    }
    parts.push(match rule.action { Action::Allow => "accept", Action::Deny => "drop" }.to_string());
    parts.join(" ")
}

fn quoted<'a>(line: &'a str, key: &str) -> Option<&'a str> {
    let start = line.find(&format!("{key}=\""))? + key.len() + 2;
    let end = line[start..].find('"')? + start;
    Some(&line[start..end])
}

/// Rules from `--list-rich-rules`, only in the shapes `rich_rule` writes.
pub fn parse_rich_rules(text: &str) -> Vec<FirewallRule> {
    text.lines().filter_map(|line| {
        let line = line.trim();
        let action = if line.ends_with(" accept") {
            Action::Allow
        } else if line.ends_with(" drop") || line.ends_with(" reject") {
            Action::Deny
        } else {
            return None;
        };
        let source = match line.find("source address=") {
            Some(at) => Some(quoted(&line[at..], "address")?.parse().ok()?),
            None => None,
        };
        let port = match line.find("port port=") {
            Some(at) => Some(parse_port(quoted(&line[at..], "port")?, quoted(&line[at..], "protocol")?)?),
            None => None,
        };
        let rule = FirewallRule::new(action, port, source);
        // Anything else in the line (a log, a service) is not one of ours.
        (rich_rule(&rule) == line || rich_rule(&rule).replace(" drop", " reject") == line).then_some(rule)
    }).collect()
}

impl Firewalld {
    /// Both the running and the saved configuration, so a reload keeps it.
    fn change(&self, flag: &str, rule: &FirewallRule) -> Result<(), PlatformError> {
        let argument = format!("{flag}={}", rich_rule(rule));
        run(&self.program, &[argument.clone()])?;
        run(&self.program, &["--permanent".to_string(), argument]).map(|_| ())
    }
}

impl FirewallBackend for Firewalld {
    fn status(&self) -> Result<FirewallStatus, PlatformError> {
        let active = run(&self.program, &owned(&["--state"])).map(|text| text.trim() == "running").unwrap_or(false);
        Ok(FirewallStatus { backend: "firewalld", active })
    }

    fn list(&self) -> Result<Vec<FirewallRule>, PlatformError> {
        Ok(parse_rich_rules(&run(&self.program, &owned(&["--permanent", "--list-rich-rules"]))?))
    }

    fn add(&self, rule: &FirewallRule) -> Result<(), PlatformError> {
        rule.validate()?;
        self.change("--add-rich-rule", rule)
    }

    fn remove(&self, id: &str) -> Result<(), PlatformError> {
        let rule = self.list()?.into_iter().find(|rule| rule.id == id).ok_or_else(|| not_found(id))?;
        self.change("--remove-rich-rule", &rule)
    }

    fn ban(&self, ip: IpAddr, _ttl: Option<Duration>) -> Result<(), PlatformError> {
        self.add(&ban_rule(ip))
    }

    fn unban(&self, ip: IpAddr) -> Result<(), PlatformError> {
        match self.remove(&ban_rule(ip).id) {
            Err(PlatformError::NotFound { .. }) => Ok(()),
            other => other,
        }
    }
}

// ---- none ------------------------------------------------------------------

#[derive(Debug)]
pub struct NoFirewall;

fn unsupported<T>() -> Result<T, PlatformError> {
    Err(PlatformError::Unsupported { capability: Capability::Firewall })
}

impl FirewallBackend for NoFirewall {
    fn status(&self) -> Result<FirewallStatus, PlatformError> {
        unsupported()
    }
    fn list(&self) -> Result<Vec<FirewallRule>, PlatformError> {
        unsupported()
    }
    fn add(&self, _rule: &FirewallRule) -> Result<(), PlatformError> {
        unsupported()
    }
    fn remove(&self, _id: &str) -> Result<(), PlatformError> {
        unsupported()
    }
    fn ban(&self, _ip: IpAddr, _ttl: Option<Duration>) -> Result<(), PlatformError> {
        unsupported()
    }
    fn unban(&self, _ip: IpAddr) -> Result<(), PlatformError> {
        unsupported()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn tcp(from: u16, to: Option<u16>) -> Option<PortSpec> {
        Some(PortSpec { from, to, protocol: Protocol::Tcp })
    }

    fn samples() -> Vec<FirewallRule> {
        vec![
            FirewallRule::new(Action::Allow, tcp(443, None), None),
            FirewallRule::new(Action::Allow, Some(PortSpec { from: 6000, to: Some(6010), protocol: Protocol::Udp }), None),
            FirewallRule::new(Action::Allow, tcp(22, None), Some("203.0.113.0/24".parse().unwrap())),
            FirewallRule::new(Action::Deny, None, Some("198.51.100.7".parse().unwrap())),
            FirewallRule::new(Action::Deny, tcp(3306, None), Some("2001:db8::/32".parse().unwrap())),
        ]
    }

    #[test]
    fn ufw_reads_back_every_rule_it_writes() {
        let added: String = samples().iter().map(|rule| format!("ufw {}\n", ufw_args(rule).join(" "))).collect();
        let text = format!("Added user rules (see 'ufw status' for running firewall):\n{added}ufw limit 22/tcp\n");
        assert_eq!(parse_ufw_added(&text), samples());
    }

    #[test]
    fn ufw_writes_the_usual_commands() {
        let rules = samples();
        assert_eq!(ufw_args(&rules[0]).join(" "), "allow 443/tcp");
        assert_eq!(ufw_args(&rules[2]).join(" "), "allow from 203.0.113.0/24 to any port 22 proto tcp");
        assert_eq!(ufw_args(&rules[3]).join(" "), "deny from 198.51.100.7");
    }

    #[test]
    fn firewalld_reads_back_every_rule_it_writes_and_skips_others() {
        let text: String = samples().iter().map(|rule| format!("{}\n", rich_rule(rule))).collect();
        let text = format!("{text}rule family=\"ipv4\" source address=\"192.0.2.1\" service name=\"ssh\" log accept\n");
        assert_eq!(parse_rich_rules(&text), samples());
        assert_eq!(
            rich_rule(&samples()[2]),
            "rule family=\"ipv4\" source address=\"203.0.113.0/24\" port port=\"22\" protocol=\"tcp\" accept"
        );
    }

    #[test]
    fn the_id_depends_only_on_what_the_rule_does() {
        let a = FirewallRule::new(Action::Allow, tcp(22, None), None);
        let b = FirewallRule::new(Action::Allow, tcp(22, None), None);
        let c = FirewallRule::new(Action::Deny, tcp(22, None), None);
        assert_eq!(a.id, b.id);
        assert_ne!(a.id, c.id);
        assert_eq!(a.id.len(), 12);
    }

    #[test]
    fn a_rule_without_port_or_source_or_with_a_backwards_range_is_refused() {
        assert!(FirewallRule::new(Action::Deny, None, None).validate().is_err());
        assert!(FirewallRule::new(Action::Allow, tcp(10, Some(5)), None).validate().is_err());
        assert!(FirewallRule::new(Action::Allow, tcp(0, None), None).validate().is_err());
        assert!(samples().iter().all(|rule| rule.validate().is_ok()));
    }

    #[test]
    fn addresses_keep_their_prefix_and_a_host_prints_without_one() {
        assert_eq!("10.0.0.0/8".parse::<IpNet>().unwrap().to_string(), "10.0.0.0/8");
        assert_eq!("10.0.0.1/32".parse::<IpNet>().unwrap().to_string(), "10.0.0.1");
        assert!("10.0.0.1/33".parse::<IpNet>().is_err());
        assert!("not-an-ip".parse::<IpNet>().is_err());
    }
}
