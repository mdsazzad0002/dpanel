//! Website security scanner used by the dPanel Security Center.
//!
//! The API only accepts a website root and a scan type; every check below is
//! fixed in code, so the endpoint can never be turned into arbitrary command
//! execution. Findings carry stable rule ids (DP-*) that the panel maps to its
//! rule catalog, severities and score categories.

use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use std::{
    collections::BTreeMap,
    fs,
    io::Read,
    os::unix::fs::PermissionsExt,
    path::{Path, PathBuf},
    process::Command,
    time::SystemTime,
};

const ALLOWED_ROOT_PREFIXES: &[&str] = &["/home/", "/var/www/", "/srv/"];
const INTEGRITY_DIR: &str = "/var/lib/dpanel/security/integrity";
const CLAMAV_DB_DIR: &str = "/var/lib/clamav";
const DEFAULT_MAX_FILES: usize = 50_000;
const MAX_ANALYZE_BYTES: u64 = 2 * 1024 * 1024;
const MAX_FINDINGS_PER_RULE: usize = 200;
const MAX_INTEGRITY_CHANGES: usize = 200;

/// Directories that belong to package managers or caches. Their contents are
/// still checked for obfuscation and webshell signatures, but plain use of
/// shell functions there is expected and not reported.
const TRUSTED_DIRS: &[&str] = &["vendor", "node_modules", "bower_components"];
/// Skipped by integrity monitoring because they change on every deploy.
const VOLATILE_DIRS: &[&str] = &[
    "vendor",
    "node_modules",
    "cache",
    "caches",
    "tmp",
    "temp",
    "logs",
    "sessions",
];
const UPLOAD_DIRS: &[&str] = &["uploads", "upload", "user_uploads", "user-uploads"];
const PHP_EXTENSIONS: &[&str] = &[
    "php", "phtml", "php3", "php4", "php5", "php7", "php8", "pht", "phar", "inc",
];
const UNUSUAL_PHP_EXTENSIONS: &[&str] = &[
    "phtml", "php3", "php4", "php5", "php7", "php8", "pht", "phar",
];
const IMAGE_EXTENSIONS: &[&str] = &["jpg", "jpeg", "png", "gif", "ico", "bmp", "webp"];
const INTEGRITY_EXTENSIONS: &[&str] = &["js", "htaccess", "ini"];
const SENSITIVE_FILES: &[&str] = &[
    ".env",
    "wp-config.php",
    "configuration.php",
    "settings.php",
    "config.inc.php",
];

#[derive(Deserialize, Debug, Clone)]
pub struct ScanRequest {
    pub root: String,
    pub site_id: String,
    #[serde(default = "default_scan_type")]
    pub scan_type: String,
    pub max_files: Option<usize>,
}

fn default_scan_type() -> String {
    "quick".to_string()
}

#[derive(Serialize, Debug, Clone, PartialEq)]
pub struct Finding {
    pub rule_id: &'static str,
    pub severity: &'static str,
    pub category: &'static str,
    pub title: String,
    pub description: String,
    pub file_path: Option<String>,
    pub line_number: Option<usize>,
    pub evidence: Option<String>,
    pub recommendation: &'static str,
    pub auto_fix_available: bool,
}

#[derive(Serialize, Debug, Default)]
pub struct ClamavReport {
    pub available: bool,
    pub ran: bool,
    pub signature_age_hours: Option<u64>,
    pub infected: usize,
    pub error: Option<String>,
}

#[derive(Serialize, Debug, Default)]
pub struct IntegrityReport {
    pub enabled: bool,
    pub baseline_created: bool,
    pub tracked_files: usize,
    pub added: usize,
    pub modified: usize,
    pub removed: usize,
}

#[derive(Serialize, Debug, Default)]
pub struct ScanReport {
    pub root: String,
    pub scan_type: String,
    pub files_scanned: usize,
    pub bytes_scanned: u64,
    pub truncated: bool,
    pub suppressed_findings: usize,
    pub clamav: ClamavReport,
    pub integrity: IntegrityReport,
    pub findings: Vec<Finding>,
}

#[derive(Clone, Copy)]
struct Steps {
    content: bool,
    permissions: bool,
    integrity: bool,
    clamav: bool,
}

fn steps_for(scan_type: &str) -> Result<Steps, String> {
    Ok(match scan_type {
        "quick" => Steps {
            content: true,
            permissions: true,
            integrity: true,
            clamav: false,
        },
        "full" => Steps {
            content: true,
            permissions: true,
            integrity: true,
            clamav: true,
        },
        "malware" => Steps {
            content: true,
            permissions: false,
            integrity: false,
            clamav: true,
        },
        "integrity" => Steps {
            content: false,
            permissions: false,
            integrity: true,
            clamav: false,
        },
        "permissions" => Steps {
            content: false,
            permissions: true,
            integrity: false,
            clamav: false,
        },
        _ => return Err(format!("Unsupported scan type: {scan_type}")),
    })
}

