//! System accounts that own websites and logins. `plans/platform-spec.md`
//! section 6.4.

use super::{PlatformError, capabilities::Capability, fs::OwnerId};
use std::{
    fs,
    io::Write,
    path::{Path, PathBuf},
    process::{Command, Stdio},
};

pub const NO_LOGIN_SHELL: &str = "/usr/sbin/nologin";

/// Which group a new account belongs to.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AccountGroup<'a> {
    /// Whatever the OS default is.
    Default,
    /// A new group named after the account.
    Own,
    /// The group named after the account, created first when missing, with
    /// this gid if one is given.
    Recreate { gid: Option<u32> },
    /// A group that already exists.
    Existing(&'a str),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AccountSpec<'a> {
    pub name: &'a str,
    pub shell: &'a str,
    pub home: Option<&'a Path>,
    pub create_home: bool,
    pub uid: Option<u32>,
    /// Lets `uid` belong to another account too, as FTP logins share the site's.
    pub share_uid: bool,
    pub group: AccountGroup<'a>,
}

impl<'a> AccountSpec<'a> {
    /// A website owner with its own group.
    pub fn site(name: &'a str, home: &'a Path, shell: &'a str) -> Self {
        Self {
            name,
            shell,
            home: Some(home),
            create_home: true,
            uid: None,
            share_uid: false,
            group: AccountGroup::Own,
        }
    }

    /// A person who logs in to the server.
    pub fn login(name: &'a str, shell: &'a str) -> Self {
        Self {
            name,
            shell,
            home: None,
            create_home: true,
            uid: None,
            share_uid: false,
            group: AccountGroup::Default,
        }
    }

    /// A website owner brought back from a backup or another server.
    pub fn restored(name: &'a str, uid: Option<u32>, gid: Option<u32>) -> Self {
        Self {
            name,
            shell: NO_LOGIN_SHELL,
            home: None,
            create_home: true,
            uid,
            share_uid: false,
            group: AccountGroup::Recreate { gid },
        }
    }

    /// A second login for an existing owner's files, such as FTP.
    pub fn sharing(name: &'a str, home: &'a Path, uid: u32, group: &'a str) -> Self {
        Self {
            name,
            shell: NO_LOGIN_SHELL,
            home: Some(home),
            create_home: false,
            uid: Some(uid),
            share_uid: true,
            group: AccountGroup::Existing(group),
        }
    }
}

/// The account lists a Unix system keeps as text.
const NO_LOGIN_SHELLS: &[&str] = &[
    "/usr/sbin/nologin",
    "/sbin/nologin",
    "/bin/false",
    "/usr/bin/false",
    "/bin/sync",
    "/sbin/halt",
    "/sbin/shutdown",
];

/// Whether a shell from the account list lets its user log in.
pub fn can_login_shell(shell: &str) -> bool {
    !shell.is_empty() && !NO_LOGIN_SHELLS.contains(&shell)
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum AccountList {
    Passwd,
    Shadow,
    Group,
}

pub trait AccountManager: Send + Sync + std::fmt::Debug {
    fn create(&self, spec: &AccountSpec) -> Result<OwnerId, PlatformError>;
    /// `keep_files` leaves the home folder in place.
    fn delete(&self, name: &str, keep_files: bool) -> Result<(), PlatformError>;
    fn exists(&self, name: &str) -> Result<bool, PlatformError>;
    fn lookup(&self, name: &str) -> Result<OwnerId, PlatformError>;
    fn set_password(&self, name: &str, secret: &str) -> Result<(), PlatformError>;
    fn lock(&self, name: &str, locked: bool) -> Result<(), PlatformError>;
    fn set_shell(&self, name: &str, shell: &str) -> Result<(), PlatformError>;
    fn set_comment(&self, name: &str, comment: &str) -> Result<(), PlatformError>;
    /// Does nothing when the group does not exist on this system.
    fn add_to_group(&self, name: &str, group: &str) -> Result<(), PlatformError>;
    fn primary_group(&self, name: &str) -> Result<String, PlatformError>;
    fn home(&self, name: &str) -> Result<PathBuf, PlatformError>;
    /// `None` when the account has no login shell to report.
    fn shell(&self, name: &str) -> Option<String>;
    /// `None` when the OS keeps no hash that can be moved to another server.
    fn password_hash(&self, name: &str) -> Option<String>;
    /// The raw list, in `/etc/passwd` format. Empty where accounts are not users.
    fn list(&self, list: AccountList) -> Result<String, PlatformError>;
}

fn failed(action: &str, detail: impl Into<String>) -> PlatformError {
    PlatformError::Failed {
        action: action.to_string(),
        detail: detail.into(),
    }
}

/// `groupadd` arguments for an account, or `None` if it needs no new group.
fn group_args(spec: &AccountSpec) -> Option<Vec<String>> {
    let AccountGroup::Recreate { gid } = spec.group else {
        return None;
    };
    let mut args = vec!["--force".to_string()];
    if let Some(gid) = gid {
        args.extend(["--gid".to_string(), gid.to_string()]);
    }
    args.push(spec.name.to_string());
    Some(args)
}

fn user_args(spec: &AccountSpec) -> Vec<String> {
    let mut args = Vec::new();
    if spec.share_uid {
        args.push("--non-unique".to_string());
    }
    if let Some(uid) = spec.uid {
        args.extend(["--uid".to_string(), uid.to_string()]);
    }
    args.push(if spec.create_home { "--create-home" } else { "--no-create-home" }.to_string());
    if let Some(home) = spec.home {
        args.extend(["--home-dir".to_string(), home.to_string_lossy().into_owned()]);
    }
    args.extend(["--shell".to_string(), spec.shell.to_string()]);
    match spec.group {
        AccountGroup::Default => {}
        AccountGroup::Own => args.push("--user-group".to_string()),
        AccountGroup::Recreate { .. } => args.extend(["--gid".to_string(), spec.name.to_string()]),
        AccountGroup::Existing(group) => args.extend(["--gid".to_string(), group.to_string()]),
    }
    args.push(spec.name.to_string());
    args
}

fn delete_args(name: &str, keep_files: bool) -> Vec<&str> {
    // Website PHP workers may still be winding down when the panel removes a
    // site. Force account removal so that a short-lived worker cannot leave
    // the website stuck in a half-deleted state.
    if keep_files {
        vec!["--force", name]
    } else {
        vec!["--force", "--remove", name]
    }
}

fn run(program: &str, args: &[&str]) -> Result<String, PlatformError> {
    let output = Command::new(program)
        .args(args)
        .output()
        .map_err(|error| failed(program, format!("failed to run {program}: {error}")))?;
    if output.status.success() {
        return Ok(String::from_utf8_lossy(&output.stdout).trim().to_string());
    }
    let error = String::from_utf8_lossy(&output.stderr).trim().to_string();
    Err(failed(
        program,
        if error.is_empty() { format!("{program} exited unsuccessfully") } else { error },
    ))
}

fn run_owned(program: &str, args: &[String]) -> Result<String, PlatformError> {
    run(program, &args.iter().map(String::as_str).collect::<Vec<_>>())
}

fn passwd_field(name: &str, index: usize) -> Result<String, PlatformError> {
    run("getent", &["passwd", name])?
        .split(':')
        .nth(index)
        .map(str::to_string)
        .ok_or_else(|| PlatformError::NotFound {
            what: format!("Unable to read the account {name}"),
        })
}

/// `useradd` and the tools around it.
#[derive(Debug)]
pub struct LinuxAccounts;

impl AccountManager for LinuxAccounts {
    fn create(&self, spec: &AccountSpec) -> Result<OwnerId, PlatformError> {
        if let Some(args) = group_args(spec) {
            run_owned("groupadd", &args)?;
        }
        run_owned("useradd", &user_args(spec))?;
        self.lookup(spec.name)
    }

    fn delete(&self, name: &str, keep_files: bool) -> Result<(), PlatformError> {
        run("userdel", &delete_args(name, keep_files)).map(|_| ())
    }

    fn exists(&self, name: &str) -> Result<bool, PlatformError> {
        Command::new("id")
            .args(["-u", name])
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .map(|status| status.success())
            .map_err(|error| failed("id", format!("failed to run id: {error}")))
    }

    fn lookup(&self, name: &str) -> Result<OwnerId, PlatformError> {
        let number = |flag: &str| -> Result<u32, PlatformError> {
            run("id", &[flag, name])?.parse().map_err(|_| PlatformError::NotFound {
                what: format!("Unable to read the account {name}"),
            })
        };
        Ok(OwnerId { uid: number("-u")?, gid: number("-g")? })
    }

    fn set_password(&self, name: &str, secret: &str) -> Result<(), PlatformError> {
        // chpasswd reads one account per line, so a new line would set another's.
        if secret.contains(['\n', '\r']) {
            return Err(failed("chpasswd", "Password cannot contain new lines."));
        }
        let mut child = Command::new("chpasswd")
            .stdin(Stdio::piped())
            .stdout(Stdio::null())
            .stderr(Stdio::piped())
            .spawn()
            .map_err(|error| failed("chpasswd", format!("failed to start chpasswd: {error}")))?;
        child
            .stdin
            .take()
            .ok_or_else(|| failed("chpasswd", "chpasswd stdin unavailable"))?
            .write_all(format!("{name}:{secret}\n").as_bytes())
            .map_err(|error| failed("chpasswd", format!("failed to write password: {error}")))?;
        let output = child
            .wait_with_output()
            .map_err(|error| failed("chpasswd", format!("failed waiting for chpasswd: {error}")))?;
        if output.status.success() {
            return Ok(());
        }
        Err(failed("chpasswd", String::from_utf8_lossy(&output.stderr).trim()))
    }

    fn lock(&self, name: &str, locked: bool) -> Result<(), PlatformError> {
        run("usermod", &[if locked { "--lock" } else { "--unlock" }, name]).map(|_| ())
    }

    fn set_shell(&self, name: &str, shell: &str) -> Result<(), PlatformError> {
        run("usermod", &["--shell", shell, name]).map(|_| ())
    }

    fn set_comment(&self, name: &str, comment: &str) -> Result<(), PlatformError> {
        run("usermod", &["--comment", comment, name]).map(|_| ())
    }

    fn add_to_group(&self, name: &str, group: &str) -> Result<(), PlatformError> {
        if run("getent", &["group", group]).is_err() {
            return Ok(());
        }
        run("usermod", &["--append", "--groups", group, name]).map(|_| ())
    }

    fn primary_group(&self, name: &str) -> Result<String, PlatformError> {
        run("id", &["-gn", name])
    }

    fn home(&self, name: &str) -> Result<PathBuf, PlatformError> {
        passwd_field(name, 5).map(PathBuf::from)
    }

    fn shell(&self, name: &str) -> Option<String> {
        passwd_field(name, 6).ok()
    }

    fn password_hash(&self, name: &str) -> Option<String> {
        run("getent", &["shadow", name])
            .ok()?
            .split(':')
            .nth(1)
            .map(str::to_string)
    }

    fn list(&self, list: AccountList) -> Result<String, PlatformError> {
        let path = match list {
            AccountList::Passwd => "/etc/passwd",
            AccountList::Shadow => "/etc/shadow",
            AccountList::Group => "/etc/group",
        };
        fs::read_to_string(path).map_err(|error| PlatformError::from_io("read account list", error))
    }
}

/// A developer's own PC: every site runs as the logged-in user, so an account
/// is only a folder under the sites root.
#[derive(Debug)]
pub struct LocalAccounts {
    pub sites_root: PathBuf,
}

impl LocalAccounts {
    /// Deleting an account deletes this folder, so a name that leaves the
    /// sites root must never reach it.
    fn folder(&self, name: &str) -> Result<PathBuf, PlatformError> {
        let safe = !name.is_empty()
            && name != "."
            && name != ".."
            && !name.contains(['/', '\\', ':'])
            && !name.ends_with(['.', ' ']);
        if safe {
            Ok(self.sites_root.join(name))
        } else {
            Err(failed("account folder", format!("Invalid account name: {name}")))
        }
    }
}

impl AccountManager for LocalAccounts {
    fn create(&self, spec: &AccountSpec) -> Result<OwnerId, PlatformError> {
        let folder = self.folder(spec.name)?;
        let home = spec.home.unwrap_or(&folder);
        fs::create_dir_all(home).map_err(|error| PlatformError::from_io("create account", error))?;
        Ok(OwnerId::LOGGED_IN_USER)
    }

    fn delete(&self, name: &str, keep_files: bool) -> Result<(), PlatformError> {
        let folder = self.folder(name)?;
        if keep_files || !folder.exists() {
            return Ok(());
        }
        fs::remove_dir_all(folder).map_err(|error| PlatformError::from_io("delete account", error))
    }

    fn exists(&self, name: &str) -> Result<bool, PlatformError> {
        Ok(self.folder(name)?.is_dir())
    }

    fn lookup(&self, name: &str) -> Result<OwnerId, PlatformError> {
        if self.exists(name)? {
            return Ok(OwnerId::LOGGED_IN_USER);
        }
        Err(PlatformError::NotFound {
            what: format!("User does not exist: {name}"),
        })
    }

    fn set_password(&self, _name: &str, _secret: &str) -> Result<(), PlatformError> {
        Ok(())
    }

    fn lock(&self, _name: &str, _locked: bool) -> Result<(), PlatformError> {
        Ok(())
    }

    fn set_shell(&self, _name: &str, _shell: &str) -> Result<(), PlatformError> {
        Ok(())
    }

    fn set_comment(&self, _name: &str, _comment: &str) -> Result<(), PlatformError> {
        Ok(())
    }

    fn add_to_group(&self, _name: &str, _group: &str) -> Result<(), PlatformError> {
        Ok(())
    }

    fn primary_group(&self, name: &str) -> Result<String, PlatformError> {
        Ok(name.to_string())
    }

    fn home(&self, name: &str) -> Result<PathBuf, PlatformError> {
        self.folder(name)
    }

    fn shell(&self, _name: &str) -> Option<String> {
        None
    }

    fn password_hash(&self, _name: &str) -> Option<String> {
        None
    }

    fn list(&self, _list: AccountList) -> Result<String, PlatformError> {
        Ok(String::new())
    }
}

/// Server profiles that have no account backend yet (tasks J and K). Refuses
/// rather than leave every site running as one user.
#[derive(Debug)]
pub struct NoAccounts;

fn unsupported<T>() -> Result<T, PlatformError> {
    Err(PlatformError::Unsupported {
        capability: Capability::Isolation,
    })
}

impl AccountManager for NoAccounts {
    fn create(&self, _spec: &AccountSpec) -> Result<OwnerId, PlatformError> {
        unsupported()
    }

    fn delete(&self, _name: &str, _keep_files: bool) -> Result<(), PlatformError> {
        unsupported()
    }

    fn exists(&self, _name: &str) -> Result<bool, PlatformError> {
        unsupported()
    }

    fn lookup(&self, _name: &str) -> Result<OwnerId, PlatformError> {
        unsupported()
    }

    fn set_password(&self, _name: &str, _secret: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn lock(&self, _name: &str, _locked: bool) -> Result<(), PlatformError> {
        unsupported()
    }

    fn set_shell(&self, _name: &str, _shell: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn set_comment(&self, _name: &str, _comment: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn add_to_group(&self, _name: &str, _group: &str) -> Result<(), PlatformError> {
        unsupported()
    }

    fn primary_group(&self, _name: &str) -> Result<String, PlatformError> {
        unsupported()
    }

    fn home(&self, _name: &str) -> Result<PathBuf, PlatformError> {
        unsupported()
    }

    fn shell(&self, _name: &str) -> Option<String> {
        None
    }

    fn password_hash(&self, _name: &str) -> Option<String> {
        None
    }

    fn list(&self, _list: AccountList) -> Result<String, PlatformError> {
        unsupported()
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn args(spec: &AccountSpec) -> String {
        user_args(spec).join(" ")
    }

    #[test]
    fn a_site_owner_gets_its_own_group_and_home() {
        let home = Path::new("/home/shop");
        assert_eq!(
            args(&AccountSpec::site("shop", home, "/bin/bash")),
            "--create-home --home-dir /home/shop --shell /bin/bash --user-group shop"
        );
    }

    #[test]
    fn a_login_uses_the_system_defaults() {
        assert_eq!(
            args(&AccountSpec::login("ada", "/bin/bash")),
            "--create-home --shell /bin/bash ada"
        );
        assert_eq!(group_args(&AccountSpec::login("ada", "/bin/bash")), None);
    }

    #[test]
    fn a_restored_owner_keeps_its_archived_ids() {
        let spec = AccountSpec::restored("shop", Some(1005), Some(1006));
        assert_eq!(
            args(&spec),
            "--uid 1005 --create-home --shell /usr/sbin/nologin --gid shop shop"
        );
        assert_eq!(group_args(&spec).unwrap().join(" "), "--force --gid 1006 shop");
    }

    #[test]
    fn a_restored_owner_without_ids_lets_the_system_choose() {
        let spec = AccountSpec::restored("shop", None, None);
        assert_eq!(args(&spec), "--create-home --shell /usr/sbin/nologin --gid shop shop");
        assert_eq!(group_args(&spec).unwrap().join(" "), "--force shop");
    }

    #[test]
    fn an_ftp_login_shares_the_owners_uid_and_makes_no_home() {
        let spec = AccountSpec::sharing("shop_ftp", Path::new("/home/shop/up"), 1005, "shop");
        assert_eq!(
            args(&spec),
            "--non-unique --uid 1005 --no-create-home --home-dir /home/shop/up \
             --shell /usr/sbin/nologin --gid shop shop_ftp"
        );
    }

    #[test]
    fn delete_removes_the_home_unless_asked_to_keep_it() {
        assert_eq!(delete_args("shop", false), ["--force", "--remove", "shop"]);
        assert_eq!(delete_args("shop", true), ["--force", "shop"]);
    }

    fn local(root: &Path) -> LocalAccounts {
        LocalAccounts { sites_root: root.to_path_buf() }
    }

    #[test]
    fn a_local_account_is_a_folder() {
        let dir = tempfile::tempdir().unwrap();
        let accounts = local(dir.path());
        let home = dir.path().join("shop");

        assert!(!accounts.exists("shop").unwrap());
        assert!(accounts.lookup("shop").is_err());
        accounts.create(&AccountSpec::site("shop", &home, NO_LOGIN_SHELL)).unwrap();
        assert!(home.is_dir());
        assert!(accounts.exists("shop").unwrap());
        assert_eq!(accounts.lookup("shop").unwrap(), OwnerId::LOGGED_IN_USER);
        assert_eq!(accounts.home("shop").unwrap(), home);
        assert_eq!(accounts.primary_group("shop").unwrap(), "shop");
    }

    #[test]
    fn deleting_a_local_account_can_keep_its_files() {
        let dir = tempfile::tempdir().unwrap();
        let accounts = local(dir.path());
        let home = dir.path().join("shop");
        accounts.create(&AccountSpec::site("shop", &home, NO_LOGIN_SHELL)).unwrap();

        accounts.delete("shop", true).unwrap();
        assert!(home.is_dir());
        accounts.delete("shop", false).unwrap();
        assert!(!home.exists());
        accounts.delete("shop", false).unwrap();
    }

    #[test]
    fn a_local_account_name_cannot_leave_the_sites_root() {
        let dir = tempfile::tempdir().unwrap();
        let accounts = local(dir.path());
        for name in ["", ".", "..", "../x", r"..\x", "a/b", r"C:\x", "c:x", "x.", "x "] {
            assert!(accounts.delete(name, false).is_err(), "{name:?}");
            assert!(accounts.exists(name).is_err(), "{name:?}");
        }
    }

    /// What task C4 moved here. A line of code that names one of these outside
    /// the platform layer bypasses the backend that the OS and profile chose.
    fn names_an_account_tool(line: &str) -> bool {
        const TOOLS: [&str; 10] = [
            "\"useradd\"",
            "\"userdel\"",
            "\"usermod\"",
            "\"groupadd\"",
            "\"chpasswd\"",
            "\"getent\"",
            "Command::new(\"id\")",
            "read_to_string(\"/etc/passwd\")",
            "read_to_string(\"/etc/shadow\")",
            "read_to_string(\"/etc/group\")",
        ];
        TOOLS.iter().any(|tool| line.contains(tool))
    }

    fn rust_files(dir: &Path, found: &mut Vec<PathBuf>) {
        for entry in fs::read_dir(dir).unwrap() {
            let path = entry.unwrap().path();
            if path.is_dir() {
                rust_files(&path, found);
            } else if path.extension().is_some_and(|extension| extension == "rs") {
                found.push(path);
            }
        }
    }

    #[test]
    fn the_guard_below_sees_a_direct_call() {
        assert!(names_an_account_tool(r#"let output = Command::new("id").arg("-u");"#));
        assert!(names_an_account_tool(r#"run_status("useradd", &args)?;"#));
        assert!(!names_an_account_tool("let id = account.uid;"));
    }

    #[test]
    fn nothing_outside_the_platform_layer_runs_an_account_tool() {
        let source = Path::new(env!("CARGO_MANIFEST_DIR")).join("src");
        let platform = source.join("platform");
        let mut files = Vec::new();
        rust_files(&source, &mut files);
        assert!(files.len() > 50, "only {} source files found", files.len());

        let offenders: Vec<String> = files
            .iter()
            .filter(|file| !file.starts_with(&platform))
            .flat_map(|file| {
                let text = fs::read_to_string(file).unwrap();
                text.lines()
                    .filter(|line| names_an_account_tool(line))
                    .map(|line| format!("{}: {}", file.display(), line.trim()))
                    .collect::<Vec<_>>()
            })
            .collect();
        assert!(offenders.is_empty(), "{offenders:#?}");
    }

    #[test]
    fn a_server_without_a_backend_refuses() {
        assert!(matches!(
            NoAccounts.exists("shop"),
            Err(PlatformError::Unsupported { capability: Capability::Isolation })
        ));
    }
}
