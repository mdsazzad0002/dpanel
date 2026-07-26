#[derive(Default)]
pub(super) struct Options {
    pub(super) root_path: String,
    pub(super) domain: String,
    pub(super) php_version: String,
    pub(super) start_directory: String,
    pub(super) db_name: Option<String>,
    pub(super) db_user: Option<String>,
    pub(super) db_password: Option<String>,
    pub(super) db_host: String,
    pub(super) db_port: String,
    pub(super) db_charset: String,
    pub(super) db_collation: String,
    pub(super) site_user: Option<String>,
    pub(super) site_group: Option<String>,
    pub(super) no_demo: bool,
    pub(super) no_db: bool,
    pub(super) no_vhost: bool,
}

impl Options {
    pub(super) fn parse(args: Vec<String>) -> Result<Self, String> {
        let mut options = Self {
            php_version: "auto".into(),
            db_host: "127.0.0.1".into(),
            db_port: "3306".into(),
            db_charset: "utf8mb4".into(),
            db_collation: "utf8mb4_unicode_ci".into(),
            ..Default::default()
        };
        let mut positional = Vec::new();
        let mut arguments = args.into_iter();

        while let Some(argument) = arguments.next() {
            match argument.as_str() {
                "--root" | "--root-path" => {
                    options.root_path = value(&mut arguments, "--root-path")?;
                }
                "--domain" => options.domain = value(&mut arguments, "--domain")?,
                "--php-version" => {
                    options.php_version = value(&mut arguments, "--php-version")?;
                }
                "--start-directory" => {
                    options.start_directory = value(&mut arguments, "--start-directory")?;
                }
                "--db-name" => options.db_name = Some(value(&mut arguments, "--db-name")?),
                "--db-user" => options.db_user = Some(value(&mut arguments, "--db-user")?),
                "--db-password" => {
                    options.db_password = Some(value(&mut arguments, "--db-password")?);
                }
                "--db-host" => options.db_host = value(&mut arguments, "--db-host")?,
                "--db-port" => options.db_port = value(&mut arguments, "--db-port")?,
                "--db-charset" => options.db_charset = value(&mut arguments, "--db-charset")?,
                "--db-collation" => {
                    options.db_collation = value(&mut arguments, "--db-collation")?;
                }
                "--user" => options.site_user = Some(value(&mut arguments, "--user")?),
                "--group" => options.site_group = Some(value(&mut arguments, "--group")?),
                "--no-demo" => options.no_demo = true,
                "--no-db" => options.no_db = true,
                "--no-vhost" => options.no_vhost = true,
                other if other.starts_with('-') => return Err(format!("Unknown option: {other}")),
                other => positional.push(other.to_string()),
            }
        }

        if options.root_path.is_empty() {
            options.root_path = positional.first().cloned().unwrap_or_default();
        }
        if options.domain.is_empty() {
            options.domain = positional.get(1).cloned().unwrap_or_default();
        }
        if options.php_version == "auto" {
            if let Some(version) = positional.get(2) {
                options.php_version = version.clone();
            }
        }
        if options.start_directory.is_empty() {
            if let Some(directory) = positional.get(3) {
                options.start_directory = directory.clone();
            }
        }

        if options.root_path.trim().is_empty() || options.domain.trim().is_empty() {
            return Err(
                "Usage: laravel-install <root-path> <domain> [php-version] [start-directory]"
                    .into(),
            );
        }
        Ok(options)
    }
}

fn value(arguments: &mut impl Iterator<Item = String>, option: &str) -> Result<String, String> {
    arguments
        .next()
        .ok_or_else(|| format!("Missing value for {option}"))
}
