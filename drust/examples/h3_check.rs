//! Standalone HTTP/3 reachability check, independent of curl (this host's
//! libcurl build has no HTTP/3 support). Does a real QUIC handshake and
//! HTTP/3 request against a live domain, using the OS-independent
//! webpki-roots trust store (real certificate validation, not skipped).
//!
//! Usage: cargo run --release --example h3_check -- <host> [path]

use std::sync::Arc;

use rustls::{ClientConfig, RootCertStore, crypto::ring::default_provider};

#[tokio::main]
async fn main() {
    let mut args = std::env::args().skip(1);
    let host = args.next().unwrap_or_else(|| {
        eprintln!("usage: h3_check <host> [path]");
        std::process::exit(1);
    });
    let path = args.next().unwrap_or_else(|| "/".to_string());

    let mut roots = RootCertStore::empty();
    roots.extend(webpki_roots::TLS_SERVER_ROOTS.iter().cloned());

    let mut client_crypto = ClientConfig::builder_with_provider(default_provider().into())
        .with_safe_default_protocol_versions()
        .expect("protocol versions")
        .with_root_certificates(roots)
        .with_no_client_auth();
    client_crypto.alpn_protocols = vec![b"h3".to_vec()];

    let quic_client_config = quinn::crypto::rustls::QuicClientConfig::try_from(client_crypto)
        .expect("quic client config");
    let client_config = quinn::ClientConfig::new(Arc::new(quic_client_config));

    let mut endpoint =
        quinn::Endpoint::client("0.0.0.0:0".parse().unwrap()).expect("client endpoint");
    endpoint.set_default_client_config(client_config);

    let addr = tokio::net::lookup_host((host.as_str(), 443))
        .await
        .expect("dns lookup failed")
        .next()
        .unwrap_or_else(|| {
            eprintln!("no address found for {host}");
            std::process::exit(1);
        });

    eprintln!("Connecting to {addr} over QUIC (HTTP/3) for host {host} ...");
    let connection = endpoint
        .connect(addr, &host)
        .expect("connect initiate failed")
        .await
        .unwrap_or_else(|error| {
            eprintln!(
                "QUIC handshake failed: {error}\n\
                 Likely cause: UDP/443 is not reachable (firewall/NAT), or the \
                 server isn't advertising ALPN \"h3\" on that port."
            );
            std::process::exit(1);
        });
    eprintln!("QUIC handshake succeeded.");

    let h3_connection = h3_quinn::Connection::new(connection);
    let (mut driver, mut send_request) = h3::client::new(h3_connection)
        .await
        .expect("h3 connection setup failed");

    let drive = async move {
        std::future::poll_fn(|cx| driver.poll_close(cx)).await;
    };

    let request = async move {
        let uri = format!("https://{host}{path}");
        let req = http::Request::builder().uri(uri).body(()).unwrap();
        let mut stream = send_request
            .send_request(req)
            .await
            .expect("send request failed");
        stream.finish().await.expect("finish request failed");
        stream.recv_response().await.expect("recv response failed")
    };

    let response = tokio::select! {
        response = request => response,
        _ = drive => {
            eprintln!("connection closed before a response arrived");
            std::process::exit(1);
        }
    };

    println!("HTTP/3 response status: {}", response.status());
}
