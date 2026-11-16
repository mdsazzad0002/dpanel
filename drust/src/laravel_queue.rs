//! Laravel queue workers for websites. Each worker definition becomes a
//! systemd template unit `dpanel-queue-{site}-{worker}@.service` running
//! `php artisan queue:work` as the site owner, with instances @1..@N enabled
//! so they come back after a reboot. `Restart=always` with no start limit
//! keeps retrying a worker that crashes or starts before the database is up.

use std::{
    fs,
    path::{Path, PathBuf},
};

use serde::{Deserialize, Serialize};

use crate::filemanager::common::{validate_account, validate_user_path};
use crate::platform::{
    self, Os,
    apps::{AppCommand, AppUnit},
    service::LogStyle,
    services,
};

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
        // specially, so only plain paths are accepted. drust's supervisor
        // passes it as one argument, with no shell.
        if !supervised()
            && !canonical_root
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
            project_root: platform::paths::plain_path(&canonical_root),
            php: php_binary(php_version)?,
        })
    }
}

#[cfg(test)]
impl QueueSite {
    pub(crate) fn for_test(site_id: &str, owner: &str, project_root: PathBuf, php: &str) -> Self {
        Self { site_id: site_id.into(), owner: owner.into(), group: owner.into(), project_root, php: php.into() }
    }
}

