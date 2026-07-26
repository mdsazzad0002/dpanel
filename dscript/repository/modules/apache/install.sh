#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

# Apache only ever sees nginx on the loopback interface, so without mod_remoteip
# every request is logged and handed to PHP as 127.0.0.1. Sites lose the real
# visitor IP, which breaks rate limiting, geo and abuse handling in the apps.
# The same file carries the backend hardening, since Apache error pages are
# proxied to visitors unchanged.
apache_configure_backend() {
  local conf_path
  case "$(pkg_distro_family)" in
    debian) conf_path='/etc/apache2/conf-available/dpanel-backend.conf' ;;
    rpm) conf_path='/etc/httpd/conf.d/dpanel-backend.conf' ;;
    *) return 0 ;;
  esac

  mkdir -p "$(dirname "$conf_path")"
  cat > "$conf_path" <<'CONF'
# Managed by dpanel. Apache runs as the backend behind nginx on this host.

# nginx terminates the connection, so the client IP arrives in a header.
<IfModule mod_remoteip.c>
    RemoteIPHeader X-Forwarded-For
    RemoteIPTrustedProxy 127.0.0.1
    RemoteIPTrustedProxy ::1
</IfModule>

# Backend error pages are proxied straight to visitors; keep the version out.
ServerTokens Prod
ServerSignature Off
TraceEnable Off
CONF

  if [[ "$(pkg_distro_family)" == "debian" ]]; then
    a2enconf dpanel-backend >/dev/null 2>&1 || true
  fi
}

apache_install() {
  pkg_install_apache_stack
  pkg_configure_apache_backend_ports

  case "$(pkg_distro_family)" in
    debian)
      a2enmod proxy proxy_fcgi setenvif rewrite headers remoteip >/dev/null 2>&1 || true
      apache_configure_backend
      pkg_enable_service apache2
      panel_info_log "apache installed as backend service."
      ;;
    rpm)
      apache_configure_backend
      systemctl enable httpd >/dev/null 2>&1 || true
      panel_info_log "apache installed as backend service."
      ;;
  esac
}

apache_remove() {
  case "$(pkg_distro_family)" in
    debian)
      a2disconf dpanel-backend >/dev/null 2>&1 || true
      rm -f /etc/apache2/conf-available/dpanel-backend.conf
      pkg_remove apache2
      panel_info_log "apache removed."
      ;;
    rpm)
      rm -f /etc/httpd/conf.d/dpanel-backend.conf
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
