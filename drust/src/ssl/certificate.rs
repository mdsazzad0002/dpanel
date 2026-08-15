use std::net::ToSocketAddrs;
use std::path::Path;
use std::process::Command;

use serde_json::{Value, json};

use crate::app::ensure_root;

fn valid_domain(domain: &str) -> bool {
    !domain.is_empty()
        && domain.len() <= 253
        && domain.contains('.')
        && domain.bytes().all(|character| {
            character.is_ascii_alphanumeric() || character == b'.' || character == b'-'
        })
}

fn command_success(program: &str, args: &[&str]) -> bool {
    Command::new(program)
        .args(args)
        .status()
        .map(|status| status.success())
        .unwrap_or(false)
}

fn schedule_gateway_restart() -> bool {
    // SSL issuance can be requested through the edge gateway itself. Restarting
    // it synchronously here drops that in-flight HTTP response, which browsers
    // report as a network error even though the certificate was issued. A
    // transient timer lets the API response finish before the gateway restarts.
    command_success(
        "systemd-run",
        &[
            "--quiet",
            "--collect",
            "--on-active=2s",
            "systemctl",
            "restart",
            "edge-gateway.service",
        ],
    )
}

fn shell_single_quote(value: &str) -> String {
    format!("'{}'", value.replace('\'', "'\\''"))
}

fn http_auth_hook(root_path: &str) -> String {
    let challenge_dir = format!("{root_path}/.well-known/acme-challenge");
    let script = format!(
        "umask 022; mkdir -p {directory} && printf %s \"$CERTBOT_VALIDATION\" > {directory}/\"$CERTBOT_TOKEN\"",
        directory = shell_single_quote(&challenge_dir),
    );
    format!("/bin/sh -c {}", shell_single_quote(&script))
}

fn http_cleanup_hook(root_path: &str) -> String {
    let challenge_dir = format!("{root_path}/.well-known/acme-challenge");
    let well_known_dir = format!("{root_path}/.well-known");
    let script = format!(
        "rm -f -- {directory}/\"$CERTBOT_TOKEN\"; rmdir -- {directory} {well_known} 2>/dev/null || true",
        directory = shell_single_quote(&challenge_dir),
        well_known = shell_single_quote(&well_known_dir),
    );
    format!("/bin/sh -c {}", shell_single_quote(&script))
}

fn certificate_valid(path: &str, domain: &str, renew_before_days: u64) -> bool {
    let seconds = renew_before_days.saturating_mul(86_400).to_string();
    command_success(
        "openssl",
        &["x509", "-in", path, "-noout", "-checkend", &seconds],
    ) && command_success(
        "openssl",
        &["x509", "-in", path, "-noout", "-checkhost", domain],
    )
}

fn certificate_expiry(path: &str) -> Option<String> {
    let output = Command::new("openssl")
        .args(["x509", "-in", path, "-noout", "-enddate"])
        .output()
        .ok()?;
    if !output.status.success() {
        return None;
    }
    String::from_utf8_lossy(&output.stdout)
        .trim()
        .strip_prefix("notAfter=")
        .map(str::to_string)
}

pub(super) fn ensure(
    domain: &str,
    root_path: &str,
    include_www: bool,
    renew_before_days: u64,
) -> Result<Value, String> {
    ensure_root()?;
    let domain = domain.trim().to_lowercase();
    if !valid_domain(&domain) {
        return Err("Invalid SSL domain.".into());
    }
    let root_path_buf = Path::new(root_path);
    if !root_path_buf.starts_with("/home/")
        || root_path_buf
            .components()
            .any(|part| matches!(part, std::path::Component::ParentDir))
    {
        return Err("Website root path must be inside /home/<owner>.".into());
    }
    if !root_path_buf.is_dir() {
        return Err(format!("Website root path does not exist: {root_path}"));
    }

    let live_dir = format!("/etc/letsencrypt/live/{domain}");
    let certificate_path = format!("{live_dir}/fullchain.pem");
    let private_key_path = format!("{live_dir}/privkey.pem");
    let existed = Path::new(&certificate_path).is_file();
    let main_valid = existed && certificate_valid(&certificate_path, &domain, renew_before_days);
    let www_domain = format!("www.{domain}");
    let include_www = include_www
        && (www_domain.as_str(), 80)
            .to_socket_addrs()
            .map(|mut addresses| addresses.next().is_some())
            .unwrap_or(false);
    let www_valid = !include_www
        || (existed && certificate_valid(&certificate_path, &www_domain, renew_before_days));
    let needs_issue = !main_valid || !www_valid;

    if needs_issue {
        if !command_success("sh", &["-c", "command -v certbot >/dev/null 2>&1"]) {
            return Err("certbot is not installed.".into());
        }
        let auth_hook = http_auth_hook(root_path);
        let cleanup_hook = http_cleanup_hook(root_path);
        let mut args = vec![
            "certonly",
            "--non-interactive",
            "--agree-tos",
            "--register-unsafely-without-email",
            "--manual",
            "--preferred-challenges",
            "http",
            "--manual-auth-hook",
            &auth_hook,
            "--manual-cleanup-hook",
            &cleanup_hook,
            // The vhost points at /etc/letsencrypt/live/<domain>. Pinning the lineage
            // name keeps that path correct when the domain set changes, instead of
            // certbot silently starting a <domain>-0001 lineage the vhost never reads.
            "--cert-name",
            &domain,
            "--expand",
            "-d",
            &domain,
        ];
        if include_www {
            args.push("-d");
            args.push(&www_domain);
        }
        let output = Command::new("certbot")
            .args(&args)
            .output()
            .map_err(|error| format!("failed to start certbot: {error}"))?;
        if !output.status.success() {
            let stdout = String::from_utf8_lossy(&output.stdout);
            let stderr = String::from_utf8_lossy(&output.stderr);
            let details = format!("{}\n{}", stdout.trim(), stderr.trim());
            let details = details.trim();
            return Err(if details.is_empty() {
                format!(
                    "certbot failed with exit code {}",
                    output.status.code().unwrap_or(1)
                )
            } else {
                format!(
                    "certbot failed: {}",
                    details.chars().take(4000).collect::<String>()
                )
            });
        }
    }

    if !certificate_valid(&certificate_path, &domain, 0) {
        return Err(
            "Certificate is missing, expired, or does not match the domain after issuance.".into(),
        );
    }

    // The gateway builds its SNI certificate store when it starts. Only a
    // changed certificate requires a restart; schedule it so this request can
    // return its JSON response through the gateway first.
    let gateway_reload_scheduled = if needs_issue {
        if !schedule_gateway_restart() {
            return Err(
                "Certificate is valid, but the edge gateway restart could not be scheduled.".into(),
            );
        }
        true
    } else {
        false
    };

    Ok(json!({
        "status": "valid",
        "issued": needs_issue && !existed,
        "renewed": needs_issue && existed,
        "include_www": include_www,
        "certificate_path": certificate_path,
        "private_key_path": private_key_path,
        "expires_at": certificate_expiry(&certificate_path),
        "gateway_reloaded": false,
        "gateway_reload_scheduled": gateway_reload_scheduled,
    }))
}
