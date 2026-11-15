//! Running a program as an administrator from a user's process (F6): the
//! UAC prompt, then the program, waited for. Only where a person is at the
//! screen to answer the prompt, so only on a PC.

use std::{ffi::OsStr, io, mem, os::windows::ffi::OsStrExt, path::Path};

use windows_sys::Win32::{
    Foundation::{CloseHandle, ERROR_CANCELLED},
    System::Threading::{GetExitCodeProcess, INFINITE, WaitForSingleObject},
    UI::Shell::{SEE_MASK_NO_CONSOLE, SEE_MASK_NOCLOSEPROCESS, SHELLEXECUTEINFOW, ShellExecuteExW},
};

fn wide(text: impl AsRef<OsStr>) -> Vec<u16> {
    text.as_ref().encode_wide().chain(Some(0)).collect()
}

/// The program's exit code, or `None` when the user declined the prompt.
pub fn run(program: &Path, parameters: &str) -> Result<Option<u32>, String> {
    let (verb, file, parameters) = (wide("runas"), wide(program), wide(parameters));
    // SAFETY: all-zero is a valid SHELLEXECUTEINFOW; the fields used are set below.
    let mut info: SHELLEXECUTEINFOW = unsafe { mem::zeroed() };
    info.cbSize = mem::size_of::<SHELLEXECUTEINFOW>() as u32;
    info.fMask = SEE_MASK_NOCLOSEPROCESS | SEE_MASK_NO_CONSOLE;
    info.lpVerb = verb.as_ptr();
    info.lpFile = file.as_ptr();
    info.lpParameters = parameters.as_ptr();
    info.nShow = 0; // SW_HIDE
    // SAFETY: the strings live until the call returns.
    if unsafe { ShellExecuteExW(&mut info) } == 0 {
        let error = io::Error::last_os_error();
        if error.raw_os_error() == Some(ERROR_CANCELLED as i32) {
            return Ok(None);
        }
        return Err(format!("cannot run {} as an administrator: {error}", program.display()));
    }
    if info.hProcess.is_null() {
        return Err("Windows started no process".into());
    }
    let mut code = 0u32;
    // SAFETY: the process handle SEE_MASK_NOCLOSEPROCESS asked for; closed below.
    unsafe {
        WaitForSingleObject(info.hProcess, INFINITE);
        GetExitCodeProcess(info.hProcess, &mut code);
        CloseHandle(info.hProcess);
    }
    Ok(Some(code))
}
