//! Laravel queue workers for websites. Each worker definition becomes a
//! systemd template unit `dpanel-queue-{site}-{worker}@.service` running
//! `php artisan queue:work` as the site owner, with instances @1..@N enabled
//! so they come back after a reboot. `Restart=always` with no start limit
//! keeps retrying a worker that crashes or starts before the database is up.

use std::{
    fs,
    path::{Path, PathBuf},
    process::Command,
};

use serde::{Deserialize, Serialize};

use crate::filemanager::common::{validate_account, validate_user_path};

const UNIT_DIR: &str = "/etc/systemd/system";
const WANTS_DIR: &str = "/etc/systemd/system/multi-user.target.wants";
pub const MAX_PROCESSES: u16 = 16;
pub const MAX_WORKERS_PER_SITE: usize = 10;
/// Workers exit after this long and systemd starts a fresh one, so slow
/// memory leaks are bounded and deployed code is picked up within the hour.
const MAX_TIME_SECONDS: u32 = 3600;

#[derive(Clone, Debug, Deserialize)]
pub struct WorkerSpec {
    pub id: String,
    pub connection: Option<String>,
    pub queue: Option<String>,
    pub processes: u16,
    pub tries: u16,
    pub timeout: u32,
    pub sleep: u16,
    pub memory: u32,
}

#[derive(Debug, Serialize)]
pub struct WorkerStatus {
    pub id: String,
    pub instances: Vec<InstanceStatus>,
}

#[derive(Debug, Serialize)]
pub struct InstanceStatus {
    pub instance: u16,
    pub active_state: String,
    pub enabled: bool,
}

/// The site whose workers are being managed, validated once up front.
pub struct QueueSite {
    site_id: String,
    owner: String,
    group: String,
    project_root: PathBuf,
    php: String,
}

impl QueueSite {
    pub fn open(site_id: &str, owner: &str, project_root: &str, php_version: &str) -> Result<Self, String> {
        let site_id = valid_id(site_id, "site id")?;
        let (_, canonical_home, group) = validate_account(owner)?;
        let project_root = validate_user_path(owner, project_root.trim_end_matches('/'))?;
        let canonical_root = fs::canonicalize(&project_root)
            .map_err(|error| format!("project root is unavailable: {}: {error}", project_root.display()))?;
        if !canonical_root.starts_with(&canonical_home) {
            return Err("Project root resolves outside the account home.".into());
        }
        if !canonical_root.join("artisan").is_file() {
            return Err(format!("No Laravel artisan file in {}", canonical_root.display()));
        }
        // The path goes into ExecStart; systemd treats spaces, quotes and %
        // specially, so only plain paths are accepted.
        if !canonical_root
            .to_string_lossy()
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || matches!(c, '/' | '.' | '_' | '-'))
        {
            return Err("Queue workers need a project path made of letters, digits, '.', '_', '-' and '/'.".into());
        }

        Ok(Self {
            site_id,
            owner: owner.to_string(),
            group,
            project_root: canonical_root,
            php: php_binary(php_version)?,
        })
    }
}

/// Makes the site's units match `workers`: writes each worker's template,
/// enables and starts instances 1..=processes, and stops, disables and
/// deletes everything else the site had.
pub fn apply(site: &QueueSite, workers: &[WorkerSpec]) -> Result<(), String> {
    if workers.len() > MAX_WORKERS_PER_SITE {
        return Err(format!("A website can have at most {MAX_WORKERS_PER_SITE} queue workers."));
    }
    let mut wanted = Vec::with_capacity(workers.len());
    let mut changed_templates = Vec::new();
    for worker in workers {
        let worker_id = valid_worker_id(&worker.id)?;
        let template = template_name(&site.site_id, &worker_id);
        let content = unit_content(site, worker)?;
        let path = Path::new(UNIT_DIR).join(format!("{template}.service"));
        if fs::read_to_string(&path).ok().as_deref() != Some(content.as_str()) {
            fs::write(&path, &content).map_err(|error| format!("cannot write {}: {error}", path.display()))?;
            changed_templates.push(template.clone());
        }
        wanted.push((template, worker.processes.clamp(1, MAX_PROCESSES)));
    }

    // Workers that were removed from the site.
    let wanted_names: Vec<&str> = wanted.iter().map(|(name, _)| name.as_str()).collect();
    for template in site_templates(&site.site_id) {
        if !wanted_names.contains(&template.as_str()) {
            remove_template(&template)?;
        }
    }
    systemctl(&["daemon-reload"])?;

    for (template, processes) in &wanted {
        // Instances beyond the new count.
        for instance in known_instances(template) {
            if instance > *processes {
                let unit = instance_name(template, instance);
                let _ = systemctl(&["disable", "--now", &unit]);
            }
        }
        let restart = changed_templates.contains(template);
        for instance in 1..=*processes {
            let unit = instance_name(template, instance);
            let _ = systemctl(&["reset-failed", &unit]);
            systemctl(&["enable", &unit])?;
            systemctl(&[if restart { "restart" } else { "start" }, &unit])?;
        }
    }

    Ok(())
}

