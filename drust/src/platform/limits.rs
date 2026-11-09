//! CPU, memory and disk limits per account. `plans/platform-spec.md` sections
//! 6.6 and 6.7. Linux has both (P2, P4); J2, J3, K2 and K3 bring the rest.

use super::{PlatformError, capabilities::Capability, fs::OwnerId};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum LimitLevel {
    /// The OS refuses to go over the limit.
    Hard,
    /// Going over is noticed and acted on afterwards.
    Soft,
    None,
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct ResourceProfile {
    pub cpu_percent: u32,
    pub memory_mb: u32,
    pub max_processes: u32,
}

#[derive(Debug, Clone, Copy, PartialEq)]
pub struct UsageSample {
    pub cpu_percent: f32,
    pub memory_mb: u64,
    pub processes: u32,
}

/// One panel account's share of the machine. Keyed by the account (`a<user
/// id>`), not by a system user: an account's websites may run as several
/// system users, and the package limit is for all of them together.
pub trait ResourceLimiter: Send + Sync + std::fmt::Debug {
    fn level(&self) -> LimitLevel;
    /// A `0` in the profile means no limit of that kind.
    fn apply(&self, group: &str, profile: &ResourceProfile) -> Result<(), PlatformError>;
    fn usage(&self, group: &str) -> Result<UsageSample, PlatformError>;
    fn clear(&self, group: &str) -> Result<(), PlatformError>;
}

/// The systemd slice an account's processes run in on Linux.
pub fn slice_name(group: &str) -> String {
    format!("dpanel-{group}.slice")
}

/// `a` and the panel user's id: safe in a unit, path or file name.
pub fn valid_group(group: &str) -> bool {
    group.len() >= 2
        && group.len() <= 21
        && group.starts_with('a')
        && group[1..].bytes().all(|byte| byte.is_ascii_digit())
}

pub trait DiskQuota: Send + Sync + std::fmt::Debug {
    fn level(&self) -> LimitLevel;
    fn set(&self, owner: &OwnerId, limit_mb: u64) -> Result<(), PlatformError>;
    fn usage_mb(&self, owner: &OwnerId) -> Result<u64, PlatformError>;
    fn clear(&self, owner: &OwnerId) -> Result<(), PlatformError>;
}

/// Stands in on every OS until its backend exists.
#[derive(Debug)]
pub struct NoLimits;

fn no_limits<T>() -> Result<T, PlatformError> {
    Err(PlatformError::Unsupported {
        capability: Capability::ResourceLimits,
    })
}

fn no_quota<T>() -> Result<T, PlatformError> {
    Err(PlatformError::Unsupported {
        capability: Capability::DiskQuota,
    })
}

impl ResourceLimiter for NoLimits {
    fn level(&self) -> LimitLevel {
        LimitLevel::None
    }

    fn apply(&self, _group: &str, _profile: &ResourceProfile) -> Result<(), PlatformError> {
        no_limits()
    }

    fn usage(&self, _group: &str) -> Result<UsageSample, PlatformError> {
        no_limits()
    }

    fn clear(&self, _group: &str) -> Result<(), PlatformError> {
        no_limits()
    }
}

impl DiskQuota for NoLimits {
    fn level(&self) -> LimitLevel {
        LimitLevel::None
    }

    fn set(&self, _owner: &OwnerId, _limit_mb: u64) -> Result<(), PlatformError> {
        no_quota()
    }

    fn usage_mb(&self, _owner: &OwnerId) -> Result<u64, PlatformError> {
        no_quota()
    }

    fn clear(&self, _owner: &OwnerId) -> Result<(), PlatformError> {
        no_quota()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const OWNER: OwnerId = OwnerId { uid: 1000, gid: 1000 };

    #[test]
    fn without_a_backend_each_limit_names_its_own_capability() {
        assert_eq!(ResourceLimiter::level(&NoLimits), LimitLevel::None);
        assert_eq!(DiskQuota::level(&NoLimits), LimitLevel::None);
        assert!(matches!(
            ResourceLimiter::clear(&NoLimits, "a1"),
            Err(PlatformError::Unsupported { capability: Capability::ResourceLimits })
        ));
        assert!(matches!(
            NoLimits.set(&OWNER, 100),
            Err(PlatformError::Unsupported { capability: Capability::DiskQuota })
        ));
    }

    #[test]
    fn a_group_is_a_and_digits_only() {
        assert!(valid_group("a12"));
        assert!(!valid_group("a"));
        assert!(!valid_group("b12"));
        assert!(!valid_group("a1/../x"));
        assert!(!valid_group("a-1"));
    }
}
