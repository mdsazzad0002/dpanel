#![allow(dead_code)]

//! HTTP/3 (QUIC) listener. Runs alongside the TCP HTTP/1.1+h2 listener on the
//! same port, sharing the same [`super::DynamicCertResolver`] and the same
//! axum [`Router`] (including its host-header/x-forwarded-proto middleware),
//! so request handling stays identical across protocols.

use std::{net::SocketAddr, sync::Arc};

use axum::{
    body::{Body, Bytes},
    extract::Request,
    response::Response,
};
use bytes::Buf;
use futures_util::{Stream, StreamExt};
use h3::{quic::BidiStream, server::RequestStream};
use tower::ServiceExt;
use tracing::{info, warn};

use super::DynamicCertResolver;
use super::tls::build_quic_server_config;

pub async fn run_h3_listener(
    router: axum::Router,
    bind: String,
    resolver: Arc<DynamicCertResolver>,
) -> Result<(), String> {
    let addr: SocketAddr = bind
        .parse()
        .map_err(|error| format!("quic bind address invalid: {error}"))?;
    let server_config = build_quic_server_config(resolver)?;
    let endpoint = quinn::Endpoint::server(server_config, addr)
        .map_err(|error| format!("quic endpoint bind failed: {error}"))?;
    info!(bind = %bind, "HTTP/3 (QUIC) listener ready");

    while let Some(incoming) = endpoint.accept().await {
        let router = router.clone();
        tokio::spawn(async move {
            let connection = match incoming.await {
                Ok(connection) => connection,
                Err(error) => {
                    warn!(%error, "QUIC handshake failed");
                    return;
                }
            };
            let peer = connection.remote_address();
            let h3_connection = h3_quinn::Connection::new(connection);
            let mut h3_conn = match h3::server::builder().build(h3_connection).await {
                Ok(conn) => conn,
                Err(error) => {
                    warn!(%peer, %error, "HTTP/3 connection setup failed");
                    return;
                }
            };
            loop {
                match h3_conn.accept().await {
                    Ok(Some(resolver)) => {
                        let router = router.clone();
                        tokio::spawn(async move {
                            match resolver.resolve_request().await {
                                Ok((request, stream)) => {
                                    handle_h3_request(router, request, stream).await;
                                }
                                Err(error) => {
                                    warn!(%peer, %error, "HTTP/3 request resolve failed");
                                }
                            }
                        });
                    }
                    Ok(None) => break,
                    Err(error) => {
                        warn!(%peer, %error, "HTTP/3 connection closed");
                        break;
                    }
                }
            }
        });
    }
    Ok(())
}

async fn handle_h3_request<S>(
    router: axum::Router,
    request: http::Request<()>,
    stream: RequestStream<S, Bytes>,
) where
    S: BidiStream<Bytes> + Send + 'static,
    S::SendStream: Send,
    S::RecvStream: Send,
{
    let (mut send, recv) = stream.split();
    let (parts, _) = request.into_parts();
    let body = Body::from_stream(recv_data_stream(recv));
    let axum_request = Request::from_parts(parts, body);

    let response: Response = match router.oneshot(axum_request).await {
        Ok(response) => response,
        Err(never) => match never {},
    };

    let (parts, body) = response.into_parts();
    if let Err(error) = send
        .send_response(http::Response::from_parts(parts, ()))
        .await
    {
        warn!(%error, "HTTP/3 response head send failed");
        return;
    }

    let mut data_stream = body.into_data_stream();
    while let Some(chunk) = data_stream.next().await {
        match chunk {
            Ok(bytes) => {
                if let Err(error) = send.send_data(bytes).await {
                    warn!(%error, "HTTP/3 response body send failed");
                    return;
                }
            }
            Err(error) => {
                warn!(%error, "HTTP/3 response body read failed");
                return;
            }
        }
    }
    if let Err(error) = send.finish().await {
        warn!(%error, "HTTP/3 response finish failed");
    }
}

fn recv_data_stream<S>(
    stream: RequestStream<S, Bytes>,
) -> impl Stream<Item = Result<Bytes, axum::Error>>
where
    S: h3::quic::RecvStream,
{
    futures_util::stream::unfold(stream, |mut stream| async move {
        match stream.recv_data().await {
            Ok(Some(mut buf)) => {
                let bytes = buf.copy_to_bytes(buf.remaining());
                Some((Ok(bytes), stream))
            }
            Ok(None) => None,
            Err(error) => Some((Err(axum::Error::new(error)), stream)),
        }
    })
}

#[cfg(test)]
mod tests {
    use std::{process::Command, sync::Arc, time::Duration};

    use rustls::{
        DigitallySignedStruct, SignatureScheme,
        client::danger::{HandshakeSignatureValid, ServerCertVerified, ServerCertVerifier},
        crypto::ring::default_provider,
        pki_types::{CertificateDer, ServerName, UnixTime},
    };

    use crate::edge_gateway::{
        DbSnapshotConfig, SnapshotCacheConfig, TlsIdentity, TlsStore, build_tls_config,
        sample_dispatch_context, sample_snapshot,
        server::{build_demo_router, make_demo_state},
    };

    use super::run_h3_listener;