/// Restarts every running worker of the site, e.g. after a deploy.
pub fn restart(site_id: &str) -> Result<(), String> {
    let site_id = valid_id(site_id, "site id")?;
    for template in site_templates(&site_id) {
        for instance in known_instances(&template) {
            let unit = instance_name(&template, instance);
            let _ = systemctl(&["reset-failed", &unit]);
            systemctl(&["restart", &unit])?;
        }
    }
    Ok(())
}

/// Stops, disables and deletes all of the site's worker units.
pub fn remove_all(site_id: &str) -> Result<(), String> {
    let site_id = valid_id(site_id, "site id")?;
    let templates = site_templates(&site_id);
    for template in &templates {
        remove_template(template)?;
    }
    if !templates.is_empty() {
        systemctl(&["daemon-reload"])?;
    }
    Ok(())
}

pub fn status(site_id: &str) -> Result<Vec<WorkerStatus>, String> {
    let site_id = valid_id(site_id, "site id")?;
    let prefix = format!("{}-", template_prefix(&site_id));
    Ok(site_templates(&site_id)
        .into_iter()
        .map(|template| {
            let id = template
                .strip_prefix(&prefix)
                .and_then(|rest| rest.strip_suffix('@'))
                .unwrap_or_default()
                .to_string();
            let instances = known_instances(&template)
                .into_iter()
                .map(|instance| {
                    let unit = instance_name(&template, instance);
                    InstanceStatus {
                        instance,
                        active_state: systemctl_output(&["is-active", &unit]),
                        enabled: Path::new(WANTS_DIR).join(&unit).exists(),
                    }
                })
                .collect();
            WorkerStatus { id, instances }
        })
        .collect())
}

/// The last `lines` journal lines of one worker's instances.
pub fn logs(site_id: &str, worker_id: &str, lines: u16) -> Result<String, String> {
    let site_id = valid_id(site_id, "site id")?;
    let worker_id = valid_worker_id(worker_id)?;
    let template = template_name(&site_id, &worker_id);
    let output = Command::new("journalctl")
        .args(["--no-pager", "-o", "short-iso", "-n", &lines.clamp(10, 500).to_string(), "-u", &format!("{template}*")])
        .output()
        .map_err(|error| format!("cannot run journalctl: {error}"))?;
    Ok(String::from_utf8_lossy(&output.stdout).into_owned())
}

fn unit_content(site: &QueueSite, worker: &WorkerSpec) -> Result<String, String> {
    let connection = match worker.connection.as_deref().map(str::trim).filter(|value| !value.is_empty()) {
        Some(connection) => format!(" {}", valid_queue_name(connection, "connection")?),
        None => String::new(),
    };
    let queue = valid_queue_name(
        worker.queue.as_deref().map(str::trim).filter(|value| !value.is_empty()).unwrap_or("default"),
        "queue",
    )?;
    let timeout = worker.timeout.clamp(0, 3600);
    let exec = format!(
        "{php} {root}/artisan queue:work{connection} --queue={queue} --sleep={sleep} --tries={tries} --timeout={timeout} --memory={memory} --max-time={MAX_TIME_SECONDS} --no-interaction",
        php = site.php,
        root = site.project_root.display(),
        sleep = worker.sleep.clamp(1, 60),
        tries = worker.tries.clamp(1, 100),
        memory = worker.memory.clamp(64, 4096),
    );

    Ok(format!(
        "; Managed by dPanel (Laravel queue worker). Do not edit by hand.\n\
         [Unit]\n\
         Description=dPanel queue worker {site} {worker} #%i\n\
         After=network-online.target mariadb.service mysql.service redis-server.service postgresql.service\n\
         Wants=network-online.target\n\
         StartLimitIntervalSec=0\n\
         \n\
         [Service]\n\
         Type=simple\n\
         User={owner}\n\
         Group={group}\n\
         WorkingDirectory={root}\n\
         ExecStart={exec}\n\
         Restart=always\n\
         RestartSec=5\n\
         KillSignal=SIGTERM\n\
         TimeoutStopSec={stop}\n\
         StandardOutput=journal\n\
         StandardError=journal\n\
         \n\
         [Install]\n\
         WantedBy=multi-user.target\n",
        site = site.site_id,
        worker = worker.id,
        owner = site.owner,
        group = site.group,
        root = site.project_root.display(),
        // queue:work finishes its current job on SIGTERM; give it that long.
        stop = timeout + 30,
    ))
}

fn template_prefix(site_id: &str) -> String {
    format!("dpanel-queue-{site_id}")
}

fn template_name(site_id: &str, worker_id: &str) -> String {
    format!("{}-{worker_id}@", template_prefix(site_id))
}

fn instance_name(template: &str, instance: u16) -> String {
    format!("{template}{instance}.service")
}