pub fn run(request: &ScanRequest) -> Result<ScanReport, String> {
    let steps = steps_for(&request.scan_type)?;
    let root = validate_root(&request.root)?;
    let site_id = validate_site_id(&request.site_id)?;
    let max_files = request
        .max_files
        .unwrap_or(DEFAULT_MAX_FILES)
        .clamp(1, 500_000);

    let mut report = ScanReport {
        root: root.display().to_string(),
        scan_type: request.scan_type.clone(),
        ..Default::default()
    };
    let mut collector = Collector::default();
    let mut hashes: BTreeMap<String, String> = BTreeMap::new();

    walk(&root, max_files, &mut report, |path, relative, metadata| {
        let ext = extension(path);
        let components: Vec<String> = relative
            .components()
            .map(|c| c.as_os_str().to_string_lossy().to_lowercase())
            .collect();
        let dirs = &components[..components.len().saturating_sub(1)];
        let is_php = PHP_EXTENSIONS.contains(&ext.as_str());

        if steps.permissions {
            check_permissions(relative, metadata, &mut collector);
        }

        if steps.content {
            if is_php && dirs.iter().any(|d| UPLOAD_DIRS.contains(&d.as_str())) {
                collector.push(Finding {
                    rule_id: "DP-PHP-001",
                    severity: "high",
                    category: "php",
                    title: "Executable PHP file in upload directory".into(),
                    description: "Upload directories should only hold user content. A PHP file here can be executed by anyone who knows its URL.".into(),
                    file_path: Some(display(relative)),
                    line_number: None,
                    evidence: None,
                    recommendation: "Remove the file if you did not put it there, and disable PHP execution in upload directories.",
                    auto_fix_available: true,
                });
            }
            if UNUSUAL_PHP_EXTENSIONS.contains(&ext.as_str()) {
                collector.push(Finding {
                    rule_id: "DP-PHP-008",
                    severity: "medium",
                    category: "php",
                    title: format!("Unusual PHP extension .{ext}"),
                    description: "Alternative PHP extensions are rarely used by applications and are a common way to slip scripts past upload filters.".into(),
                    file_path: Some(display(relative)),
                    line_number: None,
                    evidence: None,
                    recommendation: "Confirm the file belongs to your application; otherwise remove it.",
                    auto_fix_available: false,
                });
            }
            let wants_content = is_php || IMAGE_EXTENSIONS.contains(&ext.as_str());
            if wants_content && metadata.len() <= MAX_ANALYZE_BYTES {
                if let Ok(bytes) = read_limited(path, MAX_ANALYZE_BYTES) {
                    report_bytes(&bytes);
                    if is_php {
                        let trusted = dirs.iter().any(|d| TRUSTED_DIRS.contains(&d.as_str()));
                        for finding in analyze_php(&bytes, trusted) {
                            collector.push(with_path(finding, relative));
                        }
                    } else if contains_php_open_tag(&bytes) {
                        collector.push(Finding {
                            rule_id: "DP-PHP-007",
                            severity: "high",
                            category: "malware",
                            title: format!("PHP code hidden in .{ext} file"),
                            description: "An image file contains a PHP open tag. This is a known technique for smuggling a webshell through image uploads.".into(),
                            file_path: Some(display(relative)),
                            line_number: None,
                            evidence: None,
                            recommendation: "Delete the file and check how it was uploaded.",
                            auto_fix_available: false,
                        });
                    }
                }
            }
        }

        if steps.integrity
            && !dirs.iter().any(|d| VOLATILE_DIRS.contains(&d.as_str()))
            && (is_php || INTEGRITY_EXTENSIONS.contains(&ext.as_str()))
        {
            if let Ok(hash) = sha256_file(path) {
                hashes.insert(display(relative), hash);
            }
        }
    })?;

    if steps.integrity {
        report.integrity = compare_integrity(&site_id, &root, hashes, &mut collector)?;
    }

    if steps.clamav {
        report.clamav = run_clamav(&root, &mut collector);
    }

    report.suppressed_findings = collector.suppressed;
    report.findings = collector.findings;
    Ok(report)
}

thread_local! {
    static BYTES_SCANNED: std::cell::Cell<u64> = const { std::cell::Cell::new(0) };
}

fn report_bytes(bytes: &[u8]) {
    BYTES_SCANNED.with(|total| total.set(total.get() + bytes.len() as u64));
}

#[derive(Default)]
struct Collector {
    findings: Vec<Finding>,
    per_rule: BTreeMap<&'static str, usize>,
    suppressed: usize,
}

impl Collector {
    fn push(&mut self, finding: Finding) {
        let count = self.per_rule.entry(finding.rule_id).or_default();
        if *count >= MAX_FINDINGS_PER_RULE {
            self.suppressed += 1;
            return;
        }
        *count += 1;
        self.findings.push(finding);
    }
}

