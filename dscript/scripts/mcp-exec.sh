#!/usr/bin/env bash
set -uo pipefail

# Runs one shell command on the panel host for an MCP tool call. drust calls
# this as root on behalf of the panel. It does nothing until an admin opts in
# on the host with `sudo dpanel mcp local on [read|full]`, which writes
# /etc/dpanel/mcp-local.conf.
#
# Usage: mcp-exec.sh <read|full> <timeout-seconds> <command>
#
# read  runs the command as `nobody` (plus the log-reading groups), so even a
#       compromised panel cannot use it to change the system.
# full  runs the command as root, and only when the host allows full access.
#
# Always exits 0 once the command ran; the last line carries its exit code as
# __DPANEL_MCP_EXIT__=<code>.

CONF=/etc/dpanel/mcp-local.conf

fail() { printf '[mcp-exec] %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
[[ $# -eq 3 ]] || fail 'Usage: mcp-exec.sh <read|full> <timeout-seconds> <command>'

mode="$1" timeout_s="$2" command="$3"

[[ "$mode" == read || "$mode" == full ]] || fail 'Mode must be read or full.'
[[ "$timeout_s" =~ ^[0-9]+$ ]] && (( timeout_s >= 1 && timeout_s <= 3600 )) || fail 'Invalid timeout.'
[[ -n "$command" ]] || fail 'Empty command.'

[[ -f "$CONF" && ! -L "$CONF" ]] || fail 'Local MCP commands are off on this host. Enable them with: sudo dpanel mcp local on [read|full]'
[[ "$(stat -c '%u' "$CONF")" == 0 ]] || fail "$CONF must be owned by root."

allowed="$(awk -F= '$1 == "MCP_LOCAL_EXEC" {gsub(/[^a-z]/, "", $2); print $2; exit}' "$CONF")"
case "$allowed" in
  read|full) ;;
  *) fail 'Local MCP commands are off on this host. Enable them with: sudo dpanel mcp local on [read|full]' ;;
esac

if [[ "$mode" == full && "$allowed" != full ]]; then
  fail 'This host only allows read-only MCP commands. Allow fixes with: sudo dpanel mcp local on full'
fi

if [[ "$mode" == full ]]; then
  output="$(cd / && timeout --kill-after=5 "$timeout_s" bash -c "$command" </dev/null 2>&1)"
  code=$?
else
  groups=""
  for group in adm systemd-journal; do
    if getent group "$group" >/dev/null 2>&1; then groups+="${groups:+,}${group}"; fi
  done
  group_args=(--clear-groups)
  [[ -n "$groups" ]] && group_args=(--groups="$groups")
  nobody_gid="$(id -g nobody 2>/dev/null)" || fail 'User nobody is missing.'
  output="$(cd / && timeout --kill-after=5 "$timeout_s" \
    setpriv --reuid=nobody --regid="$nobody_gid" "${group_args[@]}" \
      --inh-caps=-all --bounding-set=-all --no-new-privs \
      env -i PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin HOME=/ LANG=C.UTF-8 \
      bash -c "$command" </dev/null 2>&1)"
  code=$?
fi

[[ $code -eq 124 ]] && output+=$'\n'"[mcp-exec] Command timed out after ${timeout_s}s."
printf '%s\n__DPANEL_MCP_EXIT__=%d\n' "$output" "$code"
