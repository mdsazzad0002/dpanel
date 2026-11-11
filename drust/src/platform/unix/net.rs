//! The addresses on this machine's interfaces, from `getifaddrs(3)`.

use std::{
    ffi::CStr,
    io,
    net::{IpAddr, Ipv4Addr, Ipv6Addr},
};

use crate::platform::network::InterfaceAddress;

pub fn addresses() -> io::Result<Vec<InterfaceAddress>> {
    let mut head: *mut libc::ifaddrs = std::ptr::null_mut();
    // SAFETY: `head` is a writable pointer; the list is freed below.
    if unsafe { libc::getifaddrs(&mut head) } != 0 {
        return Err(io::Error::last_os_error());
    }
    let mut found = Vec::new();
    let mut cursor = head;
    while !cursor.is_null() {
        // SAFETY: `cursor` is a node of the list getifaddrs returned, alive until freeifaddrs.
        let entry = unsafe { &*cursor };
        cursor = entry.ifa_next;
        if entry.ifa_name.is_null() {
            continue;
        }
        let Some(address) = ip_of(entry.ifa_addr) else {
            continue;
        };
        let prefix = ip_of(entry.ifa_netmask).map_or(0, prefix_length);
        // SAFETY: a non-null `ifa_name` is a NUL-terminated string owned by the list.
        let interface = unsafe { CStr::from_ptr(entry.ifa_name) }.to_string_lossy().into_owned();
        found.push(InterfaceAddress { interface, address, prefix });
    }
    // SAFETY: `head` came from getifaddrs and nothing points into the list any more.
    unsafe { libc::freeifaddrs(head) };
    Ok(found)
}

/// The IPv4 or IPv6 address in a sockaddr from the list, if it holds one.
fn ip_of(addr: *const libc::sockaddr) -> Option<IpAddr> {
    if addr.is_null() {
        return None;
    }
    // SAFETY: getifaddrs gives sockaddrs whose family says which struct they are.
    unsafe {
        match i32::from((*addr).sa_family) {
            libc::AF_INET => {
                let v4 = &*(addr as *const libc::sockaddr_in);
                Some(IpAddr::V4(Ipv4Addr::from(u32::from_be(v4.sin_addr.s_addr))))
            }
            libc::AF_INET6 => {
                let v6 = &*(addr as *const libc::sockaddr_in6);
                Some(IpAddr::V6(Ipv6Addr::from(v6.sin6_addr.s6_addr)))
            }
            _ => None,
        }
    }
}

fn prefix_length(mask: IpAddr) -> u8 {
    match mask {
        IpAddr::V4(v4) => u32::from(v4).count_ones() as u8,
        IpAddr::V6(v6) => u128::from(v6).count_ones() as u8,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn netmasks_become_prefix_lengths() {
        assert_eq!(prefix_length("255.255.255.0".parse().unwrap()), 24);
        assert_eq!(prefix_length("ffff:ffff:ffff:ffff::".parse().unwrap()), 64);
    }

    #[test]
    fn the_loopback_interface_is_listed() {
        let list = addresses().unwrap();
        assert!(list.iter().any(|entry| entry.address.is_loopback()));
    }
}