fn validate_root(root: &str) -> Result<PathBuf, String> {
    let raw = Path::new(root);
    if !raw.is_absolute() {
        return Err("Scan root must be an absolute path.".into());
    }
    let canonical =
        fs::canonicalize(raw).map_err(|e| format!("Scan root is not accessible: {e}"))?;
    let text = canonical.to_string_lossy();
    let allowed = ALLOWED_ROOT_PREFIXES
        .iter()
        .any(|prefix| text.starts_with(prefix) && text.len() > prefix.len());
    if !allowed {
        return Err(format!(
            "Scan root must be inside {}.",
            ALLOWED_ROOT_PREFIXES.join(", ")
        ));
    }
    if !canonical.is_dir() {
        return Err("Scan root is not a directory.".into());
    }
    Ok(canonical)
}

fn validate_site_id(site_id: &str) -> Result<String, String> {
    let valid = !site_id.is_empty()
        && site_id.len() <= 64
        && site_id
            .chars()
            .all(|c| c.is_ascii_alphanumeric() || c == '-' || c == '_');
    if valid {
        Ok(site_id.to_string())
    } else {
        Err("Invalid site id.".into())
    }
}

fn walk<F>(
    root: &Path,
    max_files: usize,
    report: &mut ScanReport,
    mut visit: F,
) -> Result<(), String>
where
    F: FnMut(&Path, &Path, &fs::Metadata),
{
    BYTES_SCANNED.with(|total| total.set(0));
    let mut stack = vec![root.to_path_buf()];
    while let Some(dir) = stack.pop() {
        let Ok(entries) = fs::read_dir(&dir) else {
            continue;
        };
        for entry in entries.flatten() {
            let path = entry.path();
            // symlink_metadata: never follow links out of the website root.
            let Ok(metadata) = fs::symlink_metadata(&path) else {
                continue;
            };
            let file_type = metadata.file_type();
            if file_type.is_symlink() {
                continue;
            }
            let relative = path.strip_prefix(root).unwrap_or(&path).to_path_buf();
            if file_type.is_dir() {
                if entry.file_name() == ".git" {
                    continue;
                }
                visit(&path, &relative, &metadata);
                stack.push(path);
            } else if file_type.is_file() {
                if report.files_scanned >= max_files {
                    report.truncated = true;
                    report.bytes_scanned = BYTES_SCANNED.with(|total| total.get());
                    return Ok(());
                }
                report.files_scanned += 1;
                visit(&path, &relative, &metadata);
            }
        }
    }
    report.bytes_scanned = BYTES_SCANNED.with(|total| total.get());
    Ok(())
}

fn check_permissions(relative: &Path, metadata: &fs::Metadata, collector: &mut Collector) {
    let mode = metadata.permissions().mode();
    let is_dir = metadata.is_dir();
    // Sticky world-writable directories (like /tmp) are a deliberate setup.
    if mode & 0o002 != 0 && !(is_dir && mode & 0o1000 != 0) {
        collector.push(Finding {
            rule_id: "DP-PERM-001",
            severity: "medium",
            category: "permissions",
            title: format!("World-writable {}", if is_dir { "directory" } else { "file" }),
            description: format!(
                "Mode {:o} lets every user on the server change this {}.",
                mode & 0o7777,
                if is_dir { "directory" } else { "file" }
            ),
            file_path: Some(display(relative)),
            line_number: None,
            evidence: None,
            recommendation: "Remove write access for others (chmod o-w). Directories usually need 755 and files 644.",
            auto_fix_available: true,
        });
    }
    let name = relative
        .file_name()
        .map(|n| n.to_string_lossy().to_lowercase())
        .unwrap_or_default();
    if !is_dir && SENSITIVE_FILES.contains(&name.as_str()) && mode & 0o004 != 0 {
        collector.push(Finding {
            rule_id: "DP-PERM-002",
            severity: "medium",
            category: "permissions",
            title: format!("{name} is readable by every user"),
            description: "This file usually holds database passwords or secret keys. On a shared server other accounts can read it.".into(),
            file_path: Some(display(relative)),
            line_number: None,
            evidence: None,
            recommendation: "Restrict it to the site owner and web server group (chmod 640).",
            auto_fix_available: true,
        });
    }
}

/// Lowercased content with ASCII whitespace removed, plus the byte offset in
/// the original for every kept byte, so `eval (base64_decode (` still matches
/// and matches can be traced back to a line number.
struct Compact {
    text: Vec<u8>,
    offsets: Vec<u32>,
}

fn compact(bytes: &[u8]) -> Compact {
    let mut text = Vec::with_capacity(bytes.len());
    let mut offsets = Vec::with_capacity(bytes.len());
    for (index, byte) in bytes.iter().enumerate() {
        if byte.is_ascii_whitespace() {
            continue;
        }
        text.push(byte.to_ascii_lowercase());
        offsets.push(index as u32);
    }
    Compact { text, offsets }
}

fn find(haystack: &[u8], needle: &[u8]) -> Option<usize> {
    if needle.is_empty() || haystack.len() < needle.len() {
        return None;
    }
    haystack
        .windows(needle.len())
        .position(|window| window == needle)
}

