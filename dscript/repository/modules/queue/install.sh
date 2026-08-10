#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
QUEUE_TEMPLATE="${SCRIPT_DIR}/../../templates/supervisor/dpanel-queue.conf"
QUEUE_CONFIG='/etc/supervisor/conf.d/dpanel-queue.conf'

queue_write_config() {
  [[ -f "$QUEUE_TEMPLATE" ]] || panel_die "Queue worker template is missing: $QUEUE_TEMPLATE"
  install -m 0644 "$QUEUE_TEMPLATE" "$QUEUE_CONFIG"
  supervisorctl reread >/dev/null 2>&1 || true
  supervisorctl update >/dev/null 2>&1 || true
}

queue_install() {
  pkg_install_supervisor_stack
  pkg_enable_service supervisor
  queue_write_config
  panel_info_log "queue runtime installed."
}

queue_remove() {
  rm -f "$QUEUE_CONFIG"
  supervisorctl reread >/dev/null 2>&1 || true
  supervisorctl update >/dev/null 2>&1 || true
  panel_info_log "queue runtime removed."
}

queue_update() {
  queue_install
  supervisorctl restart dpanel-queue dpanel-heavy-queue >/dev/null 2>&1 || pkg_restart_service supervisor
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
