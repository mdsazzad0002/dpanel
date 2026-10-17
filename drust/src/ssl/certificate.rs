use std::net::{Ipv4Addr, ToSocketAddrs};
use std::path::Path;
use std::process::Command;

use serde_json::{Value, json};

use crate::app::ensure_root;

/// Suffixes that are never publicly resolvable, so a CA can never issue a
/// certificate for them. Kept in sync with
/// `SslLifecycleService::RESERVED_SSL_SUFFIXES` on the dpanel side, which
/// filters these out before ever calling this API; this is defense-in-depth
/// for any other caller.
const RESERVED_SSL_SUFFIXES: [&str; 5] = [".localhost", ".local", ".test", ".example", ".invalid"];

/// The panel's own application directory. Website roots must otherwise live
/// under `/home/<owner>/...`, but the panel's own reserved website (id 1)
/// is deployed here instead, so it needs an explicit, fixed exception.
const PANEL_APP_ROOT: &str = "/var/www/dpanel";

/// Where self-signed IP certificates live. The gateway falls back to
/// `/etc/drust/tls/<domain>.{crt,key}` when there is no certbot lineage.
const SELF_SIGNED_DIR: &str = "/etc/drust/tls";

/// Let's Encrypt only issues IP certificates under the short-lived profile
/// (~6 days), so they are renewed once fewer than this many days remain.
const IP_CERT_RENEW_BEFORE_DAYS: u64 = 3;

/// A public IP gets a Let's Encrypt certificate (certbot 5.1+); a private
/// IP, or a server whose certbot cannot issue for IPs, gets a self-signed one.
fn is_ip_address(domain: &str) -> bool {
    domain.parse::<Ipv4Addr>().is_ok()
}

/// Whether a public CA can reach and validate this address.
fn is_public_ipv4(ip: &str) -> bool {
    let Ok(address) = ip.parse::<Ipv4Addr>() else {
        return false;
    };
    let [a, b, ..] = address.octets();
    !(address.is_private()
        || address.is_loopback()
        || address.is_link_local()
        || address.is_unspecified()
        || address.is_broadcast()
        || address.is_documentation()
        || address.is_multicast()
        // Carrier-grade NAT (100.64.0.0/10) and 240.0.0.0/4.
        || (a == 100 && (64..128).contains(&b))
        || a >= 240)
}

fn certbot_supports_ip() -> bool {
    Command::new("certbot")
        .args(["--help", "all"])
        .output()
        .map(|output| String::from_utf8_lossy(&output.stdout).contains("--ip-address"))
        .unwrap_or(false)
}

fn valid_domain(domain: &str) -> bool {
    !domain.is_empty()
        && domain.len() <= 253
        && domain.contains('.')
        && domain.bytes().all(|character| {
            character.is_ascii_alphanumeric() || character == b'.' || character == b'-'
        })
        && domain != "localhost"
        && !RESERVED_SSL_SUFFIXES
            .iter()
            .any(|suffix| domain.ends_with(suffix))
}

fn command_success(program: &str, args: &[&str]) -> bool {
    Command::new(program)
        .args(args)
        .status()
        .map(|status| status.success())
        .unwrap_or(false)
}

