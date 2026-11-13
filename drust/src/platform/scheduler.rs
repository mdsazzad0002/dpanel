//! Cron jobs. `plans/platform-spec.md` section 6.13.
//!
//! Linux keeps one file per job in `/etc/cron.d`. Windows and macOS have no
//! cron that dPanel can write to, so drust keeps the jobs in its own state
//! folder and runs them itself, once a minute, as the site owner.

use super::{PlatformError, fs::FileMode};
use chrono::{Datelike, Timelike};
use serde::{Deserialize, Serialize};
use std::{
    collections::{BTreeMap, HashSet},
    fs,
    path::{Path, PathBuf},
    sync::Mutex,
    time::Duration,
};

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct CronJob {
    pub id: String,
    pub user: String,
    pub expression: String,
    pub command: String,
}

pub trait Scheduler: Send + Sync + std::fmt::Debug {
    fn upsert(&self, job: &CronJob) -> Result<(), PlatformError>;
    /// Nothing to remove is not an error.
    fn remove(&self, id: &str) -> Result<(), PlatformError>;
    /// Where drust has to run the jobs itself, which `run_timer` does.
    fn timer_store(&self) -> Option<&Path> {
        None
    }
}

fn io_error(action: &'static str) -> impl Fn(std::io::Error) -> PlatformError {
    move |error| PlatformError::from_io(action, error)
}

/// Writes through a temporary file, so a half-written job never runs.
fn write_atomically(path: &Path, content: &str, mode: FileMode) -> Result<(), PlatformError> {
    let temp = path.with_extension(format!("tmp-{}", std::process::id()));
    fs::write(&temp, content).map_err(io_error("write cron job"))?;
    super::files().set_mode(&temp, mode)?;
    fs::rename(&temp, path).map_err(io_error("activate cron job"))
}

/// `/etc/cron.d/dpanel-<id>`, which cron picks up by itself.
#[derive(Debug)]
pub struct CronDirScheduler {
    pub dir: PathBuf,
}

impl CronDirScheduler {
    fn files(&self, id: &str) -> [PathBuf; 2] {
        [
            self.dir.join(format!("dpanel-{id}")),
            // Written by the panel before it was renamed.
            self.dir.join(format!("dpanel-{id}")),
        ]
    }
}

impl Scheduler for CronDirScheduler {
    fn upsert(&self, job: &CronJob) -> Result<(), PlatformError> {
        let [path, legacy] = self.files(&job.id);
        let content = format!(
            "# Managed by dPanel; job-id={}\n{} {} {}\n",
            job.id, job.expression, job.user, job.command
        );
        write_atomically(&path, &content, FileMode::Shared)?;
        if legacy.exists() {
            fs::remove_file(&legacy).map_err(io_error("remove legacy cron job"))?;
        }
        Ok(())
    }

    fn remove(&self, id: &str) -> Result<(), PlatformError> {
        for path in self.files(id) {
            if path.exists() {
                fs::remove_file(&path).map_err(io_error("remove cron job"))?;
            }
        }
        Ok(())
    }
}

/// Every job in one private file, since a command can hold a password.
#[derive(Debug)]
pub struct TimerScheduler {
    pub store: PathBuf,
}

static STORE_LOCK: Mutex<()> = Mutex::new(());

fn load(store: &Path) -> Result<BTreeMap<String, CronJob>, PlatformError> {
    match fs::read_to_string(store) {
        Ok(text) => serde_json::from_str(&text).map_err(|error| PlatformError::Failed {
            action: "read cron jobs".into(),
            detail: format!("{} is not valid: {error}", store.display()),
        }),
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => Ok(BTreeMap::new()),
        Err(error) => Err(PlatformError::from_io("read cron jobs", error)),
    }
}

impl TimerScheduler {
    fn change(&self, edit: impl FnOnce(&mut BTreeMap<String, CronJob>)) -> Result<(), PlatformError> {
        let _guard = STORE_LOCK.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        let mut jobs = load(&self.store)?;
        edit(&mut jobs);
        if let Some(parent) = self.store.parent() {
            fs::create_dir_all(parent).map_err(io_error("create cron job folder"))?;
        }
        let text = serde_json::to_string_pretty(&jobs).expect("jobs serialize");
        write_atomically(&self.store, &text, FileMode::Private)
    }
}

impl Scheduler for TimerScheduler {
    fn upsert(&self, job: &CronJob) -> Result<(), PlatformError> {
        // Refused now, not silently skipped every minute later.
        Schedule::parse(&job.expression).map_err(|detail| PlatformError::Failed {
            action: "parse cron expression".into(),
            detail,
        })?;
        self.change(|jobs| {
            jobs.insert(job.id.clone(), job.clone());
        })
    }

    fn remove(&self, id: &str) -> Result<(), PlatformError> {
        self.change(|jobs| {
            jobs.remove(id);
        })
    }

    fn timer_store(&self) -> Option<&Path> {
        Some(&self.store)
    }
}

