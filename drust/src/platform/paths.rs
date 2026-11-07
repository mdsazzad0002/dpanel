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
    Uv,
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
            Self::Uv => "uv",
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
            Self::Uv => &["/usr/local/bin/uv", "/usr/bin/uv", "/root/.local/bin/uv"],
        }
    }
}

/// Where a PHP CLI can be, best first. `version` is already validated, like `8.3`.
fn php_places(
    os: Os,
    runtime_dir: &Path,
    version: Option<&str>,
    path_dirs: impl Iterator<Item = PathBuf>,
) -> Vec<PathBuf> {
    match (os, version) {
        (Os::Linux, Some(version)) => vec![
            format!("/usr/bin/php{version}").into(),
            format!("/usr/local/bin/php{version}").into(),
        ],
        (Os::Linux, None) => ["/usr/bin/php", "/usr/local/bin/php", "/usr/bin/php8.3", "/usr/bin/php8.2"]
            .map(PathBuf::from)
            .to_vec(),
        (Os::Macos, Some(version)) => vec![
            format!("/opt/homebrew/opt/php@{version}/bin/php").into(),
            format!("/usr/local/opt/php@{version}/bin/php").into(),
        ],
        (Os::Macos, None) => ["/opt/homebrew/bin/php", "/usr/local/bin/php"].map(PathBuf::from).to_vec(),
        (Os::Windows, Some(version)) => vec![runtime_dir.join("php").join(version).join("php.exe")],
        (Os::Windows, None) => std::iter::once(runtime_dir.join("php").join("php.exe"))
            .chain(path_dirs.map(|dir| dir.join("php.exe")))
            .collect(),
    }
}

/// Where a php-cgi of `version` can be, best first. Windows only: other
/// systems have PHP-FPM. Besides dPanel's own copy, a PHP on `PATH` shows where
/// a stack such as WampServer keeps its versions, as `<root>\php8.3.28`.
fn php_cgi_places(
    os: Os,
    runtime_dir: &Path,
    version: &str,
    path_dirs: impl Iterator<Item = PathBuf>,
    is_file: impl Fn(&Path) -> bool,
    list_dir: impl Fn(&Path) -> Vec<PathBuf>,
) -> Vec<PathBuf> {
    if os != Os::Windows {
        return Vec::new();
    }
    let prefix = format!("php{version}.");
    let patch = |dir: &Path| {
        let name = dir.file_name()?.to_str()?;
        name.strip_prefix(&prefix)?.parse::<u32>().ok()
    };
    let mut stacks: Vec<(u32, PathBuf)> = path_dirs
        .filter(|dir| is_file(&dir.join("php.exe")))
        .filter_map(|dir| dir.parent().map(Path::to_path_buf))
        .flat_map(|root| list_dir(&root))
        .filter_map(|dir| patch(&dir).map(|patch| (patch, dir)))
        .collect();
    stacks.sort_by(|a, b| b.cmp(a));
    std::iter::once(runtime_dir.join("php").join(version))
        .chain(stacks.into_iter().map(|(_, dir)| dir))
        .map(|dir| dir.join("php-cgi.exe"))
        .collect()
}

/// `canonicalize` on Windows gives `\\?\C:\site`, which not every program
/// accepts. Network paths keep their prefix.
pub fn plain_path(path: &Path) -> PathBuf {
    let text = path.to_string_lossy();
    match text.strip_prefix(r"\\?\") {
        Some(rest) if rest.as_bytes().get(1) == Some(&b':') => PathBuf::from(rest),
        _ => path.to_path_buf(),
    }
}

/// The one CLI to run. Linux always means its first place, so a copy elsewhere
/// is never picked up by accident; the others take the first that is there.
fn chosen_php(os: Os, mut places: Vec<PathBuf>, is_file: impl Fn(&Path) -> bool) -> PathBuf {
    let found = places.iter().position(|path| os != Os::Linux && is_file(path));
    places.swap_remove(found.unwrap_or(0))
}

