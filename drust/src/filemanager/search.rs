//! Recursive search under a file manager root: names, or the text inside
//! files. This is what the panel's search window calls, so it answers the
//! way an editor's "quick open" and "find in files" do.
//!
//! The walk runs on shared hosting, over trees that may hold hundreds of
//! thousands of files, so every part of it is bounded: entries scanned,
//! results returned, bytes read per file, matches per file, and wall clock.
//! Whenever a bound is reached the answer says so with `truncated`, rather
//! than the request growing without end.
//!
//! Symlinks are never followed — that keeps the walk inside the account and
//! free of loops.

use std::fs;
use std::io::Read;
use std::path::PathBuf;
use std::sync::Arc;
use std::time::{Duration, Instant};

use axum::{
    extract::{Json, State},
    http::HeaderMap,
    response::{IntoResponse, Response},
};
use serde::{Deserialize, Serialize};

use crate::api::{ApiResponse, ApiState, check_token};

use super::common::{ensure_canonical_inside_home, validate_account, validate_user_path};

/// Directory entries the walk will look at before it gives up.
const MAX_SCANNED_ENTRIES: usize = 200_000;
const DEFAULT_MAX_RESULTS: usize = 200;
const MAX_RESULTS: usize = 500;
/// Files larger than this are not read for content matches.
const MAX_FILE_BYTES: u64 = 2 * 1024 * 1024;
const MAX_MATCHES_PER_FILE: usize = 20;
/// How much of a matching line travels back to the panel.
const MAX_LINE_CHARS: usize = 400;
const TIME_BUDGET: Duration = Duration::from_secs(5);
/// Folders that would otherwise drown every result list.
const NOISY_DIRS: [&str; 4] = [".git", "node_modules", ".svn", ".hg"];

#[derive(Deserialize)]
pub(crate) struct Request {
    username: String,
    base_path: String,
    /// Folder to search under, relative to `base_path`. Empty means all.
    path: Option<String>,
    query: String,
    /// "names" (the default) or "content".
    mode: Option<String>,
    case_sensitive: Option<bool>,
    whole_word: Option<bool>,
    /// Glob the file name must match, e.g. `*.php`.
    include: Option<String>,
    show_hidden: Option<bool>,
    /// Look inside .git, node_modules and friends as well.
    include_ignored: Option<bool>,
    max_results: Option<usize>,
}

#[derive(Serialize)]
struct LineMatch {
    line: usize,
    column: usize,
    length: usize,
    text: String,
}

#[derive(Serialize)]
struct Hit {
    path: String,
    name: String,
    #[serde(rename = "type")]
    kind: String,
    score: i64,
    #[serde(skip_serializing_if = "Vec::is_empty")]
    matches: Vec<LineMatch>,
}

/// `*` stands for any run of characters, `?` for one. Everything else is
/// itself, so a query without wildcards is a plain comparison.
fn glob_matches(pattern: &str, name: &str) -> bool {
    let pattern: Vec<char> = pattern.to_lowercase().chars().collect();
    let name: Vec<char> = name.to_lowercase().chars().collect();
    let (mut p, mut n) = (0_usize, 0_usize);
    let (mut star, mut mark) = (usize::MAX, 0_usize);

    while n < name.len() {
        if p < pattern.len() && (pattern[p] == '?' || pattern[p] == name[n]) {
            p += 1;
            n += 1;
        } else if p < pattern.len() && pattern[p] == '*' {
            star = p;
            mark = n;
            p += 1;
        } else if star != usize::MAX {
            p = star + 1;
            mark += 1;
            n = mark;
        } else {
            return false;
        }
    }

    while p < pattern.len() && pattern[p] == '*' {
        p += 1;
    }

    p == pattern.len()
}

fn is_word_char(c: char) -> bool {
    c.is_alphanumeric() || c == '_'
}

fn is_boundary(name: &[char], index: usize) -> bool {
    if index == 0 {
        return true;
    }

    let previous = name[index - 1];
    if matches!(previous, ' ' | '.' | '_' | '-' | '/' | '\\') {
        return true;
    }

    previous.is_lowercase() && name[index].is_uppercase()
}

/// The same shape of score the panel uses, so the order it receives already
/// makes sense before it re-ranks what it was given.
fn name_score(name: &str, query: &str) -> Option<i64> {
    let chars: Vec<char> = name.chars().collect();
    let lower: Vec<char> = name.to_lowercase().chars().collect();
    let needle: Vec<char> = query.to_lowercase().chars().collect();

    if needle.is_empty() {
        return Some(0);
    }

    // A run of characters sitting together beats the same characters spread
    // across the name.
    if let Some(at) = lower
        .windows(needle.len())
        .position(|window| window == needle.as_slice())
    {
        let mut score = 1000 - (at as i64) * 2;
        if at == 0 {
            score += 200;
        } else if is_boundary(&chars, at) {
            score += 120;
        }
        if chars.len() == needle.len() {
            score += 400;
        }

        return Some(score - (chars.len() as i64 - needle.len() as i64).max(0));
    }

    let mut score = 0_i64;
    let mut cursor = 0_usize;
    let mut previous: i64 = -2;
    for wanted in &needle {
        let found = lower[cursor..].iter().position(|c| c == wanted)? + cursor;
        score += 1;
        if found as i64 == previous + 1 {
            score += 8;
        }
        if is_boundary(&chars, found) {
            score += 12;
        }
        if found == 0 {
            score += 15;
        }
        if previous >= 0 {
            score -= (found as i64 - previous - 1).min(6);
        }
        previous = found as i64;
        cursor = found + 1;
    }

    Some(score - (chars.len() as i64) / 8)
}