/// The five cron fields, each as the set of values it allows.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Schedule {
    minutes: u64,
    hours: u64,
    days: u64,
    months: u64,
    weekdays: u64,
    /// Cron's rule: when both day fields are restricted, either one matching is enough.
    either_day: bool,
}

/// One field, like `*/15`, `1-5`, `0,30` or `7`, as a bit set.
fn field(text: &str, min: u32, max: u32) -> Result<u64, String> {
    let mut bits = 0u64;
    for part in text.split(',') {
        let (range, step) = match part.split_once('/') {
            Some((range, step)) => {
                let step: u32 = step.parse().map_err(|_| format!("bad step in {part}"))?;
                if step == 0 {
                    return Err(format!("bad step in {part}"));
                }
                (range, step)
            }
            None => (part, 1),
        };
        let number = |value: &str| -> Result<u32, String> {
            let value: u32 = value
                .parse()
                .map_err(|_| format!("{value} is not a number; names and @-shortcuts are not supported"))?;
            if value < min || value > max {
                return Err(format!("{value} is outside {min}-{max}"));
            }
            Ok(value)
        };
        let (start, end) = match range {
            "*" => (min, max),
            _ => match range.split_once('-') {
                Some((start, end)) => (number(start)?, number(end)?),
                // `5/10` means from 5 to the end.
                None if step > 1 => (number(range)?, max),
                None => {
                    let value = number(range)?;
                    (value, value)
                }
            },
        };
        if start > end {
            return Err(format!("{part} runs backwards"));
        }
        for value in (start..=end).step_by(step as usize) {
            bits |= 1 << value;
        }
    }
    Ok(bits)
}

impl Schedule {
    pub fn parse(expression: &str) -> Result<Self, String> {
        let fields: Vec<&str> = expression.split_whitespace().collect();
        let [minutes, hours, days, months, weekdays] = fields[..] else {
            return Err("Cron expression must contain exactly five fields.".into());
        };
        let mut weekday_bits = field(weekdays, 0, 7)?;
        // Both 0 and 7 are Sunday.
        if weekday_bits & (1 << 7) != 0 {
            weekday_bits |= 1;
        }
        Ok(Self {
            minutes: field(minutes, 0, 59)?,
            hours: field(hours, 0, 23)?,
            days: field(days, 1, 31)?,
            months: field(months, 1, 12)?,
            weekdays: weekday_bits,
            either_day: !days.starts_with('*') && !weekdays.starts_with('*'),
        })
    }

    /// Whether the job runs in this minute.
    pub fn is_due<T: Datelike + Timelike>(&self, time: &T) -> bool {
        let has = |bits: u64, value: u32| bits & (1 << value) != 0;
        let day = has(self.days, time.day());
        let weekday = has(self.weekdays, time.weekday().num_days_from_sunday());
        let day_matches = if self.either_day { day || weekday } else { day && weekday };
        has(self.minutes, time.minute())
            && has(self.hours, time.hour())
            && has(self.months, time.month())
            && day_matches
    }
}

/// The jobs due at `time`. A job whose expression no longer parses is skipped.
pub fn due_jobs<T: Datelike + Timelike>(store: &Path, time: &T) -> Result<Vec<CronJob>, PlatformError> {
    let jobs = {
        let _guard = STORE_LOCK.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
        load(store)?
    };
    Ok(jobs
        .into_values()
        .filter(|job| Schedule::parse(&job.expression).is_ok_and(|schedule| schedule.is_due(time)))
        .collect())
}

/// The shell a cron line is written for.
fn shell_command(owner: &str, line: &str) -> Result<std::process::Command, PlatformError> {
    let (shell, flag) = if cfg!(windows) { ("cmd", "/C") } else { ("/bin/sh", "-c") };
    let mut command = super::current().runner().command(owner, std::ffi::OsStr::new(shell))?;
    command.arg(flag).arg(line);
    command
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null());
    Ok(command)
}

/// Jobs still running from an earlier minute, which are not started again.
static RUNNING: Mutex<Option<HashSet<String>>> = Mutex::new(None);

/// Claims `id`; `false` when its last run has not finished.
fn claim(id: &str) -> bool {
    let mut running = RUNNING.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    running.get_or_insert_with(HashSet::new).insert(id.to_string())
}

fn release(id: &str) {
    let mut running = RUNNING.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    if let Some(running) = running.as_mut() {
        running.remove(id);
    }
}

fn start(job: CronJob) {
    if !claim(&job.id) {
        eprintln!("[WARN] cron job {} is still running; this run is skipped", job.id);
        return;
    }
    std::thread::spawn(move || {
        let result = shell_command(&job.user, &job.command)
            .map_err(|error| error.to_string())
            .and_then(|mut command| command.status().map_err(|error| error.to_string()));
        match result {
            Ok(status) if !status.success() => {
                eprintln!("[WARN] cron job {} exited with {status}", job.id)
            }
            Err(error) => eprintln!("[WARN] cron job {} did not start: {error}", job.id),
            Ok(_) => {}
        }
        release(&job.id);
    });
}

