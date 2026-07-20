#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

redis_install() {
  pkg_install_redis_stack
  pkg_enable_service redis-server || pkg_enable_service redis
  panel_info_log "redis installed."
}

redis_remove() {
  pkg_remove redis-server redis
  panel_info_log "redis removed."
}

redis_update() {
  redis_install
  pkg_restart_service redis-server || pkg_restart_service redis
  panel_info_log "redis updated."
}

case "$action" in
  install)
    redis_install
    ;;
  remove)
    redis_remove
    ;;
  update)
    redis_update
    ;;
  *)
    panel_die "Unsupported redis action: $action"
    ;;
esac
