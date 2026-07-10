#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

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
