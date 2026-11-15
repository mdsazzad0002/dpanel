//! Which shell the web terminal runs (F5). Linux servers run bash in the
//! bubblewrap sandbox as the site owner (`edge_gateway/terminal_ws.rs`). On
//! a PC (local profile) the developer owns every site and is already logged
//! in, so the terminal is a plain shell as that user, as the plan's local
//! table says: PowerShell through ConPTY on Windows, zsh on macOS. A server
//! that is not Linux has no way yet to run it as the site's own account, so
//! it gets no terminal at all; one running as SYSTEM would hand customers
//! the machine.

use std::{
    ffi::OsString,
    path::{Path, PathBuf},
};

use super::{Os, PlatformError, Profile, capabilities::Capability, paths::Paths};

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum Shell {
    /// bash in bubblewrap, as the site owner (Linux).
    Sandboxed,
    /// A plain interactive shell as the logged-in user.
    Direct(DirectShell),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct DirectShell {
    pub program: PathBuf,
    pub args: Vec<String>,
    /// The whole environment: the runner's allowlist, then these.
    pub env: Vec<(String, OsString)>,
}

/// The shell for a site whose home is `home`, with `php` meaning the CLI of
/// `php_version` when that version is installed.
pub fn shell(home: &Path, php_version: Option<&str>) -> Result<Shell, PlatformError> {
    let platform = super::current();
    let path_var = std::env::var_os("PATH").unwrap_or_default();
    shell_for(platform.os(), platform.profile(), platform.paths(), home, php_version, &path_var, &|path| path.is_file())
}

pub(super) fn shell_for(
    os: Os,
    profile: Profile,
    paths: &Paths,
    home: &Path,
    php_version: Option<&str>,
    path_var: &OsString,
    is_file: &dyn Fn(&Path) -> bool,
) -> Result<Shell, PlatformError> {
    match (os, profile) {
        (Os::Linux, _) => Ok(Shell::Sandboxed),
        (_, Profile::Server) => Err(PlatformError::Unsupported { capability: Capability::Terminal }),
        (Os::Windows, Profile::Local) => {
            // PowerShell 7 when it is there, else the Windows PowerShell every
            // Windows has.
            let pwsh = super::paths::on_windows_path("pwsh", std::env::split_paths(path_var), is_file);
            let program = pwsh.unwrap_or_else(|| {
                let root = std::env::var_os("SystemRoot").unwrap_or_else(|| r"C:\Windows".into());
                PathBuf::from(root).join(r"System32\WindowsPowerShell\v1.0\powershell.exe")
            });
            Ok(Shell::Direct(DirectShell {
                program,
                args: ["-NoLogo", "-NoProfile", "-NoExit"].map(String::from).to_vec(),
                env: environment(paths, home, php_version, path_var, is_file, ';', "USERPROFILE"),
            }))
        }
        (Os::Macos, Profile::Local) => Ok(Shell::Direct(DirectShell {
            program: "/bin/zsh".into(),
            args: vec!["-i".into()],
            env: environment(paths, home, php_version, path_var, is_file, ':', "HOME"),
        })),
    }
}

/// `HOME`-like variable at the site, `TERM`, and `PATH` with the site's PHP
/// first. drust's own variables, such as `DRUST_API_TOKEN`, never reach the
/// shell: the caller starts from the runner's allowlist.
fn environment(
    paths: &Paths,
    home: &Path,
    php_version: Option<&str>,
    path_var: &OsString,
    is_file: &dyn Fn(&Path) -> bool,
    separator: char,
    home_var: &str,
) -> Vec<(String, OsString)> {
    let php_dir = php_version
        .map(|version| paths.php_cli(Some(version)))
        .filter(|php| is_file(php))
        .and_then(|php| php.parent().map(Path::to_path_buf));
    let mut path = OsString::new();
    if let Some(dir) = php_dir {
        path.push(dir);
        path.push(separator.to_string());
    }
    path.push(path_var);
    vec![
        (home_var.to_string(), home.as_os_str().to_owned()),
        ("TERM".into(), "xterm-256color".into()),
        ("PATH".into(), path),
    ]
}

/// The allowlisted part of drust's environment, for a shell that starts
/// from nothing.
pub fn allowed_environment() -> Vec<(String, OsString)> {
    super::runner::inherited(|name| std::env::var_os(name))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn paths(os: Os) -> Paths {
        Paths::for_system(os, Profile::Local, |name| match name {
            "LOCALAPPDATA" => Some(r"C:\Users\a\AppData\Local".into()),
            "USERPROFILE" => Some(r"C:\Users\a".into()),
            "HOME" => Some("/Users/a".into()),
            _ => None,
        })
    }

    fn env(shell: &Shell, name: &str) -> OsString {
        let Shell::Direct(direct) = shell else { panic!("not a direct shell") };
        direct.env.iter().find(|(key, _)| key == name).map(|(_, value)| value.clone()).unwrap()
    }

    #[test]
    fn linux_keeps_its_sandbox_and_other_servers_get_no_terminal() {
        let none = |_: &Path| false;
        let home = Path::new("/home/shop");
        for profile in [Profile::Server, Profile::Local] {
            let shell = shell_for(Os::Linux, profile, &paths(Os::Linux), home, None, &"".into(), &none);
            assert_eq!(shell.unwrap(), Shell::Sandboxed);
        }
        for os in [Os::Windows, Os::Macos] {
            let shell = shell_for(os, Profile::Server, &paths(os), home, None, &"".into(), &none);
            assert!(matches!(shell, Err(PlatformError::Unsupported { capability: Capability::Terminal })));
        }
    }

    #[test]
    fn windows_runs_powershell_in_the_site_with_its_php_first() {
        let paths = paths(Os::Windows);
        let php = paths.php_cli(Some("8.3"));
        let pwsh = Path::new(r"C:\Program Files\PowerShell\7").join("pwsh.exe");
        let home = Path::new(r"C:\Users\a\dPanel\sites\shop");
        let path_var = OsString::from(r"C:\Program Files\PowerShell\7;C:\Windows\System32");

        let shell = shell_for(Os::Windows, Profile::Local, &paths, home, Some("8.3"), &path_var, &|path| path == php || path == pwsh)
            .unwrap();

        let Shell::Direct(direct) = &shell else { panic!("not a direct shell") };
        assert_eq!(direct.program, pwsh);
        assert!(direct.args.contains(&"-NoProfile".to_string()));
        assert_eq!(env(&shell, "USERPROFILE"), home.as_os_str());
        let mut expected = php.parent().unwrap().as_os_str().to_owned();
        expected.push(";");
        expected.push(&path_var);
        assert_eq!(env(&shell, "PATH"), expected);
    }

    #[test]
    fn windows_falls_back_to_windows_powershell_and_the_plain_path() {
        let home = Path::new(r"C:\sites\shop");
        let path_var = OsString::from(r"C:\Windows\System32");
        let shell = shell_for(Os::Windows, Profile::Local, &paths(Os::Windows), home, Some("8.3"), &path_var, &|_| false)
            .unwrap();
        let Shell::Direct(direct) = &shell else { panic!("not a direct shell") };
        assert!(direct.program.to_string_lossy().ends_with(r"WindowsPowerShell\v1.0\powershell.exe"));
        // PHP 8.3 is not installed, so PATH is left alone.
        assert_eq!(env(&shell, "PATH"), path_var);
    }
}
