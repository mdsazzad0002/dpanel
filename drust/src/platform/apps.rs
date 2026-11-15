//! A site's own long-running program, such as a Node or Rust app (backlog
//! C3): one definition, kept by whatever runs programs here. On Linux that
//! is a systemd unit in `/etc/systemd/system`, as before; on Windows it is
//! drust's own supervisor, which remembers it across restarts. Starting,
//! stopping and status then go through `platform::services()` by name on
//! every OS.
//!
//! On a PC the app runs as the logged-in user, as every site does there. A
//! non-Linux server cannot yet run it as the site's own account, so there it
//! is refused rather than run as SYSTEM.

use std::{
    ffi::OsString,
    fs,
    path::{Path, PathBuf},
    sync::Arc,
};

use super::{
    PlatformError,
    capabilities::Capability,
    supervisor::{EnvPolicy, ProgramSpec, Supervisor},
};

#[derive(Debug, Clone, PartialEq, Eq)]
pub enum AppCommand {
    /// A program and plain arguments.
    Program { program: PathBuf, args: Vec<String> },
    /// A command line the site owner wrote, run by a shell.
    Shell(String),
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct AppUnit {
    /// Also the service name: `dpanel-node-<site>`.
    pub name: String,
    pub description: String,
    pub owner: String,
    pub workdir: PathBuf,
    /// In order; `PORT`, `HOST` and the like.
    pub env: Vec<(String, String)>,
    pub command: AppCommand,
}

pub trait AppManager: Send + Sync + std::fmt::Debug {
    /// Saves the definition; `true` when it changed. Does not start it.
    fn define(&self, unit: &AppUnit) -> Result<bool, PlatformError>;
    /// Stops the app and deletes its definition; `false` when there was none.
    fn remove(&self, name: &str) -> Result<bool, PlatformError>;
    fn exists(&self, name: &str) -> bool;
}

const UNIT_DIR: &str = "/etc/systemd/system";

/// systemd units, written only when their text changes.
#[derive(Debug)]
pub struct SystemdApps;

fn unit_path(name: &str) -> PathBuf {
    Path::new(UNIT_DIR).join(format!("{name}.service"))
}

/// What systemd reads as one word: as is when plain, else single-quoted.
fn systemd_word(word: &str) -> String {
    let plain = !word.is_empty()
        && word.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"/._+-:=,@".contains(&byte));
    if plain { word.to_string() } else { format!("'{}'", word.replace('\\', "\\\\").replace('\'', "\\'")) }
}

/// The unit's whole text. `Environment=` lines are quoted when the value has
/// a space, so systemd keeps it as one variable.
pub fn systemd_unit(unit: &AppUnit) -> String {
    let exec = match &unit.command {
        AppCommand::Program { program, args } => std::iter::once(program.display().to_string())
            .chain(args.iter().map(|arg| systemd_word(arg)))
            .collect::<Vec<_>>()
            .join(" "),
        AppCommand::Shell(line) => format!("/bin/bash -lc '{}'", line.replace('\'', "'\\''")),
    };
    let env: String = unit
        .env
        .iter()
        .map(|(name, value)| {
            if value.contains([' ', '"', '\\', '\'']) {
                format!("Environment=\"{name}={}\"\n", value.replace('\\', "\\\\").replace('"', "\\\""))
            } else {
                format!("Environment={name}={value}\n")
            }
        })
        .collect();
    format!(
        "; Managed dynamically by drust. Do not edit by hand.\n\
         [Unit]\n\
         Description={description}\n\
         After=network.target\n\
         \n\
         [Service]\n\
         Type=simple\n\
         User={owner}\n\
         Group={owner}\n\
         WorkingDirectory={workdir}\n\
         {env}\
         ExecStart={exec}\n\
         Restart=always\n\
         RestartSec=3\n\
         StandardOutput=journal\n\
         StandardError=journal\n\
         \n\
         [Install]\n\
         WantedBy=multi-user.target\n",
        description = unit.description,
        owner = unit.owner,
        workdir = unit.workdir.display(),
    )
}

impl AppManager for SystemdApps {
    fn define(&self, unit: &AppUnit) -> Result<bool, PlatformError> {
        let path = unit_path(&unit.name);
        let content = systemd_unit(unit);
        if fs::read_to_string(&path).ok().as_deref() == Some(content.as_str()) {
            return Ok(false);
        }
        fs::write(&path, content).map_err(|error| PlatformError::from_io("write the systemd unit", error))?;
        super::services().reload_definitions()?;
        Ok(true)
    }

    fn remove(&self, name: &str) -> Result<bool, PlatformError> {
        let path = unit_path(name);
        if !path.is_file() {
            return Ok(false);
        }
        let services = super::services();
        let _ = services.switch(&[name], false);
        fs::remove_file(&path).map_err(|error| PlatformError::from_io("remove the systemd unit", error))?;
        let _ = services.reset_failed(name);
        services.reload_definitions()?;
        Ok(true)
    }

    fn exists(&self, name: &str) -> bool {
        unit_path(name).is_file()
    }
}

