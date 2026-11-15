//! The hosts file on a PC (F6): each local site's name points at
//! 127.0.0.1, so `mysite.test` reaches the gateway. drust owns only the lines
//! that end in `# dpanel`, the same mark `windows/dpanel.ps1` used, and never
//! touches the rest.
//!
//! Writing the file needs an administrator. drust on a PC runs as the user,
//! so `set` reports `PermissionDenied`, and the caller runs `drust hosts set`
//! elevated (`platform::elevate`), which shows Windows' UAC prompt.

use std::{collections::BTreeSet, fs, io, path::PathBuf};

use super::{Os, PlatformError};

pub const MARK: &str = "# dpanel";

pub fn file() -> PathBuf {
    file_for(super::current().os(), std::env::var_os("SystemRoot"))
}

fn file_for(os: Os, system_root: Option<std::ffi::OsString>) -> PathBuf {
    match os {
        Os::Windows => PathBuf::from(system_root.unwrap_or_else(|| r"C:\Windows".into()))
            .join("System32")
            .join("drivers")
            .join("etc")
            .join("hosts"),
        Os::Linux | Os::Macos => PathBuf::from("/etc/hosts"),
    }
}

/// A host name: dot-separated labels of letters, digits and inner hyphens,
/// at most 253 characters. Lowercase only, as the panel stores them.
pub fn valid_name(name: &str) -> bool {
    name.len() <= 253
        && !name.is_empty()
        && name.split('.').all(|label| {
            !label.is_empty()
                && label.len() <= 63
                && !label.starts_with('-')
                && !label.ends_with('-')
                && label.bytes().all(|byte| byte.is_ascii_lowercase() || byte.is_ascii_digit() || byte == b'-')
        })
}

fn is_ours(line: &str) -> bool {
    line.trim_end().strip_suffix("dpanel").is_some_and(|rest| rest.trim_end().ends_with('#'))
}

/// The names drust put in the file.
pub fn managed(text: &str) -> BTreeSet<String> {
    text.lines()
        .filter(|line| is_ours(line))
        .filter_map(|line| line.split_whitespace().nth(1))
        .map(str::to_string)
        .collect()
}

/// `text` with drust's lines replaced by one `127.0.0.1` line per name, at
/// the end. Other lines and the file's line endings stay as they were.
pub fn with_names(text: &str, names: &BTreeSet<String>) -> String {
    let newline = if text.contains("\r\n") { "\r\n" } else { "\n" };
    let mut kept: Vec<&str> = text.lines().filter(|line| !is_ours(line)).collect();
    while kept.last().is_some_and(|line| line.trim().is_empty()) {
        kept.pop();
    }
    let mut out = String::new();
    for line in kept {
        out.push_str(line);
        out.push_str(newline);
    }
    for name in names {
        out.push_str(&format!("127.0.0.1 {name} {MARK}{newline}"));
    }
    out
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Change {
    Unchanged,
    Written,
}

/// Makes drust's lines exactly `names`. `PermissionDenied` when this process
/// may not write the file.
pub fn set(names: &BTreeSet<String>) -> Result<Change, PlatformError> {
    if let Some(bad) = names.iter().find(|name| !valid_name(name)) {
        return Err(PlatformError::Failed { action: "update the hosts file".into(), detail: format!("not a host name: {bad}") });
    }
    let path = file();
    let text = fs::read_to_string(&path).map_err(|error| PlatformError::from_io("read the hosts file", error))?;
    let wanted = with_names(&text, names);
    if wanted == text {
        return Ok(Change::Unchanged);
    }
    // In place, not by rename: the file keeps its ACL.
    match fs::write(&path, wanted) {
        Ok(()) => Ok(Change::Written),
        Err(error) if error.kind() == io::ErrorKind::PermissionDenied => Err(PlatformError::PermissionDenied {
            what: format!("writing {} needs an administrator", path.display()),
        }),
        Err(error) => Err(PlatformError::from_io("write the hosts file", error)),
    }
}

/// Asks the OS to forget cached lookups after a change. Best effort: Windows'
/// DNS client also notices a changed hosts file by itself.
pub fn flush_resolver_cache() {
    #[cfg(windows)]
    {
        let mut command = std::process::Command::new("ipconfig");
        command.arg("/flushdns").stdout(std::process::Stdio::null()).stderr(std::process::Stdio::null());
        super::process::without_console(&mut command);
        let _ = command.status();
    }
}

/// The names drust has in the hosts file now.
pub fn current() -> Result<BTreeSet<String>, PlatformError> {
    fs::read_to_string(file()).map(|text| managed(&text)).map_err(|error| PlatformError::from_io("read the hosts file", error))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn names(list: &[&str]) -> BTreeSet<String> {
        list.iter().map(|name| name.to_string()).collect()
    }

    #[test]
    fn replaces_only_its_own_lines_and_keeps_crlf() {
        let text = "# Copyright\r\n127.0.0.1 localhost\r\n127.0.0.1 old.test # dpanel\r\n10.0.0.5 nas\r\n127.0.0.1 gone.test  #dpanel  \r\n\r\n";
        let out = with_names(text, &names(&["b.test", "a.test"]));
        assert_eq!(
            out,
            "# Copyright\r\n127.0.0.1 localhost\r\n10.0.0.5 nas\r\n127.0.0.1 a.test # dpanel\r\n127.0.0.1 b.test # dpanel\r\n"
        );
        assert_eq!(managed(&out), names(&["a.test", "b.test"]));
        assert_eq!(managed(text), names(&["gone.test", "old.test"]));
        // The same names again change nothing.
        assert_eq!(with_names(&out, &managed(&out)), out);
    }

    #[test]
    fn no_names_removes_every_line_of_ours() {
        let text = "127.0.0.1 localhost\n127.0.0.1 shop.test # dpanel\n";
        assert_eq!(with_names(text, &BTreeSet::new()), "127.0.0.1 localhost\n");
    }

    #[test]
    fn a_line_that_only_mentions_dpanel_is_not_ours() {
        let text = "127.0.0.1 dpanel\n# dpanel docs at 10.0.0.1 # see dpanel.org\n";
        assert!(managed(text).is_empty());
        assert_eq!(with_names(text, &BTreeSet::new()), text);
    }

    #[test]
    fn only_host_names_go_in() {
        assert!(valid_name("shop.test"));
        assert!(valid_name("my-shop.example.test"));
        assert!(valid_name("localhost"));
        for bad in ["", "Shop.test", "-a.test", "a-.test", "a..test", "a.test\n127.0.0.1 bank.com", "a test", "a.test#x"] {
            assert!(!valid_name(bad), "{bad:?}");
        }
        assert!(!valid_name(&format!("{}.test", "a".repeat(64))));
    }

    #[test]
    fn the_file_is_where_each_os_keeps_it() {
        assert_eq!(
            file_for(Os::Windows, Some(r"D:\Win".into())),
            PathBuf::from(r"D:\Win").join("System32").join("drivers").join("etc").join("hosts")
        );
        assert_eq!(file_for(Os::Linux, None), PathBuf::from("/etc/hosts"));
    }
}
