//! `drust.env`, read by drust itself where no systemd `EnvironmentFile`
//! does it (F4): a Windows service and a program started at logon get no
//! shell environment, yet need `DRUST_API_TOKEN`, `DPANEL_PROFILE` and the
//! listen addresses.

use std::path::{Path, PathBuf};

use super::{Os, Profile, paths::Paths};

/// Where `drust.env` is for the profile the environment names now. Read
/// before anything asks for `platform::current()`, which fixes the profile.
pub fn default_path() -> PathBuf {
    let profile = Profile::parse(std::env::var("DPANEL_PROFILE").ok().as_deref());
    Paths::for_system(Os::detect(), profile, |name| std::env::var(name).ok()).env_file()
}

/// `KEY=value` lines; `#` comments and blank lines skipped, an optional
/// `export `, and one pair of matching quotes around the value removed.
pub fn parse(text: &str) -> Vec<(String, String)> {
    text.lines()
        .map(str::trim)
        .filter(|line| !line.is_empty() && !line.starts_with('#'))
        .filter_map(|line| {
            let line = line.strip_prefix("export ").unwrap_or(line);
            let (key, value) = line.split_once('=')?;
            let key = key.trim();
            let valid = !key.is_empty() && key.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_');
            let value = value.trim();
            let value = [b'"', b'\'']
                .iter()
                .find_map(|&quote| {
                    let quote = quote as char;
                    value.strip_prefix(quote)?.strip_suffix(quote)
                })
                .unwrap_or(value);
            valid.then(|| (key.to_string(), value.to_string()))
        })
        .collect()
}

/// Sets every variable the file names that the environment does not
/// already have, so an explicit setting wins. A missing file is no error.
/// Call while drust has one thread only.
pub fn load(path: &Path) -> Result<usize, String> {
    let text = match std::fs::read_to_string(path) {
        Ok(text) => text,
        Err(error) if error.kind() == std::io::ErrorKind::NotFound => return Ok(0),
        Err(error) => return Err(format!("{}: {error}", path.display())),
    };
    let mut set = 0;
    for (key, value) in parse(&text) {
        if std::env::var_os(&key).is_none() {
            // SAFETY: called at startup, before drust starts any thread.
            unsafe { std::env::set_var(&key, value) };
            set += 1;
        }
    }
    Ok(set)
}

#[cfg(test)]
mod tests {
    use super::parse;

    #[test]
    fn reads_the_same_lines_systemd_does() {
        let text = "# token\nDRUST_API_TOKEN=abc=def\n\nexport DPANEL_PROFILE=local\nQUOTED=\"two words\"\nSINGLE='x'\nbad key=1\n=nothing\n";
        let expected = [("DRUST_API_TOKEN", "abc=def"), ("DPANEL_PROFILE", "local"), ("QUOTED", "two words"), ("SINGLE", "x")]
            .map(|(key, value)| (key.to_string(), value.to_string()));
        assert_eq!(parse(text), expected);
    }
}
