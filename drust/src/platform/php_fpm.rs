//! Where Debian/Ubuntu's PHP-FPM keeps its files (`ondrej/php` layout), for
//! the worker overview and limits. Only Linux servers run PHP-FPM; on other
//! systems these paths simply do not exist.

use std::path::{Path, PathBuf};

/// `/etc/php`, one folder per installed version.
pub fn config_root() -> &'static Path {
    Path::new("/etc/php")
}

/// `/etc/php/<version>/fpm`.
pub fn fpm_dir(version: &str) -> PathBuf {
    config_root().join(version).join("fpm")
}

/// The version in a path such as a master's `(/etc/php/8.3/fpm/php-fpm.conf)`.
pub fn version_in_text(text: &str) -> Option<&str> {
    const MARKER: &str = "/etc/php/";
    let rest = &text[text.find(MARKER)? + MARKER.len()..];
    rest.split('/').next()
}

/// The shared master's error log.
pub fn shared_log(version: &str) -> PathBuf {
    PathBuf::from(format!("/var/log/php{version}-fpm.log"))
}

/// A dedicated master's error log.
pub fn dedicated_log(owner: &str, version: &str) -> PathBuf {
    PathBuf::from(format!("/var/log/php{version}-fpm-dpanel-{owner}.log"))
}

/// `php-fpm<version>`, for `-t` config tests.
pub fn binary(version: &str) -> PathBuf {
    PathBuf::from(format!("/usr/sbin/php-fpm{version}"))
}
