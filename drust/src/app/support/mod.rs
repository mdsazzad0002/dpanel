mod domain;
mod filesystem;
mod process;
mod system;
mod user;

pub use domain::{conf_basename, panel_aliases_for, split_aliases};
pub use filesystem::{backup_file, read_to_string, write_string};
pub use process::{ensure_root, run_output, run_status};
pub use system::{
    detect_app_root, distro_family, ensure_comment_listen, ensure_listen_line, parse_port,
    remove_legacy_panel_vhosts, restart_services_for_web_stack,
};
pub use user::{random_hex, user_group, valid_username};

pub(crate) use filesystem::current_epoch;
pub(crate) use process::program_exists;
pub(crate) use user::{user_home, valid_email};

pub fn info(message: &str) {
    println!("[INFO] {message}");
}

pub fn warn(message: &str) {
    eprintln!("[WARN] {message}");
}

pub fn normalize_php_version(value: &str, fallback: &str) -> String {
    let value = value.trim();
    if value
        .chars()
        .all(|character| character.is_ascii_digit() || character == '.')
        && value.contains('.')
    {
        value.to_string()
    } else {
        fallback.to_string()
    }
}
