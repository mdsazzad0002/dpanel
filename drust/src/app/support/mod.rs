pub mod archive;
mod filesystem;
mod process;
mod user;

pub use filesystem::{read_to_string, write_string};
pub use process::{ensure_root, run_status};
pub use user::{random_hex, user_group, valid_username};

pub(crate) use filesystem::current_epoch;
pub(crate) use process::{Depth, program_exists, try_chown};
pub(crate) use user::{user_home, valid_email};

pub fn info(message: &str) {
    println!("[INFO] {message}");
}

pub fn warn(message: &str) {
    eprintln!("[WARN] {message}");
}

