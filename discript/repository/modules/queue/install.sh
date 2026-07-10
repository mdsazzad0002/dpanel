#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

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
