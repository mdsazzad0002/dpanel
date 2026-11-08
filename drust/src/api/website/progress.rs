//! Restore progress for the panel, and the disk and stream helpers restores share.

#[allow(unused_imports)]
use super::*;

/// Stage/percent snapshot written to a JSON file the panel's restore status
/// endpoint reads, so the UI can show which step a restore is on (and which
/// one it died in) instead of a bare 500.
pub(super) struct RestoreProgress {
    path: Option<PathBuf>,
    stage: std::cell::RefCell<String>,
}

impl RestoreProgress {
    pub(super) fn new(value: Option<&str>) -> Self {
        let root = platform::paths().storage_dir().join("restore-progress");
        let path = value.map(normalize_path).filter(|path| {
            path.starts_with(root)
                && path.extension().and_then(|v| v.to_str()) == Some("json")
                && !path
                    .components()
                    .any(|part| matches!(part, Component::ParentDir))
        });
        Self {
            path,
            stage: std::cell::RefCell::new(String::new()),
        }
    }

    pub(super) fn update(&self, stage: &str, percent: u8, message: &str) {
        *self.stage.borrow_mut() = stage.to_string();
        self.write(serde_json::json!({
            "stage": stage,
            "percent": percent.min(100),
            "message": message,
        }));
    }

    pub(super) fn fail(&self, error: &str) {
        let stage = self.stage.borrow().clone();
        self.write(serde_json::json!({
            "stage": "failed",
            "failed_stage": stage,
            "message": error,
        }));
    }

    pub(super) fn write(&self, value: serde_json::Value) {
        let Some(path) = &self.path else { return };
        if let Some(parent) = path.parent() {
            let _ = fs::create_dir_all(parent);
        }
        let temp = path.with_extension("json.tmp");
        if fs::write(&temp, value.to_string()).is_ok() {
            let _ = fs::rename(&temp, path);
        }
    }
}

/// Streams `path` into `command`'s stdin, reporting byte progress between
/// `from`..`to` percent. stdout/stderr are drained on their own threads so a
/// chatty child (tar -t on a big tree) can't deadlock against our writes, and a
/// child that exits early (mysql on a bad statement) surfaces its own stderr
/// instead of a bare "Broken pipe".
pub(super) fn pipe_file_into(
    mut command: Command,
    path: &Path,
    progress: &RestoreProgress,
    stage: &str,
    label: &str,
    (from, to): (u8, u8),
) -> Result<Vec<u8>, String> {
    let mut input = fs::File::open(path).map_err(|e| format!("cannot open {label}: {e}"))?;
    let total = input.metadata().map(|m| m.len()).unwrap_or(0).max(1);
    let mut child = command
        .stdin(Stdio::piped())
        .stdout(Stdio::piped())
        .stderr(Stdio::piped())
        .spawn()
        .map_err(|e| format!("cannot start {label}: {e}"))?;
    let mut stdout = child.stdout.take().ok_or("child stdout unavailable")?;
    let mut stderr = child.stderr.take().ok_or("child stderr unavailable")?;
    let stdout_reader = std::thread::spawn(move || {
        let mut buffer = Vec::new();
        let _ = stdout.read_to_end(&mut buffer);
        buffer
    });
    let stderr_reader = std::thread::spawn(move || {
        let mut buffer = Vec::new();
        let _ = stderr.read_to_end(&mut buffer);
        buffer
    });

    let mut stdin = child.stdin.take().ok_or("child stdin unavailable")?;
    let mut buffer = vec![0u8; 1024 * 1024];
    let mut sent: u64 = 0;
    let mut last_percent = u8::MAX;
    let mut write_error = None;
    loop {
        let read = match input.read(&mut buffer) {
            Ok(0) => break,
            Ok(read) => read,
            Err(e) => {
                write_error = Some(format!("cannot read {label}: {e}"));
                break;
            }
        };
        if let Err(e) = stdin.write_all(&buffer[..read]) {
            write_error = Some(format!("{label} stopped reading input: {e}"));
            break;
        }
        sent += read as u64;
        let span = u64::from(to.saturating_sub(from));
        let percent = from + ((sent.min(total) * span) / total) as u8;
        if percent != last_percent {
            last_percent = percent;
            progress.update(
                stage,
                percent,
                &format!(
                    "{label}: {} / {}",
                    human_bytes(sent),
                    human_bytes(total)
                ),
            );
        }
    }
    drop(stdin);
    let status = child
        .wait()
        .map_err(|e| format!("cannot finish {label}: {e}"))?;
    let stdout = stdout_reader.join().unwrap_or_default();
    let stderr = stderr_reader.join().unwrap_or_default();
    if !status.success() {
        let detail = String::from_utf8_lossy(&stderr).trim().to_string();
        return Err(format!(
            "{label} failed ({status}): {}",
            if detail.is_empty() {
                write_error.unwrap_or_else(|| "no error output".into())
            } else {
                detail
            }
        ));
    }
    if let Some(error) = write_error {
        return Err(error);
    }
    Ok(stdout)
}

/// A callback for [`crate::app::archive`] that reports bytes read between
/// `from`..`to` percent, once per percent.
pub(super) fn byte_progress<'a>(
    progress: &'a RestoreProgress,
    stage: &'a str,
    label: &'a str,
    (from, to): (u8, u8),
) -> impl Fn(u64, u64) + 'a {
    let last = std::cell::Cell::new(u8::MAX);
    move |read, total| {
        let span = u64::from(to.saturating_sub(from));
        let percent = from + ((read.min(total) * span) / total.max(1)) as u8;
        if percent != last.get() {
            last.set(percent);
            progress.update(stage, percent, &format!("{label}: {} / {}", human_bytes(read), human_bytes(total)));
        }
    }
}

pub(super) fn human_bytes(bytes: u64) -> String {
    const GB: f64 = 1024.0 * 1024.0 * 1024.0;
    const MB: f64 = 1024.0 * 1024.0;
    let value = bytes as f64;
    if value >= GB {
        format!("{:.2} GB", value / GB)
    } else {
        format!("{:.1} MB", value / MB)
    }
}

/// Free bytes on the filesystem holding `path`.
pub(super) fn available_bytes(path: &Path) -> Option<u64> {
    platform::disk_space(path).map(|(_, available)| available)
}

pub(super) fn ensure_free_space(path: &Path, needed: u64, purpose: &str) -> Result<(), String> {
    match available_bytes(path) {
        Some(free) if free < needed => Err(format!(
            "not enough disk space for {purpose}: {} free on {}, about {} needed",
            human_bytes(free),
            path.display(),
            human_bytes(needed)
        )),
        _ => Ok(()),
    }
}