    fn generate_self_signed_cert(dir: &std::path::Path) -> (std::path::PathBuf, std::path::PathBuf) {
        let cert = dir.join("cert.pem");
        let key = dir.join("key.pem");
        let status = Command::new("openssl")
            .args([
                "req",
                "-x509",
                "-newkey",
                "rsa:2048",
                "-nodes",
                "-keyout",
                key.to_str().unwrap(),
                "-out",
                cert.to_str().unwrap(),
                "-days",
                "1",
                "-subj",
                "/CN=localhost",
                "-addext",
                "subjectAltName=DNS:localhost",
            ])
            .status()
            .expect("openssl invocation failed; is openssl installed?");
        assert!(status.success(), "openssl cert generation failed");
        (cert, key)
    }

    /// Skips chain-of-trust verification so the test can use a throwaway
    /// self-signed leaf cert without minting a matching root CA.
    #[derive(Debug)]
    struct AcceptAnyCert;

    impl ServerCertVerifier for AcceptAnyCert {
        fn verify_server_cert(
            &self,
            _end_entity: &CertificateDer<'_>,
            _intermediates: &[CertificateDer<'_>],
            _server_name: &ServerName<'_>,
            _ocsp_response: &[u8],
            _now: UnixTime,
        ) -> Result<ServerCertVerified, rustls::Error> {
            Ok(ServerCertVerified::assertion())
        }

        fn verify_tls12_signature(
            &self,
            _message: &[u8],
            _cert: &CertificateDer<'_>,
            _dss: &DigitallySignedStruct,
        ) -> Result<HandshakeSignatureValid, rustls::Error> {
            Ok(HandshakeSignatureValid::assertion())
        }

        fn verify_tls13_signature(
            &self,
            _message: &[u8],
            _cert: &CertificateDer<'_>,
            _dss: &DigitallySignedStruct,
        ) -> Result<HandshakeSignatureValid, rustls::Error> {
            Ok(HandshakeSignatureValid::assertion())
        }

        fn supported_verify_schemes(&self) -> Vec<SignatureScheme> {
            default_provider()
                .signature_verification_algorithms
                .supported_schemes()
        }
    }

    /// End-to-end smoke test: real QUIC handshake, real HTTP/3 request/response
    /// framing, through the same axum router the TCP h1/h2 listener uses.
    /// Guards against silently breaking the h3 <-> axum body bridge.
    #[tokio::test]
    async fn serves_http3_request_end_to_end() {
        let bind_addr = "127.0.0.1:18443";
        let dir = tempfile::tempdir().expect("tempdir");
        let (cert_path, key_path) = generate_self_signed_cert(dir.path());

        let store = TlsStore {
            identities: Arc::from([TlsIdentity {
                hostnames: Arc::from(["localhost".to_string()]),
                cert_path,
                key_path,
            }]),
        };
        let (_, resolver) = build_tls_config(&store).expect("tls config build failed");

        let state = make_demo_state(
            sample_snapshot(),
            sample_dispatch_context(),
            SnapshotCacheConfig::fast(),
            DbSnapshotConfig::new(),
        )
        .expect("demo state build failed");
        let router = build_demo_router(state);

        tokio::spawn(run_h3_listener(
            router,
            bind_addr.to_string(),
            resolver,
        ));
        // Give the QUIC endpoint a moment to bind before the client connects.
        tokio::time::sleep(Duration::from_millis(200)).await;

        let mut client_crypto = rustls::ClientConfig::builder_with_provider(default_provider().into())
            .with_safe_default_protocol_versions()
            .expect("client protocol versions")
            .dangerous()
            .with_custom_certificate_verifier(Arc::new(AcceptAnyCert))
            .with_no_client_auth();
        client_crypto.alpn_protocols = vec![b"h3".to_vec()];
        let quic_client_config = quinn::crypto::rustls::QuicClientConfig::try_from(client_crypto)
            .expect("quic client config");
        let client_config = quinn::ClientConfig::new(Arc::new(quic_client_config));

        let mut endpoint =
            quinn::Endpoint::client("127.0.0.1:0".parse().unwrap()).expect("client endpoint");
        endpoint.set_default_client_config(client_config);

        let connect = async {
            let connection = endpoint
                .connect(bind_addr.parse().unwrap(), "localhost")
                .expect("connect initiate")
                .await
                .expect("quic handshake");
            let h3_connection = h3_quinn::Connection::new(connection);
            let (mut driver, mut send_request) = h3::client::new(h3_connection)
                .await
                .expect("h3 client handshake");

            let drive = async move {
                std::future::poll_fn(|cx| driver.poll_close(cx)).await;
            };

            let request = async move {
                let req = http::Request::builder()
                    .uri("https://localhost/__admin/health")
                    .body(())
                    .unwrap();
                let mut stream = send_request.send_request(req).await.expect("send request");
                stream.finish().await.expect("finish request");
                stream.recv_response().await.expect("recv response")
            };

            tokio::select! {
                response = request => response,
                _ = drive => panic!("h3 connection driver exited before response"),
            }
        };

        let response = tokio::time::timeout(Duration::from_secs(10), connect)
            .await
            .expect("http/3 request timed out");
        assert_eq!(response.status(), http::StatusCode::OK);
    }
}
