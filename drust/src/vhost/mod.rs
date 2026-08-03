pub(crate) fn run_fix_web_stack(_backend_port: u16, _frontend_port: u16) -> Result<(), String> {
    Err("legacy web stack support has been removed from drust.".into())
}

pub(crate) fn run_fix_panel_web_stack(_args: Vec<String>) -> Result<(), String> {
    Err("legacy panel web stack support has been removed from drust.".into())
}

pub(crate) fn run_sync_vhost(args: Vec<String>) -> Result<(), String> {
    let _ = args;
    Err("legacy vhost sync support has been removed from drust.".into())
}
