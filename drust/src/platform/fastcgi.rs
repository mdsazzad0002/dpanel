//! How the gateway reaches PHP. `plans/platform-spec.md` section 6.8.

use std::{
    io,
    net::SocketAddr,
    path::PathBuf,
};
use tokio::{
    io::{AsyncRead, AsyncWrite},
    net::TcpStream,
};

pub trait FastCgiIo: AsyncRead + AsyncWrite + Unpin + Send {}
impl<T: AsyncRead + AsyncWrite + Unpin + Send> FastCgiIo for T {}

pub type FastCgiStream = Box<dyn FastCgiIo>;

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum FastCgiEndpoint {
    Unix(PathBuf),
    Tcp(SocketAddr),
}

impl FastCgiEndpoint {
    pub async fn connect(&self) -> io::Result<FastCgiStream> {
        match self {
            Self::Unix(path) => connect_unix(path).await,
            // PHP trusts whoever reaches this port, so it must never leave the machine.
            Self::Tcp(address) if !address.ip().is_loopback() => Err(io::Error::new(
                io::ErrorKind::PermissionDenied,
                format!("FastCGI must stay on loopback, not {address}"),
            )),
            Self::Tcp(address) => {
                let stream = TcpStream::connect(address).await?;
                // FastCGI sends small records and waits for an answer.
                stream.set_nodelay(true)?;
                Ok(Box::new(stream))
            }
        }
    }
}

#[cfg(unix)]
async fn connect_unix(path: &std::path::Path) -> io::Result<FastCgiStream> {
    Ok(Box::new(tokio::net::UnixStream::connect(path).await?))
}

#[cfg(not(unix))]
async fn connect_unix(_path: &std::path::Path) -> io::Result<FastCgiStream> {
    Err(io::Error::new(
        io::ErrorKind::Unsupported,
        "this system has no Unix sockets",
    ))
}

/// A stand-in PHP server on every transport this OS has, so tests of the
/// FastCGI protocol stay free of OS checks.
#[cfg(test)]
pub(crate) mod testing {
    use super::*;
    use tokio::net::TcpListener;

    pub(crate) enum Listener {
        Tcp(TcpListener),
        #[cfg(unix)]
        Unix(tokio::net::UnixListener, tempfile::TempDir),
    }

    impl Listener {
        pub(crate) fn endpoint(&self) -> FastCgiEndpoint {
            match self {
                Self::Tcp(listener) => FastCgiEndpoint::Tcp(listener.local_addr().unwrap()),
                #[cfg(unix)]
                Self::Unix(_, dir) => FastCgiEndpoint::Unix(dir.path().join("fpm.sock")),
            }
        }

        pub(crate) async fn accept(&self) -> FastCgiStream {
            match self {
                Self::Tcp(listener) => Box::new(listener.accept().await.unwrap().0),
                #[cfg(unix)]
                Self::Unix(listener, _) => Box::new(listener.accept().await.unwrap().0),
            }
        }
    }

    pub(crate) async fn listeners() -> Vec<Listener> {
        let tcp = Listener::Tcp(TcpListener::bind("127.0.0.1:0").await.unwrap());
        #[cfg(unix)]
        {
            let dir = tempfile::tempdir().unwrap();
            let unix = tokio::net::UnixListener::bind(dir.path().join("fpm.sock")).unwrap();
            vec![Listener::Unix(unix, dir), tcp]
        }
        #[cfg(not(unix))]
        vec![tcp]
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use tokio::io::{AsyncReadExt, AsyncWriteExt};

    #[tokio::test]
    async fn every_transport_carries_bytes_both_ways() {
        for listener in testing::listeners().await {
            let endpoint = listener.endpoint();
            let server = tokio::spawn(async move {
                let mut stream = listener.accept().await;
                let mut buffer = [0; 4];
                stream.read_exact(&mut buffer).await.unwrap();
                stream.write_all(b"pong").await.unwrap();
                buffer
            });
            let mut client = endpoint.connect().await.unwrap();
            client.write_all(b"ping").await.unwrap();
            let mut reply = [0; 4];
            client.read_exact(&mut reply).await.unwrap();
            assert_eq!(&reply, b"pong");
            assert_eq!(&server.await.unwrap(), b"ping");
        }
    }

    #[tokio::test]
    async fn tcp_refuses_anything_but_loopback() {
        let endpoint = FastCgiEndpoint::Tcp("192.0.2.1:9100".parse().unwrap());
        let error = endpoint.connect().await.err().unwrap();
        assert_eq!(error.kind(), io::ErrorKind::PermissionDenied);
    }

    #[tokio::test]
    async fn a_closed_port_is_connection_refused_so_callers_can_retry() {
        let free = tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();
        let endpoint = FastCgiEndpoint::Tcp(free.local_addr().unwrap());
        drop(free);
        let error = endpoint.connect().await.err().unwrap();
        assert_eq!(error.kind(), io::ErrorKind::ConnectionRefused);
    }
}
