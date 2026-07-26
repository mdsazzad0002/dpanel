mod common;
mod fix_panel_web_stack;
mod fix_web_stack;
mod options;
mod sync;

pub(crate) fn run_fix_web_stack(
    apache_backend_port: u16,
    nginx_frontend_port: u16,
) -> Result<(), String> {
    fix_web_stack::run(apache_backend_port, nginx_frontend_port)
}

pub(crate) fn run_fix_panel_web_stack(args: Vec<String>) -> Result<(), String> {
    fix_panel_web_stack::run(options::Panel::parse(args)?)
}

pub(crate) fn run_sync_vhost(args: Vec<String>) -> Result<(), String> {
    sync::run(options::Sync::parse(args)?)
}
