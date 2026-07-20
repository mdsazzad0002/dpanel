#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

firewall_install() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_install_firewall_stack
      if command -v ufw >/dev/null 2>&1; then
        ufw allow 22/tcp || true
        ufw allow 80/tcp || true
        ufw allow 443/tcp || true
        ufw allow 2083/tcp || true
        ufw --force enable || true
      fi
      ;;
    rpm)
      pkg_install_firewall_stack
      systemctl enable firewalld >/dev/null 2>&1 || true
      systemctl start firewalld >/dev/null 2>&1 || true
      ;;
  esac
  panel_info_log "firewall configured."
}

firewall_remove() {
  panel_warn_log "Firewall removal is not automated."
}

firewall_update() {
  firewall_install
}

case "$action" in
  install)
    firewall_install
    ;;
  remove)
    firewall_remove
    ;;
  update)
    firewall_update
    ;;
  *)
    panel_die "Unsupported firewall action: $action"
    ;;
esac
