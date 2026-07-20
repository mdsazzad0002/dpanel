#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

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
