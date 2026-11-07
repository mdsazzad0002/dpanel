//! Process settings that differ by OS.

use tokio::process::Command;

/// Starts the child in its own process group, so that it and everything it
/// spawns can be stopped together.
pub fn new_process_group(command: &mut Command) -> &mut Command {
    #[cfg(unix)]
    {
        command.process_group(0)
    }
    #[cfg(windows)]
    {
        const CREATE_NEW_PROCESS_GROUP: u32 = 0x0000_0200;
        command.creation_flags(CREATE_NEW_PROCESS_GROUP)
    }
}