fn contains_php_open_tag(bytes: &[u8]) -> bool {
    let lower: Vec<u8> = bytes.iter().map(u8::to_ascii_lowercase).collect();
    find(&lower, b"<?php").is_some() || find(&lower, b"<?=").is_some()
}

const DECODERS: &[&str] = &[
    "base64_decode(",
    "gzinflate(",
    "gzuncompress(",
    "gzdecode(",
    "str_rot13(",
    "convert_uudecode(",
];
const EXECUTORS: &[&str] = &["eval(", "assert(", "create_function("];
const SHELL_FUNCTIONS: &[&str] = &[
    "system(",
    "shell_exec(",
    "passthru(",
    "proc_open(",
    "popen(",
    "pcntl_exec(",
    "exec(",
];
const REQUEST_INPUTS: &[&str] = &[
    "$_get",
    "$_post",
    "$_request",
    "$_cookie",
    "$_server[\"http_",
    "$_server['http_",
    "$_files",
];
const WEBSHELL_SIGNATURES: &[&str] = &[
    "filesman",
    "c99shell",
    "r57shell",
    "wso_version",
    "b374k",
    "indoxploit",
    "alfa_data",
    "alfashell",
    "<title>minishell",
    "uname-a;id;",
];

fn analyze_php(bytes: &[u8], trusted: bool) -> Vec<Finding> {
    let compact = compact(bytes);
    let text = &compact.text;
    let mut findings = Vec::new();
    let line_of = |position: usize| -> Option<usize> {
        let original = *compact.offsets.get(position)? as usize;
        Some(
            bytes[..original.min(bytes.len())]
                .iter()
                .filter(|b| **b == b'\n')
                .count()
                + 1,
        )
    };
    let evidence_at = |position: usize| -> Option<String> {
        let original = *compact.offsets.get(position)? as usize;
        let start = bytes[..original]
            .iter()
            .rposition(|b| *b == b'\n')
            .map_or(0, |i| i + 1);
        let end = bytes[original..]
            .iter()
            .position(|b| *b == b'\n')
            .map_or(bytes.len(), |i| original + i);
        let line = String::from_utf8_lossy(&bytes[start..end])
            .trim()
            .to_string();
        Some(truncate(&line, 200))
    };

    // DP-PHP-006: known webshell markers.
    for signature in WEBSHELL_SIGNATURES {
        if let Some(position) = find(text, signature.as_bytes()) {
            findings.push(Finding {
                rule_id: "DP-PHP-006",
                severity: "critical",
                category: "malware",
                title: "Known webshell signature".into(),
                description: format!("The file contains the marker \"{signature}\" used by a publicly known PHP webshell."),
                file_path: None,
                line_number: line_of(position),
                evidence: evidence_at(position),
                recommendation: "Quarantine or delete the file, then change the site's passwords and check how it got there.",
                auto_fix_available: false,
            });
            break;
        }
    }

    // DP-PHP-002: eval of decoded data.
    let mut nested = None;
    'outer: for executor in EXECUTORS {
        for decoder in DECODERS {
            let pattern = format!("{executor}{decoder}");
            if let Some(position) = find(text, pattern.as_bytes()) {
                nested = Some(position);
                break 'outer;
            }
        }
    }
    let executor_at = EXECUTORS.iter().find_map(|e| find(text, e.as_bytes()));
    let decoder_present = DECODERS.iter().any(|d| find(text, d.as_bytes()).is_some());
    if let Some(position) = nested {
        findings.push(Finding {
            rule_id: "DP-PHP-002",
            severity: "critical",
            category: "malware",
            title: "Obfuscated code execution".into(),
            description: "Decoded data is passed straight to eval()/assert(). Legitimate applications almost never do this; it is the most common PHP malware pattern.".into(),
            file_path: None,
            line_number: line_of(position),
            evidence: evidence_at(position),
            recommendation: "Quarantine or delete the file and restore a clean copy from a backup or the original package.",
            auto_fix_available: false,
        });
    } else if let (Some(position), true, false) = (executor_at, decoder_present, trusted) {
        findings.push(Finding {
            rule_id: "DP-PHP-002",
            severity: "high",
            category: "malware",
            title: "eval() combined with decoding functions".into(),
            description: "The file uses eval()/assert() together with base64/gzip decoding, a frequent obfuscation pattern.".into(),
            file_path: None,
            line_number: line_of(position),
            evidence: evidence_at(position),
            recommendation: "Review the code. If you do not recognise it, restore a clean copy.",
            auto_fix_available: false,
        });
    }

    // DP-PHP-003: request input fed directly to an executor or shell function.
    'input: for function in EXECUTORS.iter().chain(SHELL_FUNCTIONS.iter()) {
        for input in REQUEST_INPUTS {
            let pattern = format!("{function}{input}");
            if let Some(position) = find(text, pattern.as_bytes()) {
                findings.push(Finding {
                    rule_id: "DP-PHP-003",
                    severity: "critical",
                    category: "malware",
                    title: "Request input executed as code or shell command".into(),
                    description: format!(
                        "{}) receives user-controlled input directly. This is a remote code execution backdoor.",
                        function.trim_end_matches('(')
                    ),
                    file_path: None,
                    line_number: line_of(position),
                    evidence: evidence_at(position),
                    recommendation: "Quarantine or delete the file immediately and review access logs for requests to it.",
                    auto_fix_available: false,
                });
                break 'input;
            }
        }
    }

    // DP-PHP-004: long encoded blobs.
    if let Some(position) = long_encoded_run(bytes) {
        findings.push(Finding {
            rule_id: "DP-PHP-004",
            severity: if trusted { "low" } else { "medium" },
            category: "malware",
            title: "Large encoded blob in PHP file".into(),
            description: "The file contains a very long base64 or hex-escaped string, often used to hide a malicious payload.".into(),
            file_path: None,
            line_number: Some(bytes[..position].iter().filter(|b| **b == b'\n').count() + 1),
            evidence: None,
            recommendation: "Check what the encoded data is used for. Ignore this finding if it is a known asset such as an embedded font or image.",
            auto_fix_available: false,
        });
    }

    // DP-PHP-005: shell functions in application code (expected in packages).
    if !trusted {
        let mut first = None;
        for function in SHELL_FUNCTIONS {
            if let Some(position) = find_function_call(text, function.as_bytes()) {
                first = Some(first.map_or(position, |current: usize| current.min(position)));
            }
        }
        if let Some(position) = first {
            findings.push(Finding {
                rule_id: "DP-PHP-005",
                severity: "low",
                category: "php",
                title: "Shell command function in use".into(),
                description: "The file calls a function that runs shell commands. This can be legitimate, but it is also how backdoors run commands.".into(),
                file_path: None,
                line_number: line_of(position),
                evidence: evidence_at(position),
                recommendation: "Make sure no user input reaches this call. Consider adding these functions to disable_functions if the site does not need them.",
                auto_fix_available: false,
            });
        }
    }

    findings
}

