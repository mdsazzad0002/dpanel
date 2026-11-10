//! What this build of drust can do on this system.

use super::{Os, Profile, acme::SslEngine};
use serde::Serialize;
use std::{env, path::Path};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Capability {
    Websites,
    PhpMulti,
    Isolation,
    ResourceLimits,
    DiskQuota,
    Mail,
    Dns,
    Ssl,
    Firewall,
    Ftp,
    Terminal,
    Docker,
    Redis,
    Reseller,
    /// Exact Unix modes and owners, as the file manager shows and sets them.
    FilePermissions,
    /// Virus scans. They need `systemd` units and a real `timeout`.
    Clamav,
    /// Starting and stopping system services; the supervisor brings it to the others.
    Services,
    /// The web application firewall: libmodsecurity and its rules are installed (S2).
    Waf,
}

impl Capability {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Websites => "websites",
            Self::PhpMulti => "php_multi",
            Self::Isolation => "isolation",
            Self::ResourceLimits => "resource_limits",
            Self::DiskQuota => "disk_quota",
            Self::Mail => "mail",
            Self::Dns => "dns",
            Self::Ssl => "ssl",
            Self::Firewall => "firewall",
            Self::Ftp => "ftp",
            Self::Terminal => "terminal",
            Self::Docker => "docker",
            Self::Redis => "redis",
            Self::Reseller => "reseller",
            Self::FilePermissions => "file_permissions",
            Self::Clamav => "clamav",
            Self::Services => "services",
            Self::Waf => "waf",
        }
    }
}

/// The body of `GET /capabilities`; see section 5 of `plans/platform-spec.md`.
#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Report {
    pub os: &'static str,
    pub profile: &'static str,
    pub version: &'static str,
    pub capabilities: Capabilities,
}

#[derive(Debug, Clone, PartialEq, Eq, Serialize)]
pub struct Capabilities {
    pub websites: bool,
    pub php_multi: bool,
    pub isolation: &'static str,
    pub resource_limits: &'static str,
    pub disk_quota: &'static str,
    pub mail: &'static str,
    pub dns: &'static str,
    pub ssl: &'static str,
    pub firewall: bool,
    pub ftp: bool,
    pub terminal: bool,
    pub docker: bool,
    pub redis: bool,
    pub reseller: bool,
    pub file_permissions: bool,
    pub clamav: bool,
    pub services: bool,
    pub waf: bool,
}

/// What this machine reports, with Docker looked up on `PATH`.
pub fn report() -> Report {
    let platform = super::current();
    report_for(platform.os(), platform.profile(), docker_on_path(), super::waf::available(), super::acme::engine())
}

/// `docker_installed` only matters on a local profile, where Docker Desktop is
/// optional; `waf_installed` is whether libmodsecurity and the rules are there;
/// `ssl_engine` is what issues certificates here (a Linux server may pick).
fn report_for(
    os: Os,
    profile: Profile,
    docker_installed: bool,
    waf_installed: bool,
    ssl_engine: SslEngine,
) -> Report {
    let server = profile == Profile::Server;
    let linux = os == Os::Linux;
    let capabilities = Capabilities {
        websites: true,
        php_multi: true,
        isolation: if server { "hard" } else { "none" },
        resource_limits: match (profile, os) {
            (Profile::Local, _) => "none",
            (Profile::Server, Os::Macos) => "soft",
            (Profile::Server, _) => "hard",
        },
        disk_quota: if server { "hard" } else { "none" },
        mail: match (profile, os) {
            (Profile::Local, _) => "catcher",
            (Profile::Server, Os::Linux) => "postfix",
            (Profile::Server, _) => "stalwart",
        },
        dns: match (profile, os) {
            (Profile::Local, _) => "hosts",
            (Profile::Server, Os::Linux) => "powerdns",
            (Profile::Server, Os::Windows) => "windows_dns",
            (Profile::Server, Os::Macos) => "external",
        },
        ssl: ssl_engine.as_str(),
        firewall: server,
        ftp: server,
        terminal: !server || linux,
        docker: if server { linux } else { docker_installed },
        redis: os != Os::Windows,
        reseller: server,
        file_permissions: os != Os::Windows,
        clamav: linux,
        services: linux,
        waf: server && waf_installed,
    };
    Report {
        os: os.as_str(),
        profile: profile.as_str(),
        version: env!("CARGO_PKG_VERSION"),
        capabilities,
    }
}

