//! drust as a Windows Service (F4): `drust service install` registers it
//! with the Service Control Manager to start at boot as LocalSystem, and the
//! SCM runs `drust service run`, which reports to it and stops the
//! supervised programs when Windows asks. For the server profile; a PC
//! starts drust at logon instead (`autostart.rs`), as the user.

use std::{
    ffi::OsStr,
    io, mem,
    os::windows::ffi::OsStrExt,
    ptr,
    sync::{Condvar, Mutex, OnceLock},
};

use windows_sys::Win32::{
    Foundation::{ERROR_CALL_NOT_IMPLEMENTED, ERROR_SERVICE_DOES_NOT_EXIST, NO_ERROR},
    Storage::FileSystem::DELETE,
    System::Services::{
        ChangeServiceConfig2W, CloseServiceHandle, ControlService, CreateServiceW, DeleteService, OpenSCManagerW,
        OpenServiceW, RegisterServiceCtrlHandlerExW, SC_ACTION, SC_ACTION_NONE, SC_ACTION_RESTART, SC_HANDLE,
        SC_MANAGER_CONNECT, SC_MANAGER_CREATE_SERVICE, SERVICE_ACCEPT_SHUTDOWN, SERVICE_ACCEPT_STOP,
        SERVICE_ALL_ACCESS, SERVICE_AUTO_START, SERVICE_CONFIG_DESCRIPTION, SERVICE_CONFIG_FAILURE_ACTIONS,
        SERVICE_CONTROL_INTERROGATE, SERVICE_CONTROL_SHUTDOWN, SERVICE_CONTROL_STOP, SERVICE_DESCRIPTIONW,
        SERVICE_ERROR_NORMAL, SERVICE_FAILURE_ACTIONSW, SERVICE_QUERY_STATUS, SERVICE_RUNNING, SERVICE_START,
        SERVICE_START_PENDING, SERVICE_STATUS, SERVICE_STATUS_HANDLE, SERVICE_STOP, SERVICE_STOP_PENDING,
        SERVICE_STOPPED, SERVICE_TABLE_ENTRYW, SERVICE_WIN32_OWN_PROCESS, SetServiceStatus, StartServiceCtrlDispatcherW,
        StartServiceW,
    },
};

pub const NAME: &str = "dPanel";
const DESCRIPTION: &str = "dPanel's drust: API, web gateway, MariaDB and the panel's queue worker.";

fn wide(text: impl AsRef<OsStr>) -> Vec<u16> {
    text.as_ref().encode_wide().chain(Some(0)).collect()
}

/// Closes an SCM handle when it goes out of scope.
struct Handle(SC_HANDLE);

impl Drop for Handle {
    fn drop(&mut self) {
        // SAFETY: a handle this module opened and nothing else closes.
        unsafe { CloseServiceHandle(self.0) };
    }
}

fn manager(access: u32) -> Result<Handle, String> {
    // SAFETY: local machine, default database; the result is checked.
    let handle = unsafe { OpenSCManagerW(ptr::null(), ptr::null(), access) };
    if handle.is_null() {
        let error = io::Error::last_os_error();
        return Err(format!("cannot open the Service Control Manager ({error}); run this as an administrator"));
    }
    Ok(Handle(handle))
}

