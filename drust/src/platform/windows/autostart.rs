//! Starting drust when the user logs on (F4, local profile): a value under
//! `HKCU\Software\Microsoft\Windows\CurrentVersion\Run`. It needs no
//! administrator and runs drust as the user, as the plan's least-privilege
//! rule for a PC asks. A Task Scheduler logon task would do the same, but
//! creating one needs an administrator.

use std::{ffi::OsStr, os::windows::ffi::OsStrExt, ptr};

use windows_sys::Win32::{
    Foundation::{ERROR_FILE_NOT_FOUND, ERROR_SUCCESS},
    System::Registry::{
        HKEY, HKEY_CURRENT_USER, KEY_QUERY_VALUE, KEY_SET_VALUE, REG_SZ, RegCloseKey, RegDeleteValueW, RegOpenKeyExW,
        RegQueryValueExW, RegSetValueExW,
    },
};

const RUN_KEY: &str = r"Software\Microsoft\Windows\CurrentVersion\Run";
const VALUE: &str = "dPanel";

fn wide(text: impl AsRef<OsStr>) -> Vec<u16> {
    text.as_ref().encode_wide().chain(Some(0)).collect()
}

fn os_error(code: u32) -> std::io::Error {
    std::io::Error::from_raw_os_error(code as i32)
}

struct Key(HKEY);

impl Drop for Key {
    fn drop(&mut self) {
        // SAFETY: a key this module opened.
        unsafe { RegCloseKey(self.0) };
    }
}

fn run_key(access: u32) -> Result<Key, String> {
    let path = wide(RUN_KEY);
    let mut key: HKEY = ptr::null_mut();
    // SAFETY: NUL-terminated path; `key` is written on success.
    let code = unsafe { RegOpenKeyExW(HKEY_CURRENT_USER, path.as_ptr(), 0, access, &mut key) };
    if code != ERROR_SUCCESS {
        return Err(format!("cannot open HKCU\\{RUN_KEY}: {}", os_error(code)));
    }
    Ok(Key(key))
}

/// Runs `command_line` at every logon from now on.
pub fn enable(command_line: &str) -> Result<(), String> {
    let key = run_key(KEY_SET_VALUE)?;
    let (name, data) = (wide(VALUE), wide(command_line));
    // SAFETY: REG_SZ data with its NUL, sized in bytes.
    let code = unsafe {
        RegSetValueExW(key.0, name.as_ptr(), 0, REG_SZ, data.as_ptr().cast(), (data.len() * 2) as u32)
    };
    if code != ERROR_SUCCESS {
        return Err(format!("cannot write the logon entry: {}", os_error(code)));
    }
    Ok(())
}

/// Removes the logon entry; `false` when there was none.
pub fn disable() -> Result<bool, String> {
    let key = run_key(KEY_SET_VALUE)?;
    let name = wide(VALUE);
    // SAFETY: NUL-terminated value name.
    match unsafe { RegDeleteValueW(key.0, name.as_ptr()) } {
        ERROR_SUCCESS => Ok(true),
        ERROR_FILE_NOT_FOUND => Ok(false),
        code => Err(format!("cannot remove the logon entry: {}", os_error(code))),
    }
}

/// The command line that runs at logon, if any.
pub fn command_line() -> Result<Option<String>, String> {
    let key = run_key(KEY_QUERY_VALUE)?;
    let name = wide(VALUE);
    let mut buffer = vec![0u16; 2048];
    let mut bytes = (buffer.len() * 2) as u32;
    let mut kind = 0;
    // SAFETY: the buffer is `bytes` long; the result is checked.
    let code = unsafe {
        RegQueryValueExW(key.0, name.as_ptr(), ptr::null(), &mut kind, buffer.as_mut_ptr().cast(), &mut bytes)
    };
    match code {
        ERROR_SUCCESS => {
            buffer.truncate(bytes as usize / 2);
            while buffer.last() == Some(&0) {
                buffer.pop();
            }
            Ok(Some(String::from_utf16_lossy(&buffer)))
        }
        ERROR_FILE_NOT_FOUND => Ok(None),
        code => Err(format!("cannot read the logon entry: {}", os_error(code))),
    }
}