fn docker_on_path() -> bool {
    let binary = format!("docker{}", env::consts::EXE_SUFFIX);
    env::var_os("PATH")
        .is_some_and(|paths| env::split_paths(&paths).any(|dir| is_file(&dir.join(&binary))))
}

fn is_file(path: &Path) -> bool {
    path.metadata().is_ok_and(|metadata| metadata.is_file())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn capabilities(os: Os, profile: Profile, docker: bool) -> serde_json::Value {
        serde_json::to_value(report_for(os, profile, docker, false, SslEngine::choose(os, profile, None))).unwrap()["capabilities"].clone()
    }

    #[test]
    fn linux_server_has_everything() {
        assert_eq!(
            capabilities(Os::Linux, Profile::Server, false),
            json!({
                "websites": true, "php_multi": true, "isolation": "hard",
                "resource_limits": "hard", "disk_quota": "hard",
                "mail": "postfix", "dns": "powerdns", "ssl": "certbot",
                "firewall": true, "ftp": true, "terminal": true, "docker": true,
                "redis": true, "reseller": true, "file_permissions": true, "clamav": true, "services": true, "waf": false,
            })
        );
    }

    #[test]
    fn windows_server_uses_its_own_backends() {
        assert_eq!(
            capabilities(Os::Windows, Profile::Server, true),
            json!({
                "websites": true, "php_multi": true, "isolation": "hard",
                "resource_limits": "hard", "disk_quota": "hard",
                "mail": "stalwart", "dns": "windows_dns", "ssl": "acme",
                "firewall": true, "ftp": true, "terminal": false, "docker": false,
                "redis": false, "reseller": true, "file_permissions": false, "clamav": false,
                "services": false, "waf": false,
            })
        );
    }

    #[test]
    fn macos_server_has_soft_resource_limits() {
        assert_eq!(
            capabilities(Os::Macos, Profile::Server, true),
            json!({
                "websites": true, "php_multi": true, "isolation": "hard",
                "resource_limits": "soft", "disk_quota": "hard",
                "mail": "stalwart", "dns": "external", "ssl": "acme",
                "firewall": true, "ftp": true, "terminal": false, "docker": false,
                "redis": true, "reseller": true, "file_permissions": true, "clamav": false,
                "services": false, "waf": false,
            })
        );
    }

    #[test]
    fn local_profile_drops_hosting_features_on_every_os() {
        for os in [Os::Linux, Os::Macos, Os::Windows] {
            let local = capabilities(os, Profile::Local, false);
            assert_eq!(local["isolation"], "none");
            assert_eq!(local["resource_limits"], "none");
            assert_eq!(local["disk_quota"], "none");
            assert_eq!(local["mail"], "catcher");
            assert_eq!(local["dns"], "hosts");
            assert_eq!(local["ssl"], "local_ca");
            assert_eq!(local["firewall"], false);
            assert_eq!(local["ftp"], false);
            assert_eq!(local["terminal"], true);
            assert_eq!(local["reseller"], false);
        }
    }

    #[test]
    fn the_waf_needs_a_server_and_its_library() {
        let on = |os, profile, installed| serde_json::to_value(report_for(os, profile, false, installed, SslEngine::choose(os, profile, None))).unwrap()["capabilities"]["waf"].clone();
        assert_eq!(on(Os::Linux, Profile::Server, true), true);
        assert_eq!(on(Os::Linux, Profile::Server, false), false);
        assert_eq!(on(Os::Linux, Profile::Local, true), false);
    }

    #[test]
    fn a_linux_server_reports_the_ssl_engine_it_was_told_to_use() {
        let ssl = |engine| serde_json::to_value(report_for(Os::Linux, Profile::Server, false, false, engine)).unwrap()["capabilities"]["ssl"].clone();
        assert_eq!(ssl(SslEngine::Certbot), "certbot");
        assert_eq!(ssl(SslEngine::Acme), "acme");
    }

    #[test]
    fn local_docker_follows_what_is_installed() {
        assert_eq!(capabilities(Os::Macos, Profile::Local, true)["docker"], true);
        assert_eq!(capabilities(Os::Macos, Profile::Local, false)["docker"], false);
    }

    #[test]
    fn report_names_the_os_profile_and_version() {
        let report = serde_json::to_value(report_for(Os::Macos, Profile::Local, false, false, SslEngine::LocalCa)).unwrap();
        assert_eq!(report["os"], "macos");
        assert_eq!(report["profile"], "local");
        assert_eq!(report["version"], env!("CARGO_PKG_VERSION"));
    }
}