/// Registers the service to start at boot, restarting it when it fails,
/// and starts it now. `command_line` is what the SCM runs, quoted.
pub fn install(command_line: &str) -> Result<(), String> {
    let manager = manager(SC_MANAGER_CREATE_SERVICE)?;
    let (name, command) = (wide(NAME), wide(command_line));
    // SAFETY: every string is NUL-terminated and outlives the call; a null
    // account means LocalSystem.
    let service = unsafe {
        CreateServiceW(
            manager.0,
            name.as_ptr(),
            name.as_ptr(),
            SERVICE_ALL_ACCESS,
            SERVICE_WIN32_OWN_PROCESS,
            SERVICE_AUTO_START,
            SERVICE_ERROR_NORMAL,
            command.as_ptr(),
            ptr::null(),
            ptr::null_mut(),
            ptr::null(),
            ptr::null(),
            ptr::null(),
        )
    };
    if service.is_null() {
        return Err(format!("cannot create the {NAME} service: {}", io::Error::last_os_error()));
    }
    let service = Handle(service);

    let mut description = wide(DESCRIPTION);
    let info = SERVICE_DESCRIPTIONW { lpDescription: description.as_mut_ptr() };
    // Restart after 5 s, twice more after 30 s; forget failures after a day.
    let mut actions = [
        SC_ACTION { Type: SC_ACTION_RESTART, Delay: 5_000 },
        SC_ACTION { Type: SC_ACTION_RESTART, Delay: 30_000 },
        SC_ACTION { Type: SC_ACTION_RESTART, Delay: 30_000 },
        SC_ACTION { Type: SC_ACTION_NONE, Delay: 0 },
    ];
    let failure = SERVICE_FAILURE_ACTIONSW {
        dwResetPeriod: 86_400,
        lpRebootMsg: ptr::null_mut(),
        lpCommand: ptr::null_mut(),
        cActions: actions.len() as u32,
        lpsaActions: actions.as_mut_ptr(),
    };
    // SAFETY: the structs and what they point to live across the calls.
    unsafe {
        ChangeServiceConfig2W(service.0, SERVICE_CONFIG_DESCRIPTION, (&raw const info).cast());
        ChangeServiceConfig2W(service.0, SERVICE_CONFIG_FAILURE_ACTIONS, (&raw const failure).cast());
    }
    // SAFETY: no arguments.
    if unsafe { StartServiceW(service.0, 0, ptr::null()) } == 0 {
        return Err(format!("the service is installed but did not start: {}", io::Error::last_os_error()));
    }
    Ok(())
}

/// Stops the service and removes it. Not installed is not an error.
pub fn uninstall() -> Result<bool, String> {
    let manager = manager(SC_MANAGER_CONNECT)?;
    let name = wide(NAME);
    // SAFETY: NUL-terminated name; the result is checked.
    let service = unsafe { OpenServiceW(manager.0, name.as_ptr(), SERVICE_STOP | SERVICE_QUERY_STATUS | DELETE) };
    if service.is_null() {
        let error = io::Error::last_os_error();
        if error.raw_os_error() == Some(ERROR_SERVICE_DOES_NOT_EXIST as i32) {
            return Ok(false);
        }
        return Err(format!("cannot open the {NAME} service: {error}"));
    }
    let service = Handle(service);
    // SAFETY: an all-zero SERVICE_STATUS is valid; a stopped service refuses
    // the control, which is fine.
    let mut status: SERVICE_STATUS = unsafe { mem::zeroed() };
    unsafe { ControlService(service.0, SERVICE_CONTROL_STOP, &mut status) };
    // SAFETY: an open handle with DELETE access.
    if unsafe { DeleteService(service.0) } == 0 {
        return Err(format!("cannot remove the {NAME} service: {}", io::Error::last_os_error()));
    }
    Ok(true)
}

/// Whether the service is registered.
pub fn installed() -> Result<bool, String> {
    let manager = manager(SC_MANAGER_CONNECT)?;
    let name = wide(NAME);
    // SAFETY: as in `uninstall`.
    let service = unsafe { OpenServiceW(manager.0, name.as_ptr(), SERVICE_QUERY_STATUS | SERVICE_START) };
    if service.is_null() {
        return Ok(false);
    }
    drop(Handle(service));
    Ok(true)
}

struct Hooks {
    main: Box<dyn Fn() + Send + Sync>,
    stop: Box<dyn Fn() + Send + Sync>,
}

