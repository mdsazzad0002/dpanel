//! Size and free space of the volume holding a path.

use std::{os::windows::ffi::OsStrExt, path::Path};
use windows_sys::Win32::Storage::FileSystem::GetDiskFreeSpaceExW;

/// `(total, available)` in bytes; available is what the calling user may still write.
pub fn space(path: &Path) -> Option<(u64, u64)> {
    let wide: Vec<u16> = path.as_os_str().encode_wide().chain(std::iter::once(0)).collect();
    let (mut available, mut total, mut free) = (0u64, 0u64, 0u64);
    // SAFETY: `wide` is NUL-terminated and the three outputs are writable u64s.
    let ok = unsafe { GetDiskFreeSpaceExW(wide.as_ptr(), &mut available, &mut total, &mut free) };
    if ok == 0 {
        return None;
    }
    Some((total, available))
}
