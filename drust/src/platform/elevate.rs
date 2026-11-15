//! Running a program as an administrator, asking the person at the screen
//! first (F6). Windows shows its UAC prompt; elsewhere this is not built.

use std::path::Path;

use super::PlatformError;

/// Runs `program` elevated and waits: its exit code, or `None` when the
/// user said no. `parameters` is the whole quoted argument string.
pub fn run(program: &Path, parameters: &str) -> Result<Option<u32>, PlatformError> {
    #[cfg(windows)]
    {
        super::windows::elevate::run(program, parameters)
            .map_err(|detail| PlatformError::Failed { action: "run as an administrator".into(), detail })
    }
    #[cfg(not(windows))]
    {
        let _ = (program, parameters);
        Err(PlatformError::Unsupported { capability: super::capabilities::Capability::Dns })
    }
}
