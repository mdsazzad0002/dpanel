#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

NGINX_TUNING_CONF='/etc/nginx/conf.d/dpanel-tuning.conf'

# The stock gzip block only compresses text/html, and nginx skips proxied
# responses entirely by default. Since every dynamic response here arrives from
# Apache over the loopback, CSS/JS/JSON went out uncompressed on every site.
nginx_configure_tuning() {
  mkdir -p "$(dirname "${NGINX_TUNING_CONF}")"
  cat > "${NGINX_TUNING_CONF}" <<'CONF'
# Managed by dpanel. Applies to every server block on this host.
server_tokens off;

gzip on;
gzip_vary on;
gzip_proxied any;
gzip_comp_level 4;
gzip_min_length 1024;
gzip_types
    text/plain
    text/css
    text/xml
    text/javascript
    application/javascript
    application/json
    application/xml
    application/rss+xml
    image/svg+xml
    font/woff
    font/woff2;
CONF
  panel_info_log "nginx tuning written to ${NGINX_TUNING_CONF}."
}

nginx_install() {
  pkg_install_nginx_stack
  nginx_configure_tuning
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
  rm -f "${NGINX_TUNING_CONF}"
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

nginx_start() {
  pkg_enable_service nginx
  systemctl start nginx
  panel_info_log "nginx started."
}

nginx_stop() {
  systemctl stop nginx
  panel_info_log "nginx stopped."
}

nginx_reload() {
  nginx -t
  systemctl reload nginx
  panel_info_log "nginx reloaded."
}

nginx_restart() {
  nginx -t
  systemctl reload-or-restart nginx
  panel_info_log "nginx restarted."
}

nginx_status() {
  systemctl status nginx --no-pager
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
  start)
    nginx_start
    ;;
  stop)
    nginx_stop
    ;;
  reload)
    nginx_reload
    ;;
  restart)
    nginx_restart
    ;;
  status)
    nginx_status
    ;;
  *)
    panel_die "Unsupported nginx action: $action"
    ;;
esac
