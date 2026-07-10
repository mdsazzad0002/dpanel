#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

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
