//! Where DNS-01 answers go (task M4): the TXT record `_acme-challenge.<name>`
//! in the zone of a wildcard site, on this server's PowerDNS or on
//! Cloudflare. The panel names the provider and the zone with every request;
//! a Cloudflare token comes with the request and is never written down.

use std::{
    collections::HashMap,
    net::{IpAddr, SocketAddr},
    sync::{Arc, Mutex},
    time::{Duration, Instant},
};

use futures_util::future::BoxFuture;
use hickory_resolver::{
    TokioAsyncResolver,
    config::{NameServerConfigGroup, ResolverConfig, ResolverOpts},
};
use serde::Deserialize;
use serde_json::{Value, json};

use crate::{
    api::dnssec::{ensure_powerdns, pdnsutil},
    platform::{
        PlatformError,
        acme::{self, Dns01Solver},
    },
};

const CLOUDFLARE_API: &str = "https://api.cloudflare.com/client/v4";

/// How long a new TXT record may take to reach every nameserver.
const PROPAGATION: Duration = Duration::from_secs(120);

/// What the panel says about a wildcard site's DNS.
#[derive(Deserialize)]
#[serde(tag = "provider", rename_all = "snake_case")]
pub(crate) enum DnsTarget {
    /// A zone in this server's PowerDNS.
    Powerdns { zone: String },
    /// A zone on Cloudflare, with an API token that may edit its DNS.
    Cloudflare { zone: String, api_token: String },
}

fn failed(action: &str, detail: impl Into<String>) -> PlatformError {
    PlatformError::Failed { action: action.to_string(), detail: detail.into() }
}

fn normalized(zone: &str) -> String {
    zone.trim().trim_end_matches('.').to_ascii_lowercase()
}

/// `zone` is `domain` or one of its parents.
fn zone_holds(zone: &str, domain: &str) -> bool {
    domain == zone || domain.strip_suffix(zone).is_some_and(|rest| rest.ends_with('.'))
}

/// The solver for `target`, once its zone is known to hold `domain`.
pub(crate) fn solver(target: &DnsTarget, domain: &str) -> Result<Arc<dyn Dns01Solver>, String> {
    let (DnsTarget::Powerdns { zone } | DnsTarget::Cloudflare { zone, .. }) = target;
    let zone = normalized(zone);
    if !acme::valid_domain_name(&zone) || !zone_holds(&zone, domain) {
        return Err(format!("{zone} is not the DNS zone of {domain}."));
    }
    match target {
        DnsTarget::Powerdns { .. } => {
            ensure_powerdns().map_err(|_| "This server does not run PowerDNS.".to_string())?;
            Ok(Arc::new(PowerDns { zone }))
        }
        DnsTarget::Cloudflare { api_token, .. } => {
            let token = api_token.trim();
            if token.is_empty() || token.len() > 512 || !token.bytes().all(|byte| byte.is_ascii_graphic()) {
                return Err("The Cloudflare API token is missing or malformed.".into());
            }
            Ok(Arc::new(Cloudflare::new(zone, token.to_string())?))
        }
    }
}

/// Asks each of `nameservers` directly, never through a cache, until every
/// one of them serves `value` at `name`.
async fn wait_until_served(nameservers: &[String], name: &str, value: &str) -> Result<(), PlatformError> {
    let mut addresses: Vec<IpAddr> = Vec::new();
    for host in nameservers {
        if let Ok(found) = tokio::net::lookup_host((host.as_str(), 53)).await {
            addresses.extend(found.map(|address: SocketAddr| address.ip()));
        }
    }
    addresses.sort();
    addresses.dedup();
    if addresses.is_empty() {
        return Err(failed(
            "publish the DNS-01 record",
            format!("None of the zone's nameservers ({}) has an address.", nameservers.join(", ")),
        ));
    }

    let resolvers: Vec<(IpAddr, TokioAsyncResolver)> = addresses
        .into_iter()
        .map(|address| {
            let mut options = ResolverOpts::default();
            options.use_hosts_file = false;
            options.cache_size = 0;
            options.timeout = Duration::from_secs(3);
            options.attempts = 1;
            options.recursion_desired = false;
            let servers = NameServerConfigGroup::from_ips_clear(&[address], 53, true);
            (address, TokioAsyncResolver::tokio(ResolverConfig::from_parts(None, vec![], servers), options))
        })
        .collect();

    let deadline = Instant::now() + PROPAGATION;
    let mut waiting: Vec<IpAddr>;
    loop {
        waiting = Vec::new();
        for (address, resolver) in &resolvers {
            let served = resolver.txt_lookup(format!("{name}.")).await.is_ok_and(|answer| {
                answer.iter().any(|txt| {
                    let joined: Vec<u8> = txt.txt_data().iter().flat_map(|part| part.iter().copied()).collect();
                    joined == value.as_bytes()
                })
            });
            if !served {
                waiting.push(*address);
            }
        }
        if waiting.is_empty() {
            return Ok(());
        }
        if Instant::now() + Duration::from_secs(3) > deadline {
            break;
        }
        tokio::time::sleep(Duration::from_secs(3)).await;
    }
    let waiting: Vec<String> = waiting.iter().map(IpAddr::to_string).collect();
    Err(failed(
        "publish the DNS-01 record",
        format!(
            "After {} seconds {} still did not answer the TXT record {name}. Do the domain's NS records point there?",
            PROPAGATION.as_secs(),
            waiting.join(", ")
        ),
    ))
}

