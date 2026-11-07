use std::{io, ptr};
use windows_sys::Win32::Security::Cryptography::{
    BCRYPT_USE_SYSTEM_PREFERRED_RNG, BCryptGenRandom,
};

pub fn fill(buffer: &mut [u8]) -> io::Result<()> {
    let length = u32::try_from(buffer.len())
        .map_err(|_| io::Error::new(io::ErrorKind::InvalidInput, "random buffer too large"))?;
    // SAFETY: the pointer and length describe `buffer`, which outlives the call.
    let status = unsafe {
        BCryptGenRandom(
            ptr::null_mut(),
            buffer.as_mut_ptr(),
            length,
            BCRYPT_USE_SYSTEM_PREFERRED_RNG,
        )
    };
    if status == 0 {
        Ok(())
    } else {
        Err(io::Error::other(format!("BCryptGenRandom failed: {status:#x}")))
    }
}