/// Finds `name(` only when it is a standalone call, so `exec(` does not match
/// `curl_exec(` or `->exec(` (PDO).
fn find_function_call(text: &[u8], name: &[u8]) -> Option<usize> {
    let mut start = 0;
    while let Some(relative) = find(&text[start..], name) {
        let position = start + relative;
        let preceding = position.checked_sub(1).map(|i| text[i]);
        let standalone = match preceding {
            None => true,
            Some(b) => {
                !(b.is_ascii_alphanumeric() || b == b'_' || b == b'>' || b == b':' || b == b'$')
            }
        };
        if standalone {
            return Some(position);
        }
        start = position + 1;
    }
    None
}

fn long_encoded_run(bytes: &[u8]) -> Option<usize> {
    const MIN_BASE64_RUN: usize = 2000;
    const MIN_HEX_ESCAPES: usize = 300;
    let mut run_start = 0;
    let mut run = 0;
    for (index, byte) in bytes.iter().enumerate() {
        if byte.is_ascii_alphanumeric() || matches!(byte, b'+' | b'/' | b'=') {
            if run == 0 {
                run_start = index;
            }
            run += 1;
            if run >= MIN_BASE64_RUN {
                return Some(run_start);
            }
        } else {
            run = 0;
        }
    }
    let escapes = bytes
        .windows(2)
        .filter(|w| w[0] == b'\\' && w[1] == b'x')
        .count();
    (escapes >= MIN_HEX_ESCAPES).then(|| bytes.windows(2).position(|w| w == b"\\x").unwrap_or(0))
}

fn with_path(mut finding: Finding, relative: &Path) -> Finding {
    finding.file_path = Some(display(relative));
    finding
}

fn display(relative: &Path) -> String {
    relative.to_string_lossy().to_string()
}

fn extension(path: &Path) -> String {
    let name = path
        .file_name()
        .map(|n| n.to_string_lossy().to_lowercase())
        .unwrap_or_default();
    if name == ".htaccess" {
        return "htaccess".into();
    }
    if name == ".user.ini" {
        return "ini".into();
    }
    path.extension()
        .map(|e| e.to_string_lossy().to_lowercase())
        .unwrap_or_default()
}

fn truncate(text: &str, max: usize) -> String {
    if text.chars().count() <= max {
        return text.to_string();
    }
    let mut out: String = text.chars().take(max).collect();
    out.push('…');
    out
}

fn read_limited(path: &Path, limit: u64) -> Result<Vec<u8>, String> {
    let file = fs::File::open(path).map_err(|e| e.to_string())?;
    let mut bytes = Vec::new();
    file.take(limit)
        .read_to_end(&mut bytes)
        .map_err(|e| e.to_string())?;
    Ok(bytes)
}

