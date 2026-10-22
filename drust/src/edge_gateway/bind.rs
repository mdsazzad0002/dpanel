use std::io;
use std::net::{Ipv6Addr, SocketAddr};

use socket2::{Domain, Protocol, Socket, Type};
use tracing::warn;

/// `0.0.0.0:<port>` (what the installer writes) only accepts IPv4, so a
/// server's IPv6 address refuses every connection. Listen on `[::]:<port>`
/// with IPV6_V6ONLY off instead: one socket that takes both IPv4 and IPv6.
fn dual_stack_addr(bind: &str) -> Option<SocketAddr> {
    let addr: SocketAddr = bind.parse().ok()?;
    match addr {
        SocketAddr::V4(v4) if v4.ip().is_unspecified() => {
            Some(SocketAddr::from((Ipv6Addr::UNSPECIFIED, v4.port())))
        }
        _ => None,
    }
}

fn dual_stack_socket(addr: SocketAddr, kind: Type, protocol: Protocol) -> io::Result<Socket> {
    let socket = Socket::new(Domain::IPV6, kind, Some(protocol))?;
    socket.set_only_v6(false)?;
    socket.set_reuse_address(true)?;
    socket.set_nonblocking(true)?;
    socket.bind(&addr.into())?;
    Ok(socket)
}

pub async fn bind_tcp(bind: &str) -> io::Result<tokio::net::TcpListener> {
    if let Some(addr) = dual_stack_addr(bind) {
        match dual_stack_socket(addr, Type::STREAM, Protocol::TCP).and_then(|socket| {
            socket.listen(1024)?;
            tokio::net::TcpListener::from_std(socket.into())
        }) {
            Ok(listener) => return Ok(listener),
            // IPv6 disabled on this host: keep serving IPv4.
            Err(error) => warn!(%error, bind, "dual-stack bind failed; listening on IPv4 only"),
        }
    }
    tokio::net::TcpListener::bind(bind).await
}

pub fn bind_udp(addr: SocketAddr) -> io::Result<std::net::UdpSocket> {
    if let Some(dual) = dual_stack_addr(&addr.to_string()) {
        match dual_stack_socket(dual, Type::DGRAM, Protocol::UDP) {
            Ok(socket) => return Ok(socket.into()),
            Err(error) => warn!(%error, %addr, "dual-stack UDP bind failed; listening on IPv4 only"),
        }
    }
    std::net::UdpSocket::bind(addr)
}

#[cfg(test)]
mod tests {
    use super::dual_stack_addr;

    #[test]
    fn only_unspecified_ipv4_becomes_dual_stack() {
        assert_eq!(dual_stack_addr("0.0.0.0:443").unwrap().to_string(), "[::]:443");
        assert!(dual_stack_addr("127.0.0.1:443").is_none());
        assert!(dual_stack_addr("[::1]:443").is_none());
    }
}
