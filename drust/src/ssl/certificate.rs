use std::net::{Ipv4Addr, ToSocketAddrs};
use std::path::{Path, PathBuf};
use std::process::Command;
use std::sync::Arc;
use std::time::Duration;

use serde_json::{Value, json};

use super::{custom, dns01};
use crate::app::ensure_root;
use crate::platform::{
    self,
    acme::{self, AcmeClient, CertInfo, CertRequest, Challenge, Dns01Solver, SslEngine},
    fs::FileMode,
};

/// Suffixes that are never publicly resolvable, so a CA can never issue a
/// certificate for them. Kept in sync with
/// `SslLifecycleService::RESERVED_SSL_SUFFIXES` on the dpanel side, which
/// filters these out before ever calling this API; this is defense-in-depth
/// for any other caller.
const RESERVED_SSL_SUFFIXES: [&str; 5] = [".localhost", ".local", ".test", ".example", ".invalid"];

/// The panel's own application directory. Website roots must otherwise live
/// under `/home/<owner>/...`, but the panel's own reserved website (id 1)
/// is deployed here instead, so it needs an explicit, fixed exception.

/// Where self-signed IP certificates live. The gateway falls back to
/// `/etc/drust/tls/<domain>.{crt,key}` when there is no certbot lineage.

/// Every IP, public or private, gets an 825-day self-signed certificate.
fn is_ip_address(domain: &str) -> bool {
    domain.parse::<Ipv4Addr>().is_ok()
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

fn ensure_ip(ip: &str, renew_before_days: u64, force: bool) -> Result<Value, String> {
    // The gateway serves a certbot lineage before /etc/drust/tls, so an old
    // ~6-day Let's Encrypt IP certificate would hide the 825-day one.
    let lineage_removed = delete_certbot_lineage(ip)?;
    ensure_self_signed(ip, renew_before_days, force || lineage_removed, None)
}

/// Deletes the certbot lineage named `name`; false when there was none.
fn delete_certbot_lineage(name: &str) -> Result<bool, String> {
    if !Path::new(acme::CERTBOT_LIVE_DIR).join(name).exists() {
        return Ok(false);
    }
    let output = Command::new("certbot")
        .args(["delete", "--non-interactive", "--cert-name", name])
        .output()
        .map_err(|error| format!("failed to start certbot: {error}"))?;
    if !output.status.success() {
        return Err(format!(
            "certbot delete failed: {}",
            String::from_utf8_lossy(&output.stderr).trim().chars().take(4000).collect::<String>()
        ));
    }
    Ok(true)
}

fn ensure_self_signed(
    ip: &str,
    renew_before_days: u64,
    force: bool,
    note: Option<&str>,
) -> Result<Value, String> {
    let tls_dir = platform::paths().tls_dir();
    let certificate_path = tls_dir.join(format!("{ip}.crt")).display().to_string();
    let private_key_path = tls_dir.join(format!("{ip}.key")).display().to_string();
    let existed = Path::new(&certificate_path).is_file() && Path::new(&private_key_path).is_file();
    let needs_issue =
        force || !existed || !ip_certificate_valid(&certificate_path, ip, renew_before_days);

    if needs_issue {
        std::fs::create_dir_all(&tls_dir)
            .map_err(|error| format!("failed to create {}: {error}", tls_dir.display()))?;
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
        platform::files()
            .set_mode(Path::new(&private_key_path), FileMode::Private)
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

/// What `ensure` answers while an uploaded certificate serves the domain. It
/// is never renewed here: an expired one is an error the panel shows.
fn custom_status(domain: &str, uploaded: &custom::Custom, now: i64) -> Result<Value, String> {
    if uploaded.info.not_after <= now {
        return Err(format!(
            "The uploaded certificate for {domain} has expired. Upload a new one, or reinstall SSL to get a free certificate."
        ));
    }
    if !uploaded.info.covers(domain) {
        return Err(format!("The uploaded certificate is not for {domain}."));
    }
    let mut data = valid_response(
        &uploaded.certificate_path,
        &uploaded.private_key_path,
        uploaded.info.not_after,
        (false, uploaded.info.names.contains(&format!("*.{domain}"))),
        false,
        false,
    );
    data["custom"] = json!(true);
    Ok(data)
}

/// `POST /api/v1/ssl/install`: the customer's own certificate and key.
pub(super) fn install_custom(domain: &str, certificate: &str, private_key: &str) -> Result<Value, String> {
    ensure_root()?;
    let domain = domain.trim().to_lowercase();
    if !valid_domain(&domain) || is_ip_address(&domain) {
        return Err("Invalid SSL domain.".into());
    }
    let tls_dir = platform::paths().tls_dir();
    let uploaded = custom::install(&tls_dir, &domain, certificate, private_key, acme::unix_now())?;
    if !trigger_gateway_reload() {
        return Err("Certificate is installed, but notifying the edge gateway to reload it failed.".into());
    }
    let mut data = valid_response(
        &uploaded.certificate_path,
        &uploaded.private_key_path,
        uploaded.info.not_after,
        (false, uploaded.info.names.contains(&format!("*.{domain}"))),
        true,
        false,
    );
    data["custom"] = json!(true);
    data["names"] = json!(uploaded.info.names);
    Ok(data)
}

/// `wildcard`: also `*.<domain>`, proved in the DNS zone it names (M4).
pub(super) fn ensure(
    domain: &str,
    root_path: &str,
    include_www: bool,
    renew_before_days: u64,
    force: bool,
    wildcard: Option<&dns01::DnsTarget>,
) -> Result<Value, String> {
    ensure_root()?;
    let domain = domain.trim().to_lowercase();
    if !valid_domain(&domain) {
        return Err("Invalid SSL domain.".into());
    }
    let tls_dir = platform::paths().tls_dir();
    if !force {
        if let Some(uploaded) = custom::current(&tls_dir, &domain) {
            return custom_status(&domain, &uploaded, acme::unix_now());
        }
    }
    let data = ensure_issued(&domain, root_path, include_www, renew_before_days, force, wildcard)?;
    // A forced reinstall replaces an uploaded certificate, but only once the
    // new one exists, so a failed issue leaves the site as it was.
    if custom::remove(&tls_dir, &domain)? && !trigger_gateway_reload() {
        return Err("Certificate is valid, but notifying the edge gateway to reload it failed.".into());
    }
    Ok(data)
}

fn ensure_issued(
    domain: &str,
    root_path: &str,
    include_www: bool,
    renew_before_days: u64,
    force: bool,
    wildcard: Option<&dns01::DnsTarget>,
) -> Result<Value, String> {
    let domain = domain.to_string();
    let root_path_buf = Path::new(root_path);
    let has_parent_dir_component = root_path_buf
        .components()
        .any(|part| matches!(part, std::path::Component::ParentDir));
    let panel_root = platform::paths().panel_root();
    let sites_root = platform::paths().sites_root();
    let is_panel_app_root = root_path_buf.starts_with(panel_root);
    if has_parent_dir_component || (!root_path_buf.starts_with(sites_root) && !is_panel_app_root) {
        return Err(format!(
            "Website root path must be inside {}/<owner> (or {} for the panel itself).",
            sites_root.display(),
            panel_root.display()
        ));
    }
    if !root_path_buf.is_dir() {
        return Err(format!("Website root path does not exist: {root_path}"));
    }
    if is_ip_address(&domain) {
        if wildcard.is_some() {
            return Err("An IP address cannot have a wildcard certificate.".into());
        }
        return ensure_ip(&domain, renew_before_days, force);
    }

    let at = || AcmeEnsure {
        tls_dir: platform::paths().tls_dir(),
        directory: acme::directory_url(),
        certbot: acme::certbot_lineage(&domain),
        now: acme::unix_now(),
    };
    // certbot here has no DNS plugin, so a wildcard always comes from drust's
    // own client, whatever the engine. The gateway prefers that file.
    if let Some(target) = wildcard {
        if acme::engine() == SslEngine::LocalCa {
            return Err("Wildcard certificates need a public CA; this is a local install.".into());
        }
        let solver = dns01::solver(target, &domain)?;
        return ensure_with_acme(
            &acme::client(),
            &at(),
            &domain,
            false,
            Some(solver),
            renew_before_days,
            force,
            trigger_gateway_reload,
        );
    }

    let www_domain = format!("www.{domain}");
    let include_www = include_www
        && (www_domain.as_str(), 80)
            .to_socket_addrs()
            .map(|mut addresses| addresses.next().is_some())
            .unwrap_or(false);
    if acme::engine() == SslEngine::Acme {
        return ensure_with_acme(
            &acme::client(),
            &at(),
            &domain,
            include_www,
            None,
            renew_before_days,
            force,
            trigger_gateway_reload,
        );
    }

    let live_dir = format!("{}/{domain}", acme::CERTBOT_LIVE_DIR);
    let certificate_path = format!("{live_dir}/fullchain.pem");
    let private_key_path = format!("{live_dir}/privkey.pem");
    let existed = Path::new(&certificate_path).is_file();
    let main_valid = existed && certificate_valid(&certificate_path, &domain, renew_before_days);
    let www_valid = !include_www
        || (existed && certificate_valid(&certificate_path, &www_domain, renew_before_days));
    // force: reinstall requested from the panel, even for a valid certificate.
    let needs_issue = force || !main_valid || !www_valid;

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
        if force && existed {
            // Without it certbot keeps a certificate that is not yet due for renewal.
            args.push("--force-renewal");
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

    // The site was a wildcard before: the gateway would keep serving the ACME
    // client's file, so it goes, now that certbot's certificate is good.
    let dropped_wildcard = acme::client().forget(&domain).map_err(|error| error.to_string())?;

    // Only a changed certificate requires the gateway to pick up new files.
    let gateway_reloaded = if needs_issue || dropped_wildcard {
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
        "wildcard": false,
        "certificate_path": certificate_path,
        "private_key_path": private_key_path,
        "expires_at": certificate_expiry(&certificate_path),
        "gateway_reloaded": gateway_reloaded,
        "gateway_reload_scheduled": false,
    }))
}

/// Where `ensure_with_acme` looks, apart from the client.
struct AcmeEnsure {
    tls_dir: PathBuf,
    directory: String,
    /// The certbot certificate and key for the domain, if certbot issued one.
    certbot: Option<(PathBuf, PathBuf)>,
    now: i64,
}

fn valid_response(
    certificate_path: &Path,
    private_key_path: &Path,
    not_after: i64,
    (include_www, wildcard): (bool, bool),
    issued: bool,
    renewed: bool,
) -> Value {
    json!({
        "status": "valid",
        "issued": issued,
        "renewed": renewed,
        "include_www": include_www,
        "wildcard": wildcard,
        "certificate_path": certificate_path.display().to_string(),
        "private_key_path": private_key_path.display().to_string(),
        "expires_at": chrono::DateTime::from_timestamp(not_after, 0).map(|time| time.to_rfc3339()),
        "gateway_reloaded": issued || renewed,
        "gateway_reload_scheduled": false,
    })
}

/// `ensure` with drust's own ACME client instead of certbot.
///
/// A certbot certificate that is still good is kept, so turning the engine on
/// does not reissue every site at once and run into the CA's rate limits. The
/// client takes over when that certificate comes up for renewal, for new
/// sites, and on a forced reinstall.
///
/// With `wildcard` the certificate is `domain` and `*.domain` over DNS-01;
/// the wildcard already covers www.
#[allow(clippy::too_many_arguments)]
fn ensure_with_acme(
    client: &dyn AcmeClient,
    at: &AcmeEnsure,
    domain: &str,
    include_www: bool,
    wildcard: Option<Arc<dyn Dns01Solver>>,
    renew_before_days: u64,
    force: bool,
    reload_gateway: impl Fn() -> bool,
) -> Result<Value, String> {
    let is_wildcard = wildcard.is_some();
    let include_www = include_www && !is_wildcard;
    let mut domains = vec![domain.to_string()];
    if is_wildcard {
        domains.push(format!("*.{domain}"));
    } else if include_www {
        domains.push(format!("www.{domain}"));
    }
    let shape = (include_www, is_wildcard);
    let margin = Duration::from_secs(renew_before_days.saturating_mul(86_400));
    let good = |info: &CertInfo| info.outlives(at.now, margin) && domains.iter().all(|name| info.covers(name));

    let certificate_path = at.tls_dir.join(format!("{domain}.crt"));
    let private_key_path = at.tls_dir.join(format!("{domain}.key"));
    let current = CertInfo::read(&certificate_path).filter(|_| private_key_path.is_file());
    if !force {
        if let Some(info) = current.as_ref().filter(|info| good(info)) {
            return Ok(valid_response(&certificate_path, &private_key_path, info.not_after, shape, false, false));
        }
        if let Some((certificate, key)) = at.certbot.as_ref() {
            if let Some(info) = CertInfo::read(certificate).filter(|info| good(info)) {
                return Ok(valid_response(certificate, key, info.not_after, shape, false, false));
            }
        }
    }

    let challenge = if is_wildcard { Challenge::Dns01 } else { Challenge::Http01 };
    let request = CertRequest { domains, challenge, directory: at.directory.clone(), dns: wildcard };
    let issued = client.issue(&request).map_err(|error| error.to_string())?;
    if !reload_gateway() {
        return Err("Certificate is valid, but notifying the edge gateway to reload it failed.".into());
    }
    let existed = current.is_some();
    Ok(valid_response(
        &issued.certificate_path,
        &issued.private_key_path,
        issued.not_after,
        shape,
        !existed,
        existed,
    ))
}

/// Renews every certificate drust's ACME client issued that is close to
/// expiry. With certbot as the engine there is nothing to do: its own timer
/// renews what it issued.
pub(super) fn renew_due() -> Result<Value, String> {
    ensure_root()?;
    let engine = acme::engine();
    if engine != SslEngine::Acme {
        return Ok(json!({ "engine": engine.as_str(), "results": [] }));
    }
    let results = acme::client().renew_due().map_err(|error| error.to_string())?;
    let gateway_reloaded = results.iter().any(|result| result.renewed) && trigger_gateway_reload();
    Ok(json!({
        "engine": engine.as_str(),
        "gateway_reloaded": gateway_reloaded,
        "results": results
            .iter()
            .map(|result| json!({ "domain": result.domain, "renewed": result.renewed, "error": result.error }))
            .collect::<Vec<_>>(),
    }))
}

/// Uninstall: removes the certificate the gateway would serve for `domain`
/// (its certbot lineage, and for an IP the self-signed pair) and reloads.
pub(super) fn remove(domain: &str) -> Result<Value, String> {
    ensure_root()?;
    let domain = domain.trim().to_lowercase();
    if !valid_domain(&domain) {
        return Err("Invalid SSL domain.".into());
    }
    let mut removed = delete_certbot_lineage(&domain)?;
    if custom::remove(&platform::paths().tls_dir(), &domain)? {
        removed = true;
    }
    // Only what the ACME client issued: /etc/drust/tls also holds the panel's
    // own certificate, which came from the installer.
    if acme::client().forget(&domain).map_err(|error| error.to_string())? {
        removed = true;
    }
    // Only IPs: /etc/drust/tls also holds the panel's own domain certificate.
    if is_ip_address(&domain) {
        let tls_dir = platform::paths().tls_dir();
        for path in [
            tls_dir.join(format!("{domain}.crt")).display().to_string(),
            tls_dir.join(format!("{domain}.key")).display().to_string(),
        ] {
            if Path::new(&path).is_file() {
                std::fs::remove_file(&path).map_err(|error| format!("failed to remove {path}: {error}"))?;
                removed = true;
            }
        }
    }
    if removed && !trigger_gateway_reload() {
        return Err("Certificate removed, but notifying the edge gateway to reload failed.".into());
    }
    Ok(json!({ "status": "removed", "removed": removed }))
}

#[cfg(test)]
mod tests {
    use std::cell::Cell;
    use std::sync::Mutex;

    use rcgen::{CertificateParams, KeyPair};

    use super::*;
    use crate::platform::{
        PlatformError,
        acme::{IssuedCert, RenewResult},
    };

    const NOW: i64 = 1_800_000_000;
    const DAY: i64 = 86_400;

    /// A self-signed certificate for `names` that ends `days` after `NOW`.
    fn write_certificate(path: &Path, names: &[&str], days: i64) {
        let key = KeyPair::generate().unwrap();
        let mut params = CertificateParams::new(names.iter().map(|name| name.to_string()).collect::<Vec<_>>()).unwrap();
        params.not_after = time::OffsetDateTime::from_unix_timestamp(NOW + days * DAY).unwrap();
        std::fs::write(path, params.self_signed(&key).unwrap().pem()).unwrap();
        std::fs::write(path.with_extension("key"), key.serialize_pem()).unwrap();
    }

    /// Issues 90-day certificates into `tls_dir` and remembers what was asked.
    struct FakeClient {
        tls_dir: PathBuf,
        asked: Mutex<Vec<Vec<String>>>,
    }

    impl AcmeClient for FakeClient {
        fn issue(&self, request: &CertRequest) -> Result<IssuedCert, PlatformError> {
            let wildcard = request.domains.iter().any(|name| name.starts_with("*."));
            assert_eq!(request.challenge, if wildcard { Challenge::Dns01 } else { Challenge::Http01 });
            assert_eq!(request.dns.is_some(), wildcard);
            assert_eq!(request.directory, "https://ca.example/directory");
            self.asked.lock().unwrap().push(request.domains.clone());
            let certificate_path = self.tls_dir.join(format!("{}.crt", request.domains[0]));
            let names: Vec<&str> = request.domains.iter().map(String::as_str).collect();
            write_certificate(&certificate_path, &names, 90);
            Ok(IssuedCert {
                domain: request.domains[0].clone(),
                private_key_path: certificate_path.with_extension("key"),
                certificate_path,
                not_after: NOW + 90 * DAY,
            })
        }

        fn renew_due(&self) -> Result<Vec<RenewResult>, PlatformError> {
            unreachable!()
        }

        fn revoke(&self, _: &str) -> Result<(), PlatformError> {
            unreachable!()
        }
    }

    /// The fake client never asks it anything.
    struct NoDns;

    impl Dns01Solver for NoDns {
        fn publish<'a>(&'a self, _: &'a str, _: &'a str) -> futures_util::future::BoxFuture<'a, Result<(), PlatformError>> {
            unreachable!()
        }

        fn withdraw<'a>(&'a self, _: &'a str, _: &'a str) -> futures_util::future::BoxFuture<'a, Result<(), PlatformError>> {
            unreachable!()
        }
    }

    struct Fixture {
        folder: tempfile::TempDir,
        client: FakeClient,
        at: AcmeEnsure,
        reloads: Cell<u32>,
    }

    impl Fixture {
        fn new() -> Self {
            let folder = tempfile::tempdir().unwrap();
            let tls_dir = folder.path().join("tls");
            std::fs::create_dir_all(&tls_dir).unwrap();
            Self {
                client: FakeClient { tls_dir: tls_dir.clone(), asked: Mutex::new(Vec::new()) },
                at: AcmeEnsure { tls_dir, directory: "https://ca.example/directory".into(), certbot: None, now: NOW },
                folder,
                reloads: Cell::new(0),
            }
        }

        fn own(&self, extension: &str) -> PathBuf {
            self.at.tls_dir.join(format!("example.com.{extension}"))
        }

        fn ensure(&self, include_www: bool, force: bool) -> Value {
            let reload = || {
                self.reloads.set(self.reloads.get() + 1);
                true
            };
            ensure_with_acme(&self.client, &self.at, "example.com", include_www, None, 30, force, reload).unwrap()
        }

        fn ensure_wildcard(&self, force: bool) -> Value {
            let reload = || {
                self.reloads.set(self.reloads.get() + 1);
                true
            };
            let dns: Arc<dyn Dns01Solver> = Arc::new(NoDns);
            ensure_with_acme(&self.client, &self.at, "example.com", true, Some(dns), 30, force, reload).unwrap()
        }

        fn asked(&self) -> Vec<Vec<String>> {
            self.client.asked.lock().unwrap().clone()
        }
    }

    #[test]
    fn a_site_without_a_certificate_gets_one_and_the_gateway_reloads() {
        let fixture = Fixture::new();

        let data = fixture.ensure(false, false);

        assert_eq!(fixture.asked(), vec![vec!["example.com".to_string()]]);
        assert_eq!(fixture.reloads.get(), 1);
        assert_eq!(data["status"], "valid");
        assert_eq!(data["issued"], true);
        assert_eq!(data["renewed"], false);
        assert_eq!(data["gateway_reloaded"], true);
        assert_eq!(data["certificate_path"], fixture.own("crt").display().to_string());
        assert_eq!(data["private_key_path"], fixture.own("key").display().to_string());
        // The panel reads this with Carbon::parse.
        let expires = chrono::DateTime::parse_from_rfc3339(data["expires_at"].as_str().unwrap()).unwrap();
        assert_eq!(expires.timestamp(), NOW + 90 * DAY);
    }

    #[test]
    fn a_good_certificate_is_left_alone_unless_a_reinstall_is_forced() {
        let fixture = Fixture::new();
        write_certificate(&fixture.own("crt"), &["example.com"], 31);

        let data = fixture.ensure(false, false);
        assert!(fixture.asked().is_empty());
        assert_eq!(fixture.reloads.get(), 0);
        assert_eq!((&data["issued"], &data["renewed"], &data["gateway_reloaded"]), (&json!(false), &json!(false), &json!(false)));

        let data = fixture.ensure(false, true);
        assert_eq!(fixture.asked().len(), 1);
        assert_eq!((&data["issued"], &data["renewed"]), (&json!(false), &json!(true)));
    }

    #[test]
    fn a_certificate_inside_the_renewal_window_is_renewed() {
        let fixture = Fixture::new();
        write_certificate(&fixture.own("crt"), &["example.com"], 30);

        let data = fixture.ensure(false, false);

        assert_eq!(fixture.asked().len(), 1);
        assert_eq!(data["renewed"], true);
        assert_eq!(fixture.reloads.get(), 1);
    }

    #[test]
    fn a_certificate_that_lacks_www_or_its_key_is_issued_again() {
        let fixture = Fixture::new();
        write_certificate(&fixture.own("crt"), &["example.com"], 80);

        let data = fixture.ensure(true, false);
        assert_eq!(fixture.asked(), vec![vec!["example.com".to_string(), "www.example.com".to_string()]]);
        assert_eq!(data["include_www"], true);

        std::fs::remove_file(fixture.own("key")).unwrap();
        let data = fixture.ensure(true, false);
        assert_eq!(fixture.asked().len(), 2);
        assert_eq!(data["issued"], true);
    }

    #[test]
    fn a_good_certbot_certificate_is_kept_until_it_is_due() {
        let mut fixture = Fixture::new();
        let lineage = fixture.folder.path().join("fullchain.pem");
        fixture.at.certbot = Some((lineage.clone(), fixture.folder.path().join("privkey.pem")));

        write_certificate(&lineage, &["example.com"], 60);
        let data = fixture.ensure(false, false);
        assert!(fixture.asked().is_empty());
        assert_eq!(data["certificate_path"], lineage.display().to_string());
        assert_eq!(data["private_key_path"], fixture.folder.path().join("privkey.pem").display().to_string());
        assert_eq!(data["issued"], false);

        // Due: the ACME client takes over, and its certificate is the answer.
        write_certificate(&lineage, &["example.com"], 10);
        let data = fixture.ensure(false, false);
        assert_eq!(fixture.asked().len(), 1);
        assert_eq!(data["certificate_path"], fixture.own("crt").display().to_string());
        assert_eq!(data["issued"], true);
    }

    #[test]
    fn a_forced_reinstall_moves_a_certbot_site_to_the_acme_client() {
        let mut fixture = Fixture::new();
        let lineage = fixture.folder.path().join("fullchain.pem");
        write_certificate(&lineage, &["example.com"], 60);
        fixture.at.certbot = Some((lineage, fixture.folder.path().join("privkey.pem")));

        let data = fixture.ensure(false, true);

        assert_eq!(fixture.asked().len(), 1);
        assert_eq!(data["certificate_path"], fixture.own("crt").display().to_string());
    }

    #[test]
    fn an_uploaded_certificate_reports_itself_and_an_expired_one_is_an_error() {
        let folder = tempfile::tempdir().unwrap();
        let (certificate_path, private_key_path) = acme::custom_certificate_files(folder.path(), "example.com");
        std::fs::create_dir_all(certificate_path.parent().unwrap()).unwrap();
        write_certificate(&certificate_path, &["example.com"], 20);
        let uploaded = custom::current(folder.path(), "example.com").unwrap();

        // Inside what would be the renewal window, and still left alone.
        let data = custom_status("example.com", &uploaded, NOW).unwrap();
        assert_eq!(data["custom"], true);
        assert_eq!((&data["issued"], &data["renewed"], &data["gateway_reloaded"]), (&json!(false), &json!(false), &json!(false)));
        assert_eq!(data["certificate_path"], certificate_path.display().to_string());
        assert_eq!(data["private_key_path"], private_key_path.display().to_string());

        let error = custom_status("example.com", &uploaded, NOW + 21 * DAY).unwrap_err();
        assert!(error.contains("has expired"), "{error}");
        assert!(custom_status("other.com", &uploaded, NOW).unwrap_err().contains("not for other.com"));
    }

    #[test]
    fn a_wildcard_is_asked_for_over_dns_and_kept_while_it_is_good() {
        let mut fixture = Fixture::new();
        // A good plain certificate, and a certbot one, do not count: neither covers *.
        write_certificate(&fixture.own("crt"), &["example.com", "www.example.com"], 80);
        let lineage = fixture.folder.path().join("fullchain.pem");
        write_certificate(&lineage, &["example.com"], 60);
        fixture.at.certbot = Some((lineage, fixture.folder.path().join("privkey.pem")));

        let data = fixture.ensure_wildcard(false);

        assert_eq!(fixture.asked(), vec![vec!["example.com".to_string(), "*.example.com".to_string()]]);
        assert_eq!((&data["wildcard"], &data["include_www"], &data["renewed"]), (&json!(true), &json!(false), &json!(true)));
        assert_eq!(data["certificate_path"], fixture.own("crt").display().to_string());
        assert_eq!(fixture.reloads.get(), 1);

        let data = fixture.ensure_wildcard(false);
        assert_eq!(fixture.asked().len(), 1);
        assert_eq!(data["wildcard"], true);
        // Turning the wildcard off keeps it while it is good: it covers www too.
        let data = fixture.ensure(true, false);
        assert_eq!(fixture.asked().len(), 1);
        assert_eq!(data["wildcard"], false);
    }

    #[test]
    fn a_gateway_that_cannot_be_told_is_an_error() {
        let fixture = Fixture::new();
        let error =
            ensure_with_acme(&fixture.client, &fixture.at, "example.com", false, None, 30, false, || false).unwrap_err();
        assert!(error.contains("edge gateway"), "{error}");
    }
}