fn sha256_file(path: &Path) -> Result<String, String> {
    let mut file = fs::File::open(path).map_err(|e| e.to_string())?;
    let mut hasher = Sha256::new();
    let mut buffer = [0u8; 64 * 1024];
    loop {
        let read = file.read(&mut buffer).map_err(|e| e.to_string())?;
        if read == 0 {
            break;
        }
        hasher.update(&buffer[..read]);
    }
    Ok(format!("{:x}", hasher.finalize()))
}

#[derive(Serialize, Deserialize, Default)]
struct Baseline {
    root: String,
    updated_at: u64,
    files: BTreeMap<String, String>,
}

fn baseline_path(site_id: &str) -> PathBuf {
    Path::new(INTEGRITY_DIR).join(format!("{site_id}.json"))
}

fn compare_integrity(
    site_id: &str,
    root: &Path,
    current: BTreeMap<String, String>,
    collector: &mut Collector,
) -> Result<IntegrityReport, String> {
    let path = baseline_path(site_id);
    let root_text = root.display().to_string();
    let previous: Option<Baseline> = fs::read(&path)
        .ok()
        .and_then(|bytes| serde_json::from_slice(&bytes).ok())
        // A site moved to a new root starts a fresh baseline.
        .filter(|baseline: &Baseline| baseline.root == root_text);

    let mut report = IntegrityReport {
        enabled: true,
        tracked_files: current.len(),
        ..Default::default()
    };

    match &previous {
        None => report.baseline_created = true,
        Some(baseline) => {
            let changes = diff_baseline(&baseline.files, &current);
            report.added = changes
                .iter()
                .filter(|c| c.kind == ChangeKind::Added)
                .count();
            report.modified = changes
                .iter()
                .filter(|c| c.kind == ChangeKind::Modified)
                .count();
            report.removed = changes
                .iter()
                .filter(|c| c.kind == ChangeKind::Removed)
                .count();
            if changes.len() > MAX_INTEGRITY_CHANGES {
                collector.push(Finding {
                    rule_id: "DP-INT-004",
                    severity: "low",
                    category: "integrity",
                    title: format!("{} code files changed since the last scan", changes.len()),
                    description: format!(
                        "{} added, {} modified, {} removed. A change this large is usually a deploy or update.",
                        report.added, report.modified, report.removed
                    ),
                    file_path: None,
                    line_number: None,
                    evidence: None,
                    recommendation: "If you did not deploy or update the site, review the changes and run a full malware scan.",
                    auto_fix_available: false,
                });
            } else {
                for change in changes {
                    collector.push(change.into_finding());
                }
            }
        }
    }

    // Every scan moves the baseline forward, so each change is reported once;
    // the panel keeps the finding open until someone reviews it.
    fs::create_dir_all(INTEGRITY_DIR).map_err(|e| format!("Cannot create {INTEGRITY_DIR}: {e}"))?;
    let _ = fs::set_permissions(INTEGRITY_DIR, fs::Permissions::from_mode(0o700));
    let baseline = Baseline {
        root: root_text,
        updated_at: now_secs(),
        files: current,
    };
    let json = serde_json::to_vec(&baseline).map_err(|e| e.to_string())?;
    let tmp = path.with_extension("json.tmp");
    fs::write(&tmp, json).map_err(|e| format!("Cannot write integrity baseline: {e}"))?;
    fs::rename(&tmp, &path).map_err(|e| format!("Cannot save integrity baseline: {e}"))?;
    Ok(report)
}

#[derive(Debug, PartialEq, Clone, Copy)]
enum ChangeKind {
    Added,
    Modified,
    Removed,
}

#[derive(Debug)]
struct Change {
    kind: ChangeKind,
    path: String,
    old_hash: Option<String>,
    new_hash: Option<String>,
}

impl Change {
    fn into_finding(self) -> Finding {
        let is_php = PHP_EXTENSIONS.contains(&extension(Path::new(&self.path)).as_str());
        let (rule_id, severity, title, recommendation) = match self.kind {
            ChangeKind::Modified => (
                "DP-INT-001",
                "medium",
                "Code file modified",
                "Confirm the change was made by you or your deploy. If not, restore the file and run a full scan.",
            ),
            ChangeKind::Added => (
                "DP-INT-002",
                if is_php { "medium" } else { "low" },
                "New code file",
                "Confirm the file belongs to your application.",
            ),
            ChangeKind::Removed => (
                "DP-INT-003",
                "low",
                "Code file removed",
                "Confirm the file was removed on purpose.",
            ),
        };
        let evidence = match (&self.old_hash, &self.new_hash) {
            (Some(old), Some(new)) => Some(format!("sha256 {} -> {}", short(old), short(new))),
            (None, Some(new)) => Some(format!("sha256 {}", short(new))),
            (Some(old), None) => Some(format!("sha256 was {}", short(old))),
            (None, None) => None,
        };
        Finding {
            rule_id,
            severity,
            category: "integrity",
            title: title.into(),
            description: "Detected by comparing SHA-256 hashes with the previous scan.".into(),
            file_path: Some(self.path),
            line_number: None,
            evidence,
            recommendation,
            auto_fix_available: false,
        }
    }
}

