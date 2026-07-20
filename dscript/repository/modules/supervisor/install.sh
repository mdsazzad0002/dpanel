#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

supervisor_install() {
  pkg_install_supervisor_stack
  pkg_enable_service supervisor
  panel_info_log "supervisor installed."
}

supervisor_remove() {
  pkg_remove supervisor
  panel_info_log "supervisor removed."
}

supervisor_update() {
  supervisor_install
  pkg_restart_service supervisor
  panel_info_log "supervisor updated."
}

case "$action" in
  install)
    supervisor_install
    ;;
  remove)
    supervisor_remove
    ;;
  update)
    supervisor_update
    ;;
  *)
    panel_die "Unsupported supervisor action: $action"
    ;;
esac
