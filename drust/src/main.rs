mod admin;
mod api;
mod app;
mod database;
mod filemanager;
mod health;
mod laravel;
mod php;
mod php_config;
mod script;
mod scripts;
mod ssl;
mod vhost;
mod vhost_ops;
mod web_stack;

fn main() -> std::process::ExitCode {
    api::serve(std::env::args().skip(1).collect())
}
