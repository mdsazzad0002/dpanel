//! Which site a request gets, given the local address it arrived on
//! (backlog U5). The gateway listens on every address (`0.0.0.0`/`[::]`)
//! and checks the address of each connection here, instead of one listener
//! per address: an address added to the server later works without a
//! restart, and a site moved to another IP only needs a reload.
//!
//! Only addresses in the panel's IP pool (`ip_addresses`) have rules. On a
//! dedicated address only the sites assigned to it answer, and a request
//! for a bare IP gets that address's default site. Anything else (loopback,
//! an address outside the pool) matches by Host alone, as before.

use std::{
    collections::HashMap,
    net::IpAddr,
};

use super::{RuntimeSnapshot, SiteConfig, resolve_site};

/// The local address a request arrived on, put into the request's
/// extensions by the listeners. IPv4 over the dual-stack socket arrives as
/// `::ffff:a.b.c.d`; it is stored as plain IPv4.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub struct LocalAddr(pub IpAddr);

impl LocalAddr {
    pub fn new(ip: IpAddr) -> Self {
        Self(canonical(ip))
    }
}

pub fn canonical(ip: IpAddr) -> IpAddr {
    match ip {
        IpAddr::V6(v6) => v6.to_ipv4_mapped().map_or(IpAddr::V6(v6), IpAddr::V4),
        other => other,
    }
}

/// One pool address as the gateway needs it.
#[derive(Clone, Debug, Default, PartialEq, Eq, Hash)]
pub struct AddressRule {
    /// Dedicated to a user or reseller: only its own sites answer here.
    pub dedicated: bool,
    /// The site shown for a request to the bare IP.
    pub default_site: Option<String>,
}

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub struct IpBindings {
    /// Keyed by the address the gateway sees (the private one behind NAT).
    pub addresses: HashMap<IpAddr, AddressRule>,
    /// Site id to its assigned address; a site without one is on the main IP.
    pub site_ips: HashMap<String, IpAddr>,
}

impl IpBindings {
    /// Order-independent text for the snapshot fingerprint.
    pub fn fingerprint(&self) -> String {
        let mut parts = self
            .addresses
            .iter()
            .map(|(ip, rule)| format!("a {ip} {} {:?}", rule.dedicated, rule.default_site))
            .chain(self.site_ips.iter().map(|(id, ip)| format!("s {id} {ip}")))
            .collect::<Vec<_>>();
        parts.sort_unstable();
        parts.join("\n")
    }
}

/// `host` as a request carries it (may have a port); `local` is where the
/// connection arrived, `None` when unknown (tests, internal calls).
pub fn select_site<'a>(snapshot: &'a RuntimeSnapshot, host: &str, local: Option<IpAddr>) -> Option<&'a SiteConfig> {
    let found = resolve_site(snapshot, host);
    let Some(local) = local.map(canonical) else {
        return found;
    };
    let Some(rule) = snapshot.ip_bindings.addresses.get(&local) else {
        return found;
    };
    match found {
        Some(site) if !rule.dedicated || snapshot.ip_bindings.site_ips.get(&site.id) == Some(&local) => Some(site),
        // Another site's name on a dedicated address: as if unknown.
        Some(_) => None,
        None if is_bare_ip(host) => rule
            .default_site
            .as_deref()
            .and_then(|id| snapshot.sites.iter().find(|site| site.id == id)),
        None => None,
    }
}

