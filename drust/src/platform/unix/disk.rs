//! Size and free space of the filesystem holding a path.

use std::{ffi::CString, os::unix::ffi::OsStrExt, path::Path};

/// `(total, available)` in bytes; available is what a normal user may still write.
pub fn space(path: &Path) -> Option<(u64, u64)> {
    let path = CString::new(path.as_os_str().as_bytes()).ok()?;
    let mut stat: libc::statvfs = unsafe { std::mem::zeroed() };
    // SAFETY: `path` is a valid C string and `stat` a writable statvfs.
    if unsafe { libc::statvfs(path.as_ptr(), &mut stat) } != 0 {
        return None;
    }
    let block = stat.f_frsize as u64;
    Some((stat.f_blocks as u64 * block, stat.f_bavail as u64 * block))
}
