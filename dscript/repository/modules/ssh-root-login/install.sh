#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"

action="${1:-install}"
shift || true

run_disable_root() {
  local user_script="${DPANEL_RUNTIME_DIR}/scripts/disable-root-login.sh"
  if [[ ! -x "$user_script" ]]; then
    panel_die "Runtime script not found: ${user_script}"
  fi

  bash "$user_script" "$@"
}

case "$action" in
  install)
    run_disable_root "$@"
    panel_info_log "ssh-root-login module installed."
    ;;
  update)
    run_disable_root "$@"
    panel_info_log "ssh-root-login module updated."
    ;;
  remove)
    panel_warn_log "SSH root-login restore is not automated."
    ;;
  *)
    panel_die "Unsupported ssh-root-login action: $action"
    ;;
esac