/// Runs the due jobs at the start of every minute, for as long as drust runs.
/// A minute drust was not running in is not made up for, as with cron.
pub async fn run_timer(store: PathBuf) {
    loop {
        let now = chrono::Local::now();
        let wait = 60 - u64::from(now.second());
        tokio::time::sleep(Duration::from_secs(wait)).await;
        let now = chrono::Local::now();
        match due_jobs(&store, &now) {
            Ok(jobs) => jobs.into_iter().for_each(start),
            Err(error) => eprintln!("[WARN] cron jobs not read: {error}"),
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use chrono::NaiveDate;

    fn at(day: u32, hour: u32, minute: u32) -> chrono::NaiveDateTime {
        // 2026-11-01 is a Sunday.
        NaiveDate::from_ymd_opt(2026, 11, day).unwrap().and_hms_opt(hour, minute, 0).unwrap()
    }

    fn due(expression: &str, time: chrono::NaiveDateTime) -> bool {
        Schedule::parse(expression).unwrap().is_due(&time)
    }

    #[test]
    fn steps_ranges_and_lists() {
        assert!(due("*/15 * * * *", at(2, 3, 45)));
        assert!(!due("*/15 * * * *", at(2, 3, 46)));
        assert!(due("0 9-17 * * 1-5", at(2, 12, 0)));
        assert!(!due("0 9-17 * * 1-5", at(1, 12, 0)));
        assert!(due("5,35 * * * *", at(2, 0, 35)));
        assert!(due("10/20 * * * *", at(2, 0, 50)));
        assert!(!due("10/20 * * * *", at(2, 0, 0)));
    }

    #[test]
    fn sunday_is_both_zero_and_seven() {
        assert!(due("0 0 * * 7", at(1, 0, 0)));
        assert!(due("0 0 * * 0", at(1, 0, 0)));
        assert!(!due("0 0 * * 7", at(2, 0, 0)));
    }

    #[test]
    fn two_restricted_day_fields_match_either_one() {
        // The 15th, or any Monday.
        assert!(due("0 0 15 * 1", at(15, 0, 0)));
        assert!(due("0 0 15 * 1", at(2, 0, 0)));
        assert!(!due("0 0 15 * 1", at(3, 0, 0)));
        // A star in one keeps both needed.
        assert!(!due("0 0 15 * *", at(2, 0, 0)));
    }

    #[test]
    fn what_the_timer_cannot_run_is_refused() {
        for bad in ["* * * *", "@daily", "0 0 * * mon", "60 * * * *", "*/0 * * * *", "5-1 * * * *"] {
            assert!(Schedule::parse(bad).is_err(), "{bad}");
        }
    }

    fn job(id: &str, expression: &str) -> CronJob {
        CronJob { id: id.into(), user: "shop".into(), expression: expression.into(), command: "true".into() }
    }

    #[test]
    fn the_timer_store_keeps_jobs_and_finds_the_due_ones() {
        let dir = std::env::temp_dir().join(format!("dpanel-timer-{}", std::process::id()));
        let scheduler = TimerScheduler { store: dir.join("cron-jobs.json") };
        scheduler.upsert(&job("a", "0 * * * *")).unwrap();
        scheduler.upsert(&job("b", "30 * * * *")).unwrap();
        scheduler.upsert(&job("a", "15 * * * *")).unwrap();
        assert!(scheduler.upsert(&job("c", "@hourly")).is_err());
        let ids = |minute| {
            due_jobs(&scheduler.store, &at(2, 1, minute))
                .unwrap()
                .into_iter()
                .map(|job| job.id)
                .collect::<Vec<_>>()
        };
        assert_eq!(ids(15), ["a"]);
        assert!(ids(0).is_empty());
        scheduler.remove("b").unwrap();
        scheduler.remove("missing").unwrap();
        assert!(ids(30).is_empty());
        let _ = fs::remove_dir_all(&dir);
    }

    #[test]
    fn a_job_still_running_is_not_started_again() {
        assert!(claim("overlap-test"));
        assert!(!claim("overlap-test"));
        release("overlap-test");
        assert!(claim("overlap-test"));
        release("overlap-test");
    }

    #[test]
    fn cron_dir_files_replace_the_legacy_name() {
        let dir = std::env::temp_dir().join(format!("dpanel-crond-{}", std::process::id()));
        fs::create_dir_all(&dir).unwrap();
        fs::write(dir.join("dpanel-7"), "old").unwrap();
        let scheduler = CronDirScheduler { dir: dir.clone() };
        scheduler.upsert(&job("7", "0 * * * *")).unwrap();
        assert_eq!(
            fs::read_to_string(dir.join("dpanel-7")).unwrap(),
            "# Managed by dPanel; job-id=7\n0 * * * * shop true\n"
        );
        assert!(!dir.join("dpanel-7").exists());
        scheduler.remove("7").unwrap();
        assert!(!dir.join("dpanel-7").exists());
        let _ = fs::remove_dir_all(&dir);
    }
}