/// Content is matched literally; a regular expression would need a crate,
/// and a literal needle with the case and whole-word switches covers what
/// the panel's search box offers.
fn find_in_line(line: &str, needle: &str, case_sensitive: bool, whole_word: bool) -> Vec<(usize, usize)> {
    let haystack = if case_sensitive { line.to_string() } else { line.to_lowercase() };
    let needle_owned = if case_sensitive { needle.to_string() } else { needle.to_lowercase() };
    if needle_owned.is_empty() {
        return Vec::new();
    }

    let mut hits = Vec::new();
    let mut from = 0_usize;
    while let Some(at) = haystack[from..].find(&needle_owned) {
        let start = from + at;
        let end = start + needle_owned.len();

        if whole_word {
            let before = haystack[..start].chars().next_back();
            let after = haystack[end..].chars().next();
            let open = before.map(is_word_char).unwrap_or(false) || after.map(is_word_char).unwrap_or(false);
            if open {
                from = start + needle_owned.chars().next().map(char::len_utf8).unwrap_or(1);
                continue;
            }
        }

        hits.push((start, end));
        if hits.len() >= MAX_MATCHES_PER_FILE {
            break;
        }
        from = end.max(start + 1);
    }

    hits
}

/// A NUL byte in the first block is how every grep decides a file is binary.
fn looks_binary(bytes: &[u8]) -> bool {
    bytes.iter().take(8192).any(|byte| *byte == 0)
}

fn char_offset(text: &str, byte_index: usize) -> usize {
    text[..byte_index].chars().count()
}

fn clip_line(line: &str) -> String {
    if line.chars().count() <= MAX_LINE_CHARS {
        return line.to_string();
    }

    line.chars().take(MAX_LINE_CHARS).collect::<String>() + "…"
}

fn search_file_content(
    path: &PathBuf,
    needle: &str,
    case_sensitive: bool,
    whole_word: bool,
) -> Option<Vec<LineMatch>> {
    let metadata = fs::symlink_metadata(path).ok()?;
    if !metadata.is_file() || metadata.len() > MAX_FILE_BYTES {
        return None;
    }

    let mut buffer = Vec::new();
    fs::File::open(path).ok()?.take(MAX_FILE_BYTES).read_to_end(&mut buffer).ok()?;
    if looks_binary(&buffer) {
        return None;
    }

    let text = String::from_utf8_lossy(&buffer);
    let mut matches = Vec::new();
    for (number, line) in text.lines().enumerate() {
        for (start, end) in find_in_line(line, needle, case_sensitive, whole_word) {
            matches.push(LineMatch {
                line: number + 1,
                column: char_offset(line, start) + 1,
                length: char_offset(line, end) - char_offset(line, start),
                text: clip_line(line.trim_end()),
            });
            if matches.len() >= MAX_MATCHES_PER_FILE {
                break;
            }
        }
        if matches.len() >= MAX_MATCHES_PER_FILE {
            break;
        }
    }

    (!matches.is_empty()).then_some(matches)
}

