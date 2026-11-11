//! The IP addresses on this machine's network interfaces (backlog U5), for
//! the panel's IP pool. Unix reads them with `getifaddrs`; Windows has no
//! implementation yet and says so.

use std::net::IpAddr;

use serde::Serialize;

use super::PlatformError;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct InterfaceAddress {
    pub interface: String,
    pub address: IpAddr,
    /// Network prefix length from the netmask, `0` when there is none.
    pub prefix: u8,
}

/// Where the addresses come from. A trait so the tests need no real interfaces.
pub trait AddressSource: Send + Sync {
    fn addresses(&self) -> Result<Vec<InterfaceAddress>, PlatformError>;
}

pub struct SystemAddresses;

impl AddressSource for SystemAddresses {
    fn addresses(&self) -> Result<Vec<InterfaceAddress>, PlatformError> {
        native()
    }
}

#[cfg(unix)]
fn native() -> Result<Vec<InterfaceAddress>, PlatformError> {
    super::unix::net::addresses().map_err(|error| PlatformError::from_io("list network addresses", error))
}

#[cfg(not(unix))]
fn native() -> Result<Vec<InterfaceAddress>, PlatformError> {
    Err(PlatformError::Unsupported { capability: super::capabilities::Capability::IpAddresses })
}

/// One address a website or mail could use, as `GET /system/addresses` shows it.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct ServerAddress {
    pub interface: String,
    pub address: String,
    pub prefix: u8,
    /// `ipv4` or `ipv6`.
    pub family: &'static str,
    /// Reachable from the internet as it is; a private one needs a NAT
    /// address in the pool before it goes into DNS.
    pub public: bool,
}

/// The addresses a site could answer on: no loopback, link-local,
/// multicast or unspecified ones, each address once, IPv4 first.
pub fn usable(found: Vec<InterfaceAddress>) -> Vec<ServerAddress> {
    let mut list = found
        .into_iter()
        .filter(|entry| assignable(entry.address))
        .map(|entry| ServerAddress {
            interface: entry.interface,
            address: entry.address.to_string(),
            prefix: entry.prefix,
            family: if entry.address.is_ipv4() { "ipv4" } else { "ipv6" },
            public: is_public(entry.address),
        })
        .collect::<Vec<_>>();
    list.sort_by(|a, b| {
        let key = |entry: &ServerAddress| (entry.family != "ipv4", entry.address.parse::<IpAddr>().ok());
        key(a).cmp(&key(b))
    });
    list.dedup_by(|a, b| a.address == b.address);
    list
}

fn assignable(ip: IpAddr) -> bool {
    if ip.is_loopback() || ip.is_unspecified() || ip.is_multicast() {
        return false;
    }
    match ip {
        IpAddr::V4(v4) => !v4.is_link_local() && !v4.is_broadcast(),
        // fe80::/10: only valid on its own link, with a zone id.
        IpAddr::V6(v6) => (v6.segments()[0] & 0xffc0) != 0xfe80,
    }
}

/// Not private (RFC 1918, ULA), shared (CGNAT 100.64/10) or documentation space.
pub fn is_public(ip: IpAddr) -> bool {
    if !assignable(ip) {
        return false;
    }
    match ip {
        IpAddr::V4(v4) => {
            let [a, b, c, _] = v4.octets();
            !(v4.is_private()
                || (a == 100 && (64..128).contains(&b))
                || (a == 192 && b == 0 && c == 2)
                || (a == 198 && b == 51 && c == 100)
                || (a == 203 && b == 0 && c == 113)
                || (a == 198 && (18..20).contains(&b)))
        }
        IpAddr::V6(v6) => {
            let first = v6.segments()[0];
            // fc00::/7 unique local, 2001:db8::/32 documentation.
            (first & 0xfe00) != 0xfc00 && !(first == 0x2001 && v6.segments()[1] == 0x0db8)
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    struct FakeAddresses(Vec<InterfaceAddress>);

    impl AddressSource for FakeAddresses {
        fn addresses(&self) -> Result<Vec<InterfaceAddress>, PlatformError> {
            Ok(self.0.clone())
        }
    }

    fn entry(interface: &str, address: &str, prefix: u8) -> InterfaceAddress {
        InterfaceAddress { interface: interface.into(), address: address.parse().unwrap(), prefix }
    }

    #[test]
    fn only_addresses_a_site_can_use_are_listed_once_ipv4_first() {
        let source = FakeAddresses(vec![
            entry("lo", "127.0.0.1", 8),
            entry("lo", "::1", 128),
            entry("eth0", "2a01:4f8::10", 64),
            entry("eth0", "fe80::1", 64),
            entry("eth0", "203.0.114.20", 24),
            entry("eth0:1", "10.0.0.5", 24),
            entry("eth1", "10.0.0.5", 24),
            entry("eth0", "169.254.1.1", 16),
            entry("eth0", "fd00::5", 64),
        ]);
        let list = usable(source.addresses().unwrap());
        let addresses = list.iter().map(|entry| (entry.address.as_str(), entry.family, entry.public)).collect::<Vec<_>>();
        assert_eq!(addresses, vec![
            ("10.0.0.5", "ipv4", false),
            ("203.0.114.20", "ipv4", true),
            ("2a01:4f8::10", "ipv6", true),
            ("fd00::5", "ipv6", false),
        ]);
        assert_eq!(list[0].interface, "eth0:1");
        assert_eq!(list[1].prefix, 24);
    }

    #[test]
    fn private_shared_and_documentation_space_is_not_public() {
        for private in ["10.1.2.3", "172.16.0.1", "192.168.1.1", "100.64.0.1", "192.0.2.10", "198.51.100.7", "203.0.113.9", "fd12::1", "2001:db8::1"] {
            assert!(!is_public(private.parse().unwrap()), "{private}");
        }
        for public in ["8.8.8.8", "100.128.0.1", "2606:4700::1111"] {
            assert!(is_public(public.parse().unwrap()), "{public}");
        }
    }

    #[test]
    fn the_real_source_answers_or_says_unsupported() {
        match SystemAddresses.addresses() {
            Ok(list) => assert!(list.iter().all(|entry| !entry.interface.is_empty())),
            Err(error) => assert!(matches!(error, PlatformError::Unsupported { .. } | PlatformError::Failed { .. })),
        }
    }
}
