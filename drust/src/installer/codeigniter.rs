//! CodeIgniter 4: `codeigniter4/appstarter`, then finalize.

use super::service::Permissions;

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &["writable"],
    secret_config: None,
};

pub(super) fn package() -> (&'static str, &'static str) {
    ("codeigniter4/appstarter", "^4.0")
}
