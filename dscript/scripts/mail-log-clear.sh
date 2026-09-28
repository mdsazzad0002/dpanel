#!/usr/bin/env bash
set -euo pipefail

# Empties the mail log from the panel's Mail Health page. The current content is
# kept as a compressed archive next to it first, and only the newest few
# archives are retained. Only mail logs under /var/log can be cleared.
# Usage: mail-log-clear.sh [log_path]

keep_archives=5

fail() { printf '[mail-log] %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'

path="${1:-/var/log/mail.log}"
resolved="$(realpath -e -- "$path" 2>/dev/null || true)"
[[ -n "$resolved" && -f "$resolved" ]] || fail "Log not found: ${path}"
[[ "$resolved" == /var/log/* ]] || fail 'Only logs under /var/log can be cleared.'
case "$(basename "$resolved")" in
  mail.log|maillog|mail.err|mail.warn) ;;
  *) fail 'Only mail logs can be cleared.' ;;
esac

before="$(stat -c %s "$resolved")"
if (( before > 0 )); then
  archive="${resolved}.cleared-$(date +%Y%m%d%H%M%S).gz"
  gzip -c -- "$resolved" > "$archive"
  chown --reference="$resolved" "$archive"
  chmod 0640 "$archive"
fi

# Truncate in place: rsyslog keeps its file handle and continues appending.
truncate -s 0 -- "$resolved"

# shellcheck disable=SC2012
ls -1t -- "${resolved}".cleared-*.gz 2>/dev/null | tail -n +"$((keep_archives + 1))" | xargs -r rm -f --

printf 'LOG_CLEARED=%s\nBYTES_FREED=%s\nARCHIVE=%s\n' "$resolved" "$before" "${archive:-}"
