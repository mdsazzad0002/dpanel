#!/usr/bin/env bash
set -euo pipefail

# Prints the recent mail-delivery lines for the panel's Mail Health page, which
# runs as the web user and cannot read the root/adm-owned mail log itself.
# Usage: mail-log-tail.sh <max_lines> [log_path...]
# Only files under /var/log are read, and only MTA/spam-filter lines are
# returned, so this cannot be used to read arbitrary files or other logs.

max_lines="${1:-5000}"
shift || true

fail() { printf '[mail-log] %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
[[ "$max_lines" =~ ^[0-9]+$ ]] && (( max_lines >= 1 && max_lines <= 20000 )) || fail 'Invalid line count.'

pattern='postfix/|rspamd|spamd'
[[ $# -gt 0 ]] || set -- /var/log/mail.log /var/log/maillog

for path in "$@"; do
  resolved="$(realpath -e -- "$path" 2>/dev/null || true)"
  [[ -n "$resolved" && "$resolved" == /var/log/* && -f "$resolved" ]] || continue
  printf 'LOG_SOURCE=%s\n' "$resolved"
  # Read a bounded tail first so a huge log is never scanned end to end.
  tail -n 200000 -- "$resolved" | grep -E "$pattern" | tail -n "$max_lines" || true
  exit 0
done

if command -v journalctl >/dev/null 2>&1; then
  printf 'LOG_SOURCE=systemd journal\n'
  journalctl -u postfix -u 'postfix@*' -u rspamd -u spamassassin --no-pager -n "$max_lines" 2>/dev/null || true
  exit 0
fi

fail 'No mail log found.'
