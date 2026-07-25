#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

apache_install() {
  pkg_install_apache_stack
  pkg_configure_apache_backend_ports

  case "$(pkg_distro_family)" in
    debian)
      a2enmod proxy proxy_fcgi setenvif rewrite headers >/dev/null 2>&1 || true
      pkg_enable_service apache2
      panel_info_log "apache installed as backend service."
      ;;
    rpm)
      systemctl enable httpd >/dev/null 2>&1 || true
      panel_info_log "apache installed as backend service."
      ;;
  esac
}

apache_remove() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_remove apache2
      panel_info_log "apache removed."
      ;;
    rpm)
      pkg_remove httpd
      panel_info_log "apache removed."
      ;;
  esac
}

apache_update() {
  apache_install
  case "$(pkg_distro_family)" in
    debian)
      pkg_restart_service apache2
      ;;
    rpm)
      pkg_restart_service httpd
      ;;
  esac
  panel_info_log "apache updated."
}

apache_service_name() {
  case "$(pkg_distro_family)" in
    debian) printf 'apache2' ;;
    rpm) printf 'httpd' ;;
    *) printf 'apache2' ;;
  esac
}

apache_start() {
  local service
  service="$(apache_service_name)"
  pkg_enable_service "$service"
  systemctl start "$service"
  panel_info_log "apache started."
}

apache_stop() {
  local service
  service="$(apache_service_name)"
  systemctl stop "$service"
  panel_info_log "apache stopped."
}

apache_reload() {
  local service
  service="$(apache_service_name)"
  case "$(pkg_distro_family)" in
    debian) apache2ctl -t ;;
    rpm) httpd -t ;;
  esac
  systemctl reload "$service"
  panel_info_log "apache reloaded."
}

apache_restart() {
  local service
  service="$(apache_service_name)"
  case "$(pkg_distro_family)" in
    debian) apache2ctl -t ;;
    rpm) httpd -t ;;
  esac
  systemctl reload-or-restart "$service"
  panel_info_log "apache restarted."
}

apache_status() {
  systemctl status "$(apache_service_name)" --no-pager
}

case "$action" in
  install)
    apache_install
    ;;
  remove)
    apache_remove
    ;;
  update)
    apache_update
    ;;
  start)
    apache_start
    ;;
  stop)
    apache_stop
    ;;
  reload)
    apache_reload
    ;;
  restart)
    apache_restart
    ;;
  status)
    apache_status
    ;;
  *)
    panel_die "Unsupported apache action: $action"
    ;;
esac