fn short(hash: &str) -> &str {
    &hash[..hash.len().min(16)]
}

fn diff_baseline(
    previous: &BTreeMap<String, String>,
    current: &BTreeMap<String, String>,
) -> Vec<Change> {
    let mut changes = Vec::new();
    for (path, hash) in current {
        match previous.get(path) {
            None => changes.push(Change {
                kind: ChangeKind::Added,
                path: path.clone(),
                old_hash: None,
                new_hash: Some(hash.clone()),
            }),
            Some(old) if old != hash => changes.push(Change {
                kind: ChangeKind::Modified,
                path: path.clone(),
                old_hash: Some(old.clone()),
                new_hash: Some(hash.clone()),
            }),
            _ => {}
        }
    }
    for (path, hash) in previous {
        if !current.contains_key(path) {
            changes.push(Change {
                kind: ChangeKind::Removed,
                path: path.clone(),
                old_hash: Some(hash.clone()),
                new_hash: None,
            });
        }
    }
    changes
}

fn now_secs() -> u64 {
    SystemTime::now()
        .duration_since(SystemTime::UNIX_EPOCH)
        .unwrap_or_default()
        .as_secs()
}

fn find_binary(candidates: &[&str]) -> Option<String> {
    candidates
        .iter()
        .find(|candidate| Path::new(candidate).is_file())
        .map(|candidate| candidate.to_string())
}

fn signature_age_hours() -> Option<u64> {
    let newest = ["daily.cld", "daily.cvd"]
        .iter()
        .filter_map(|name| fs::metadata(Path::new(CLAMAV_DB_DIR).join(name)).ok())
        .filter_map(|m| m.modified().ok())
        .max()?;
    Some(
        SystemTime::now()
            .duration_since(newest)
            .unwrap_or_default()
            .as_secs()
            / 3600,
    )
}

fn run_clamav(root: &Path, collector: &mut Collector) -> ClamavReport {
    let mut report = ClamavReport::default();
    let Some(clamscan) = find_binary(&["/usr/bin/clamscan", "/usr/local/bin/clamscan"]) else {
        report.error =
            Some("ClamAV is not installed. Install it with: dpanel clamav install".into());
        return report;
    };
    report.available = true;

    // The daemons stay off by default, so refresh signatures on demand when
    // they are missing or older than a day. A failed refresh is not fatal.
    let stale = signature_age_hours().is_none_or(|hours| hours >= 24);
    if stale {
        if let Some(freshclam) = find_binary(&["/usr/bin/freshclam", "/usr/local/bin/freshclam"]) {
            let _ = Command::new("/usr/bin/timeout")
                .args(["300", &freshclam, "--quiet"])
                .status();
        }
    }
    report.signature_age_hours = signature_age_hours();

    let output = Command::new("/usr/bin/timeout")
        .args([
            "3600",
            &clamscan,
            "--recursive",
            "--infected",
            "--no-summary",
            "--follow-dir-symlinks=0",
            "--follow-file-symlinks=0",
            "--max-filesize=25M",
            "--max-scansize=100M",
        ])
        .arg(root)
        .output();
    let output = match output {
        Ok(output) => output,
        Err(error) => {
            report.error = Some(format!("Could not run clamscan: {error}"));
            return report;
        }
    };
    report.ran = true;
    // clamscan: 0 = clean, 1 = infections found, anything else = error.
    let code = output.status.code().unwrap_or(-1);
    if code != 0 && code != 1 {
        let stderr = String::from_utf8_lossy(&output.stderr).trim().to_string();
        report.error = Some(if code == 124 {
            "clamscan timed out after one hour.".into()
        } else if stderr.is_empty() {
            format!("clamscan exited with code {code}")
        } else {
            truncate(&stderr, 500)
        });
    }
    for (path, signature) in parse_clamscan(&String::from_utf8_lossy(&output.stdout)) {
        report.infected += 1;
        let relative = Path::new(&path)
            .strip_prefix(root)
            .map(display)
            .unwrap_or(path.clone());
        collector.push(Finding {
            rule_id: "DP-MAL-001",
            severity: "critical",
            category: "malware",
            title: "Malware detected by ClamAV".into(),
            description: format!("ClamAV matched the signature {signature}."),
            file_path: Some(relative),
            line_number: None,
            evidence: Some(signature),
            recommendation: "Quarantine or delete the file and restore a clean copy.",
            auto_fix_available: false,
        });
    }
    report
}

fn parse_clamscan(stdout: &str) -> Vec<(String, String)> {
    stdout
        .lines()
        .filter_map(|line| {
            let line = line.strip_suffix(" FOUND")?;
            let (path, signature) = line.rsplit_once(": ")?;
            Some((path.to_string(), signature.trim().to_string()))
        })
        .collect()
}

#[cfg(test)]
mod tests {
    use super::*;

