#!/usr/bin/env bash
set -euo pipefail

DPANEL_BASE_DIR="${DPANEL_BASE_DIR:-/opt/dpanel}"
DPANEL_RUNTIME_DIR="${DPANEL_RUNTIME_DIR:-${DPANEL_BASE_DIR}/runtime}"
DPANEL_REPOSITORY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DPANEL_TEMPLATE_DIR="${DPANEL_REPOSITORY_DIR}/templates"

# shellcheck disable=SC1091
source "$(dirname "${BASH_SOURCE[0]}")/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR}/core.sh"; source "${DPANEL_RUNTIME_DIR}/package-manager.sh"; }

action="${1:-install}"

fail2ban_config_path() {
  case "$(pkg_distro_family)" in
    debian)
      printf '%s' '/etc/fail2ban/jail.d/serverpanel.local'
      ;;
    rpm)
      printf '%s' '/etc/fail2ban/jail.d/serverpanel.local'
      ;;
    *)
      printf '%s' '/etc/fail2ban/jail.d/serverpanel.local'
      ;;
  esac
}

fail2ban_install() {
  pkg_install_fail2ban_stack

  mkdir -p /etc/fail2ban/jail.d
  cp "${DPANEL_TEMPLATE_DIR}/fail2ban/jail.local" "$(fail2ban_config_path)"

  pkg_enable_service fail2ban
  systemctl restart fail2ban >/dev/null 2>&1 || systemctl start fail2ban >/dev/null 2>&1 || true
  panel_info_log "fail2ban installed."
}

fail2ban_remove() {
  rm -f "$(fail2ban_config_path)"
  pkg_remove fail2ban
  panel_info_log "fail2ban removed."
}

fail2ban_update() {
  fail2ban_install
  pkg_restart_service fail2ban || true
  panel_info_log "fail2ban updated."
}

case "$action" in
  install)
    fail2ban_install
    ;;
  remove)
    fail2ban_remove
    ;;
  update)
    fail2ban_update
    ;;
  *)
    panel_die "Unsupported fail2ban action: $action"
    ;;
esac
