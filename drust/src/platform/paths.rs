//! Where things live on each OS and profile; see section 6.1 of
//! `plans/platform-spec.md`. Linux ignores the profile, so its paths never move.

use super::{Os, Profile};
use std::path::{Path, PathBuf};

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Paths {
    panel_root: PathBuf,
    config_dir: PathBuf,
    data_dir: PathBuf,
    sites_root: PathBuf,
}

impl Paths {
    pub(super) fn for_system(os: Os, profile: Profile, env: impl Fn(&str) -> Option<String>) -> Self {
        let var = |name: &str, fallback: &str| env(name).unwrap_or_else(|| fallback.to_string());
        let (panel_root, config_dir, data_dir, sites_root) = match (os, profile) {
            (Os::Linux, _) => (
                "/var/www/dpanel".to_string(),
                "/etc/drust".to_string(),
                "/var/lib/dpanel".to_string(),
                "/home".to_string(),
            ),
            (Os::Windows, Profile::Server) => {
                let data = var("ProgramData", r"C:\ProgramData");
                (
                    format!(r"{}\dPanel\panel", var("ProgramFiles", r"C:\Program Files")),
                    format!(r"{data}\dPanel\config"),
                    format!(r"{data}\dPanel\data"),
                    format!(r"{data}\dPanel\sites"),
                )
            }
            (Os::Windows, Profile::Local) => {
                let base = format!(r"{}\dPanel", var("LOCALAPPDATA", r"C:\ProgramData"));
                let profile_dir = var("USERPROFILE", r"C:\Users\Public");
                (
                    format!(r"{base}\panel"),
                    format!(r"{base}\config"),
                    format!(r"{base}\data"),
                    format!(r"{profile_dir}\dPanel\sites"),
                )
            }
            (Os::Macos, Profile::Server) => (
                "/Library/dPanel/panel".to_string(),
                "/Library/dPanel/config".to_string(),
                "/Library/dPanel/data".to_string(),
                "/Users/Shared/dPanelSites".to_string(),
            ),
            (Os::Macos, Profile::Local) => {
                let home = var("HOME", "");
                let base = format!("{home}/Library/Application Support/dPanel");
                (
                    format!("{base}/panel"),
                    format!("{base}/config"),
                    format!("{base}/data"),
                    format!("{home}/dPanel/sites"),
                )
            }
        };
        Self {
            panel_root: panel_root.into(),
            config_dir: config_dir.into(),
            data_dir: data_dir.into(),
            sites_root: sites_root.into(),
        }
    }

    pub fn panel_root(&self) -> &Path {
        &self.panel_root
    }

    pub fn data_dir(&self) -> &Path {
        &self.data_dir
    }

    /// Laravel's `storage/app`, where the panel hands files to drust.
    pub fn storage_dir(&self) -> PathBuf {
        self.panel_root.join("storage").join("app")
    }

    pub fn sites_root(&self) -> &Path {
        &self.sites_root
    }

    /// Appended, not `join`ed: `join` drops the base for an absolute name, so an
    /// owner of `/etc` would turn every "inside the owner's home" check into `/etc`.
    pub fn site_home(&self, owner: &str) -> PathBuf {
        let mut home = self.sites_root.clone().into_os_string();
        home.push(std::path::MAIN_SEPARATOR_STR);
        home.push(owner);
        home.into()
    }

