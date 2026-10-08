#!/usr/bin/env bash
set -euo pipefail

# Rewrites the Dovecot and Postfix lookups from the panel's database settings,
# the same step a panel update runs. The Mailbox health check calls it when
# Dovecot cannot find mailboxes that Postfix accepts mail for.
# Usage: mail-repair-dovecot.sh

[[ "${EUID}" -eq 0 ]] || { printf '[mail-repair] Run as root.\n' >&2; exit 1; }

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# A source checkout keeps core.sh in bootstrap/; the installed runtime keeps
# it next to this scripts directory.
for core in "${script_dir}/../bootstrap/core.sh" "${script_dir}/../core.sh"; do
  if [[ -f "$core" ]]; then
    # shellcheck disable=SC1090
    source "$core"
    break
  fi
done
declare -F panel_configure_dovecot_sql >/dev/null || { printf '[mail-repair] dscript core.sh not found.\n' >&2; exit 1; }

panel_configure_dovecot_sql
panel_configure_postfix_sql
printf 'MAIL_REPAIR=ok\n'
