mod admin;
mod laravel;
mod support;

pub use support::{
    backup_file, conf_basename, detect_app_root, distro_family, ensure_comment_listen,
    ensure_listen_line, ensure_root, info, normalize_php_version, panel_aliases_for, parse_port,
    random_hex, read_to_string, remove_legacy_panel_vhosts, restart_services_for_web_stack,
    run_output, run_status, split_aliases, user_group, valid_username, warn, write_string,
};

pub fn run_admin_user(args: Vec<String>) -> Result<(), String> {
    admin::create_user::run(args)
}

pub fn run_disable_root_login() -> Result<(), String> {
    admin::disable_root::run()
}

pub fn run_laravel_install(args: Vec<String>) -> Result<(), String> {
    laravel::run(args)
}
