#[derive(Default)]
pub(super) struct Options {
    pub(super) username: String,
    pub(super) password: Option<String>,
    pub(super) email: Option<String>,
    pub(super) ssh_key: Option<String>,
    pub(super) shell_path: String,
    pub(super) disable_root: bool,
}

impl Options {
    pub(super) fn parse(args: Vec<String>) -> Result<Self, String> {
        let mut options = Self {
            shell_path: "/bin/bash".into(),
            ..Default::default()
        };
        let mut positional_username = None;
        let mut arguments = args.into_iter();

        while let Some(argument) = arguments.next() {
            match argument.as_str() {
                "--username" => options.username = value(&mut arguments, "--username")?,
                "--password" | "--panel-password" => {
                    options.password = Some(value(&mut arguments, &argument)?);
                }
                "--email" | "--panel-email" => {
                    options.email = Some(value(&mut arguments, &argument)?);
                }
                "--ssh-key" => options.ssh_key = Some(value(&mut arguments, "--ssh-key")?),
                "--shell" => options.shell_path = value(&mut arguments, "--shell")?,
                "--disable-root" => options.disable_root = true,
                "--keep-root" | "--no-disable-root" => options.disable_root = false,
                other if other.starts_with('-') => return Err(format!("Unknown option: {other}")),
                other if positional_username.is_none() && options.username.is_empty() => {
                    positional_username = Some(other.to_string());
                }
                other => return Err(format!("Unknown argument: {other}")),
            }
        }

        if options.username.is_empty() {
            options.username = positional_username.unwrap_or_default();
        }
        if options.username.trim().is_empty() {
            return Err("Username is required.".into());
        }
        Ok(options)
    }
}

fn value(arguments: &mut impl Iterator<Item = String>, option: &str) -> Result<String, String> {
    arguments
        .next()
        .ok_or_else(|| format!("Missing value for {option}"))
}