/// This server's PowerDNS, through `pdnsutil`, which writes the same
/// database the panel does.
struct PowerDns {
    zone: String,
}

/// The TXT values at `name` in a `pdnsutil list-zone` listing, unquoted.
fn listed_txt(listing: &str, name: &str) -> Vec<String> {
    listing
        .lines()
        .filter_map(|line| {
            let mut fields = line.split_whitespace();
            let owner = fields.next()?.trim_end_matches('.');
            let _ttl = fields.next()?;
            let (class, kind) = (fields.next()?, fields.next()?);
            if !owner.eq_ignore_ascii_case(name) || class != "IN" || kind != "TXT" {
                return None;
            }
            let content: Vec<&str> = fields.collect();
            Some(content.join(" ").trim_matches('"').to_string())
        })
        .collect()
}

impl PowerDns {
    fn current(&self, name: &str) -> Result<Vec<String>, PlatformError> {
        let run = pdnsutil(&["list-zone", &self.zone])?;
        if !run.ok {
            return Err(failed("read the zone", format!("{} is not a zone on this server.", self.zone)));
        }
        Ok(listed_txt(&run.text, name))
    }

    /// Makes the TXT set at `name` exactly `values`.
    fn set(&self, name: &str, values: &[String]) -> Result<(), PlatformError> {
        let run = if values.is_empty() {
            pdnsutil(&["delete-rrset", &self.zone, name, "TXT"])?
        } else {
            let quoted: Vec<String> = values.iter().map(|value| format!("\"{value}\"")).collect();
            let mut args = vec!["replace-rrset", self.zone.as_str(), name, "TXT", "60"];
            args.extend(quoted.iter().map(String::as_str));
            pdnsutil(&args)?
        };
        if !run.ok {
            return Err(failed("write the DNS-01 record", run.text.trim().chars().take(1000).collect::<String>()));
        }
        // A signed zone needs its NSEC3 chain to know the new name.
        let _ = pdnsutil(&["rectify-zone", &self.zone]);
        Ok(())
    }

    async fn nameservers(&self) -> Vec<String> {
        let answers = crate::dns_lookup::lookup(&crate::dns_lookup::Query { name: self.zone.clone(), kind: "NS".into() }).await;
        answers.values
    }
}

impl Dns01Solver for PowerDns {
    fn publish<'a>(&'a self, name: &'a str, value: &'a str) -> BoxFuture<'a, Result<(), PlatformError>> {
        Box::pin(async move {
            let mut values = self.current(name)?;
            if !values.iter().any(|known| known == value) {
                values.push(value.to_string());
            }
            self.set(name, &values)?;
            let nameservers = self.nameservers().await;
            if nameservers.is_empty() {
                return Err(failed(
                    "publish the DNS-01 record",
                    format!("{} has no NS records on the internet, so no CA can find it.", self.zone),
                ));
            }
            wait_until_served(&nameservers, name, value).await
        })
    }

    fn withdraw<'a>(&'a self, name: &'a str, value: &'a str) -> BoxFuture<'a, Result<(), PlatformError>> {
        Box::pin(async move {
            let values: Vec<String> = self.current(name)?.into_iter().filter(|known| known != value).collect();
            self.set(name, &values)
        })
    }
}

/// A zone on Cloudflare, through its v4 API.
struct Cloudflare {
    zone: String,
    token: String,
    http: reqwest::Client,
    /// The record each published value became, to delete exactly that one.
    records: Mutex<HashMap<String, String>>,
}

impl Cloudflare {
    fn new(zone: String, token: String) -> Result<Self, String> {
        let http = reqwest::Client::builder()
            .user_agent(concat!("drust/", env!("CARGO_PKG_VERSION")))
            .timeout(Duration::from_secs(30))
            .build()
            .map_err(|error| error.to_string())?;
        Ok(Self { zone, token, http, records: Mutex::new(HashMap::new()) })
    }

