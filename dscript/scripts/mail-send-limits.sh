#!/usr/bin/env bash
set -euo pipefail

# Writes each account's outbound mail limit for Rspamd (backlog P5). The
# rspamd module's dpanel_ratelimit.lua reads these maps; Rspamd rereads a map
# when the file changes, so nothing restarts.
#
#   --apply   write the maps from DPANEL_MAIL_SENDERS and DPANEL_MAIL_LIMITS
#             (one "key value" pair per line; see the plugin for the format).
#             Every line is checked first; one bad line changes nothing.
#   --status  print the current state only.
#
# Prints MAIL_LIMITS_READY=0|1 (Rspamd runs the plugin, has Redis and sees
# sendmail's mail), MAIL_LIMITS_REASON= when not ready, MAIL_LIMITS_CHANGED=0|1.

fail() { printf '[mail-limits] %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'

MAP_DIR=/etc/rspamd/dpanel
PLUGIN=/etc/rspamd/lua.local.d/dpanel_ratelimit.lua
RSPAMD_MILTER=inet:127.0.0.1:11332

mode=''
for arg in "$@"; do
  case "$arg" in
    --apply) mode=apply ;;
    --status) mode=status ;;
    *) fail "Unknown argument: ${arg}" ;;
  esac
done
[[ -n "$mode" ]] || fail 'Pass --apply or --status.'

not_ready_reason() {
  command -v rspamd >/dev/null 2>&1 || { printf 'Rspamd is not installed'; return; }
  [[ -f "$PLUGIN" ]] || { printf 'The Rspamd module is older than the mail limits; update it (dpanel module rspamd update)'; return; }
  [[ -f /etc/rspamd/local.d/redis.conf ]] || { printf 'Rspamd has no Redis'; return; }
  systemctl is-active --quiet rspamd 2>/dev/null || { printf 'Rspamd is not running'; return; }
  local milters
  milters="$(postconf -h non_smtpd_milters 2>/dev/null || true)"
  [[ " ${milters//,/ } " == *" ${RSPAMD_MILTER} "* ]] || { printf "Mail sent with sendmail (PHP mail) does not pass through Rspamd; update the rspamd module"; return; }
}

print_state() {
  local reason
  reason="$(not_ready_reason)"
  printf 'MAIL_LIMITS_READY=%s\nMAIL_LIMITS_REASON=%s\nMAIL_LIMITS_CHANGED=%s\n' "$([[ -z "$reason" ]] && echo 1 || echo 0)" "$reason" "$1"
}

if [[ "$mode" == status ]]; then
  print_state 0
  exit 0
fi

command -v rspamd >/dev/null 2>&1 || fail 'Rspamd is not installed.'

# Keys are lowercase addresses, domains or user:<system user>; accounts a<id>.
SENDER_LINE='^(user:[a-z_][a-z0-9_.-]{0,31}|([a-z0-9!#$%&*+/=?^_`{|}~.-]{1,64}@)?[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?) a[0-9]{1,20}$'
LIMIT_LINE='^a[0-9]{1,20} [0-9]{1,9}:[0-9]{1,9}$'

# Prints the checked map, one entry per line, sorted so an unchanged list
# leaves the file alone.
checked_map() {
  local name="$1" content="$2" pattern="$3" line
  while IFS= read -r line; do
    line="${line%$'\r'}"
    [[ -z "$line" ]] && continue
    [[ "$line" =~ $pattern ]] || fail "Invalid ${name} line: ${line}"
    printf '%s\n' "$line"
  done <<<"$content" | LC_ALL=C sort -u
}

senders="$(checked_map sender "${DPANEL_MAIL_SENDERS:-}" "$SENDER_LINE")"
limits="$(checked_map limit "${DPANEL_MAIL_LIMITS:-}" "$LIMIT_LINE")"

changed=0
write_map() {
  local file="${MAP_DIR}/$1" content="$2" tmp
  if [[ -f "$file" && "$(cat "$file")" == "$content" ]]; then
    return 0
  fi
  tmp="$(mktemp "${MAP_DIR}/.${1}.XXXXXX")"
  [[ -n "$content" ]] && printf '%s\n' "$content" > "$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$file"
  changed=1
}

install -d -m 0755 "$MAP_DIR"
# Limits first: a sender only maps to an account whose limit is already there.
write_map limits.map "$limits"
write_map senders.map "$senders"

print_state "$changed"
