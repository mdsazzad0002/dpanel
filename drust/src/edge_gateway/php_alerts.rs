//! A site whose PHP keeps crashing (spec section 9): the php-cgi pool answers
//! `503` and leaves an alert here. The gateway and the API are separate
//! processes on a PC, so alerts are lines in a file under drust's state
//! directory; `GET /php-pool/alerts?after=` hands them to the panel, which
//! tells the admins.

use std::{
    fs,
    io::Write as _,
    path::{Path, PathBuf},
    sync::Mutex,
    time::{SystemTime, UNIX_EPOCH},
};

use serde::{Deserialize, Serialize};

/// The file keeps this many; the panel reads every minute.
const KEPT: usize = 200;

#[derive(Debug, Clone, PartialEq, Eq, Serialize, Deserialize)]
pub struct PhpAlert {
    /// Milliseconds since 1970, made unique and rising, so an `after` from
    /// before a restart still finds the newer alerts.
    pub id: u64,
    /// The pool's name: the site's account.
    pub site: String,
    pub crashes: usize,
    pub window_seconds: u64,
}

pub fn alert_file() -> PathBuf {
    crate::platform::paths().state_dir().join("php-pool-alerts.jsonl")
}

static WRITE: Mutex<()> = Mutex::new(());

fn now_ms() -> u64 {
    SystemTime::now().duration_since(UNIX_EPOCH).map_or(0, |since| since.as_millis() as u64)
}

/// Adds an alert and drops the oldest beyond what is kept.
pub fn record(file: &Path, site: &str, crashes: usize, window_seconds: u64) -> std::io::Result<PhpAlert> {
    let _guard = WRITE.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    let mut alerts = read(file);
    let id = now_ms().max(alerts.last().map_or(0, |last| last.id + 1));
    let alert = PhpAlert { id, site: site.to_string(), crashes, window_seconds };
    alerts.push(alert.clone());
    let keep_from = alerts.len().saturating_sub(KEPT);
    if let Some(parent) = file.parent() {
        fs::create_dir_all(parent)?;
    }
    let temporary = file.with_extension("jsonl.tmp");
    let mut out = fs::File::create(&temporary)?;
    for alert in &alerts[keep_from..] {
        serde_json::to_writer(&mut out, alert)?;
        out.write_all(b"\n")?;
    }
    out.sync_all()?;
    drop(out);
    fs::rename(&temporary, file)?;
    Ok(alert)
}

/// Every alert in the file; lines it cannot read are skipped.
fn read(file: &Path) -> Vec<PhpAlert> {
    fs::read_to_string(file)
        .unwrap_or_default()
        .lines()
        .filter_map(|line| serde_json::from_str(line).ok())
        .collect()
}

/// The alerts newer than `after`, oldest first.
pub fn since(file: &Path, after: u64) -> Vec<PhpAlert> {
    read(file).into_iter().filter(|alert| alert.id > after).collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn alerts_rise_survive_and_are_read_after_an_id() {
        let dir = tempfile::tempdir().unwrap();
        let file = dir.path().join("state").join("php-pool-alerts.jsonl");
        assert!(since(&file, 0).is_empty());
        let first = record(&file, "shop", 5, 60).unwrap();
        let second = record(&file, "blog", 5, 60).unwrap();
        assert!(second.id > first.id);
        assert_eq!(since(&file, 0), vec![first.clone(), second.clone()]);
        assert_eq!(since(&file, first.id), vec![second.clone()]);
        assert!(since(&file, second.id).is_empty());
        fs::write(&file, format!("{}\nnot json\n", fs::read_to_string(&file).unwrap().trim_end())).unwrap();
        assert_eq!(since(&file, 0).len(), 2);
    }

    #[test]
    fn only_the_newest_are_kept() {
        let dir = tempfile::tempdir().unwrap();
        let file = dir.path().join("alerts.jsonl");
        for n in 0..KEPT + 5 {
            record(&file, &format!("site{n}"), 5, 60).unwrap();
        }
        let alerts = since(&file, 0);
        assert_eq!(alerts.len(), KEPT);
        assert_eq!(alerts[0].site, "site5");
    }
}
