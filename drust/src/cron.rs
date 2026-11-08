//! Validation of the panel's cron jobs; `platform::scheduler()` installs them.

use crate::platform::{self, scheduler::CronJob};

fn valid_id(id: &str) -> Result<(), String> {
    if id.is_empty() || !id.chars().all(|ch| ch.is_ascii_alphanumeric() || ch == '-') {
        return Err("Cron job id contains invalid characters.".to_string());
    }
    Ok(())
}

pub(crate) fn upsert(
    id: &str,
    user: &str,
    expression: &str,
    command: &str,
    enabled: bool,
) -> Result<String, String> {
    valid_id(id)?;
    if !enabled {
        return delete(id);
    }
    if user.is_empty()
        || !user
            .chars()
            .all(|ch| ch.is_ascii_alphanumeric() || ch == '_' || ch == '-')
    {
        return Err("Cron user is invalid.".to_string());
    }
    if expression.split_whitespace().count() != 5 {
        return Err("Cron expression must contain exactly five fields.".to_string());
    }
    if expression.contains(['\n', '\r'])
        || command.trim().is_empty()
        || command.contains(['\n', '\r'])
    {
        return Err("Cron expression and command must be single-line values.".to_string());
    }

    let job = CronJob {
        id: id.to_string(),
        user: user.to_string(),
        expression: expression.trim().to_string(),
        command: command.trim().to_string(),
    };
    platform::scheduler()
        .upsert(&job)
        .map_err(|error| format!("Cannot install cron job: {error}"))?;
    Ok(format!("System cron job {id} installed."))
}

pub(crate) fn delete(id: &str) -> Result<String, String> {
    valid_id(id)?;
    platform::scheduler()
        .remove(id)
        .map_err(|error| format!("Cannot remove cron job: {error}"))?;
    Ok(format!("System cron job {id} removed."))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn bad_input_never_reaches_the_scheduler() {
        assert!(upsert("../x", "shop", "* * * * *", "true", true).is_err());
        assert!(upsert("1", "sh op", "* * * * *", "true", true).is_err());
        assert!(upsert("1", "shop", "* * * *", "true", true).is_err());
        assert!(upsert("1", "shop", "* * * * *", "true\nrm -rf /", true).is_err());
        assert!(upsert("1", "shop", "* * * * *", "  ", true).is_err());
        assert!(delete("a/b").is_err());
    }
}
