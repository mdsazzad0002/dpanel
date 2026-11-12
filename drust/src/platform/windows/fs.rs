use crate::platform::{
    PlatformError, Profile,
    capabilities::Capability,
    current,
    fs::{FileAccess, FileMode, LinkPolicy, OwnerId},
};
use std::{
    ffi::c_void,
    fs::{self, File, Metadata},
    io,
    iter::once,
    mem::size_of,
    os::windows::{
        ffi::OsStrExt,
        fs::{FileTypeExt, symlink_dir, symlink_file},
        io::FromRawHandle,
    },
    path::Path,
    ptr,
};
use windows_sys::Win32::{
    Foundation::{
        ERROR_ALREADY_EXISTS, GENERIC_WRITE, GetLastError, INVALID_HANDLE_VALUE, LocalFree,
    },
    Security::{
        ACL, Authorization::{
            ConvertStringSecurityDescriptorToSecurityDescriptorW, SDDL_REVISION_1, SE_FILE_OBJECT,
            SetNamedSecurityInfoW,
        },
        DACL_SECURITY_INFORMATION, GetSecurityDescriptorDacl, PROTECTED_DACL_SECURITY_INFORMATION,
        PSECURITY_DESCRIPTOR, SECURITY_ATTRIBUTES,
    },
    Storage::FileSystem::{
        CREATE_NEW, CreateDirectoryW, CreateFileW, FILE_ATTRIBUTE_NORMAL,
        FILE_FLAG_OPEN_REPARSE_POINT, FILE_READ_ATTRIBUTES, FILE_SHARE_DELETE, FILE_SHARE_READ,
        FILE_SHARE_WRITE, OPEN_ALWAYS,
    },
};

/// ACLs written as SDDL, each protected from its folder's ACL. `OW` is the
/// file's owner, `SY` is SYSTEM and `BU` is the local Users group, which stands
/// in for the site group until per-site users exist (task J).
const PRIVATE_FILE: &str = "D:P(A;;FA;;;OW)(A;;FA;;;SY)";
const PRIVATE_DIR: &str = "D:P(A;OICI;FA;;;OW)(A;OICI;FA;;;SY)";
const SITE_DIR: &str = "D:P(A;OICI;FA;;;OW)(A;OICI;FA;;;SY)(A;OICI;FRFX;;;BU)";
const SITE_FILE: &str = "D:P(A;;FA;;;OW)(A;;FA;;;SY)(A;;FR;;;BU)";
const SHARED_FILE: &str = "D:P(A;;FA;;;OW)(A;;FA;;;SY)(A;;FR;;;BU)";
const EXECUTABLE_FILE: &str = "D:P(A;;FA;;;OW)(A;;FA;;;SY)(A;;FRFX;;;BU)";

pub struct NativeFileAccess;

/// A folder always gets an ACL its contents inherit: Windows rewrites what
/// inherited from it, and a file left with no entries locks out even its owner.
fn sddl_for(mode: FileMode, is_dir: bool) -> Option<&'static str> {
    match mode {
        FileMode::Private if is_dir => Some(PRIVATE_DIR),
        FileMode::SiteFile | FileMode::Shared | FileMode::Executable if is_dir => Some(SITE_DIR),
        FileMode::Private => Some(PRIVATE_FILE),
        FileMode::PrivateDir => Some(PRIVATE_DIR),
        FileMode::SiteDir => Some(SITE_DIR),
        FileMode::SiteFile => Some(SITE_FILE),
        FileMode::Shared => Some(SHARED_FILE),
        FileMode::Executable => Some(EXECUTABLE_FILE),
        FileMode::Raw(_) => None,
    }
}

fn wide(text: &std::ffi::OsStr) -> Vec<u16> {
    text.encode_wide().chain(once(0)).collect()
}