static HOOKS: OnceLock<Hooks> = OnceLock::new();
static STATUS_HANDLE: OnceLock<usize> = OnceLock::new();
static STOP: (Mutex<bool>, Condvar) = (Mutex::new(false), Condvar::new());

/// Runs as the service the SCM started: `main` on its own thread until
/// Windows asks the service to stop, then `stop`, then the process ends.
/// Fails at once when not started by the SCM.
pub fn run(main: impl Fn() + Send + Sync + 'static, stop: impl Fn() + Send + Sync + 'static) -> Result<(), String> {
    let _ = HOOKS.set(Hooks { main: Box::new(main), stop: Box::new(stop) });
    let mut name = wide(NAME);
    let table = [
        SERVICE_TABLE_ENTRYW { lpServiceName: name.as_mut_ptr(), lpServiceProc: Some(service_main) },
        SERVICE_TABLE_ENTRYW { lpServiceName: ptr::null_mut(), lpServiceProc: None },
    ];
    // SAFETY: a NUL-terminated table that lives until the dispatcher returns,
    // which is after the service has stopped.
    if unsafe { StartServiceCtrlDispatcherW(table.as_ptr()) } == 0 {
        return Err(format!(
            "not started by the Service Control Manager ({}); use `drust service install`",
            io::Error::last_os_error()
        ));
    }
    Ok(())
}

fn report(state: u32, wait_hint: u32) {
    let Some(&handle) = STATUS_HANDLE.get() else {
        return;
    };
    let accepted = if state == SERVICE_RUNNING { SERVICE_ACCEPT_STOP | SERVICE_ACCEPT_SHUTDOWN } else { 0 };
    let status = SERVICE_STATUS {
        dwServiceType: SERVICE_WIN32_OWN_PROCESS,
        dwCurrentState: state,
        dwControlsAccepted: accepted,
        dwWin32ExitCode: NO_ERROR,
        dwServiceSpecificExitCode: 0,
        dwCheckPoint: 0,
        dwWaitHint: wait_hint,
    };
    // SAFETY: the handle RegisterServiceCtrlHandlerExW gave.
    unsafe { SetServiceStatus(handle as SERVICE_STATUS_HANDLE, &status) };
}

unsafe extern "system" fn handler(control: u32, _event: u32, _data: *mut core::ffi::c_void, _context: *mut core::ffi::c_void) -> u32 {
    match control {
        SERVICE_CONTROL_STOP | SERVICE_CONTROL_SHUTDOWN => {
            report(SERVICE_STOP_PENDING, 15_000);
            *STOP.0.lock().unwrap_or_else(|poisoned| poisoned.into_inner()) = true;
            STOP.1.notify_all();
            NO_ERROR
        }
        SERVICE_CONTROL_INTERROGATE => NO_ERROR,
        _ => ERROR_CALL_NOT_IMPLEMENTED,
    }
}

unsafe extern "system" fn service_main(_argc: u32, _argv: *mut windows_sys::core::PWSTR) {
    let name = wide(NAME);
    // SAFETY: NUL-terminated name; the handler lives for the whole process.
    let handle = unsafe { RegisterServiceCtrlHandlerExW(name.as_ptr(), Some(handler), ptr::null()) };
    if handle.is_null() {
        return;
    }
    let _ = STATUS_HANDLE.set(handle as usize);
    report(SERVICE_START_PENDING, 10_000);
    let Some(hooks) = HOOKS.get() else {
        report(SERVICE_STOPPED, 0);
        return;
    };
    // The API server never returns; the process ends with the service.
    std::thread::spawn(|| (HOOKS.get().expect("set before the dispatcher").main)());
    report(SERVICE_RUNNING, 0);
    let mut stopping = STOP.0.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    while !*stopping {
        stopping = STOP.1.wait(stopping).unwrap_or_else(|poisoned| poisoned.into_inner());
    }
    drop(stopping);
    (hooks.stop)();
    report(SERVICE_STOPPED, 0);
}