/// drust's supervisor, on a PC.
#[derive(Debug)]
pub struct SupervisorApps {
    pub supervisor: Arc<Supervisor>,
    /// `cmd` on Windows, `/bin/sh` elsewhere, for `AppCommand::Shell`.
    pub shell: (PathBuf, Vec<String>),
}

/// The supervisor's form: the allowlisted environment, then the app's own.
pub fn program_spec(unit: &AppUnit, shell: &(PathBuf, Vec<String>)) -> ProgramSpec {
    let (program, args): (PathBuf, Vec<OsString>) = match &unit.command {
        AppCommand::Program { program, args } => (program.clone(), args.iter().map(OsString::from).collect()),
        AppCommand::Shell(line) => (
            shell.0.clone(),
            shell.1.iter().map(OsString::from).chain(std::iter::once(OsString::from(line))).collect(),
        ),
    };
    ProgramSpec {
        program,
        args,
        cwd: Some(unit.workdir.clone()),
        env: EnvPolicy::Clean,
        vars: unit.env.clone(),
        setup: None,
    }
}

impl AppManager for SupervisorApps {
    fn define(&self, unit: &AppUnit) -> Result<bool, PlatformError> {
        self.supervisor.define_app(&unit.name, &program_spec(unit, &self.shell))
    }

    fn remove(&self, name: &str) -> Result<bool, PlatformError> {
        self.supervisor.remove_app(name)
    }

    fn exists(&self, name: &str) -> bool {
        self.supervisor.is_app(name)
    }
}

/// Where apps cannot run as their site's own account yet.
#[derive(Debug)]
pub struct NoApps;

impl AppManager for NoApps {
    fn define(&self, _unit: &AppUnit) -> Result<bool, PlatformError> {
        Err(PlatformError::Unsupported { capability: Capability::Services })
    }

    fn remove(&self, _name: &str) -> Result<bool, PlatformError> {
        Ok(false)
    }

    fn exists(&self, _name: &str) -> bool {
        false
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn node(command: AppCommand) -> AppUnit {
        AppUnit {
            name: "dpanel-node-shop".into(),
            description: "dPanel Node.js site shop".into(),
            owner: "shop".into(),
            workdir: "/home/shop/app".into(),
            env: vec![("PORT".into(), "3000".into()), ("NODE_ENV".into(), "production".into())],
            command,
        }
    }

    #[test]
    fn systemd_gets_the_same_unit_as_before() {
        let unit = systemd_unit(&node(AppCommand::Program {
            program: "/usr/bin/node".into(),
            args: vec!["/home/shop/app/server.js".into()],
        }));
        assert_eq!(
            unit,
            "; Managed dynamically by drust. Do not edit by hand.\n[Unit]\nDescription=dPanel Node.js site shop\nAfter=network.target\n\n[Service]\nType=simple\nUser=shop\nGroup=shop\nWorkingDirectory=/home/shop/app\nEnvironment=PORT=3000\nEnvironment=NODE_ENV=production\nExecStart=/usr/bin/node /home/shop/app/server.js\nRestart=always\nRestartSec=3\nStandardOutput=journal\nStandardError=journal\n\n[Install]\nWantedBy=multi-user.target\n"
        );
    }

    #[test]
    fn systemd_quotes_what_it_would_split() {
        let unit = systemd_unit(&node(AppCommand::Shell("npm run start -- --name 'my app'".into())));
        assert!(unit.contains("ExecStart=/bin/bash -lc 'npm run start -- --name '\\''my app'\\'''\n"), "{unit}");
        let mut spaced = node(AppCommand::Program { program: "/usr/bin/node".into(), args: vec!["/home/a b/x.js".into()] });
        spaced.env.push(("GREETING".into(), "hello world".into()));
        let unit = systemd_unit(&spaced);
        assert!(unit.contains("ExecStart=/usr/bin/node '/home/a b/x.js'\n"), "{unit}");
        assert!(unit.contains("Environment=\"GREETING=hello world\"\n"), "{unit}");
    }

    #[test]
    fn the_supervisor_gets_a_clean_environment_and_the_apps_own() {
        let shell = (PathBuf::from("cmd"), vec!["/D".to_string(), "/C".to_string()]);
        let spec = program_spec(&node(AppCommand::Shell("npm start".into())), &shell);
        assert_eq!(spec.program, PathBuf::from("cmd"));
        assert_eq!(spec.args, ["/D", "/C", "npm start"].map(OsString::from));
        assert_eq!(spec.env, EnvPolicy::Clean);
        assert_eq!(spec.vars[0], ("PORT".to_string(), "3000".to_string()));
        assert_eq!(spec.cwd, Some(PathBuf::from("/home/shop/app")));

        let spec = program_spec(
            &node(AppCommand::Program { program: "node.exe".into(), args: vec!["server.js".into()] }),
            &shell,
        );
        assert_eq!((spec.program, spec.args), (PathBuf::from("node.exe"), vec![OsString::from("server.js")]));
    }
}
