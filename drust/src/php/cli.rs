use std::path::Path;

/// Directory, inside a bwrap sandbox, whose `php` points at the website's PHP
/// CLI. It goes first on PATH, so `php` and composer (`#!/usr/bin/env php`)
/// run the website's version instead of the system default.
const SANDBOX_BIN: &str = "/run/dpanel-php";
const BASE_PATH: &str = "/usr/local/bin:/usr/bin:/bin";

/// bwrap arguments and the PATH value that pin `php` to `version`. Unknown or
/// uninstalled versions fall back to the system default PHP.
pub(crate) fn sandbox_pin(version: Option<&str>) -> (Vec<String>, String) {
    match version.and_then(binary) {
        Some(binary) => (
            vec![
                "--dir".into(),
                SANDBOX_BIN.into(),
                "--symlink".into(),
                binary,
                format!("{SANDBOX_BIN}/php"),
            ],
            format!("{SANDBOX_BIN}:{BASE_PATH}"),
        ),
        None => (Vec::new(), BASE_PATH.into()),
    }
}

fn binary(version: &str) -> Option<String> {
    let version = version.trim();
    let bytes = version.as_bytes();
    let valid = bytes.len() == 3 && bytes[0].is_ascii_digit() && bytes[1] == b'.' && bytes[2].is_ascii_digit();
    if !valid {
        return None;
    }
    // Only /usr is bound into the sandboxes, so only look there.
    [format!("/usr/bin/php{version}"), format!("/usr/local/bin/php{version}")]
        .into_iter()
        .find(|path| Path::new(path).is_file())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_invalid_versions() {
        for version in ["", "8", "8.3; rm", "../../bin/sh", "8.10"] {
            assert_eq!(sandbox_pin(Some(version)), (Vec::new(), BASE_PATH.to_string()));
        }
        assert_eq!(sandbox_pin(None), (Vec::new(), BASE_PATH.to_string()));
    }
}