/// Template names (`dpanel-queue-{site}-{worker}@`) of the site's units.
fn site_templates(site_id: &str) -> Vec<String> {
    let prefix = format!("{}-", template_prefix(site_id));
    let mut names: Vec<String> = fs::read_dir(UNIT_DIR)
        .into_iter()
        .flatten()
        .flatten()
        .filter_map(|entry| entry.file_name().into_string().ok())
        .filter_map(|name| name.strip_suffix(".service").map(str::to_string))
        .filter(|name| {
            name.strip_prefix(&prefix)
                .and_then(|rest| rest.strip_suffix('@'))
                .is_some_and(|worker| valid_worker_id(worker).is_ok())
        })
        .collect();
    names.sort();
    names
}

/// Instance numbers that are enabled or loaded for a template.
fn known_instances(template: &str) -> Vec<u16> {
    let mut instances: Vec<u16> = fs::read_dir(WANTS_DIR)
        .into_iter()
        .flatten()
        .flatten()
        .filter_map(|entry| entry.file_name().into_string().ok())
        .chain(
            systemctl_output(&["list-units", "--all", "--plain", "--no-legend", &format!("{template}*")])
                .lines()
                .filter_map(|line| line.split_whitespace().next().map(str::to_string)),
        )
        .filter_map(|unit| {
            unit.strip_prefix(template)?
                .strip_suffix(".service")?
                .parse::<u16>()
                .ok()
        })
        .collect();
    instances.sort_unstable();
    instances.dedup();
    instances
}

fn remove_template(template: &str) -> Result<(), String> {
    for instance in known_instances(template) {
        let _ = systemctl(&["disable", "--now", &instance_name(template, instance)]);
    }
    let path = Path::new(UNIT_DIR).join(format!("{template}.service"));
    match fs::remove_file(&path) {
        Ok(()) => Ok(()),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(()),
        Err(error) => Err(format!("cannot remove {}: {error}", path.display())),
    }
}

fn valid_id(value: &str, label: &str) -> Result<String, String> {
    let value = value.trim();
    if value.is_empty() || value.len() > 64 || !value.chars().all(|c| c.is_ascii_alphanumeric() || c == '-') {
        return Err(format!("invalid {label}: {value}"));
    }
    Ok(value.to_string())
}

/// Worker ids are the panel's numeric row ids.
fn valid_worker_id(value: &str) -> Result<String, String> {
    let value = value.trim();
    if value.is_empty() || value.len() > 20 || !value.chars().all(|c| c.is_ascii_digit()) {
        return Err(format!("invalid worker id: {value}"));
    }
    Ok(value.to_string())
}

fn valid_queue_name<'a>(value: &'a str, label: &str) -> Result<&'a str, String> {
    if value.len() > 191 || !value.chars().all(|c| c.is_ascii_alphanumeric() || matches!(c, '_' | '-' | '.' | ',' | ':')) {
        return Err(format!("invalid {label} name: {value}"));
    }
    Ok(value)
}

fn php_binary(version: &str) -> Result<String, String> {
    let version = version.trim();
    let valid = version.len() == 3
        && version.as_bytes()[1] == b'.'
        && version.as_bytes()[0].is_ascii_digit()
        && version.as_bytes()[2].is_ascii_digit();
    let binary = if valid { format!("/usr/bin/php{version}") } else { "/usr/bin/php".to_string() };
    if !Path::new(&binary).is_file() {
        return Err(format!("PHP CLI {binary} is not installed on this server."));
    }
    Ok(binary)
}

fn systemctl(args: &[&str]) -> Result<(), String> {
    let output = Command::new("systemctl")
        .args(args)
        .output()
        .map_err(|error| format!("cannot run systemctl {args:?}: {error}"))?;
    if output.status.success() {
        Ok(())
    } else {
        Err(format!("systemctl {args:?} failed: {}", String::from_utf8_lossy(&output.stderr).trim()))
    }
}

fn systemctl_output(args: &[&str]) -> String {
    Command::new("systemctl")
        .args(args)
        .output()
        .map(|output| String::from_utf8_lossy(&output.stdout).trim().to_string())
        .unwrap_or_else(|_| "unknown".to_string())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn rejects_unsafe_ids_and_queue_names() {
        assert!(valid_id("4457e6de-163a-4b10", "site id").is_ok());
        assert!(valid_id("../etc", "site id").is_err());
        assert!(valid_id("a b", "site id").is_err());
        assert!(valid_worker_id("12").is_ok());
        assert!(valid_worker_id("1-2").is_err());
        assert!(valid_queue_name("high,default", "queue").is_ok());
        assert!(valid_queue_name("default; rm -rf /", "queue").is_err());
        assert!(valid_queue_name("%h", "queue").is_err());
    }

    #[test]
    fn names_units_per_site_and_worker() {
        let template = template_name("site-1", "7");
        assert_eq!(template, "dpanel-queue-site-1-7@");
        assert_eq!(instance_name(&template, 2), "dpanel-queue-site-1-7@2.service");
    }
}
