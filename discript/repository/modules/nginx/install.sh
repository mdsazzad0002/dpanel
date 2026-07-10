#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

action="${1:-install}"

nginx_install() {
  pkg_install_web_stack
  pkg_enable_service nginx
  panel_info_log "nginx installed."
}

nginx_remove() {
  pkg_remove nginx
  panel_info_log "nginx removed."
}

nginx_update() {
  pkg_install_web_stack
  pkg_restart_service nginx
  panel_info_log "nginx updated."
}

case "$action" in
  install)
    nginx_install
    ;;
  remove)
    nginx_remove
    ;;
  update)
    nginx_update
    ;;
  *)
    panel_die "Unsupported nginx action: $action"
    ;;
esac
