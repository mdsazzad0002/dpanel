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

/// F7: names with spaces and Unicode, a path past Windows' old 260-character
/// limit, a file another program holds open, and the home written in
/// another case. Run like `local_profile_end_to_end`.
#[test]
#[ignore = "needs a local profile"]
fn local_profile_awkward_paths() {
    assert!(platform::current().one_user(), "run with DPANEL_PROFILE=local");
    let home = paths().site_home("odd");
    let at = |rel: &str| home.join(rel).display().to_string();
    super::user::run(vec!["create".into(), "odd".into()]).unwrap();

    // Spaces and Unicode, through every operation.
    let odd = "public_html/My Site ü 日本";
    super::create::run_for_user("odd", &[at(odd)]).unwrap();
    super::write::write_user_file("odd", &at(&format!("{odd}/naïve file.txt")), "ঢাকা".as_bytes(), false).unwrap();
    super::filecopy::copy_user_path("odd", &at(odd), &at("public_html/copy é")).unwrap();
    super::filemove::move_user_path("odd", &at("public_html/copy é"), &at("public_html/moved ñ")).unwrap();
    super::zip::create_user_archive("odd", &[at(odd)], &at("odd ü.zip"), "zip").unwrap();
    super::unzip::unzip_user_archive("odd", &at("odd ü.zip"), Some(&at("public_html/out ø"))).unwrap();
    assert_eq!(fs::read_to_string(home.join("public_html/moved ñ/naïve file.txt")).unwrap(), "ঢাকা");
    assert_eq!(fs::read_to_string(home.join("public_html/out ø/My Site ü 日本/naïve file.txt")).unwrap(), "ঢাকা");

    // Past 260 characters, written with `/` as the panel writes paths.
    let top = "public_html/a-folder-name-of-forty-characters-long-x";
    let long = format!("public_html/{}", vec!["a-folder-name-of-forty-characters-long-x"; 8].join("/"));
    assert!(at(&long).len() > 300);
    super::create::run_for_user("odd", &[at(&long)]).unwrap();
    super::write::write_user_file("odd", &at(&format!("{long}/file.txt")), b"deep", false).unwrap();
    assert_eq!(fs::read(home.join(&long).join("file.txt")).unwrap(), b"deep");
    super::filecopy::copy_user_path("odd", &at(top), &at("public_html/long copy")).unwrap();
    super::zip::create_user_archive("odd", &[at(top)], &at("long.zip"), "zip").unwrap();
    super::unzip::unzip_user_archive("odd", &at("long.zip"), Some(&at("public_html/long out"))).unwrap();
    super::delete::delete_user_path("odd", &at(top)).unwrap();
    assert!(!home.join(top).exists());

    // A file another program holds: said plainly, and the folder stays whole.
    super::create::run_for_user("odd", &[at("public_html/held")]).unwrap();
    super::write::write_user_file("odd", &at("public_html/held/open.log"), b"x", false).unwrap();
    super::write::write_user_file("odd", &at("public_html/held/b.txt"), b"x", false).unwrap();
    let lock = platform::fs::hold_exclusively(&home.join("public_html/held/open.log"));
    if lock.is_some() {
        let in_use = |result: Result<(), String>| {
            let error = result.unwrap_err();
            assert!(error.contains("open in another program"), "{error}");
        };
        in_use(super::write::write_user_file("odd", &at("public_html/held/open.log"), b"y", true));
        in_use(super::delete::delete_user_path("odd", &at("public_html/held/open.log")));
        let refused = super::delete::delete_user_path("odd", &at("public_html/held")).unwrap_err();
        assert!(refused.contains("nothing was deleted"), "{refused}");
        assert!(home.join("public_html/held/b.txt").is_file(), "the folder was half deleted");
    }
    drop(lock);
    super::delete::delete_user_path("odd", &at("public_html/held")).unwrap();

    // The home in another case is the same home where names ignore case.
    let other_case = format!("{}/case.txt", at("public_html").to_uppercase());
    let written = super::write::write_user_file("odd", &other_case, b"c", false);
    assert_eq!(written.is_ok(), platform::paths::file_names_ignore_case(), "{written:?}");

    accounts().delete("odd", false).unwrap();
    assert!(!home.exists());
}

