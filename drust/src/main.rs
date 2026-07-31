mod admin;
mod api;
mod app;
mod database;
mod filemanager;
mod health;
mod laravel;
mod php;
mod php_config;
mod edge_gateway;
mod script;
mod scripts;
mod ssl;
mod vhost;
mod vhost_ops;

fn main() -> std::process::ExitCode {
    tracing_subscriber::fmt::init();
    let args = std::env::args().skip(1).collect::<Vec<_>>();
    if args.first().map(String::as_str) == Some("edge-gateway") {
        let bind = std::env::var("DRUST_EDGE_GATEWAY_BIND")
            .unwrap_or_else(|_| "127.0.0.1:9500".to_string());
        return match edge_gateway::serve_gateway(&bind) {
            Ok(()) => std::process::ExitCode::from(0),
            Err(error) => {
                eprintln!("{error}");
                std::process::ExitCode::from(1)
            }
        };
    }
    if args.first().map(String::as_str) == Some("demo-server") {
        let mut http_bind = "127.0.0.1:8088".to_string();
        let mut https_bind = "127.0.0.1:8443".to_string();
        let mut cert_path: Option<String> = None;
        let mut key_path: Option<String> = None;
        let mut iter = args.into_iter().skip(1);
        while let Some(arg) = iter.next() {
            match arg.as_str() {
                "--http" => {
                    if let Some(value) = iter.next() {
                        http_bind = value;
                    }
                }
                "--https" => {
                    if let Some(value) = iter.next() {
                        https_bind = value;
                    }
                }
                "--cert" => {
                    if let Some(value) = iter.next() {
                        cert_path = Some(value);
                    }
                }
                "--key" => {
                    if let Some(value) = iter.next() {
                        key_path = Some(value);
                    }
                }
                other => {
                    eprintln!("Unknown demo-server option: {other}");
                    return std::process::ExitCode::from(1);
                }
            }
        }

        let result = if let (Some(cert), Some(key)) = (cert_path, key_path) {
            let tls_runtime = edge_gateway::default_tls_runtime();
            eprintln!(
                "TLS runtime prepared: cert_dir={}, key_dir={}",
                tls_runtime.cert_dir.display(),
                tls_runtime.key_dir.display()
            );
            let tls = edge_gateway::TlsListenerConfig::new(
                https_bind,
                http_bind.clone(),
                edge_gateway::sample_tls_store(cert.into(), key.into()),
            );
            edge_gateway::serve_demo_with_tls(
                edge_gateway::sample_snapshot(),
                edge_gateway::sample_dispatch_context(),
                &http_bind,
                tls,
            )
        } else {
            edge_gateway::serve_demo_with_tls(
                edge_gateway::sample_snapshot(),
                edge_gateway::sample_dispatch_context(),
                &http_bind,
                edge_gateway::scaffold_tls_listener_config(&https_bind, &http_bind),
            )
        };
        return match result {
            Ok(()) => std::process::ExitCode::from(0),
            Err(error) => {
                eprintln!("{error}");
                std::process::ExitCode::from(1)
            }
        };
    }
    api::serve(args)
}
