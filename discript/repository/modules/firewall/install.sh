#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

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