fn trigger_gateway_reload() -> bool {
    // The gateway keeps a live-swappable SNI cert store and already listens
    // for reload events on this Redis channel (the same one dpanel's
    // EdgeGatewayReloader publishes to for vhost changes). Publishing here
    // picks up the new/renewed certificate in place, without dropping
    // in-flight HTTPS connections for every other site the way a
    // `systemctl restart edge-gateway.service` used to.
    let url =
        std::env::var("DRUST_REDIS_URL").unwrap_or_else(|_| "redis://127.0.0.1/".to_string());
    let channel = std::env::var("DRUST_REDIS_RELOAD_CHANNEL")
        .unwrap_or_else(|_| "edge:reload".to_string());
    command_success("redis-cli", &["-u", &url, "PUBLISH", &channel, "{}"])
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

fn ip_certificate_valid(path: &str, ip: &str, renew_before_days: u64) -> bool {
    let seconds = renew_before_days.saturating_mul(86_400).to_string();
    command_success(
        "openssl",
        &["x509", "-in", path, "-noout", "-checkend", &seconds],
    ) && ip_matches_certificate(path, ip)
}

/// `openssl x509 -checkip` exits 0 on a mismatch too, so read its verdict.
fn ip_matches_certificate(path: &str, ip: &str) -> bool {
    Command::new("openssl")
        .args(["x509", "-in", path, "-noout", "-checkip", ip])
        .output()
        .map(|output| {
            output.status.success()
                && String::from_utf8_lossy(&output.stdout).contains(" does match certificate")
        })
        .unwrap_or(false)
}

fn ensure_ip(ip: &str, root_path: &str, renew_before_days: u64) -> Result<Value, String> {
    let lineage_exists = Path::new(&format!("/etc/letsencrypt/live/{ip}/fullchain.pem")).is_file();
    if !is_public_ipv4(ip) {
        return ensure_self_signed(ip, renew_before_days, None);
    }
    if !certbot_supports_ip() {
        return ensure_self_signed(
            ip,
            renew_before_days,
            Some("Installed certbot cannot issue IP certificates (needs 5.1+); using a self-signed certificate."),
        );
    }
    match ensure_letsencrypt_ip(ip, root_path) {
        Ok(data) => Ok(data),
        // The gateway prefers an existing Let's Encrypt lineage, so a
        // self-signed fallback would never be served; report the failure.
        Err(error) if lineage_exists => Err(error),
        Err(error) => ensure_self_signed(
            ip,
            renew_before_days,
            Some(&format!("Let's Encrypt IP certificate failed, using a self-signed certificate. {error}")),
        ),
    }
}

/// Short-lived (~6 day) Let's Encrypt certificate for a public IP, validated
/// over HTTP-01 like a domain (DNS-01 is not available for IPs).
fn ensure_letsencrypt_ip(ip: &str, root_path: &str) -> Result<Value, String> {
    let live_dir = format!("/etc/letsencrypt/live/{ip}");
    let certificate_path = format!("{live_dir}/fullchain.pem");
    let private_key_path = format!("{live_dir}/privkey.pem");
    let existed = Path::new(&certificate_path).is_file();
    let needs_issue =
        !existed || !ip_certificate_valid(&certificate_path, ip, IP_CERT_RENEW_BEFORE_DAYS);

    if needs_issue {
        let auth_hook = http_auth_hook(root_path);
        let cleanup_hook = http_cleanup_hook(root_path);
        let output = Command::new("certbot")
            .args([
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
                "--preferred-profile",
                "shortlived",
                "--cert-name",
                ip,
                "--force-renewal",
                "--ip-address",
                ip,
            ])
            .output()
            .map_err(|error| format!("failed to start certbot: {error}"))?;
        if !output.status.success() {
            let details = format!(
                "{}\n{}",
                String::from_utf8_lossy(&output.stdout).trim(),
                String::from_utf8_lossy(&output.stderr).trim()
            );
            return Err(format!(
                "certbot failed: {}",
                details.trim().chars().take(4000).collect::<String>()
            ));
        }
    }

    if !ip_certificate_valid(&certificate_path, ip, 0) {
        return Err("Certificate is missing, expired, or does not match the IP after issuance.".into());
    }

    let gateway_reloaded = if needs_issue {
        if !trigger_gateway_reload() {
            return Err(
                "Certificate is valid, but notifying the edge gateway to reload it failed.".into(),
            );
        }
        true
    } else {
        false
    };

    Ok(json!({
        "status": "valid",
        "self_signed": false,
        "issued": needs_issue && !existed,
        "renewed": needs_issue && existed,
        "include_www": false,
        "certificate_path": certificate_path,
        "private_key_path": private_key_path,
        "expires_at": certificate_expiry(&certificate_path),
        "gateway_reloaded": gateway_reloaded,
        "gateway_reload_scheduled": false,
    }))
}

fn ensure_self_signed(ip: &str, renew_before_days: u64, note: Option<&str>) -> Result<Value, String> {
    let certificate_path = format!("{SELF_SIGNED_DIR}/{ip}.crt");
    let private_key_path = format!("{SELF_SIGNED_DIR}/{ip}.key");
    let existed = Path::new(&certificate_path).is_file() && Path::new(&private_key_path).is_file();
    let needs_issue = !existed || !ip_certificate_valid(&certificate_path, ip, renew_before_days);

    if needs_issue {
        std::fs::create_dir_all(SELF_SIGNED_DIR)
            .map_err(|error| format!("failed to create {SELF_SIGNED_DIR}: {error}"))?;
        let subject = format!("/CN={ip}");
        let san = format!("subjectAltName=IP:{ip}");
        let output = Command::new("openssl")
            .args([
                "req",
                "-x509",
                "-newkey",
                "ec",
                "-pkeyopt",
                "ec_paramgen_curve:prime256v1",
                "-nodes",
                "-days",
                "825",
                "-subj",
                &subject,
                "-addext",
                &san,
                "-keyout",
                &private_key_path,
                "-out",
                &certificate_path,
            ])
            .output()
            .map_err(|error| format!("failed to start openssl: {error}"))?;
        if !output.status.success() {
            let stderr = String::from_utf8_lossy(&output.stderr);
            return Err(format!(
                "self-signed certificate generation failed: {}",
                stderr.trim().chars().take(4000).collect::<String>()
            ));
        }
        use std::os::unix::fs::PermissionsExt;
        std::fs::set_permissions(&private_key_path, std::fs::Permissions::from_mode(0o600))
            .map_err(|error| format!("failed to restrict private key permissions: {error}"))?;
    }

    if !ip_certificate_valid(&certificate_path, ip, 0) {
        return Err("Self-signed certificate is missing or does not match the IP address.".into());
    }

    let gateway_reloaded = if needs_issue {
        if !trigger_gateway_reload() {
            return Err(
                "Certificate is valid, but notifying the edge gateway to reload it failed.".into(),
            );
        }
        true
    } else {
        false
    };

    Ok(json!({
        "status": "valid",
        "self_signed": true,
        "note": note,
        "issued": needs_issue && !existed,
        "renewed": needs_issue && existed,
        "include_www": false,
        "certificate_path": certificate_path,
        "private_key_path": private_key_path,
        "expires_at": certificate_expiry(&certificate_path),
        "gateway_reloaded": gateway_reloaded,
        "gateway_reload_scheduled": false,
    }))
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
    let has_parent_dir_component = root_path_buf
        .components()
        .any(|part| matches!(part, std::path::Component::ParentDir));
    let is_panel_app_root = root_path_buf == Path::new(PANEL_APP_ROOT)
        || root_path_buf.starts_with(format!("{PANEL_APP_ROOT}/"));
    if has_parent_dir_component || (!root_path_buf.starts_with("/home/") && !is_panel_app_root) {
        return Err(format!(
            "Website root path must be inside /home/<owner> (or {PANEL_APP_ROOT} for the panel itself)."
        ));
    }
    if !root_path_buf.is_dir() {
        return Err(format!("Website root path does not exist: {root_path}"));
    }
    if is_ip_address(&domain) {
        return ensure_ip(&domain, root_path, renew_before_days);
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

    // Only a changed certificate requires the gateway to pick up new files.
    let gateway_reloaded = if needs_issue {
        if !trigger_gateway_reload() {
            return Err(
                "Certificate is valid, but notifying the edge gateway to reload it failed.".into(),
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
        "gateway_reloaded": gateway_reloaded,
        "gateway_reload_scheduled": false,
    }))
}

#[cfg(test)]
mod tests {
    use super::is_public_ipv4;

    #[test]
    fn only_publicly_routable_ips_are_public() {
        assert!(is_public_ipv4("217.216.111.92"));
        assert!(is_public_ipv4("8.8.8.8"));
        for ip in ["192.168.0.109", "10.0.0.1", "172.16.5.4", "127.0.0.1", "169.254.1.1", "100.64.0.1", "0.0.0.0", "255.255.255.255", "203.0.113.5"] {
            assert!(!is_public_ipv4(ip), "{ip} should not be public");
        }
    }
}
