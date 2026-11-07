//! Where things live on each OS and profile; see section 6.1 of
//! `plans/platform-spec.md`. Linux ignores the profile, so its paths never move.

use super::{Os, PlatformError, Profile};
use std::{
    env,
    path::{Path, PathBuf},
};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Tool {
    Clamdscan,
    Clamscan,
    Composer,
    Docker,
    Fail2banClient,
    Freshclam,
    Node,
    Npm,
    Psql,
    Python3,
    Ss,
    Sshd,
    Systemctl,
    Ufw,
}

impl Tool {
    fn name(self) -> &'static str {
        match self {
            Self::Clamdscan => "clamdscan",
            Self::Clamscan => "clamscan",
            Self::Composer => "composer",
            Self::Docker => "docker",
            Self::Fail2banClient => "fail2ban-client",
            Self::Freshclam => "freshclam",
            Self::Node => "node",
            Self::Npm => "npm",
            Self::Psql => "psql",
            Self::Python3 => "python3",
            Self::Ss => "ss",
            Self::Sshd => "sshd",
            Self::Systemctl => "systemctl",
            Self::Ufw => "ufw",
        }
    }

    /// Fixed places, in order. drust runs as root on Linux, so it never takes a
    /// tool from `PATH` there.
    fn unix_candidates(self) -> &'static [&'static str] {
        match self {
            Self::Clamdscan => &["/usr/bin/clamdscan", "/usr/local/bin/clamdscan"],
            Self::Clamscan => &["/usr/bin/clamscan", "/usr/local/bin/clamscan"],
            Self::Composer => &["/usr/local/bin/composer", "/usr/bin/composer"],
            Self::Docker => &["/usr/bin/docker", "/usr/local/bin/docker"],
            Self::Fail2banClient => &["/usr/bin/fail2ban-client", "/usr/local/bin/fail2ban-client"],
            Self::Freshclam => &["/usr/bin/freshclam", "/usr/local/bin/freshclam"],
            Self::Node => &["/usr/bin/node", "/usr/local/bin/node"],
            Self::Npm => &["/usr/local/bin/npm", "/usr/bin/npm"],
            Self::Psql => &["/usr/bin/psql", "/usr/local/bin/psql"],
            Self::Python3 => &["/usr/bin/python3", "/usr/local/bin/python3"],
            Self::Ss => &["/usr/bin/ss", "/usr/sbin/ss"],
            Self::Sshd => &["/usr/sbin/sshd", "/usr/local/sbin/sshd"],
            Self::Systemctl => &["/usr/bin/systemctl", "/bin/systemctl"],
            Self::Ufw => &["/usr/sbin/ufw", "/usr/bin/ufw"],
        }
    }
}

