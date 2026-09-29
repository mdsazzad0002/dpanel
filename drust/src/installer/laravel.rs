//! Laravel: create-project from the skeleton or a starter kit, whitelisted
//! artisan commands, and Breeze for Laravel 10 frontend stacks. The panel's
//! LaravelInstallJob runs create_project → artisan (env/key/migrate) → breeze
//! (Laravel 10) or npm_build → finalize.

use super::service::{LONG_TIMEOUT, Permissions, SHORT_TIMEOUT, Site};

pub(super) const PERMISSIONS: Permissions = Permissions {
    writable: &["storage", "bootstrap/cache"],
    secret_config: None,
};

/// Must stay in sync with the panel's LaravelInstallService.
const ALLOWED_ARTISAN: &[&str] = &[
    "package:discover",
    "key:generate --force",
    "migrate:fresh --force",
    "storage:link",
    "optimize",
    "optimize:clear",
    "install:api --without-migration-prompt",
];

/// Whitelisted create-project sources. Starter kits only publish tagged
/// releases for Laravel 12; their main branch tracks the current major.
/// Laravel 11 has neither, and Laravel 10 gets its stacks from Breeze.
pub(super) fn package(stack: &str, version: &str) -> Result<(&'static str, &'static str), String> {
    let base = match version {
        "13" => "^13.0",
        "12" => "^12.0",
        "11" => "^11.0",
        "10" => "^10.0",
        _ => return Err("Unsupported Laravel version.".into()),
    };
    let kit = match version {
        "13" => Some("dev-main"),
        "12" => Some("^1.0"),
        _ => None,
    };
    match stack {
        "blank" | "api" => Ok(("laravel/laravel", base)),
        // Laravel 10 frontend stacks: plain skeleton now, Breeze afterwards.
        "vue" | "react" | "livewire" if version == "10" => Ok(("laravel/laravel", base)),
        "vue" | "react" | "livewire" | "svelte" => {
            let constraint = kit.ok_or("Starter kits require Laravel 12 or newer.")?;
            if stack == "svelte" && version != "13" {
                return Err("The Svelte starter kit requires Laravel 13.".into());
            }
            Ok((
                match stack {
                    "vue" => "laravel/vue-starter-kit",
                    "react" => "laravel/react-starter-kit",
                    "livewire" => "laravel/livewire-starter-kit",
                    _ => "laravel/svelte-starter-kit",
                },
                constraint,
            ))
        }
        _ => Err("Unsupported Laravel stack.".into()),
    }
}

pub(super) async fn artisan(site: &Site, command: &str) -> Result<String, String> {
    let command = command.trim();
    if !ALLOWED_ARTISAN.contains(&command) {
        return Err("Artisan command is not allowed.".into());
    }
    require_artisan(site)?;
    let mut args = vec!["artisan"];
    args.extend(command.split_whitespace());
    args.extend(["--no-interaction", "--no-ansi"]);
    site.php(&args, SHORT_TIMEOUT).await
}

/// Laravel 10 has no starter kits; Breeze scaffolds the same stacks and runs
/// its own npm install + build.
pub(super) async fn breeze(site: &Site, stack: &str) -> Result<String, String> {
    if !matches!(stack, "vue" | "react" | "livewire") {
        return Err("Unsupported Breeze stack.".into());
    }
    require_artisan(site)?;
    let require_output = site.composer_require("laravel/breeze:^1.29", true).await?;
    let install_output = site
        .php(
            &["artisan", "breeze:install", stack, "--no-interaction", "--no-ansi"],
            LONG_TIMEOUT,
        )
        .await?;
    Ok(format!("{require_output}\n\n{install_output}"))
}

fn require_artisan(site: &Site) -> Result<(), String> {
    if site.has_file("artisan") {
        Ok(())
    } else {
        Err("Laravel artisan file was not found in the project root.".into())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn skeleton_and_starter_kits() {
        assert_eq!(package("blank", "12").unwrap(), ("laravel/laravel", "^12.0"));
        assert_eq!(package("vue", "10").unwrap(), ("laravel/laravel", "^10.0"));
        assert_eq!(package("react", "12").unwrap(), ("laravel/react-starter-kit", "^1.0"));
        assert_eq!(package("svelte", "13").unwrap(), ("laravel/svelte-starter-kit", "dev-main"));
        assert!(package("svelte", "12").is_err());
        assert!(package("vue", "11").is_err());
        assert!(package("blank", "9").is_err());
    }
}
