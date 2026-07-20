use std::path::Path;

use super::options::Options;
use crate::{
    app::{ensure_root, info, run_status},
    scripts,
};

pub(super) fn run(options: Options) -> Result<(), String> {
    ensure_root()?;

    if !options.no_demo {
        let mut args = vec![
            options.root_path.clone(),
            options.domain.clone(),
            options.php_version.clone(),
        ];
        if !options.start_directory.is_empty() {
            args.push(options.start_directory.clone());
        }
        scripts::run_script("create-demo-site.sh", &args)?;
    }

    if !options.no_db {
        if let (Some(name), Some(user), Some(password)) = (
            options.db_name.as_ref(),
            options.db_user.as_ref(),
            options.db_password.as_ref(),
        ) {
            scripts::run_script(
                "database-request.sh",
                &[
                    "create".into(),
                    name.clone(),
                    user.clone(),
                    password.clone(),
                    options.db_host.clone(),
                    options.db_port.clone(),
                    options.db_charset.clone(),
                    options.db_collation.clone(),
                ],
            )?;
        }
    }

    if !options.no_vhost {
        scripts::run_script(
            "sync-vhost.sh",
            &[
                "create".into(),
                options.domain.clone(),
                options.root_path.clone(),
                options.php_version.clone(),
            ],
        )?;
    }

    if let Some(user) = options.site_user.as_ref() {
        apply_ownership(&options, user)?;
    }

    info(&format!(
        "Laravel install flow completed for {} at {}.",
        options.domain, options.root_path
    ));
    Ok(())
}

fn apply_ownership(options: &Options, user: &str) -> Result<(), String> {
    let group = options
        .site_group
        .clone()
        .unwrap_or_else(|| user.to_string());
    let owner = format!("{user}:{group}");
    run_status("chown", &["-R", &owner, &options.root_path])?;

    for path in [
        Path::new(&options.root_path).join("storage"),
        Path::new(&options.root_path).join("bootstrap/cache"),
    ] {
        if path.exists() {
            run_status("chown", &["-R", &owner, path.to_string_lossy().as_ref()])?;
            run_status("chmod", &["-R", "0775", path.to_string_lossy().as_ref()])?;
        }
    }
    info(&format!("Applied site ownership for {owner}."));
    Ok(())
}