/// A path the Win32 calls below take whatever its length: made absolute
/// (which also turns `/` into `\`), then `\\?\` in front, as std does for
/// its own calls. Without it they fail past 260 characters, and `\\?\` with
/// a `/` left in fails at any length.
fn wide_path(path: &Path) -> Vec<u16> {
    const SLASH: u16 = b'\\' as u16;
    let full = std::path::absolute(path).unwrap_or_else(|_| path.to_path_buf());
    let units: Vec<u16> = full.as_os_str().encode_wide().collect();
    let verbatim = |second: u8| units.len() >= 4 && units[..2] == [SLASH, SLASH] && units[2] == u16::from(second) && units[3] == SLASH;
    let (prefix, rest) = if verbatim(b'?') || verbatim(b'.') {
        ("", &units[..])
    } else if units.starts_with(&[SLASH, SLASH]) {
        // `\\server\share\x` is `\\?\UNC\server\share\x`.
        (r"\\?\UNC", &units[1..])
    } else {
        (r"\\?\", &units[..])
    };
    prefix.encode_utf16().chain(rest.iter().copied()).chain(once(0)).collect()
}

fn failed(action: &str) -> impl Fn(io::Error) -> PlatformError {
    move |error| PlatformError::from_io(action, error)
}

/// A parsed SDDL string, freed when dropped.
struct Descriptor(PSECURITY_DESCRIPTOR);

impl Descriptor {
    fn parse(sddl: &str) -> io::Result<Self> {
        let text = wide(sddl.as_ref());
        let mut descriptor: PSECURITY_DESCRIPTOR = ptr::null_mut();
        // SAFETY: `text` is NUL-terminated and `descriptor` is a valid out pointer.
        let parsed = unsafe {
            ConvertStringSecurityDescriptorToSecurityDescriptorW(
                text.as_ptr(),
                SDDL_REVISION_1,
                &mut descriptor,
                ptr::null_mut(),
            )
        };
        if parsed == 0 {
            return Err(io::Error::last_os_error());
        }
        Ok(Self(descriptor))
    }

    /// For creating a file or folder with this ACL from its first moment, so
    /// that it never exists with the ACL of its parent.
    fn attributes(&self) -> SECURITY_ATTRIBUTES {
        SECURITY_ATTRIBUTES {
            nLength: size_of::<SECURITY_ATTRIBUTES>() as u32,
            lpSecurityDescriptor: self.0,
            bInheritHandle: 0,
        }
    }

    fn apply(&self, path: &Path) -> io::Result<()> {
        let mut present = 0;
        let mut defaulted = 0;
        let mut dacl: *mut ACL = ptr::null_mut();
        // SAFETY: `self.0` is a valid descriptor and the rest are valid out pointers.
        if unsafe { GetSecurityDescriptorDacl(self.0, &mut present, &mut dacl, &mut defaulted) } == 0 {
            return Err(io::Error::last_os_error());
        }
        let name = wide_path(path);
        // SAFETY: `name` is NUL-terminated and `dacl` lives as long as `self`.
        let status = unsafe {
            SetNamedSecurityInfoW(
                name.as_ptr(),
                SE_FILE_OBJECT,
                DACL_SECURITY_INFORMATION | PROTECTED_DACL_SECURITY_INFORMATION,
                ptr::null_mut(),
                ptr::null_mut(),
                dacl,
                ptr::null(),
            )
        };
        if status == 0 {
            Ok(())
        } else {
            Err(io::Error::from_raw_os_error(status as i32))
        }
    }
}

impl Drop for Descriptor {
    fn drop(&mut self) {
        // SAFETY: the descriptor came from the Convert call, which says to use LocalFree.
        unsafe { LocalFree(self.0 as *mut c_void) };
    }
}

fn open(path: &Path, disposition: u32, flags: u32) -> io::Result<File> {
    let descriptor = Descriptor::parse(PRIVATE_FILE)?;
    let attributes = descriptor.attributes();
    let name = wide_path(path);
    // SAFETY: `name` is NUL-terminated and `attributes` outlives the call.
    let handle = unsafe {
        CreateFileW(
            name.as_ptr(),
            GENERIC_WRITE | FILE_READ_ATTRIBUTES,
            FILE_SHARE_READ | FILE_SHARE_WRITE | FILE_SHARE_DELETE,
            &attributes,
            disposition,
            FILE_ATTRIBUTE_NORMAL | flags,
            ptr::null_mut(),
        )
    };
    if handle == INVALID_HANDLE_VALUE {
        return Err(io::Error::last_os_error());
    }
    // SAFETY: the handle is valid and nothing else owns it.
    Ok(unsafe { File::from_raw_handle(handle) })
}

fn create_private_dir(path: &Path, attributes: &SECURITY_ATTRIBUTES) -> io::Result<()> {
    if path.is_dir() {
        return Ok(());
    }
    if let Some(parent) = path.parent().filter(|parent| !parent.as_os_str().is_empty()) {
        create_private_dir(parent, attributes)?;
    }
    let name = wide_path(path);
    // SAFETY: `name` is NUL-terminated and `attributes` outlives the call.
    if unsafe { CreateDirectoryW(name.as_ptr(), attributes) } != 0 {
        return Ok(());
    }
    // SAFETY: only reads this thread's last error.
    if unsafe { GetLastError() } == ERROR_ALREADY_EXISTS && path.is_dir() {
        return Ok(());
    }
    Err(io::Error::last_os_error())
}

/// A local profile runs everything as the logged-in user, so there is no other
/// owner to ask about or give a file to. A server needs per-site users first.
fn local_only<T>(value: T) -> Result<T, PlatformError> {
    if current().profile() == Profile::Local {
        Ok(value)
    } else {
        Err(PlatformError::Unsupported {
            capability: Capability::FilePermissions,
        })
    }
}

impl FileAccess for NativeFileAccess {
    fn set_mode(&self, path: &Path, mode: FileMode) -> Result<(), PlatformError> {
        let is_dir = fs::metadata(path).is_ok_and(|metadata| metadata.is_dir());
        let sddl = sddl_for(mode, is_dir).ok_or(PlatformError::Unsupported {
            capability: Capability::FilePermissions,
        })?;
        Descriptor::parse(sddl)
            .and_then(|descriptor| descriptor.apply(path))
            .map_err(failed("set mode"))
    }

    fn mode_bits(&self, _metadata: &Metadata) -> Option<u32> {
        None
    }

    fn owner(&self, _metadata: &Metadata) -> Result<OwnerId, PlatformError> {
        local_only(OwnerId::LOGGED_IN_USER)
    }

    fn set_owner(&self, _path: &Path, _owner: &OwnerId) -> Result<(), PlatformError> {
        local_only(())
    }

    fn set_link_owner(&self, _path: &Path, _owner: &OwnerId) -> Result<(), PlatformError> {
        local_only(())
    }

    fn create_private(&self, path: &Path) -> Result<File, PlatformError> {
        open(path, CREATE_NEW, 0).map_err(failed("create private file"))
    }

    fn replace_private(&self, path: &Path, links: LinkPolicy) -> Result<File, PlatformError> {
        let flags = match links {
            LinkPolicy::Follow => 0,
            LinkPolicy::Refuse => FILE_FLAG_OPEN_REPARSE_POINT,
        };
        let action = failed("replace private file");
        let file = open(path, OPEN_ALWAYS, flags).map_err(&action)?;
        // Truncates only after the link check: CREATE_ALWAYS would empty what
        // the link points at before there is a handle to ask.
        if links == LinkPolicy::Refuse && file.metadata().map_err(&action)?.is_symlink() {
            return Err(PlatformError::PermissionDenied {
                what: format!("refusing to follow a link: {}", path.display()),
            });
        }
        file.set_len(0).map_err(&action)?;
        Ok(file)
    }

    fn create_dir_private(&self, path: &Path) -> Result<(), PlatformError> {
        Descriptor::parse(PRIVATE_DIR)
            .and_then(|descriptor| create_private_dir(path, &descriptor.attributes()))
            .map_err(failed("create private directory"))
    }

    fn symlink(&self, target: &Path, link: &Path) -> Result<(), PlatformError> {
        // A relative target is relative to the link, not to this process.
        let resolved = link.parent().unwrap_or(Path::new("")).join(target);
        let made = if fs::metadata(resolved).is_ok_and(|metadata| metadata.is_dir()) {
            symlink_dir(target, link)
        } else {
            symlink_file(target, link)
        };
        made.map_err(failed("create symlink"))
    }

    fn remove_file(&self, path: &Path) -> Result<(), PlatformError> {
        let is_folder_link = fs::symlink_metadata(path)
            .is_ok_and(|metadata| metadata.file_type().is_symlink_dir());
        if is_folder_link { fs::remove_dir(path) } else { fs::remove_file(path) }
            .map_err(failed("remove file"))
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::platform::files;
    use std::{io::Write, process::Command};

    #[test]
    fn a_path_is_given_to_windows_in_the_form_that_has_no_length_limit() {
        let text = |path: &str| String::from_utf16(&wide_path(Path::new(path))[..]).unwrap().trim_end_matches('\0').to_string();
        assert_eq!(text(r"C:\sites\shop/public_html/a"), r"\\?\C:\sites\shop\public_html\a");
        assert_eq!(text(r"\\?\C:\x"), r"\\?\C:\x");
        assert_eq!(text(r"\\server\share\x"), r"\\?\UNC\server\share\x");
    }

    #[test]
    fn a_file_past_260_characters_gets_its_mode() {
        let folder = tempfile::tempdir().unwrap();
        let deep = (0..8).fold(folder.path().to_path_buf(), |path, _| path.join("a-folder-name-of-forty-characters-long-x"));
        fs::create_dir_all(&deep).unwrap();
        let file = deep.join("file.txt");
        fs::write(&file, "x").unwrap();
        assert!(file.as_os_str().len() > 300);
        // Written with `/` as a panel path is, which `\\?\` alone would refuse.
        let mixed = format!("{}/file.txt", deep.display());
        files().set_mode(Path::new(&mixed), FileMode::Shared).unwrap();
    }

    /// The ACL as Windows reports it, one principal per line.
    fn acl(path: &Path) -> String {
        let output = Command::new("icacls").arg(path).output().unwrap();
        String::from_utf8_lossy(&output.stdout).into_owned()
    }

    fn assert_private(path: &Path) {
        let text = acl(path);
        assert!(text.contains("SYSTEM"), "{text}");
        for other in ["BUILTIN\\Users", "Everyone", "Authenticated Users"] {
            assert!(!text.contains(other), "{other} in {text}");
        }
    }

    #[test]
    fn a_private_file_refuses_everyone_else_from_the_start() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("secret");
        let mut file = files().create_private(&path).unwrap();
        assert_private(&path);
        file.write_all(b"x").unwrap();
        drop(file);
        assert_eq!(fs::read(&path).unwrap(), b"x");
    }

    #[test]
    fn create_private_fails_if_the_file_exists() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("secret");
        fs::write(&path, "old").unwrap();
        assert!(matches!(
            files().create_private(&path),
            Err(PlatformError::Conflict { .. })
        ));
        assert_eq!(fs::read(&path).unwrap(), b"old");
    }

    #[test]
    fn replace_private_truncates_and_keeps_the_existing_acl() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("config");
        fs::write(&path, "old").unwrap();
        files().set_mode(&path, FileMode::Shared).unwrap();
        files().replace_private(&path, LinkPolicy::Refuse).unwrap();
        assert_eq!(fs::read(&path).unwrap(), b"");
        assert!(acl(&path).contains("BUILTIN\\Users"));
    }

    #[test]
    fn replace_private_creates_a_private_file() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("new");
        files().replace_private(&path, LinkPolicy::Follow).unwrap();
        assert_private(&path);
    }

    #[test]
    fn replace_private_refuses_a_link_and_leaves_its_target_alone() {
        let dir = tempfile::tempdir().unwrap();
        let target = dir.path().join("target");
        let link = dir.path().join("link");
        fs::write(&target, "keep").unwrap();
        files().symlink(&target, &link).unwrap();

        assert!(matches!(
            files().replace_private(&link, LinkPolicy::Refuse),
            Err(PlatformError::PermissionDenied { .. })
        ));
        assert_eq!(fs::read(&target).unwrap(), b"keep");

        files().replace_private(&link, LinkPolicy::Follow).unwrap();
        assert_eq!(fs::read(&target).unwrap(), b"");
    }

    #[test]
    fn a_private_folder_covers_its_parents_and_what_goes_in_it() {
        let dir = tempfile::tempdir().unwrap();
        let outer = dir.path().join("a");
        let inner = outer.join("b");
        files().create_dir_private(&inner).unwrap();
        files().create_dir_private(&inner).unwrap();
        assert_private(&outer);
        assert_private(&inner);
        let child = inner.join("file");
        fs::write(&child, "x").unwrap();
        assert_private(&child);
    }

    #[test]
    fn every_named_mode_sets_the_expected_acl() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("file");
        fs::write(&path, "x").unwrap();
        for (mode, users_can_read) in [
            (FileMode::Private, false),
            (FileMode::PrivateDir, false),
            (FileMode::SiteDir, true),
            (FileMode::SiteFile, true),
            (FileMode::Shared, true),
            (FileMode::Executable, true),
        ] {
            files().set_mode(&path, mode).unwrap();
            assert_eq!(acl(&path).contains("BUILTIN\\Users"), users_can_read, "{mode:?}");
            assert_eq!(fs::read(&path).unwrap(), b"x", "{mode:?}");
        }
    }

    /// A file that only has its folder's ACL loses it the moment the folder
    /// stops passing it on, and an empty ACL locks out even the owner.
    #[test]
    fn a_folders_mode_never_locks_out_what_is_inside() {
        for mode in [
            FileMode::Private,
            FileMode::PrivateDir,
            FileMode::SiteDir,
            FileMode::SiteFile,
            FileMode::Shared,
            FileMode::Executable,
        ] {
            let dir = tempfile::tempdir().unwrap();
            files().set_mode(dir.path(), FileMode::SiteDir).unwrap();
            let folder = dir.path().join("folder");
            fs::create_dir(&folder).unwrap();
            let file = folder.join("file");
            fs::write(&file, "x").unwrap();

            files().set_mode(&folder, mode).unwrap();

            assert_eq!(fs::read(&file).unwrap(), b"x", "{mode:?}");
            fs::write(folder.join("new"), "y").unwrap();
            assert_eq!(fs::read(folder.join("new")).unwrap(), b"y", "{mode:?}");
        }
    }

    #[test]
    fn exact_modes_are_refused_not_guessed() {
        let dir = tempfile::tempdir().unwrap();
        let path = dir.path().join("file");
        fs::write(&path, "x").unwrap();
        assert!(matches!(
            files().set_mode(&path, FileMode::Raw(0o640)),
            Err(PlatformError::Unsupported { .. })
        ));
    }

    #[test]
    fn set_mode_on_a_missing_file_is_not_found() {
        let dir = tempfile::tempdir().unwrap();
        assert!(matches!(
            files().set_mode(&dir.path().join("missing"), FileMode::Private),
            Err(PlatformError::NotFound { .. })
        ));
    }

    #[test]
    fn removing_a_link_to_a_folder_keeps_the_folder() {
        let dir = tempfile::tempdir().unwrap();
        let target = dir.path().join("target");
        fs::create_dir(&target).unwrap();
        fs::write(target.join("file"), "x").unwrap();
        let link = dir.path().join("link");
        files().symlink(&target, &link).unwrap();

        files().remove_file(&link).unwrap();
        assert!(fs::symlink_metadata(&link).is_err());
        assert_eq!(fs::read(target.join("file")).unwrap(), b"x");
    }

    #[test]
    fn a_link_to_a_folder_is_a_folder_link() {
        let dir = tempfile::tempdir().unwrap();
        let target = dir.path().join("target");
        fs::create_dir(&target).unwrap();
        fs::write(target.join("file"), "x").unwrap();
        let link = dir.path().join("link");
        files().symlink(&target, &link).unwrap();
        assert!(fs::symlink_metadata(&link).unwrap().is_symlink());
        assert_eq!(fs::read(link.join("file")).unwrap(), b"x");
    }
}
