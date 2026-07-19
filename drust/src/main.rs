mod api;
mod app;
#[path = "filemanager.rs"]
mod filemanager;
mod php_config;
mod scripts;
mod ssl;
#[path = "vhost.rs"]
mod vhost;

fn main() -> std::process::ExitCode {
    app::run()
}