    async fn call(&self, request: reqwest::RequestBuilder, action: &str) -> Result<Value, PlatformError> {
        let response = request
            .bearer_auth(&self.token)
            .send()
            .await
            .map_err(|error| failed(action, format!("Cannot reach Cloudflare: {error}")))?;
        let status = response.status();
        let body: Value = response.json().await.unwrap_or(Value::Null);
        if status.is_success() && body["success"] == true {
            return Ok(body["result"].clone());
        }
        let reason = body["errors"]
            .as_array()
            .into_iter()
            .flatten()
            .filter_map(|error| error["message"].as_str())
            .collect::<Vec<_>>()
            .join("; ");
        Err(failed(action, format!("Cloudflare answered {status}: {}", if reason.is_empty() { "no reason" } else { &reason })))
    }

    /// The zone's id and the nameservers Cloudflare gave it.
    async fn zone(&self) -> Result<(String, Vec<String>), PlatformError> {
        let request = self.http.get(format!("{CLOUDFLARE_API}/zones")).query(&[("name", self.zone.as_str())]);
        let result = self.call(request, "find the Cloudflare zone").await?;
        let zone = result.as_array().and_then(|zones| zones.first()).ok_or_else(|| {
            failed("find the Cloudflare zone", format!("The Cloudflare token cannot see a zone named {}.", self.zone))
        })?;
        let id = zone["id"].as_str().unwrap_or_default().to_string();
        if id.is_empty() || !id.bytes().all(|byte| byte.is_ascii_alphanumeric()) {
            return Err(failed("find the Cloudflare zone", "Cloudflare sent a zone without a usable id."));
        }
        let nameservers =
            zone["name_servers"].as_array().into_iter().flatten().filter_map(Value::as_str).map(str::to_string).collect();
        Ok((id, nameservers))
    }
}

impl Dns01Solver for Cloudflare {
    fn publish<'a>(&'a self, name: &'a str, value: &'a str) -> BoxFuture<'a, Result<(), PlatformError>> {
        Box::pin(async move {
            let (zone_id, nameservers) = self.zone().await?;
            let request = self
                .http
                .post(format!("{CLOUDFLARE_API}/zones/{zone_id}/dns_records"))
                .json(&json!({ "type": "TXT", "name": name, "content": value, "ttl": 60 }));
            let record = self.call(request, "write the DNS-01 record").await?;
            if let Some(id) = record["id"].as_str() {
                self.records.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).insert(value.to_string(), id.to_string());
            }
            wait_until_served(&nameservers, name, value).await
        })
    }

    fn withdraw<'a>(&'a self, _name: &'a str, value: &'a str) -> BoxFuture<'a, Result<(), PlatformError>> {
        Box::pin(async move {
            let id = self.records.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).remove(value);
            let Some(id) = id.filter(|id| id.bytes().all(|byte| byte.is_ascii_alphanumeric())) else {
                return Ok(());
            };
            let (zone_id, _) = self.zone().await?;
            let request = self.http.delete(format!("{CLOUDFLARE_API}/zones/{zone_id}/dns_records/{id}"));
            self.call(request, "remove the DNS-01 record").await.map(|_| ())
        })
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn only_the_txt_values_at_the_name_are_read() {
        let listing = "$ORIGIN .\n\
            example.com\t3600\tIN\tSOA\tns1.example.com hostmaster.example.com 1 10800 3600 604800 3600\n\
            _acme-challenge.example.com.\t60\tIN\tTXT\t\"abc-_123\"\n\
            _acme-challenge.example.com\t60\tIN\tTXT\t\"other\"\n\
            _acme-challenge.shop.example.com\t60\tIN\tTXT\t\"not this one\"\n\
            _acme-challenge.example.com\t60\tIN\tCNAME\telsewhere.example.net\n";
        assert_eq!(listed_txt(listing, "_acme-challenge.example.com"), ["abc-_123", "other"]);
        assert!(listed_txt(listing, "_acme-challenge.example.org").is_empty());
    }

    #[test]
    fn the_zone_must_hold_the_domain() {
        assert!(zone_holds("example.com", "example.com"));
        assert!(zone_holds("example.com", "shop.example.com"));
        assert!(!zone_holds("example.com", "badexample.com"));
        assert!(!zone_holds("shop.example.com", "example.com"));

        let refused = |target: DnsTarget| solver(&target, "shop.example.com").err().unwrap_or_default();
        assert!(refused(DnsTarget::Powerdns { zone: "example.org".into() }).contains("not the DNS zone"));
        assert!(refused(DnsTarget::Powerdns { zone: "--help".into() }).contains("not the DNS zone"));
        let no_token = DnsTarget::Cloudflare { zone: "Example.com.".into(), api_token: " ".into() };
        assert!(refused(no_token).contains("token"));
        let spaced = DnsTarget::Cloudflare { zone: "example.com".into(), api_token: "a b".into() };
        assert!(refused(spaced).contains("token"));
        let fine = DnsTarget::Cloudflare { zone: "example.com".into(), api_token: "abc123".into() };
        assert!(solver(&fine, "shop.example.com").is_ok());
    }
}
