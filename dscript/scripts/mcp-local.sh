#!/usr/bin/env bash
set -euo pipefail

# Turns MCP commands on the panel host on or off.
# Usage: mcp-local.sh status | on [read|full] | off
#
# read  MCP clients may run diagnostic commands here, as an unprivileged user.
# full  MCP clients with a full-access token may also fix things, as root.

CONF_DIR=/etc/dpanel
CONF="${CONF_DIR}/mcp-local.conf"

fail() { printf '[ERROR] %s\n' "$*" >&2; exit 1; }

current() {
  if [[ -f "$CONF" ]]; then
    awk -F= '$1 == "MCP_LOCAL_EXEC" {print $2; exit}' "$CONF"
  else
    printf 'off'
  fi
}

action="${1:-status}"

case "$action" in
  status)
    printf 'MCP commands on this host: %s\n' "$(current)"
    ;;
  on)
    [[ "${EUID}" -eq 0 ]] || fail 'Run as root: sudo dpanel mcp local on [read|full]'
    level="${2:-read}"
    [[ "$level" == read || "$level" == full ]] || fail 'Level must be read or full.'
    command -v setpriv >/dev/null 2>&1 || fail 'setpriv (util-linux) is required.'
    install -d -m 0755 -o root -g root "$CONF_DIR"
    # World-readable so the panel can show the setting; only root can change it.
    printf '# Managed by: dpanel mcp local\nMCP_LOCAL_EXEC=%s\n' "$level" > "${CONF}.tmp"
    chown root:root "${CONF}.tmp"
    chmod 0644 "${CONF}.tmp"
    mv -f "${CONF}.tmp" "$CONF"
    printf 'MCP commands on this host: %s\n' "$level"
    if [[ "$level" == full ]]; then
      printf 'Full-access MCP tokens can now run commands here as root. Turn it off with: sudo dpanel mcp local off\n'
    fi
    ;;
  off)
    [[ "${EUID}" -eq 0 ]] || fail 'Run as root: sudo dpanel mcp local off'
    rm -f "$CONF"
    printf 'MCP commands on this host: off\n'
    ;;
  *)
    fail 'Usage: dpanel mcp local status | on [read|full] | off'
    ;;
esac
