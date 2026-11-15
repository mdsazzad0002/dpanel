//! A PC's own name resolution and certificates (F6): `drust hosts …`,
//! `drust local-ca …`, and what `/api/v1/local/*` shares with them.

use std::{collections::BTreeSet, process::ExitCode};

use crate::platform::{self, PlatformError, hosts, local_ca};

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum HostsOutcome {
    Unchanged,
    Written,
    /// The user said no to Windows' administrator prompt.
    Declined,
}

impl HostsOutcome {
    pub fn as_str(self) -> &'static str {
        match self {
            Self::Unchanged => "unchanged",
            Self::Written => "written",
            Self::Declined => "declined",
        }
    }
}

/// Makes drust's hosts lines exactly `names`. When this process may not
/// write the file and `may_elevate`, runs `drust hosts set` as an
/// administrator, which asks the person at the screen first.
pub fn apply_hosts(names: &BTreeSet<String>, may_elevate: bool) -> Result<HostsOutcome, String> {
    match hosts::set(names) {
        Ok(hosts::Change::Unchanged) => Ok(HostsOutcome::Unchanged),
        Ok(hosts::Change::Written) => {
            hosts::flush_resolver_cache();
            Ok(HostsOutcome::Written)
        }
        Err(PlatformError::PermissionDenied { .. }) if may_elevate => {
            let exe = std::env::current_exe().map_err(|error| error.to_string())?;
            // Every name passed `valid_name`, so none needs quoting.
            let parameters = ["hosts", "set", "--no-elevate"]
                .into_iter()
                .chain(names.iter().map(String::as_str))
                .collect::<Vec<_>>()
                .join(" ");
            match platform::elevate::run(&exe, &parameters).map_err(|error| error.to_string())? {
                None => Ok(HostsOutcome::Declined),
                Some(0) => Ok(HostsOutcome::Written),
                Some(code) => Err(format!("updating the hosts file as an administrator failed (exit {code})")),
            }
        }
        Err(error) => Err(error.to_string()),
    }
}

fn fail(message: impl std::fmt::Display) -> ExitCode {
    eprintln!("{message}");
    ExitCode::from(1)
}

fn names(args: &[String]) -> Result<BTreeSet<String>, String> {
    args.iter()
        .map(|name| name.trim().to_ascii_lowercase())
        .map(|name| if hosts::valid_name(&name) { Ok(name) } else { Err(format!("not a host name: {name}")) })
        .collect()
}

pub fn hosts_cli(args: &[String]) -> ExitCode {
    let may_elevate = !args.iter().any(|arg| arg == "--no-elevate");
    let args: Vec<String> = args.iter().filter(|arg| *arg != "--no-elevate").cloned().collect();
    let current = || hosts::current().map_err(|error| error.to_string());
    let wanted = match args.split_first().map(|(action, rest)| (action.as_str(), rest)) {
        Some(("list", [])) => {
            return match current() {
                Ok(names) => {
                    println!("{}", hosts::file().display());
                    for name in names {
                        println!("127.0.0.1 {name}");
                    }
                    ExitCode::SUCCESS
                }
                Err(error) => fail(error),
            };
        }
        Some(("set", rest)) => names(rest),
        Some(("add", rest)) if !rest.is_empty() => current().and_then(|mut all| {
            all.extend(names(rest)?);
            Ok(all)
        }),
        Some(("remove", rest)) if !rest.is_empty() => current().and_then(|all| {
            let gone = names(rest)?;
            Ok(all.difference(&gone).cloned().collect())
        }),
        _ => return fail("usage: drust hosts list | set <name>… | add <name>… | remove <name>…"),
    };
    let wanted = match wanted {
        Ok(wanted) => wanted,
        Err(error) => return fail(error),
    };
    match apply_hosts(&wanted, may_elevate) {
        Ok(HostsOutcome::Declined) => fail("The hosts file was not changed: the administrator prompt was declined."),
        Ok(outcome) => {
            println!("hosts file {}: {} name(s) point at 127.0.0.1", outcome.as_str(), wanted.len());
            ExitCode::SUCCESS
        }
        Err(error) => fail(error),
    }
}

pub fn local_ca_cli(args: &[String]) -> ExitCode {
    let ca = local_ca::LocalCa::for_system(platform::paths());
    match args.iter().map(String::as_str).collect::<Vec<_>>().as_slice() {
        ["path"] => match ca.ensure() {
            Ok(_) => {
                println!("{}", ca.certificate_path().display());
                ExitCode::SUCCESS
            }
            Err(error) => fail(error),
        },
        ["status"] => match local_ca::trusted(&ca) {
            Ok(trusted) => {
                println!("{} ({})", if trusted { "trusted" } else { "not trusted" }, ca.certificate_path().display());
                ExitCode::SUCCESS
            }
            Err(error) => fail(error),
        },
        ["trust"] => match local_ca::trust(&ca) {
            Ok(true) => {
                println!("The local CA is trusted for your account; Edge and Chrome accept its certificates.");
                println!("Firefox: set security.enterprise_roots.enabled to true in about:config.");
                ExitCode::SUCCESS
            }
            Ok(false) => fail("Not trusted: the confirmation was declined."),
            Err(error) => fail(error),
        },
        ["untrust"] => match local_ca::untrust(&ca) {
            Ok(removed) => {
                println!("{}", if removed { "No longer trusted." } else { "It was not trusted." });
                ExitCode::SUCCESS
            }
            Err(error) => fail(error),
        },
        _ => fail("usage: drust local-ca status | trust | untrust | path"),
    }
}
