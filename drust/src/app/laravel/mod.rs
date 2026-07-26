mod install;
mod options;

pub(crate) fn run(args: Vec<String>) -> Result<(), String> {
    install::run(options::Options::parse(args)?)
}
