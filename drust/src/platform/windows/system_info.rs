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

/// How many processes run, as Task Manager counts them.
pub fn process_count() -> Option<usize> {
    use windows_sys::Win32::System::ProcessStatus::K32EnumProcesses;
    let mut ids = vec![0u32; 1024];
    loop {
        let size = (ids.len() * mem::size_of::<u32>()) as u32;
        let mut used = 0u32;
        // SAFETY: the buffer is `size` bytes long.
        if unsafe { K32EnumProcesses(ids.as_mut_ptr(), size, &mut used) } == 0 {
            return None;
        }
        // A full buffer may have cut the list short.
        if used < size {
            return Some(used as usize / mem::size_of::<u32>());
        }
        ids.resize(ids.len() * 2, 0);
    }
}

/// Bytes in and out of each interface that is up, as `/proc/net/dev` gives
/// them: no loopback, and none of the filter layers Windows stacks on an
/// adapter (which repeat its counts), nor idle virtual ones.
pub fn network_interfaces() -> Vec<(String, u64, u64)> {
    use windows_sys::Win32::NetworkManagement::{
        IpHelper::{FreeMibTable, GetIfTable2, IF_TYPE_SOFTWARE_LOOPBACK, MIB_IF_TABLE2},
        Ndis::IfOperStatusUp,
    };
    const HARDWARE_INTERFACE: u8 = 0b01;
    const FILTER_INTERFACE: u8 = 0b10;
    let mut table: *mut MIB_IF_TABLE2 = ptr::null_mut();
    // SAFETY: Windows allocates the table; it is freed below.
    if unsafe { GetIfTable2(&mut table) } != 0 || table.is_null() {
        return Vec::new();
    }
    // SAFETY: the table holds `NumEntries` rows, laid out after its header.
    let rows = unsafe { std::slice::from_raw_parts((*table).Table.as_ptr(), (*table).NumEntries as usize) };
    let mut interfaces: Vec<(String, u64, u64)> = Vec::new();
    for row in rows {
        if row.Type == IF_TYPE_SOFTWARE_LOOPBACK
            || row.OperStatus != IfOperStatusUp
            || row.InterfaceAndOperStatusFlags._bitfield & FILTER_INTERFACE != 0
            // WAN miniports and the like: not an adapter, and never used.
            || (row.InterfaceAndOperStatusFlags._bitfield & HARDWARE_INTERFACE == 0 && row.InOctets == 0 && row.OutOctets == 0)
        {
            continue;
        }
        let length = row.Alias.iter().position(|&unit| unit == 0).unwrap_or(row.Alias.len());
        let name = String::from_utf16_lossy(&row.Alias[..length]);
        if !name.is_empty() && !interfaces.iter().any(|(seen, ..)| *seen == name) {
            interfaces.push((name, row.InOctets, row.OutOctets));
        }
    }
    // SAFETY: allocated by GetIfTable2.
    unsafe { FreeMibTable(table.cast()) };
    interfaces
}