    fn rules(findings: &[Finding]) -> Vec<&'static str> {
        findings.iter().map(|f| f.rule_id).collect()
    }

    #[test]
    fn detects_eval_of_decoded_payload_with_spacing() {
        let code = b"<?php\n$x = 1;\neval ( base64_decode ('ZWNobyAxOw==') );\n";
        let findings = analyze_php(code, false);
        let finding = findings.iter().find(|f| f.rule_id == "DP-PHP-002").unwrap();
        assert_eq!(finding.severity, "critical");
        assert_eq!(finding.line_number, Some(3));
        assert!(
            finding
                .evidence
                .as_deref()
                .unwrap()
                .contains("base64_decode")
        );
    }

    #[test]
    fn detects_request_input_backdoor() {
        let findings = analyze_php(b"<?php system($_GET['c']);", false);
        assert!(rules(&findings).contains(&"DP-PHP-003"));
    }

    #[test]
    fn shell_functions_in_vendor_are_not_reported() {
        let code = b"<?php $process = proc_open($cmd, $spec, $pipes);";
        assert!(rules(&analyze_php(code, false)).contains(&"DP-PHP-005"));
        assert!(analyze_php(code, true).is_empty());
    }

    #[test]
    fn exec_rule_ignores_curl_exec_and_pdo() {
        let code = b"<?php curl_exec($ch); $pdo->exec('select 1'); Foo::exec();";
        assert!(!rules(&analyze_php(code, false)).contains(&"DP-PHP-005"));
    }

    #[test]
    fn clean_laravel_file_has_no_findings() {
        let code = b"<?php\nnamespace App;\nclass A { public function b() { return base64_decode($this->c); } }\n";
        assert!(analyze_php(code, false).is_empty());
    }

    #[test]
    fn detects_webshell_signature_and_long_blob() {
        let mut code = b"<?php // FilesMan\n$a = '".to_vec();
        code.extend(std::iter::repeat_n(b'A', 2500));
        code.extend(b"';");
        let found = rules(&analyze_php(&code, false));
        assert!(found.contains(&"DP-PHP-006"));
        assert!(found.contains(&"DP-PHP-004"));
    }

    #[test]
    fn parses_clamscan_output() {
        let parsed =
            parse_clamscan("/home/a/public_html/x.php: Php.Webshell-1 FOUND\n/home/a/ok: OK\n");
        assert_eq!(
            parsed,
            vec![(
                "/home/a/public_html/x.php".to_string(),
                "Php.Webshell-1".to_string()
            )]
        );
    }

    #[test]
    fn diff_reports_added_modified_removed() {
        let previous = BTreeMap::from([
            ("a.php".to_string(), "1".to_string()),
            ("b.php".to_string(), "2".to_string()),
        ]);
        let current = BTreeMap::from([
            ("a.php".to_string(), "9".to_string()),
            ("c.php".to_string(), "3".to_string()),
        ]);
        let kinds: Vec<(String, ChangeKind)> = diff_baseline(&previous, &current)
            .into_iter()
            .map(|c| (c.path, c.kind))
            .collect();
        assert_eq!(
            kinds,
            vec![
                ("a.php".to_string(), ChangeKind::Modified),
                ("c.php".to_string(), ChangeKind::Added),
                ("b.php".to_string(), ChangeKind::Removed),
            ]
        );
    }

    #[test]
    fn rejects_roots_outside_allowed_prefixes() {
        assert!(validate_root("/etc").is_err());
        assert!(validate_root("relative/path").is_err());
        assert!(validate_site_id("../x").is_err());
        assert!(validate_site_id("9f1c-ab_2").is_ok());
    }

    #[test]
    fn scans_a_site_tree() {
        let base = Path::new("/srv");
        if fs::create_dir_all(base).is_err() {
            return;
        }
        let dir = tempfile::tempdir_in(base).unwrap();
        let root = dir.path();
        fs::create_dir_all(root.join("wp-content/uploads/2024")).unwrap();
        fs::write(
            root.join("wp-content/uploads/2024/shell.php"),
            "<?php echo 1;",
        )
        .unwrap();
        fs::write(
            root.join("wp-content/uploads/2024/cat.jpg"),
            "GIF89a<?php system($x);",
        )
        .unwrap();
        fs::write(root.join("index.php"), "<?php require 'app.php';").unwrap();
        fs::set_permissions(root.join("index.php"), fs::Permissions::from_mode(0o666)).unwrap();

        let scan = |scan_type: &str| {
            run(&ScanRequest {
                root: root.display().to_string(),
                site_id: "test-scan".into(),
                scan_type: scan_type.into(),
                max_files: None,
            })
            .unwrap()
        };

        let permissions = scan("permissions");
        assert_eq!(rules(&permissions.findings), vec!["DP-PERM-001"]);

        let malware = scan("malware");
        assert_eq!(malware.files_scanned, 3);
        let found = rules(&malware.findings);
        assert!(found.contains(&"DP-PHP-001"));
        assert!(found.contains(&"DP-PHP-007"));
        assert!(!found.contains(&"DP-PERM-001"));
    }
}
