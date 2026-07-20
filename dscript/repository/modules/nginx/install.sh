#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

nginx_install() {
  pkg_install_nginx_stack
  case "$(pkg_distro_family)" in
    debian)
      pkg_enable_service nginx
      panel_info_log "nginx installed as frontend service."
      ;;
    rpm)
      systemctl enable nginx >/dev/null 2>&1 || true
      panel_info_log "nginx installed as frontend service."
      ;;
  esac
}

nginx_remove() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_remove nginx nginx-common nginx-core
      ;;
    rpm)
      pkg_remove nginx
      ;;
  esac
  panel_info_log "nginx removed."
}

nginx_update() {
  nginx_install
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
