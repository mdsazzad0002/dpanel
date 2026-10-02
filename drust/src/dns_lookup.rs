//! DNS lookups against public resolvers, the way `dig @1.1.1.1` sees them.
//!
//! The panel's mail checks used PHP's resolver, which reads /etc/hosts first:
//! a server whose own hostname is listed there (server1.example.com ->
//! this IP) looked correctly set up while the rest of the internet, Gmail
//! included, saw a different address. Here /etc/hosts and caching are off.

use hickory_resolver::{
    TokioAsyncResolver,
    config::{NameServerConfigGroup, ResolverConfig, ResolverOpts},
    proto::rr::{RData, Record, RecordType},
};
use serde::{Deserialize, Serialize};
use std::{net::IpAddr, sync::OnceLock, time::Duration};

pub(crate) const MAX_QUERIES: usize = 20;

#[derive(Deserialize)]
pub(crate) struct Query {
    pub name: String,
    #[serde(rename = "type")]
    pub kind: String,
}

#[derive(Serialize)]
pub(crate) struct Answer {
    pub name: String,
    #[serde(rename = "type")]
    pub kind: String,
    /// A/AAAA: addresses; MX: "priority host"; TXT: strings joined;
    /// PTR/CNAME/NS: hostnames; SRV: "priority weight port target";
    /// CAA: `flags tag "value"`. Hostnames are lowercase without the
    /// trailing dot. CNAME is only the queried name's own alias, not the
    /// chain behind it, so an import keeps it as a CNAME.
    pub values: Vec<String>,
    /// Empty when the lookup worked, including "no such record" (values is empty).
    pub error: String,
}

fn resolver() -> &'static TokioAsyncResolver {
    static RESOLVER: OnceLock<TokioAsyncResolver> = OnceLock::new();
    RESOLVER.get_or_init(|| {
        let mut servers = NameServerConfigGroup::cloudflare();
        servers.merge(NameServerConfigGroup::google());
        let mut options = ResolverOpts::default();
        options.use_hosts_file = false;
        options.cache_size = 0;
        options.timeout = Duration::from_secs(2);
        options.attempts = 2;
        TokioAsyncResolver::tokio(ResolverConfig::from_parts(None, vec![], servers), options)
    })
}

/// A DNS name as the panel may ask for it: letters, digits, '-', '_' and dots.
pub(crate) fn valid_name(name: &str) -> bool {
    let name = name.trim_end_matches('.');
    !name.is_empty()
        && name.len() <= 253
        && name.split('.').all(|label| {
            !label.is_empty()
                && label.len() <= 63
                && label
                    .chars()
                    .all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_')
        })
}

/// The records of `record_type` owned by `name` itself; a CNAME answer also
/// carries the records of the name it points at.
fn own_records<'a>(
    name: &str,
    record_type: RecordType,
    records: impl Iterator<Item = &'a Record>,
) -> Vec<String> {
    records
        .filter(|record| record.record_type() == record_type && host(record.name()) == name)
        .filter_map(|record| match record.data()? {
            RData::CNAME(target) => Some(host(&target.0)),
            RData::NS(target) => Some(host(&target.0)),
            RData::SRV(srv) => Some(format!(
                "{} {} {} {}",
                srv.priority(),
                srv.weight(),
                srv.port(),
                host(srv.target())
            )),
            RData::CAA(caa) => Some(caa.to_string()),
            _ => None,
        })
        .collect()
}

fn host(name: &impl ToString) -> String {
    name.to_string().trim_end_matches('.').to_lowercase()
}

/// "No such record" is an empty answer, not an error.
fn is_empty_answer(error: &hickory_resolver::error::ResolveError) -> bool {
    matches!(
        error.kind(),
        hickory_resolver::error::ResolveErrorKind::NoRecordsFound { .. }
    )
}

/// Every lookup gets an answer or an error within this time, so a page of
/// checks cannot hang on one unresponsive name server.
const LOOKUP_DEADLINE: Duration = Duration::from_secs(6);

pub(crate) async fn lookup(query: &Query) -> Answer {
    match tokio::time::timeout(LOOKUP_DEADLINE, lookup_inner(query)).await {
        Ok(answer) => answer,
        Err(_) => Answer {
            name: query.name.trim().to_lowercase(),
            kind: query.kind.to_ascii_uppercase(),
            values: vec![],
            error: "DNS lookup timed out.".into(),
        },
    }
}

