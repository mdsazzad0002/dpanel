use std::fs;
use std::io;
use std::path::{Path, PathBuf};
use std::sync::Arc;

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::Deserialize;

use crate::{
    api::{ApiResponse, ApiState, check_token},
    app::{ensure_root, program_exists, run_status, user_group, valid_username},
    platform::{
        self,
        fs::{
            FileAccess, FileMode, ModeChange, change_mode, change_mode_tree,
            owner_named as fs_owner_named, set_owner_tree,
        },
    },
};

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

#[derive(Deserialize)]
pub(crate) struct Request {
    username: Option<String>,
    root_path: Option<String>,
    all: Option<bool>,
}

pub fn run(
    username: Option<&str>,
    root_path: Option<&str>,
    all: bool,
) -> Result<Vec<String>, String> {
    ensure_root()?;
    let targets = if all {
        discover_public_html_targets()?
    } else {
        vec![resolve_target(username, root_path)?]
    };

    let mut fixed = Vec::new();
    for target in targets {
        fix_target(&target.username, &target.root_path)?;
        fixed.push(format!(
            "{}:{}",
            target.username,
            target.root_path.display()
        ));
    }

    Ok(fixed)
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match run(
        request.username.as_deref(),
        request.root_path.as_deref(),
        request.all.unwrap_or(false),
    ) {
        Ok(fixed) => ApiResponse::ok_data(
            "Permissions fixed",
            serde_json::json!({
                "fixed": fixed,
                "count": fixed.len(),
            }),
        )
        .into_response(),
        Err(error) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
    }
}

struct Target {
    username: String,
    root_path: PathBuf,
}

fn resolve_target(username: Option<&str>, root_path: Option<&str>) -> Result<Target, String> {
    let username = username
        .filter(|value| !value.trim().is_empty())
        .or_else(|| root_path.and_then(infer_username_from_home_path))
        .ok_or_else(|| "username or root_path is required unless all=true".to_string())?;

    if !valid_username(username) {
        return Err(format!("Invalid username: {username}"));
    }

    let (user_home, canonical_home, _) = validate_account(username)?;
    let path = root_path
        .map(|value| validate_user_path(username, value))
        .transpose()?
        .unwrap_or_else(|| user_home.clone());
    if path != user_home {
        return Err(format!(
            "Permission repair must start at the account home: {}",
            user_home.display()
        ));
    }
    let canonical = ensure_canonical_inside_home(&canonical_home, &path, "Root path")?;

    Ok(Target {
        username: username.to_string(),
        root_path: canonical,
    })
}

fn infer_username_from_home_path(path: &str) -> Option<&str> {
    username_under(platform::paths().sites_root(), path)
}

fn username_under<'a>(sites_root: &Path, path: &'a str) -> Option<&'a str> {
    Path::new(path)
        .strip_prefix(sites_root)
        .ok()?
        .components()
        .next()?
        .as_os_str()
        .to_str()
}

fn discover_public_html_targets() -> Result<Vec<Target>, String> {
    let mut targets = Vec::new();
    let sites_root = crate::platform::paths().sites_root();
    let homes = fs::read_dir(sites_root).map_err(|e| format!("failed to read {}: {e}", sites_root.display()))?;

    for entry in homes {
        let entry = entry.map_err(|e| format!("failed to read {} entry: {e}", sites_root.display()))?;
        let username = entry.file_name().to_string_lossy().to_string();
        if !valid_username(&username) || user_group(&username).is_err() {
            continue;
        }

        if let Ok(target) = resolve_target(Some(&username), entry.path().to_str()) {
            targets.push(target);
        }
    }

    if targets.is_empty() {
        return Err(format!("No website roots found under {}.", sites_root.display()));
    }

    Ok(targets)
}

fn fix_target(username: &str, root_path: &Path) -> Result<(), String> {
    if platform::current().one_user() {
        return fix_local_target(platform::files(), root_path);
    }
    let path = root_path.to_string_lossy();
    let files = platform::files();
    let owner = |group: &str| fs_owner_named(username, group).map_err(platform::fs::error_at(root_path));

    set_owner_tree(files, root_path, &owner("www-data")?).map_err(platform::fs::error_at(root_path))?;
    if program_exists("setfacl") {
        run_status("setfacl", &["-R", "-b", path.as_ref()])?;
        run_status("setfacl", &["-R", "-k", path.as_ref()])?;
    }
    // u+rwX,g+rX,g-w,o-rwx
    let site = ModeChange::Adjust { add: 0o640, add_exec: 0o110, remove: 0o027 };
    change_mode_tree(files, root_path, site).map_err(platform::fs::error_at(root_path))?;
    change_mode(files, root_path, ModeChange::Exact(0o750)).map_err(platform::fs::error_at(root_path))?;

    // root_path here is always the account home (see resolve_target). The
    // terminal's bwrap sandbox drops CAP_DAC_OVERRIDE before it can create
    // e.g. <home>/.ssh, so it needs "other" execute on the home dir itself
    // to traverse into it. Re-assert that after the recursive mode above.
    change_mode(files, root_path, ModeChange::Exact(0o711)).map_err(platform::fs::error_at(root_path))?;

    // The recursive mode above keeps an existing execute bit, so a Passport
    // key uploaded as 755 ends up 750, and league/oauth2-server rejects any
    // key mode outside 400/440/600/640/660. PHP-FPM runs as the site user,
    // so owner-only access is enough. As `find -path '*/storage/*'`, a
    // `storage` in the home's own path counts too.
    let mut keys = Vec::new();
    let in_storage = root_path.components().any(|part| part.as_os_str() == "storage");
    find_oauth_keys(root_path, in_storage, &mut keys)
        .map_err(|e| format!("failed to read {}: {e}", root_path.display()))?;
    for key in keys {
        change_mode(files, &key, ModeChange::Exact(0o600)).map_err(platform::fs::error_at(&key))?;
    }

    // Maildirs belong to the site user alone, as mailbox-storage creates them.
    // Dovecot copies a Maildir's group onto every new file and runs as the
    // site user, who is not in www-data, so the recursive chown above made
    // every folder CREATE fail (no Sent folder, so webmail could not save
    // sent mail). The web server has no business reading mail either.
    let mail_path = root_path.join("mail");
    if mail_path.is_dir() {
        set_owner_tree(files, &mail_path, &owner(username)?).map_err(platform::fs::error_at(&mail_path))?;
        // u+rwX,go-rwx
        let private = ModeChange::Adjust { add: 0o600, add_exec: 0o100, remove: 0o077 };
        change_mode_tree(files, &mail_path, private).map_err(platform::fs::error_at(&mail_path))?;
    }

    // The home directory is root:root 711 (traverse-only), so the sandboxed
    // site-owner process has no write access to it and can't mkdir .ssh
    // itself ("bwrap: Can't mkdir <home>/.ssh: Permission denied"). Pre-create
    // it here, owned by the site user, so it already exists before any
    // terminal session runs.
    let ssh_path = root_path.join(".ssh");
    let ssh_path_str = ssh_path.to_string_lossy();
    run_status(
        "install",
        &["-d", "-m", "0700", "-o", username, "-g", username, ssh_path_str.as_ref()],
    )?;

    Ok(())
}

