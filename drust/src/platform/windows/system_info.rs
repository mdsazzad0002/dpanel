//! Machine figures for `GET /system/overview` on Windows (backlog D4/D3):
//! what Linux reads from `/proc`. Windows has no load average.

use std::{mem, ptr};

use windows_sys::Win32::System::{
    Registry::{HKEY_LOCAL_MACHINE, RRF_RT_REG_SZ, RegGetValueW},
    SystemInformation::{GetTickCount64, GlobalMemoryStatusEx, MEMORYSTATUSEX},
};

/// Physical memory and page file in KiB: total, available, swap total, swap free.
pub fn memory_kb() -> Option<(u64, u64, u64, u64)> {
    // SAFETY: all-zero is valid; the length is set as the API requires.
    let mut status: MEMORYSTATUSEX = unsafe { mem::zeroed() };
    status.dwLength = mem::size_of::<MEMORYSTATUSEX>() as u32;
    // SAFETY: a properly sized struct.
    if unsafe { GlobalMemoryStatusEx(&mut status) } == 0 {
        return None;
    }
    // The page file counts physical memory too; what is beyond it is swap.
    let swap_total = status.ullTotalPageFile.saturating_sub(status.ullTotalPhys);
    let swap_free = status.ullAvailPageFile.saturating_sub(status.ullAvailPhys).min(swap_total);
    Some((status.ullTotalPhys / 1024, status.ullAvailPhys / 1024, swap_total / 1024, swap_free / 1024))
}

pub fn uptime_seconds() -> u64 {
    // SAFETY: no arguments.
    (unsafe { GetTickCount64() }) / 1000
}

/// The first processor's name, as Task Manager shows it.
pub fn cpu_model() -> Option<String> {
    let key: Vec<u16> = r"HARDWARE\DESCRIPTION\System\CentralProcessor\0".encode_utf16().chain(Some(0)).collect();
    let value: Vec<u16> = "ProcessorNameString".encode_utf16().chain(Some(0)).collect();
    let mut buffer = [0u16; 256];
    let mut bytes = (buffer.len() * 2) as u32;
    // SAFETY: NUL-terminated names; the buffer is `bytes` long.
    let code = unsafe {
        RegGetValueW(
            HKEY_LOCAL_MACHINE,
            key.as_ptr(),
            value.as_ptr(),
            RRF_RT_REG_SZ,
            ptr::null_mut(),
            buffer.as_mut_ptr().cast(),
            &mut bytes,
        )
    };
    if code != 0 {
        return None;
    }
    let length = (bytes as usize / 2).saturating_sub(1).min(buffer.len());
    let name = String::from_utf16_lossy(&buffer[..length]).trim().to_string();
    (!name.is_empty()).then_some(name)
}