async fn lookup_inner(query: &Query) -> Answer {
    let kind = query.kind.to_ascii_uppercase();
    let name = query.name.trim().to_lowercase();
    let mut answer = Answer {
        name: name.clone(),
        kind: kind.clone(),
        values: vec![],
        error: String::new(),
    };

    let result: Result<Vec<String>, hickory_resolver::error::ResolveError> = match kind.as_str() {
        "PTR" => match name.parse::<IpAddr>() {
            Ok(ip) => resolver()
                .reverse_lookup(ip)
                .await
                .map(|r| r.iter().map(host).collect()),
            Err(_) => {
                answer.error = "PTR needs an IP address.".into();
                return answer;
            }
        },
        _ if !valid_name(&name) => {
            answer.error = "Invalid DNS name.".into();
            return answer;
        }
        "A" => resolver()
            .ipv4_lookup(name.as_str())
            .await
            .map(|r| r.iter().map(|a| a.to_string()).collect()),
        "AAAA" => resolver()
            .ipv6_lookup(name.as_str())
            .await
            .map(|r| r.iter().map(|a| a.to_string()).collect()),
        "MX" => resolver().mx_lookup(name.as_str()).await.map(|r| {
            r.iter()
                .map(|mx| format!("{} {}", mx.preference(), host(mx.exchange())))
                .collect()
        }),
        "TXT" => resolver().txt_lookup(name.as_str()).await.map(|r| {
            r.iter()
                .map(|txt| {
                    txt.txt_data()
                        .iter()
                        .map(|part| String::from_utf8_lossy(part).into_owned())
                        .collect::<String>()
                })
                .collect()
        }),
        "CNAME" | "NS" | "SRV" | "CAA" => {
            let record_type = match kind.as_str() {
                "CNAME" => RecordType::CNAME,
                "NS" => RecordType::NS,
                "SRV" => RecordType::SRV,
                _ => RecordType::CAA,
            };
            resolver()
                .lookup(name.as_str(), record_type)
                .await
                .map(|lookup| own_records(&name, record_type, lookup.record_iter()))
        }
        _ => {
            answer.error =
                "Unsupported record type; use A, AAAA, CNAME, MX, TXT, NS, SRV, CAA or PTR.".into();
            return answer;
        }
    };

    match result {
        Ok(values) => answer.values = values,
        Err(error) if is_empty_answer(&error) => {}
        Err(error) => answer.error = error.to_string(),
    }
    answer
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn validates_names() {
        assert!(valid_name("mail.example.com"));
        assert!(valid_name("default._domainkey.example.com."));
        assert!(!valid_name(""));
        assert!(!valid_name("bad name.com"));
        assert!(!valid_name("a..b"));
        assert!(!valid_name(&format!("{}.com", "a".repeat(64))));
        assert!(!valid_name("x;rm -rf /"));
    }

    /// Repeats the mail checks to find intermittent failures.
    #[tokio::test]
    #[ignore]
    async fn repeated_lookups_are_stable() {
        let q = |name: &str, kind: &str| Query { name: name.into(), kind: kind.into() };
        let mut errors = 0;
        let mut values = std::collections::BTreeMap::<String, std::collections::BTreeSet<String>>::new();
        for _ in 0..15 {
            for (name, kind) in [("159.198.43.2", "PTR"), ("mail.dpanel.likesoftbd.com", "A"), ("drupal.dpanel.likesoftbd.com", "MX"), ("drupal.dpanel.likesoftbd.com", "TXT"), ("default._domainkey.drupal.dpanel.likesoftbd.com", "TXT"), ("_dmarc.drupal.dpanel.likesoftbd.com", "TXT"), ("untriflingly-chechen.vpsrdns.web-hosting.com", "A")] {
                let start = std::time::Instant::now();
                let answer = lookup(&q(name, kind)).await;
                if !answer.error.is_empty() {
                    errors += 1;
                    println!("ERROR {kind} {name} after {:?}: {}", start.elapsed(), answer.error);
                }
                values.entry(format!("{kind} {name}")).or_default().insert(format!("{:?}", answer.values));
            }
        }
        for (key, seen) in &values {
            println!("{key}: {} distinct -> {:?}", seen.len(), seen.iter().map(|v| v.chars().take(60).collect::<String>()).collect::<Vec<_>>());
        }
        println!("errors: {errors}");
    }

    /// Needs the network: cargo test dns_lookup -- --ignored
    #[tokio::test]
    #[ignore]
    async fn resolves_public_records() {
        let q = |name: &str, kind: &str| Query { name: name.into(), kind: kind.into() };
        let a = lookup(&q("one.one.one.one", "A")).await;
        assert!(a.values.contains(&"1.1.1.1".to_string()), "{:?} {}", a.values, a.error);
        let ptr = lookup(&q("1.1.1.1", "PTR")).await;
        assert_eq!(ptr.values, vec!["one.one.one.one"]);
        let cname = lookup(&q("www.github.com", "CNAME")).await;
        assert_eq!(cname.values, vec!["github.com"], "{}", cname.error);
        let none = lookup(&q("github.com", "CNAME")).await;
        assert!(none.values.is_empty() && none.error.is_empty(), "{:?} {}", none.values, none.error);
        let caa = lookup(&q("google.com", "CAA")).await;
        assert!(caa.values.iter().any(|v| v.contains("issue \"pki.goog")), "{:?} {}", caa.values, caa.error);
        let srv = lookup(&q("_xmpp-server._tcp.jabber.org", "SRV")).await;
        assert!(srv.values.iter().all(|v| v.split(' ').count() == 4), "{:?}", srv.values);
        println!("{:?} {:?}", caa.values, srv.values);
        let missing = lookup(&q("no-such-name.invalid", "A")).await;
        assert!(missing.values.is_empty());
        for (name, kind) in [("server1.dengrweb.com", "A"), ("159.198.43.2", "PTR"), ("mail.dpanel.likesoftbd.com", "A"), ("drupal.dpanel.likesoftbd.com", "MX"), ("drupal.dpanel.likesoftbd.com", "TXT")] {
            let answer = lookup(&q(name, kind)).await;
            println!("{kind:4} {name:34} -> {:?} {}", answer.values, answer.error);
        }
    }
}
