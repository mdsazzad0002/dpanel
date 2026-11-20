mod admin;
mod support;

pub use support::{
    ensure_root, info, random_hex, read_to_string, run_status, user_group, valid_username, warn,
    write_string,
};
pub(crate) use support::{Depth, archive, program_exists, try_chown, valid_email};

pub fn run_admin_user(args: Vec<String>) -> Result<(), String> {
    admin::create_user::run(args)
}

pub fn run_disable_root_login() -> Result<(), String> {
    admin::disable_root::run()
}