/// Makes the site's units match `workers`: writes each worker's template,
/// enables and starts instances 1..=processes, and stops, disables and
/// deletes everything else the site had.
pub fn apply(site: &QueueSite, workers: &[WorkerSpec]) -> Result<(), String> {
    if workers.len() > MAX_WORKERS_PER_SITE {
        return Err(format!("A website can have at most {MAX_WORKERS_PER_SITE} queue workers."));
    }
    if supervised() {
        return supervised_apply(site, workers);
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
    services().reload_definitions().map_err(|e| e.to_string())?;

    for (template, processes) in &wanted {
        // Instances beyond the new count.
        for instance in known_instances(template) {
            if instance > *processes {
                let unit = instance_name(template, instance);
                let _ = services().switch(&[&unit], false);
            }
        }
        let restart = changed_templates.contains(template);
        for instance in 1..=*processes {
            let unit = instance_name(template, instance);
            let _ = services().reset_failed(&unit);
            services().enable(&unit).map_err(|e| e.to_string())?;
            let started = if restart { services().restart(&unit) } else { services().start(&unit) };
            started.map_err(|e| e.to_string())?;
        }
    }

    Ok(())
}

/// Restarts every running worker of the site, e.g. after a deploy.
pub fn restart(site_id: &str) -> Result<(), String> {
    let site_id = valid_id(site_id, "site id")?;
    if supervised() {
        for (_, _, app) in site_apps(&site_id) {
            services().restart(&app).map_err(|e| e.to_string())?;
        }
        return Ok(());
    }
    for template in site_templates(&site_id) {
        for instance in known_instances(&template) {
            let unit = instance_name(&template, instance);
            let _ = services().reset_failed(&unit);
            services().restart(&unit).map_err(|e| e.to_string())?;
        }
    }
    Ok(())
}

/// Stops, disables and deletes all of the site's worker units.
pub fn remove_all(site_id: &str) -> Result<(), String> {
    let site_id = valid_id(site_id, "site id")?;
    if supervised() {
        for (_, _, app) in site_apps(&site_id) {
            platform::apps().remove(&app).map_err(|e| e.to_string())?;
        }
        return Ok(());
    }
    let templates = site_templates(&site_id);
    for template in &templates {
        remove_template(template)?;
    }
    if !templates.is_empty() {
        services().reload_definitions().map_err(|e| e.to_string())?;
    }
    Ok(())
}

pub fn status(site_id: &str) -> Result<Vec<WorkerStatus>, String> {
    let site_id = valid_id(site_id, "site id")?;
    if supervised() {
        return Ok(supervised_status(&site_id));
    }
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
                        active_state: services()
                            .status(&unit)
                            .map_or_else(|_| "unknown".to_string(), |status| status.state),
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
    let lines = u32::from(lines.clamp(10, 500));
    if supervised() {
        // Each instance has its own log; they follow one another, like the
        // journal's lines of several units.
        let mut text = String::new();
        for (_, instance, app) in site_apps(&site_id).into_iter().filter(|(worker, ..)| *worker == worker_id) {
            let log = services().logs(&app, lines, LogStyle::Timestamped).map_err(|e| e.to_string())?;
            text.push_str(&format!("==> #{instance} <==\n{log}"));
            if !text.ends_with('\n') {
                text.push('\n');
            }
        }
        return Ok(text);
    }
    let template = template_name(&site_id, &worker_id);
    services()
        .logs(&format!("{template}*"), lines, LogStyle::Timestamped)
        .map_err(|e| e.to_string())
}

/// `queue:work` and its options, the same on every OS.
fn queue_work_args(worker: &WorkerSpec) -> Result<Vec<String>, String> {
    let mut args = vec!["queue:work".to_string()];
    if let Some(connection) = worker.connection.as_deref().map(str::trim).filter(|value| !value.is_empty()) {
        args.push(valid_queue_name(connection, "connection")?.to_string());
    }
    let queue = valid_queue_name(
        worker.queue.as_deref().map(str::trim).filter(|value| !value.is_empty()).unwrap_or("default"),
        "queue",
    )?;
    args.extend([
        format!("--queue={queue}"),
        format!("--sleep={}", worker.sleep.clamp(1, 60)),
        format!("--tries={}", worker.tries.clamp(1, 100)),
        format!("--timeout={}", worker.timeout.clamp(0, 3600)),
        format!("--memory={}", worker.memory.clamp(64, 4096)),
        format!("--max-time={MAX_TIME_SECONDS}"),
        "--no-interaction".to_string(),
    ]);
    Ok(args)
}

fn unit_content(site: &QueueSite, worker: &WorkerSpec) -> Result<String, String> {
    let timeout = worker.timeout.clamp(0, 3600);
    let exec = format!(
        "{php} {root}/artisan {args}",
        php = site.php,
        root = site.project_root.display(),
        args = queue_work_args(worker)?.join(" "),
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

/// Where there is no systemd, each instance is one of drust's supervised
/// apps, `dpanel-queue-{site}-{worker}-{n}`, which the supervisor restarts
/// when it exits and brings back after a reboot.
fn supervised() -> bool {
    platform::current().os() != Os::Linux
}

fn app_name(site_id: &str, worker_id: &str, instance: u16) -> String {
    format!("{}-{worker_id}-{instance}", template_prefix(site_id))
}

/// The site's supervised instances as (worker id, instance, app name).
fn site_apps(site_id: &str) -> Vec<(String, u16, String)> {
    let prefix = format!("{}-", template_prefix(site_id));
    let mut apps: Vec<_> = services()
        .unit_names(&format!("{prefix}*"))
        .unwrap_or_default()
        .into_iter()
        .filter(|name| platform::apps().exists(name))
        .filter_map(|name| parse_app_name(&prefix, &name).map(|(worker, instance)| (worker, instance, name)))
        .collect();
    apps.sort_by(|a, b| (a.0.len(), &a.0, a.1).cmp(&(b.0.len(), &b.0, b.1)));
    apps
}

/// `{prefix}{worker}-{n}`. A site id can hold `-`, so a longer site's apps
/// share this prefix only when its next part is not a worker id and number.
fn parse_app_name(prefix: &str, name: &str) -> Option<(String, u16)> {
    let (worker, instance) = name.strip_prefix(prefix)?.split_once('-')?;
    valid_worker_id(worker).ok()?;
    if instance.is_empty() || !instance.bytes().all(|byte| byte.is_ascii_digit()) {
        return None;
    }
    Some((worker.to_string(), instance.parse().ok().filter(|n| (1..=MAX_PROCESSES).contains(n))?))
}

pub(crate) fn supervised_unit(site: &QueueSite, worker: &WorkerSpec, worker_id: &str, instance: u16) -> Result<AppUnit, String> {
    let mut args = vec![site.project_root.join("artisan").to_string_lossy().into_owned()];
    args.extend(queue_work_args(worker)?);
    Ok(AppUnit {
        name: app_name(&site.site_id, worker_id, instance),
        description: format!("dPanel queue worker {} {worker_id} #{instance}", site.site_id),
        owner: site.owner.clone(),
        workdir: site.project_root.clone(),
        env: Vec::new(),
        command: AppCommand::Program { program: PathBuf::from(&site.php), args },
    })
}

fn supervised_apply(site: &QueueSite, workers: &[WorkerSpec]) -> Result<(), String> {
    let mut wanted = Vec::new();
    for worker in workers {
        let worker_id = valid_worker_id(&worker.id)?;
        for instance in 1..=worker.processes.clamp(1, MAX_PROCESSES) {
            wanted.push(supervised_unit(site, worker, &worker_id, instance)?);
        }
    }
    // Removed workers, and instances beyond a worker's new count.
    for (_, _, app) in site_apps(&site.site_id) {
        if !wanted.iter().any(|unit| unit.name == app) {
            platform::apps().remove(&app).map_err(|e| e.to_string())?;
        }
    }
    for unit in &wanted {
        let changed = platform::apps().define(unit).map_err(|e| e.to_string())?;
        let _ = services().reset_failed(&unit.name);
        services().enable(&unit.name).map_err(|e| e.to_string())?;
        let started = if changed { services().restart(&unit.name) } else { services().start(&unit.name) };
        started.map_err(|e| e.to_string())?;
    }
    Ok(())
}

fn supervised_status(site_id: &str) -> Vec<WorkerStatus> {
    let mut workers: Vec<WorkerStatus> = Vec::new();
    for (worker, instance, app) in site_apps(site_id) {
        let status = services().status(&app);
        let instance = InstanceStatus {
            instance,
            active_state: status.as_ref().map_or_else(|_| "unknown".to_string(), |status| status.state.clone()),
            enabled: status.is_ok_and(|status| status.enabled),
        };
        match workers.iter_mut().find(|status| status.id == worker) {
            Some(status) => status.instances.push(instance),
            None => workers.push(WorkerStatus { id: worker, instances: vec![instance] }),
        }
    }
    workers
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
            services().unit_names(&format!("{template}*")).unwrap_or_default(),
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
        let _ = services().switch(&[&instance_name(template, instance)], false);
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
    let binary = crate::platform::paths().php_cli(valid.then_some(version));
    if !binary.is_file() {
        return Err(format!("PHP CLI {} is not installed on this server.", binary.display()));
    }
    Ok(binary.to_string_lossy().into_owned())
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

    fn site(root: &str, php: &str) -> QueueSite {
        QueueSite {
            site_id: "site-1".into(),
            owner: "shop".into(),
            group: "shop".into(),
            project_root: root.into(),
            php: php.into(),
        }
    }

    fn worker(connection: Option<&str>) -> WorkerSpec {
        WorkerSpec {
            id: "7".into(),
            connection: connection.map(str::to_string),
            queue: Some("high,default".into()),
            processes: 2,
            tries: 3,
            timeout: 90,
            sleep: 3,
            memory: 128,
        }
    }

    /// Live servers already run this unit; a changed text restarts them all.
    #[test]
    fn the_systemd_template_is_unchanged() {
        let text = unit_content(&site("/home/shop/app", "/usr/bin/php8.3"), &worker(Some("redis"))).unwrap();
        assert!(text.contains("\nExecStart=/usr/bin/php8.3 /home/shop/app/artisan queue:work redis --queue=high,default --sleep=3 --tries=3 --timeout=90 --memory=128 --max-time=3600 --no-interaction\n"), "{text}");
        assert!(text.contains("\nTimeoutStopSec=120\n"));
        let text = unit_content(&site("/home/shop/app", "/usr/bin/php8.3"), &worker(None)).unwrap();
        assert!(text.contains("artisan queue:work --queue=high,default "), "{text}");
    }

    #[test]
    fn a_supervised_instance_runs_artisan_with_no_shell() {
        let unit = supervised_unit(&site(r"C:\sites\shop app", r"C:\php\8.3\php.exe"), &worker(Some("redis")), "7", 2).unwrap();
        assert_eq!(unit.name, "dpanel-queue-site-1-7-2");
        assert_eq!(unit.workdir, Path::new(r"C:\sites\shop app"));
        let AppCommand::Program { program, args } = unit.command else { panic!() };
        assert_eq!(program, Path::new(r"C:\php\8.3\php.exe"));
        assert_eq!(args[0], Path::new(r"C:\sites\shop app").join("artisan").to_string_lossy());
        assert_eq!(&args[1..3], ["queue:work", "redis"]);
        assert_eq!(args.last().unwrap(), "--no-interaction");
        assert!(supervised_unit(&site("x", "php"), &WorkerSpec { queue: Some("a;b".into()), ..worker(None) }, "7", 1).is_err());
    }

    #[test]
    fn supervised_names_belong_to_one_site() {
        let prefix = format!("{}-", template_prefix("a"));
        assert_eq!(parse_app_name(&prefix, "dpanel-queue-a-7-2"), Some(("7".into(), 2)));
        // Site `a-1`'s worker 7, instance 2.
        assert_eq!(parse_app_name(&prefix, "dpanel-queue-a-1-7-2"), None);
        assert_eq!(parse_app_name(&prefix, "dpanel-queue-a-7-0"), None);
        assert_eq!(parse_app_name(&prefix, "dpanel-queue-a-7-99"), None);
        assert_eq!(parse_app_name(&prefix, "dpanel-queue-a-x-1"), None);
        assert_eq!(app_name("a", "7", 2), "dpanel-queue-a-7-2");
    }
}
