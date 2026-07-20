use sha1::{Digest, Sha1};

fn sanitize(value: &str) -> String {
    let normalized = value
        .trim()
        .to_lowercase()
        .chars()
        .map(|character| {
            if character.is_ascii_alphanumeric() || character == '.' || character == '-' {
                character
            } else {
                '-'
            }
        })
        .collect::<String>();
    normalized.trim_matches('-').to_string()
}

fn short_hash(value: &str) -> String {
    let hash = Sha1::digest(value.as_bytes());
    format!("{hash:x}")[..12].to_string()
}

fn token(domain: &str) -> String {
    let mut token = sanitize(domain);
    if token.is_empty() {
        token = "site".to_string();
    }
    if token.len() > 110 {
        token.truncate(110);
    }
    token
}

pub fn conf_basename(domain: &str) -> String {
    format!("{}-{}", token(domain), short_hash(domain))
}

pub fn split_aliases(raw: &str) -> Vec<String> {
    raw.replace(';', ",")
        .split(',')
        .map(|item| item.trim().to_string())
        .filter(|item| !item.is_empty())
        .collect()
}

fn should_add_www_alias(domain: &str, no_www: bool) -> bool {
    !no_www && !domain.starts_with("www.") && domain.contains('.')
}

pub fn panel_aliases_for(domain: &str, aliases: &[String], no_www: bool) -> String {
    let mut all = Vec::new();
    if should_add_www_alias(domain, no_www) {
        all.push(format!("www.{domain}"));
    }
    for alias in aliases {
        if !alias.trim().is_empty() && alias.trim() != domain {
            all.push(alias.trim().to_string());
        }
    }
    all.join(" ")
}