/// An IPv4 or IPv6 literal, with or without port and brackets, or no host at all.
fn is_bare_ip(host: &str) -> bool {
    let host = host.trim();
    if host.is_empty() || host == "-" {
        return true;
    }
    if let Some(rest) = host.strip_prefix('[') {
        return rest.split(']').next().is_some_and(|inner| inner.parse::<IpAddr>().is_ok());
    }
    if host.parse::<IpAddr>().is_ok() {
        return true;
    }
    host.rsplit_once(':').is_some_and(|(address, _)| address.parse::<IpAddr>().is_ok())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::edge_gateway::{CachePolicy, RouteAction, RouteConfig};
    use std::{sync::Arc, time::Duration};

    fn site(id: &str, host: &str) -> SiteConfig {
        let mut site = crate::edge_gateway::basic_auth::test_site();
        site.id = id.to_string();
        site.hostnames = Arc::from([host.to_string()]);
        site.routes = Arc::from([RouteConfig { path_prefix: "/".to_string(), action: RouteAction::Static }]);
        site
    }

    fn snapshot(bindings: IpBindings) -> RuntimeSnapshot {
        let sites = [site("shared", "shared.test"), site("own", "own.test"), site("other", "other.test")];
        let cache = CachePolicy { enabled: false, ttl: Duration::from_secs(0), stale_while_revalidate: Duration::from_secs(0) };
        RuntimeSnapshot::new(1, Arc::from(sites), Arc::from([]), cache).with_ip_bindings(bindings)
    }

    fn ip(text: &str) -> IpAddr {
        text.parse().unwrap()
    }

    fn bindings() -> IpBindings {
        let mut bindings = IpBindings::default();
        bindings.addresses.insert(ip("10.0.0.5"), AddressRule { dedicated: false, default_site: Some("shared".into()) });
        bindings.addresses.insert(ip("10.0.0.6"), AddressRule { dedicated: true, default_site: Some("own".into()) });
        bindings.addresses.insert(ip("2001:db8::6"), AddressRule { dedicated: true, default_site: None });
        bindings.site_ips.insert("own".into(), ip("10.0.0.6"));
        bindings
    }

    fn id(site: Option<&SiteConfig>) -> Option<&str> {
        site.map(|site| site.id.as_str())
    }

    #[test]
    fn a_dedicated_address_answers_only_for_its_own_sites() {
        let snapshot = snapshot(bindings());
        assert_eq!(id(select_site(&snapshot, "own.test", Some(ip("10.0.0.6")))), Some("own"));
        assert_eq!(id(select_site(&snapshot, "other.test", Some(ip("10.0.0.6")))), None);
        assert_eq!(id(select_site(&snapshot, "shared.test:443", Some(ip("10.0.0.6")))), None);
        // Nothing is assigned to the IPv6 address, so no name answers there.
        assert_eq!(id(select_site(&snapshot, "own.test", Some(ip("2001:db8::6")))), None);
    }

    #[test]
    fn a_shared_address_serves_every_name_and_a_dedicated_site_stays_reachable() {
        let snapshot = snapshot(bindings());
        assert_eq!(id(select_site(&snapshot, "other.test", Some(ip("10.0.0.5")))), Some("other"));
        // Lenient on purpose: while DNS still points at the old address after a move.
        assert_eq!(id(select_site(&snapshot, "own.test", Some(ip("10.0.0.5")))), Some("own"));
    }

    #[test]
    fn a_bare_ip_request_gets_the_address_default_site() {
        let snapshot = snapshot(bindings());
        assert_eq!(id(select_site(&snapshot, "10.0.0.6", Some(ip("10.0.0.6")))), Some("own"));
        assert_eq!(id(select_site(&snapshot, "203.0.113.6:80", Some(ip("10.0.0.6")))), Some("own"));
        assert_eq!(id(select_site(&snapshot, "", Some(ip("10.0.0.5")))), Some("shared"));
        assert_eq!(id(select_site(&snapshot, "[2001:db8::6]:443", Some(ip("2001:db8::6")))), None);
        // An unknown name is not the default site: that would show it under any domain.
        assert_eq!(id(select_site(&snapshot, "unknown.test", Some(ip("10.0.0.6")))), None);
    }

    #[test]
    fn mapped_ipv4_from_the_dual_stack_socket_counts_as_ipv4() {
        let snapshot = snapshot(bindings());
        assert_eq!(id(select_site(&snapshot, "other.test", Some(ip("::ffff:10.0.0.6")))), None);
        assert_eq!(LocalAddr::new(ip("::ffff:10.0.0.6")).0, ip("10.0.0.6"));
    }

    #[test]
    fn addresses_outside_the_pool_and_unknown_locals_match_by_host_only() {
        let snapshot = snapshot(bindings());
        assert_eq!(id(select_site(&snapshot, "other.test", Some(ip("127.0.0.1")))), Some("other"));
        assert_eq!(id(select_site(&snapshot, "other.test", None)), Some("other"));
        assert_eq!(id(select_site(&snapshot, "127.0.0.1", Some(ip("127.0.0.1")))), None);
        let empty = super::super::config::RuntimeSnapshot::empty();
        assert_eq!(*empty.ip_bindings, IpBindings::default());
    }

    #[test]
    fn fingerprint_ignores_order() {
        let mut first = bindings();
        let second = bindings();
        assert_eq!(first.fingerprint(), second.fingerprint());
        first.site_ips.insert("other".into(), ip("10.0.0.5"));
        assert_ne!(first.fingerprint(), second.fingerprint());
    }
}