/// Where `tool` is, given what exists on disk and the `PATH` directories.
fn locate(
    os: Os,
    tool: Tool,
    path_dirs: impl Iterator<Item = PathBuf>,
    is_file: impl Fn(&Path) -> bool,
) -> Option<PathBuf> {
    let fixed = tool.unix_candidates().iter().map(PathBuf::from);
    match os {
        Os::Linux => fixed.into_iter().find(|path| is_file(path)),
        Os::Macos => fixed
            .chain([Path::new("/opt/homebrew/bin").join(tool.name())])
            .find(|path| is_file(path)),
        // npm and composer are batch files there.
        Os::Windows => path_dirs
            .flat_map(|dir| {
                ["exe", "cmd", "bat"].map(|extension| dir.join(format!("{}.{extension}", tool.name())))
            })
            .find(|path| is_file(path)),
    }
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Paths {
    os: Os,
    panel_root: PathBuf,
    config_dir: PathBuf,
    data_dir: PathBuf,
    sites_root: PathBuf,
    run_dir: PathBuf,
    temp_dir: PathBuf,
    runtime_dir: PathBuf,
    state_dir: PathBuf,
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
        let data_dir = PathBuf::from(data_dir);
        let (run_dir, temp_dir, runtime_dir, state_dir) = match os {
            Os::Linux => (
                PathBuf::from("/run"),
                PathBuf::from("/tmp"),
                PathBuf::from("/opt/dpanel"),
                PathBuf::from("/var/lib/drust"),
            ),
            Os::Macos | Os::Windows => (
                data_dir.join("run"),
                match os {
                    Os::Windows => var("TEMP", r"C:\Windows\Temp").into(),
                    _ => var("TMPDIR", "/tmp").trim_end_matches('/').into(),
                },
                data_dir.join("runtime"),
                data_dir.clone(),
            ),
        };
        Self {
            os,
            panel_root: panel_root.into(),
            config_dir: config_dir.into(),
            data_dir,
            sites_root: sites_root.into(),
            run_dir,
            temp_dir,
            runtime_dir,
            state_dir,
        }
    }

    pub fn panel_root(&self) -> &Path {
        &self.panel_root
    }

    pub fn data_dir(&self) -> &Path {
        &self.data_dir
    }

    /// drust's own state. On Linux this is a separate folder from `data_dir`
    /// (`/var/lib/drust`), and servers already hold data in both.
    pub fn state_dir(&self) -> &Path {
        &self.state_dir
    }

    /// Sockets, locks and other files that do not outlive a reboot.
    pub fn run_dir(&self) -> &Path {
        &self.run_dir
    }

    pub fn temp_dir(&self) -> &Path {
        &self.temp_dir
    }

    /// Software dPanel installs for itself, such as pgAdmin.
    pub fn runtime_dir(&self) -> &Path {
        &self.runtime_dir
    }

    /// The folder holding the panel and what ships beside it, such as phpMyAdmin.
    pub fn install_root(&self) -> &Path {
        self.panel_root.parent().unwrap_or(&self.panel_root)
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

    pub fn binary(&self, tool: Tool) -> Result<PathBuf, PlatformError> {
        let path_var = env::var_os("PATH").unwrap_or_default();
        locate(self.os, tool, env::split_paths(&path_var), Path::is_file).ok_or_else(|| {
            PlatformError::NotFound {
                what: format!("{} is not installed", tool.name()),
            }
        })
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
                    "TEMP" => r"D:\Temp",
                    "TMPDIR" => "/var/folders/x/T/",
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
            assert_eq!(linux.run_dir(), Path::new("/run"));
            assert_eq!(linux.temp_dir(), Path::new("/tmp"));
            assert_eq!(linux.runtime_dir(), Path::new("/opt/dpanel"));
            assert_eq!(linux.state_dir(), Path::new("/var/lib/drust"));
            assert_eq!(linux.install_root(), Path::new("/var/www"));
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
    fn other_systems_keep_runtime_files_under_their_data_dir() {
        let macos = paths(Os::Macos, Profile::Server);
        assert_eq!(macos.run_dir(), Path::new("/Library/dPanel/data/run"));
        assert_eq!(macos.runtime_dir(), Path::new("/Library/dPanel/data/runtime"));
        assert_eq!(macos.state_dir(), Path::new("/Library/dPanel/data"));
        assert_eq!(macos.temp_dir(), Path::new("/var/folders/x/T"));
        assert_eq!(paths(Os::Windows, Profile::Server).temp_dir(), Path::new(r"D:\Temp"));
    }

    fn found(os: Os, tool: Tool, present: &[&str]) -> Option<PathBuf> {
        let dirs = [PathBuf::from("/first"), PathBuf::from("/second")];
        locate(os, tool, dirs.into_iter(), |path| {
            present.iter().any(|item| Path::new(item) == path)
        })
    }

    #[test]
    fn linux_keeps_each_tools_search_order() {
        let both = ["/usr/bin/docker", "/usr/local/bin/docker"];
        assert_eq!(found(Os::Linux, Tool::Docker, &both), Some("/usr/bin/docker".into()));
        assert_eq!(found(Os::Linux, Tool::Docker, &both[1..]), Some("/usr/local/bin/docker".into()));
        // Composer and npm look in /usr/local first, and ufw in sbin.
        let both = ["/usr/bin/composer", "/usr/local/bin/composer"];
        assert_eq!(found(Os::Linux, Tool::Composer, &both), Some("/usr/local/bin/composer".into()));
        let both = ["/usr/bin/ufw", "/usr/sbin/ufw"];
        assert_eq!(found(Os::Linux, Tool::Ufw, &both), Some("/usr/sbin/ufw".into()));
    }

    /// A root process that trusts `PATH` runs whatever is first in it.
    #[test]
    fn linux_never_takes_a_tool_from_path() {
        assert_eq!(found(Os::Linux, Tool::Node, &["/first/node", "/first/node.exe"]), None);
        assert_eq!(found(Os::Linux, Tool::Node, &["/opt/homebrew/bin/node"]), None);
    }

    #[test]
    fn macos_also_finds_homebrew_on_apple_silicon() {
        assert_eq!(
            found(Os::Macos, Tool::Node, &["/opt/homebrew/bin/node"]),
            Some("/opt/homebrew/bin/node".into())
        );
        assert_eq!(found(Os::Macos, Tool::Node, &["/first/node"]), None);
    }

    #[test]
    fn windows_searches_path_in_order() {
        assert_eq!(
            found(Os::Windows, Tool::Node, &["/second/node.exe", "/first/node.exe"]),
            Some("/first/node.exe".into())
        );
        assert_eq!(found(Os::Windows, Tool::Npm, &["/second/npm.cmd"]), Some("/second/npm.cmd".into()));
        assert_eq!(found(Os::Windows, Tool::Node, &["/usr/bin/node"]), None);
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