    pub fn tls_dir(&self) -> PathBuf {
        self.config_dir.join("tls")
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn paths(os: Os, profile: Profile) -> Paths {
        Paths::for_system(os, profile, |name| {
            Some(
                match name {
                    "ProgramFiles" => r"D:\Apps",
                    "ProgramData" => r"D:\Data",
                    "LOCALAPPDATA" => r"D:\Users\me\AppData\Local",
                    "USERPROFILE" => r"D:\Users\me",
                    "HOME" => "/Users/me",
                    other => panic!("unexpected variable {other}"),
                }
                .to_string(),
            )
        })
    }

    fn roots(paths: &Paths) -> [&str; 4] {
        [
            paths.panel_root.to_str().unwrap(),
            paths.config_dir.to_str().unwrap(),
            paths.data_dir.to_str().unwrap(),
            paths.sites_root.to_str().unwrap(),
        ]
    }

    /// These are live servers' paths: changing one strands their data.
    #[test]
    fn linux_paths_are_the_ones_servers_already_use() {
        for profile in [Profile::Server, Profile::Local] {
            let linux = paths(Os::Linux, profile);
            assert_eq!(linux.panel_root(), Path::new("/var/www/dpanel"));
            assert_eq!(linux.data_dir(), Path::new("/var/lib/dpanel"));
            assert_eq!(linux.tls_dir(), Path::new("/etc/drust/tls"));
            assert_eq!(linux.storage_dir(), Path::new("/var/www/dpanel/storage/app"));
            assert_eq!(linux.sites_root(), Path::new("/home"));
        }
    }

    #[test]
    fn windows_follows_the_spec_table() {
        assert_eq!(
            roots(&paths(Os::Windows, Profile::Server)),
            [
                r"D:\Apps\dPanel\panel",
                r"D:\Data\dPanel\config",
                r"D:\Data\dPanel\data",
                r"D:\Data\dPanel\sites",
            ]
        );
        assert_eq!(
            roots(&paths(Os::Windows, Profile::Local)),
            [
                r"D:\Users\me\AppData\Local\dPanel\panel",
                r"D:\Users\me\AppData\Local\dPanel\config",
                r"D:\Users\me\AppData\Local\dPanel\data",
                r"D:\Users\me\dPanel\sites",
            ]
        );
    }

    #[test]
    fn macos_follows_the_spec_table() {
        assert_eq!(
            roots(&paths(Os::Macos, Profile::Server)),
            [
                "/Library/dPanel/panel",
                "/Library/dPanel/config",
                "/Library/dPanel/data",
                "/Users/Shared/dPanelSites",
            ]
        );
        assert_eq!(
            roots(&paths(Os::Macos, Profile::Local)),
            [
                "/Users/me/Library/Application Support/dPanel/panel",
                "/Users/me/Library/Application Support/dPanel/config",
                "/Users/me/Library/Application Support/dPanel/data",
                "/Users/me/dPanel/sites",
            ]
        );
    }

    #[test]
    fn missing_windows_variables_fall_back_to_the_system_defaults() {
        let windows = Paths::for_system(Os::Windows, Profile::Server, |_| None);
        assert_eq!(
            roots(&windows),
            [
                r"C:\Program Files\dPanel\panel",
                r"C:\ProgramData\dPanel\config",
                r"C:\ProgramData\dPanel\data",
                r"C:\ProgramData\dPanel\sites",
            ]
        );
    }

    #[test]
    fn a_site_home_is_under_the_sites_root() {
        let linux = paths(Os::Linux, Profile::Server);
        assert_eq!(linux.site_home("bob"), Path::new("/home/bob"));
        assert!(Path::new("/home/bob/public_html").starts_with(linux.site_home("bob")));
        assert!(!Path::new("/home/bobby/public_html").starts_with(linux.site_home("bob")));
    }

    /// The ownership checks were `path.starts_with(format!("/home/{owner}/"))`.
    #[test]
    fn site_home_accepts_exactly_what_the_old_prefix_did() {
        let linux = paths(Os::Linux, Profile::Server);
        let owners = ["bob", "bobby", "", "..", "a/b", "/etc", "bob/"];
        let targets = [
            "/home", "/home/", "/home/bob", "/home/bob/", "/home/bob/x", "/home/bobby/x",
            "/home/a/b/x", "/home/../etc", "/home/bob/../etc", "/etc/shadow", "/homes/bob", "bob",
        ];
        for owner in owners {
            for target in targets {
                let target = Path::new(target);
                assert_eq!(
                    target.starts_with(linux.site_home(owner)),
                    target.starts_with(format!("/home/{owner}/")),
                    "owner {owner:?}, target {target:?}"
                );
            }
        }
    }

    #[test]
    fn an_absolute_owner_cannot_leave_the_sites_root() {
        let home = paths(Os::Linux, Profile::Server).site_home("/etc");
        assert!(home.starts_with("/home"));
        assert!(!Path::new("/etc/shadow").starts_with(&home));
    }
}
