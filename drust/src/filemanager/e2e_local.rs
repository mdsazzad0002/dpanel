use crate::platform::{self, accounts, paths};
use std::fs;

/// Run alone with DPANEL_PROFILE=local, a throwaway USERPROFILE and no `chown` on PATH.
#[test]
#[ignore = "needs a local profile"]
fn local_profile_end_to_end() {
    assert!(platform::current().one_user(), "run with DPANEL_PROFILE=local");
    assert!(!crate::app::program_exists("chown"), "chown must be off PATH for this to prove anything");
    let home = paths().site_home("shop");
    let at = |rel: &str| home.join(rel).display().to_string();
    println!("home {}", home.display());

    super::user::run(vec!["create".into(), "shop".into()]).unwrap();
    assert!(home.join("public_html").is_dir());
    assert!(accounts().exists("shop").unwrap());

    super::create::run_for_user("shop", &[at("public_html/app")]).unwrap();
    super::write::write_user_file("shop", &at("public_html/app/a.txt"), b"one", false).unwrap();
    super::write::write_user_file("shop", &at("public_html/app/a.txt"), b"two", true).unwrap();
    assert_eq!(fs::read(home.join("public_html/app/a.txt")).unwrap(), b"two");

    // cp -a path, then a merging move: the merge branch uses copy_tree too.
    super::filecopy::copy_user_path("shop", &at("public_html/app"), &at("public_html/copy")).unwrap();
    assert_eq!(fs::read(home.join("public_html/copy/a.txt")).unwrap(), b"two");
    super::write::write_user_file("shop", &at("public_html/copy/b.txt"), b"b", false).unwrap();
    super::filemove::move_user_path("shop", &at("public_html/copy"), &at("public_html/app")).unwrap();
    assert!(home.join("public_html/app/b.txt").is_file());
    assert!(!home.join("public_html/copy").exists());

    super::zip::create_user_archive("shop", &[at("public_html/app")], &at("out.zip"), "zip").unwrap();
    super::unzip::unzip_user_archive("shop", &at("out.zip"), Some(&at("public_html/x"))).unwrap();
    assert!(home.join("public_html/x/app/a.txt").is_file() || home.join("public_html/x/a.txt").is_file());

    let refused = super::chmod::change_permissions("shop", &at("public_html/app/a.txt"), "640", false);
    println!("chmod -> {refused:?}");
    assert!(refused.unwrap_err().contains("not supported"));

    fs::create_dir_all(home.join("app/storage")).unwrap();
    fs::write(home.join("app/storage/oauth-private.key"), "k").unwrap();
    let fixed = super::fix_permissions::run(Some("shop"), None, false).unwrap();
    println!("fixed {fixed:?}");
    assert!(home.join(".ssh").is_dir());
    let inferred = super::fix_permissions::run(None, home.to_str(), false).unwrap();
    assert_eq!(inferred.len(), 1);

    super::delete::delete_user_path("shop", &at("public_html/x")).unwrap();
    assert!(!home.join("public_html/x").exists());
    accounts().delete("shop", false).unwrap();
    assert!(!home.exists());
}