fn python_places(os: Os, runtime_dir: &Path, version: &str) -> Vec<PathBuf> {
    match os {
        Os::Linux => vec![
            format!("/usr/local/pyenv/versions/{version}/bin/python3").into(),
            format!("/usr/bin/python{version}").into(),
            format!("/usr/local/bin/python{version}").into(),
        ],
        Os::Macos => vec![
            format!("/opt/homebrew/bin/python{version}").into(),
            format!("/usr/local/bin/python{version}").into(),
        ],
        Os::Windows => vec![runtime_dir.join("python").join(version).join("python.exe")],
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
        Os::Windows => on_windows_path(tool.name(), path_dirs, is_file),
    }
}

/// The first `PATH` entry that can run `name`, which Windows needs an extension for.
pub(super) fn on_windows_path(
    name: &str,
    path_dirs: impl Iterator<Item = PathBuf>,
    is_file: impl Fn(&Path) -> bool,
) -> Option<PathBuf> {
    path_dirs
        .flat_map(|dir| ["exe", "cmd", "bat"].map(|extension| dir.join(format!("{name}.{extension}"))))
        .find(|path| is_file(path))
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
                    // A developer's panel lives in their checkout.
                    env("DPANEL_PANEL_ROOT").unwrap_or_else(|| format!(r"{base}\panel")),
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

    /// Every place a PHP CLI can be, best first. `None` is the system's own PHP.
    pub fn php_candidates(&self, version: Option<&str>) -> Vec<PathBuf> {
        let path_var = env::var_os("PATH").unwrap_or_default();
        php_places(self.os, &self.runtime_dir, version, env::split_paths(&path_var))
    }

    /// The PHP CLI to run. It may not exist, which the caller reports.
    pub fn php_cli(&self, version: Option<&str>) -> PathBuf {
        chosen_php(self.os, self.php_candidates(version), Path::is_file)
    }

    /// The php-cgi of `version`, which is already validated, like `8.3`.
    pub fn php_cgi(&self, version: &str) -> Result<PathBuf, PlatformError> {
        let path_var = env::var_os("PATH").unwrap_or_default();
        let list_dir = |dir: &Path| {
            std::fs::read_dir(dir)
                .map(|entries| entries.filter_map(Result::ok).map(|entry| entry.path()).collect())
                .unwrap_or_default()
        };
        php_cgi_places(self.os, &self.runtime_dir, version, env::split_paths(&path_var), Path::is_file, list_dir)
            .into_iter()
            .find(|path| path.is_file())
            .ok_or_else(|| PlatformError::NotFound {
                what: format!("php-cgi for PHP {version} is not installed"),
            })
    }

    /// Every place an installed Python `version` can be, best first.
    pub fn python_candidates(&self, version: &str) -> Vec<PathBuf> {
        python_places(self.os, &self.runtime_dir, version)
    }

    /// Where drust installs Python versions the system does not ship.
    pub fn managed_python_dir(&self) -> PathBuf {
        match self.os {
            Os::Linux => PathBuf::from("/usr/local/dpanel-python"),
            Os::Macos | Os::Windows => self.runtime_dir.join("python"),
        }
    }

    /// A domain with no document root of its own. `domain` is appended, like
    /// an owner in `site_home`, so that it can never replace the base.
    pub fn default_document_root(&self, domain: &str) -> PathBuf {
        match self.os {
            Os::Linux => PathBuf::from(format!("/var/www/{domain}/public")),
            Os::Macos | Os::Windows => self.site_home(domain).join("public"),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn paths(os: Os, profile: Profile) -> Paths {
        Paths::for_system(os, profile, |name| {
            if name == "DPANEL_PANEL_ROOT" {
                return None;
            }
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

    #[test]
    fn php_cgi_comes_from_dpanel_then_the_newest_patch_of_the_stack_on_path() {
        let cgi = |dir: &str| Path::new(dir).join("php-cgi.exe");
        let places = php_cgi_places(
            Os::Windows,
            Path::new("D:/rt"),
            "8.3",
            ["D:/none", "D:/wamp/php8.2.1"].map(PathBuf::from).into_iter(),
            |path| path == Path::new("D:/wamp/php8.2.1").join("php.exe"),
            |_| ["D:/wamp/php8.3.9", "D:/wamp/php8.3.28", "D:/wamp/php8.2.1", "D:/wamp/php8.30.0"].map(PathBuf::from).to_vec(),
        );
        assert_eq!(
            places,
            [Path::new("D:/rt").join("php").join("8.3").join("php-cgi.exe"), cgi("D:/wamp/php8.3.28"), cgi("D:/wamp/php8.3.9")]
        );
        let none = php_cgi_places(Os::Linux, Path::new("/rt"), "8.3", std::iter::empty(), |_| true, |_| Vec::new());
        assert!(none.is_empty());
    }

    #[test]
    fn plain_path_drops_only_a_drive_paths_verbatim_prefix() {
        assert_eq!(plain_path(Path::new(r"\\?\C:\site\index.php")), Path::new(r"C:\site\index.php"));
        assert_eq!(plain_path(Path::new(r"\\?\UNC\host\share")), Path::new(r"\\?\UNC\host\share"));
        assert_eq!(plain_path(Path::new("/home/a/index.php")), Path::new("/home/a/index.php"));
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

    fn php(os: Os, version: Option<&str>) -> Vec<PathBuf> {
        let dirs = [PathBuf::from("/first"), PathBuf::from("/second")];
        php_places(os, Path::new("/runtime"), version, dirs.into_iter())
    }

    /// Linux callers relied on these exact paths before they moved here.
    #[test]
    fn linux_keeps_its_php_and_python_paths() {
        assert_eq!(
            php(Os::Linux, Some("8.3")),
            [Path::new("/usr/bin/php8.3"), Path::new("/usr/local/bin/php8.3")]
        );
        assert_eq!(
            php(Os::Linux, None),
            ["/usr/bin/php", "/usr/local/bin/php", "/usr/bin/php8.3", "/usr/bin/php8.2"]
                .map(Path::new)
        );
        assert_eq!(
            python_places(Os::Linux, Path::new("/runtime"), "3.12"),
            [
                "/usr/local/pyenv/versions/3.12/bin/python3",
                "/usr/bin/python3.12",
                "/usr/local/bin/python3.12",
            ]
            .map(Path::new)
        );
        let linux = paths(Os::Linux, Profile::Server);
        assert_eq!(linux.managed_python_dir(), Path::new("/usr/local/dpanel-python"));
        assert_eq!(
            linux.default_document_root("example.com"),
            Path::new("/var/www/example.com/public")
        );
    }

    #[test]
    fn linux_never_runs_a_php_other_than_its_first_place() {
        let chosen = chosen_php(Os::Linux, php(Os::Linux, None), |path| path == Path::new("/usr/local/bin/php"));
        assert_eq!(chosen, Path::new("/usr/bin/php"));
    }

    #[test]
    fn windows_finds_php_in_the_runtime_folder_or_on_path() {
        assert_eq!(
            php(Os::Windows, Some("8.3")),
            [Path::new("/runtime").join("php").join("8.3").join("php.exe")]
        );
        let places = php(Os::Windows, None);
        assert_eq!(places[0], Path::new("/runtime").join("php").join("php.exe"));
        let on_path = Path::new("/second").join("php.exe");
        assert_eq!(chosen_php(Os::Windows, places.clone(), |path| path == on_path), on_path);
        assert_eq!(chosen_php(Os::Windows, places.clone(), |_| false), places[0]);
    }

    #[test]
    fn macos_looks_in_homebrew_for_versions() {
        assert_eq!(
            php(Os::Macos, Some("8.3"))[0],
            Path::new("/opt/homebrew/opt/php@8.3/bin/php")
        );
        assert_eq!(
            python_places(Os::Macos, Path::new("/runtime"), "3.12")[0],
            Path::new("/opt/homebrew/bin/python3.12")
        );
    }

    #[test]
    fn uv_is_found_like_every_other_tool() {
        assert_eq!(
            found(Os::Linux, Tool::Uv, &["/root/.local/bin/uv", "/usr/bin/uv"]),
            Some("/usr/bin/uv".into())
        );
        assert_eq!(found(Os::Linux, Tool::Uv, &["/first/uv.exe"]), None);
        assert_eq!(found(Os::Windows, Tool::Uv, &["/first/uv.exe"]), Some("/first/uv.exe".into()));
    }

    #[test]
    fn a_domains_default_root_cannot_replace_the_sites_root() {
        let windows = paths(Os::Windows, Profile::Local);
        let root = windows.default_document_root(r"C:\Windows");
        assert!(root.to_string_lossy().starts_with(r"D:\Users\me\dPanel\sites"));
        assert!(root.ends_with("public"));
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
