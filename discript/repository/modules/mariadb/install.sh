#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

action="${1:-install}"

mariadb_install() {
  pkg_install_mariadb_stack
  pkg_enable_service mariadb
  panel_info_log "mariadb installed."
}

mariadb_remove() {
  pkg_remove mariadb-server mariadb-client mariadb
  panel_info_log "mariadb removed."
}

mariadb_update() {
  mariadb_install
  pkg_restart_service mariadb
  panel_info_log "mariadb updated."
}

case "$action" in
  install)
    mariadb_install
    ;;
  remove)
    mariadb_remove
    ;;
  update)
    mariadb_update
    ;;
  *)
    panel_die "Unsupported mariadb action: $action"
    ;;
esac
