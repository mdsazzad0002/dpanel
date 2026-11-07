//! What this build of drust can do on this system.

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
    /// Unix modes, owners and links. Windows gets its ACLs in task F1.
    FilePermissions,
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
        }
    }
}
