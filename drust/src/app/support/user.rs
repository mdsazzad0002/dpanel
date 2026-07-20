use std::{fs, io::Read, path::PathBuf};

use super::process::{program_exists, run_output};

pub fn valid_username(username: &str) -> bool {
    let mut characters = username.chars();
    match characters.next() {
        Some(character) if character.is_ascii_lowercase() || character == '_' => {}
        _ => return false,
    }
    characters.all(|character| {
        character.is_ascii_lowercase()
            || character.is_ascii_digit()
            || character == '_'
            || character == '-'
    })
}

pub(crate) fn valid_email(email: &str) -> bool {
    let parts = email.trim().split('@').collect::<Vec<_>>();
    parts.len() == 2 && !parts[0].is_empty() && parts[1].contains('.')
}

pub fn random_hex(len: usize) -> Result<String, String> {
    if program_exists("openssl") {
        let output = run_output("openssl", &["rand", "-hex", "32"])?;
        return Ok(output.chars().take(len).collect());
    }

    let mut bytes = vec![0_u8; len / 2 + 1];
    let mut file = fs::File::open("/dev/urandom")
        .map_err(|error| format!("failed to open /dev/urandom: {error}"))?;
    file.read_exact(&mut bytes)
        .map_err(|error| format!("failed to read random bytes: {error}"))?;
    let output = bytes
        .into_iter()
        .map(|byte| format!("{byte:02x}"))
        .collect::<String>();
    Ok(output.chars().take(len).collect())
}

pub(crate) fn user_home(username: &str) -> Result<PathBuf, String> {
    let output = run_output("getent", &["passwd", username])?;
    let fields = output.split(':').collect::<Vec<_>>();
    if fields.len() >= 6 {
        Ok(PathBuf::from(fields[5]))
    } else {
        Err(format!("Unable to determine home directory for {username}"))
    }
}

pub fn user_group(username: &str) -> Result<String, String> {
    run_output("id", &["-gn", username])
}