/// The same repair where every site runs as the logged-in user: no owners to
/// put right, and what must stay private stays so through `FileAccess`.
fn fix_local_target(files: &dyn FileAccess, root_path: &Path) -> Result<(), String> {
    let set_mode = |path: &Path, mode: FileMode| {
        files
            .set_mode(path, mode)
            .map_err(|e| format!("failed to set mode of {}: {e}", path.display()))
    };
    set_mode(root_path, FileMode::SiteDir)?;

    let mut keys = Vec::new();
    find_oauth_keys(root_path, false, &mut keys)
        .map_err(|e| format!("failed to read {}: {e}", root_path.display()))?;
    for key in keys {
        set_mode(&key, FileMode::Private)?;
    }

    let mail_path = root_path.join("mail");
    if mail_path.is_dir() {
        set_mode(&mail_path, FileMode::PrivateDir)?;
    }

    let ssh_path = root_path.join(".ssh");
    files
        .create_dir_private(&ssh_path)
        .map_err(|e| format!("failed to create {}: {e}", ssh_path.display()))?;
    set_mode(&ssh_path, FileMode::PrivateDir)
}

/// Passport keys under a `storage` folder. A link is not followed, as `find` would not.
fn find_oauth_keys(dir: &Path, in_storage: bool, keys: &mut Vec<PathBuf>) -> io::Result<()> {
    for entry in fs::read_dir(dir)? {
        let entry = entry?;
        let name = entry.file_name();
        let kind = entry.file_type()?;
        if kind.is_dir() {
            find_oauth_keys(&entry.path(), in_storage || name == "storage", keys)?;
        } else if kind.is_file()
            && in_storage
            && (name == "oauth-private.key" || name == "oauth-public.key")
        {
            keys.push(entry.path());
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::platform::{files, fs::mode_string, os_has_no_file_modes};
    use std::process::Command;

    /// Whether anyone but the owner can reach `path`.
    fn open_to_others(path: &Path) -> bool {
        if os_has_no_file_modes() {
            let output = Command::new("icacls").arg(path).output().unwrap();
            return String::from_utf8_lossy(&output.stdout).contains("BUILTIN\\Users");
        }
        let metadata = fs::metadata(path).unwrap();
        !matches!(mode_string(files(), &metadata).as_deref(), Some("0600" | "0700"))
    }

    #[test]
    fn the_username_is_the_first_folder_under_the_sites_root() {
        let root = Path::new("/home");
        assert_eq!(username_under(root, "/home/shop"), Some("shop"));
        assert_eq!(username_under(root, "/home/shop/public_html"), Some("shop"));
        assert_eq!(username_under(root, "/home"), None);
        assert_eq!(username_under(root, "/var/www/shop"), None);
        assert_eq!(username_under(root, "/homely/shop"), None);
    }

    #[test]
    fn a_local_repair_keeps_keys_mail_and_ssh_private() {
        let dir = tempfile::tempdir().unwrap();
        let root = dir.path();
        let keys = root.join("app/storage");
        fs::create_dir_all(&keys).unwrap();
        fs::create_dir_all(root.join("mail")).unwrap();
        fs::create_dir_all(root.join("public_html")).unwrap();
        for name in ["oauth-private.key", "oauth-public.key", "readme.txt"] {
            fs::write(keys.join(name), "x").unwrap();
        }
        // Not under storage, so not one of Passport's keys.
        fs::write(root.join("public_html/oauth-private.key"), "x").unwrap();
        files().set_mode(&root.join("public_html/oauth-private.key"), FileMode::Shared).unwrap();

        fix_local_target(files(), root).unwrap();

        assert!(!open_to_others(&keys.join("oauth-private.key")));
        assert!(!open_to_others(&keys.join("oauth-public.key")));
        assert!(!open_to_others(&root.join("mail")));
        assert!(!open_to_others(&root.join(".ssh")));
        assert!(open_to_others(&root.join("public_html/oauth-private.key")));
    }

    #[test]
    fn a_local_repair_can_run_twice() {
        let dir = tempfile::tempdir().unwrap();
        fix_local_target(files(), dir.path()).unwrap();
        fix_local_target(files(), dir.path()).unwrap();
        assert!(dir.path().join(".ssh").is_dir());
    }
}
