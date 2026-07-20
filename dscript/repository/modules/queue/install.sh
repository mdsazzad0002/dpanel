#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

queue_install() {
  pkg_install_supervisor_stack
  pkg_enable_service supervisor
  panel_info_log "queue runtime installed."
}

queue_remove() {
  pkg_remove supervisor
  panel_info_log "queue runtime removed."
}

queue_update() {
  queue_install
  pkg_restart_service supervisor
  panel_info_log "queue runtime updated."
}

case "$action" in
  install)
    queue_install
    ;;
  remove)
    queue_remove
    ;;
  update)
    queue_update
    ;;
  *)
    panel_die "Unsupported queue action: $action"
    ;;
esac