fn search(request: &Request) -> Result<serde_json::Value, String> {
    let query = request.query.trim().to_string();
    if query.is_empty() {
        return Err("Nothing to search for.".into());
    }

    let (_, canonical_home, _) = validate_account(&request.username)?;
    let base = validate_user_path(&request.username, &request.base_path)?;
    let base = ensure_canonical_inside_home(&canonical_home, &base, "File manager root")?;
    let relative = request.path.as_deref().unwrap_or("").trim_matches('/');
    let requested = if relative.is_empty() { base.clone() } else { base.join(relative) };
    let root = ensure_canonical_inside_home(&base, &requested, "Search folder")?;
    if !root.is_dir() {
        return Err("Requested path is not a directory.".into());
    }

    let content_mode = request.mode.as_deref() == Some("content");
    let show_hidden = request.show_hidden.unwrap_or(false);
    let include_ignored = request.include_ignored.unwrap_or(false);
    let case_sensitive = request.case_sensitive.unwrap_or(false);
    let whole_word = request.whole_word.unwrap_or(false);
    let include = request
        .include
        .as_deref()
        .map(str::trim)
        .filter(|pattern| !pattern.is_empty())
        .map(str::to_string);
    let wanted = request
        .max_results
        .unwrap_or(DEFAULT_MAX_RESULTS)
        .clamp(1, MAX_RESULTS);

    let started = Instant::now();
    let mut scanned = 0_usize;
    let mut truncated = false;
    let mut hits: Vec<Hit> = Vec::new();
    let mut stack = vec![(root.clone(), relative.to_string())];

    while let Some((directory, directory_relative)) = stack.pop() {
        if started.elapsed() > TIME_BUDGET || scanned >= MAX_SCANNED_ENTRIES {
            truncated = true;
            break;
        }

        let Ok(entries) = fs::read_dir(&directory) else {
            continue;
        };

        for entry in entries {
            let Ok(entry) = entry else { continue };
            scanned += 1;
            if scanned >= MAX_SCANNED_ENTRIES || started.elapsed() > TIME_BUDGET {
                truncated = true;
                break;
            }

            let name = entry.file_name().to_string_lossy().to_string();
            if !show_hidden && name.starts_with('.') {
                continue;
            }

            let Ok(metadata) = fs::symlink_metadata(entry.path()) else {
                continue;
            };
            if metadata.file_type().is_symlink() {
                continue;
            }

            let entry_relative = if directory_relative.is_empty() {
                name.clone()
            } else {
                format!("{directory_relative}/{name}")
            };

            if metadata.is_dir() {
                if !include_ignored && NOISY_DIRS.contains(&name.as_str()) {
                    continue;
                }
                stack.push((entry.path(), entry_relative.clone()));
            }

            if hits.len() >= wanted {
                truncated = true;
                continue;
            }

            if content_mode {
                if !metadata.is_file() {
                    continue;
                }
                if let Some(pattern) = include.as_deref() {
                    if !glob_matches(pattern, &name) {
                        continue;
                    }
                }
                if let Some(matches) =
                    search_file_content(&entry.path(), &query, case_sensitive, whole_word)
                {
                    hits.push(Hit {
                        path: entry_relative,
                        name,
                        kind: "file".into(),
                        score: 0,
                        matches,
                    });
                }

                continue;
            }

            let matched = if query.contains('*') || query.contains('?') {
                glob_matches(&query, &name).then_some(0)
            } else {
                name_score(&name, &query)
            };

            let Some(score) = matched else { continue };
            if let Some(pattern) = include.as_deref() {
                if !glob_matches(pattern, &name) {
                    continue;
                }
            }

            hits.push(Hit {
                path: entry_relative,
                name,
                kind: if metadata.is_dir() { "dir".into() } else { "file".into() },
                score,
                matches: Vec::new(),
            });
        }
    }

    if !content_mode {
        hits.sort_by(|a, b| b.score.cmp(&a.score).then(a.name.len().cmp(&b.name.len())));
    } else {
        hits.sort_by(|a, b| a.path.cmp(&b.path));
    }
    hits.truncate(wanted);

    serde_json::to_value(serde_json::json!({
        "results": hits,
        "truncated": truncated,
        "scanned": scanned,
        "mode": if content_mode { "content" } else { "names" },
    }))
    .map_err(|e| e.to_string())
}

pub(crate) async fn handle(
    State(state): State<Arc<ApiState>>,
    headers: HeaderMap,
    Json(request): Json<Request>,
) -> Response {
    if let Err(error) = check_token(&state, &headers) {
        return error.into_response();
    }

    match tokio::task::spawn_blocking(move || search(&request)).await {
        Ok(Ok(data)) => ApiResponse::ok_data("Search finished", data).into_response(),
        Ok(Err(error)) => ApiResponse::error(&format!("Failed: {error}")).into_response(),
        Err(error) => ApiResponse::error(&format!("Search worker failed: {error}")).into_response(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn glob_handles_stars_and_single_characters() {
        assert!(glob_matches("*.php", "index.php"));
        assert!(glob_matches("user?.js", "user1.js"));
        assert!(!glob_matches("user?.js", "user12.js"));
        assert!(glob_matches("*.PHP", "index.php"));
        assert!(!glob_matches("*.php", "index.js"));
    }

    #[test]
    fn scattered_characters_score_below_a_run() {
        let run = name_score("UserController.php", "user").unwrap();
        let scattered = name_score("UserMailController.php", "umc").unwrap();
        assert!(run > scattered);
        assert!(name_score("index.php", "zzz").is_none());
    }

    #[test]
    fn whole_word_skips_partial_hits() {
        assert!(find_in_line("the username is here", "user", false, true).is_empty());
        assert_eq!(find_in_line("the user is here", "user", false, true).len(), 1);
        assert_eq!(find_in_line("User and user", "user", false, false).len(), 2);
        assert_eq!(find_in_line("User and user", "user", true, false).len(), 1);
    }

    #[test]
    fn binary_content_is_left_alone() {
        assert!(looks_binary(&[b'a', 0, b'b']));
        assert!(!looks_binary(b"plain text"));
    }
}
