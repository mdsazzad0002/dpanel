//! One Job Object for the whole drust process, set to kill every process in
//! it when its last handle closes. drust never closes that handle, so Windows
//! closes it when drust exits, however it exits, and takes the children with
//! it. Without this, a gateway that crashes leaves its php-cgi workers
//! running and holding their ports.

use std::{io, mem, os::windows::io::RawHandle, ptr, sync::OnceLock};
use windows_sys::Win32::{
    Foundation::HANDLE,
    System::JobObjects::{
        AssignProcessToJobObject, CreateJobObjectW, JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE,
        JOBOBJECT_EXTENDED_LIMIT_INFORMATION, JobObjectExtendedLimitInformation,
        SetInformationJobObject,
    },
};

/// The job's handle, as an integer because a raw pointer is not `Send`.
/// Creating it failed if this holds an error; the error is kept so every
/// caller sees why, and creation is not retried.
static JOB: OnceLock<Result<usize, String>> = OnceLock::new();

fn job() -> Result<HANDLE, String> {
    JOB.get_or_init(|| {
        // SAFETY: no name and no security attributes; the result is checked.
        let job = unsafe { CreateJobObjectW(ptr::null(), ptr::null()) };
        if job.is_null() {
            return Err(format!("cannot create a Job Object: {}", io::Error::last_os_error()));
        }
        // SAFETY: all-zero is a valid value of this plain C struct.
        let mut limits: JOBOBJECT_EXTENDED_LIMIT_INFORMATION = unsafe { mem::zeroed() };
        limits.BasicLimitInformation.LimitFlags = JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
        // SAFETY: `job` is open, and the pointer and size describe `limits`.
        let set = unsafe {
            SetInformationJobObject(
                job,
                JobObjectExtendedLimitInformation,
                (&raw const limits).cast(),
                mem::size_of::<JOBOBJECT_EXTENDED_LIMIT_INFORMATION>() as u32,
            )
        };
        if set == 0 {
            return Err(format!("cannot set the Job Object's limit: {}", io::Error::last_os_error()));
        }
        // The handle is never closed on purpose: see the module comment.
        Ok(job as usize)
    })
    .clone()
    .map(|job| job as HANDLE)
}

/// Puts the process into drust's job, so that it ends when drust ends.
/// Anything it starts after this joins the job too. Windows 8 and later allow
/// a process to be in more than one job, so this works even when drust itself
/// runs inside one (a service, a terminal).
pub fn end_with_this_process(process: RawHandle) -> Result<(), String> {
    let job = job()?;
    // SAFETY: both handles are open; `process` belongs to a child the caller
    // still holds.
    if unsafe { AssignProcessToJobObject(job, process as HANDLE) } == 0 {
        return Err(format!("cannot put the process into drust's Job Object: {}", io::Error::last_os_error()));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        os::windows::io::AsRawHandle,
        process::{Command, Stdio},
        time::Duration,
    };
    use windows_sys::Win32::{
        Foundation::{CloseHandle, WAIT_OBJECT_0},
        System::Threading::{OpenProcess, PROCESS_SYNCHRONIZE, WaitForSingleObject},
    };

    const PARENT: &str = "platform::windows::job::tests::parent_that_exits";
    const SLEEPER: &str = "platform::windows::job::tests::sleeper";

    fn helper(name: &str) -> Command {
        let mut command = Command::new(std::env::current_exe().unwrap());
        command.args(["--exact", name, "--ignored", "--nocapture", "--quiet"]);
        command
    }

    /// Not a test: stands in for php-cgi, which never exits by itself.
    #[test]
    #[ignore = "started by the job tests"]
    fn sleeper() {
        std::thread::sleep(Duration::from_secs(60));
    }

    /// Not a test: stands in for drust. Starts a sleeper, ties it to the job
    /// unless told not to, writes its pid to a file and exits without stopping it.
    #[test]
    #[ignore = "started by the job tests"]
    fn parent_that_exits() {
        let child = helper(SLEEPER)
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .spawn()
            .unwrap();
        if std::env::var_os("JOB_TEST_UNTIED").is_none() {
            end_with_this_process(child.as_raw_handle()).unwrap();
        }
        std::fs::write(std::env::var_os("JOB_TEST_PID_FILE").unwrap(), child.id().to_string()).unwrap();
    }

    /// Runs the parent, and returns whether its sleeper ended within a few
    /// seconds of the parent exiting.
    fn sleeper_ends_with_parent(tied: bool) -> bool {
        let mut parent = helper(PARENT);
        if !tied {
            parent.env("JOB_TEST_UNTIED", "1");
        }
        let pid_file = tempfile::NamedTempFile::new().unwrap();
        let status = parent
            .env("JOB_TEST_PID_FILE", pid_file.path())
            .stdout(Stdio::null())
            .stderr(Stdio::null())
            .status()
            .unwrap();
        assert!(status.success());
        let pid: u32 = std::fs::read_to_string(pid_file.path()).unwrap().trim().parse().unwrap();
        // SAFETY: plain calls on a pid; the handle is checked and closed.
        unsafe {
            let sleeper = OpenProcess(PROCESS_SYNCHRONIZE, 0, pid);
            if sleeper.is_null() {
                return true; // already gone
            }
            let ended = WaitForSingleObject(sleeper, 5_000) == WAIT_OBJECT_0;
            CloseHandle(sleeper);
            if !ended {
                let _ = Command::new("taskkill").args(["/PID", &pid.to_string(), "/F"]).output();
            }
            ended
        }
    }

    #[test]
    fn a_tied_child_ends_when_its_parent_exits() {
        assert!(sleeper_ends_with_parent(true));
    }

    #[test]
    fn an_untied_child_outlives_its_parent() {
        // Shows the test above would catch a job that does nothing.
        assert!(!sleeper_ends_with_parent(false));
    }

    #[test]
    fn the_job_is_made_once() {
        assert_eq!(job().unwrap(), job().unwrap());
    }
}
