//! The programs drust supervises on a PC (local profile): the gateway,
//! MariaDB and the panel's queue worker. Their names match the Linux units
//! the panel already asks about.

use std::{ffi::OsString, path::PathBuf};

use super::{Catalog, EnvPolicy, ProgramSpec, Setup};
use crate::platform::paths::Paths;

pub const EDGE_GATEWAY: &str = "edge-gateway";
pub const MARIADB: &str = "mariadb";
pub const QUEUE: &str = "dpanel-queue";

#[derive(Debug)]
pub struct LocalUnits {
    pub paths: Paths,
    /// drust itself, which also runs the gateway.
    pub drust: PathBuf,
}

impl LocalUnits {
    fn gateway(&self) -> ProgramSpec {
        ProgramSpec {
            program: self.drust.clone(),
            args: vec!["edge-gateway".into()],
            cwd: None,
            env: EnvPolicy::Inherit,
            setup: None,
        }
    }

    /// MariaDB from `drust runtime install mariadb`. Its data lives in
    /// `data_dir\mariadb`, made by `mariadb-install-db` on the first start,
    /// and it listens on 127.0.0.1 only. Its `root` has no password, as with
    /// other local stacks, so nothing but this PC can reach it.
    fn mariadb(&self) -> Result<ProgramSpec, String> {
        let home = self
            .paths
            .newest_own_install("mariadb")
            .ok_or("MariaDB is not installed; run `drust runtime install mariadb 11.8`")?;
        let data = self.paths.data_dir().join("mariadb");
        let defaults = data.join("my.ini");
        let mut defaults_arg = OsString::from("--defaults-file=");
        defaults_arg.push(&defaults);
        let mut data_arg = OsString::from("--datadir=");
        data_arg.push(&data);
        Ok(ProgramSpec {
            program: home.join("bin").join("mariadbd.exe"),
            args: vec![defaults_arg, "--console".into(), "--bind-address=127.0.0.1".into()],
            cwd: Some(home.clone()),
            env: EnvPolicy::Clean,
            setup: Some(Setup {
                program: home.join("bin").join("mariadb-install-db.exe"),
                args: vec![data_arg, "--port=3306".into()],
                creates: defaults,
            }),
        })
    }

    fn queue(&self) -> Result<ProgramSpec, String> {
        let panel = self.paths.panel_root();
        let artisan = panel.join("artisan");
        if !artisan.is_file() {
            return Err(format!("no panel at {}", panel.display()));
        }
        Ok(ProgramSpec {
            program: self.paths.php_cli(None),
            args: vec![
                artisan.into_os_string(),
                "queue:work".into(),
                "--sleep=3".into(),
                "--tries=3".into(),
                // Exits hourly so a code change is picked up; the supervisor
                // starts it again.
                "--max-time=3600".into(),
            ],
            cwd: Some(panel.to_path_buf()),
            env: EnvPolicy::Inherit,
            setup: None,
        })
    }
}

impl Catalog for LocalUnits {
    fn names(&self) -> Vec<String> {
        [EDGE_GATEWAY, MARIADB, QUEUE].map(str::to_string).to_vec()
    }

    fn spec(&self, name: &str) -> Option<Result<ProgramSpec, String>> {
        match name {
            EDGE_GATEWAY => Some(Ok(self.gateway())),
            MARIADB => Some(self.mariadb()),
            QUEUE => Some(self.queue()),
            _ => None,
        }
    }
}
