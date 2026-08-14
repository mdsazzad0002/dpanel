#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
QUEUE_TEMPLATE="${SCRIPT_DIR}/../../templates/supervisor/dpanel-queue.conf"
QUEUE_CONFIG='/etc/supervisor/conf.d/dpanel-queue.conf'
QUEUE_APP_DIR="${PANEL_APP_DIR:-/var/www/dpanel}"

queue_install_dependencies() {
  local php_version redis_package

  pkg_install_redis_stack
  pkg_enable_service redis-server || pkg_enable_service redis
  pkg_install_supervisor_stack
  pkg_enable_service supervisor

  if ! /usr/bin/php -r 'exit(extension_loaded("redis") ? 0 : 1);'; then
    php_version="$(/usr/bin/php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')"
    case "$(pkg_distro_family)" in
      debian) redis_package="php${php_version}-redis" ;;
      rpm) redis_package='php-redis' ;;
    esac
    pkg_install_available "$redis_package"
  fi

  /usr/bin/php -r 'exit(extension_loaded("redis") ? 0 : 1);' || \
    panel_die "The Redis PHP extension is not available to /usr/bin/php."
}

queue_configure_application() {
  local env_file

  [[ -x "${QUEUE_APP_DIR}/artisan" ]] || panel_die "Laravel artisan is missing: ${QUEUE_APP_DIR}/artisan"

  env_file="$(panel_resolve_app_env_file)"
  if [[ -z "$env_file" ]]; then
    env_file="$(panel_ensure_app_env_file "${QUEUE_APP_DIR}/.env")"
  fi

  panel_env_set "$env_file" QUEUE_CONNECTION redis
  panel_env_set "$env_file" REDIS_CLIENT phpredis
  panel_env_set "$env_file" REDIS_QUEUE_CONNECTION default
  panel_env_set "$env_file" REDIS_QUEUE_RETRY_AFTER 3700

  install -d -o www-data -g www-data -m 0775 \
    "${QUEUE_APP_DIR}/storage/logs" \
    "${QUEUE_APP_DIR}/storage/framework/cache" \
    "${QUEUE_APP_DIR}/storage/framework/sessions" \
    "${QUEUE_APP_DIR}/storage/framework/views"

  (cd "$QUEUE_APP_DIR" && php artisan optimize:clear >/dev/null 2>&1 || true)
  (cd "$QUEUE_APP_DIR" && php artisan config:cache >/dev/null 2>&1 || true)
  panel_fix_app_permissions
}

queue_write_config() {
  [[ -f "$QUEUE_TEMPLATE" ]] || panel_die "Queue worker template is missing: $QUEUE_TEMPLATE"
  sed "s|__DPANEL_APP_DIR__|${QUEUE_APP_DIR}|g" "$QUEUE_TEMPLATE" > "$QUEUE_CONFIG"
  chmod 0644 "$QUEUE_CONFIG"
  supervisorctl reread >/dev/null 2>&1 || true
  supervisorctl update >/dev/null 2>&1 || true
}

queue_install() {
  queue_install_dependencies
  queue_configure_application
  queue_write_config
  supervisorctl start dpanel-queue dpanel-heavy-queue >/dev/null 2>&1 || true
  panel_info_log "queue runtime installed."
}

queue_remove() {
  rm -f "$QUEUE_CONFIG"
  supervisorctl reread >/dev/null 2>&1 || true
  supervisorctl update >/dev/null 2>&1 || true
  panel_info_log "queue runtime removed."
}

queue_update() {
  queue_install
  supervisorctl restart dpanel-queue dpanel-heavy-queue >/dev/null 2>&1 || pkg_restart_service supervisor
  panel_info_log "queue runtime updated."
}

case "$action" in
  install)
    queue_install
    ;;
  remove)
    queue_remove
    ;;
  update)
    queue_update
    ;;
  *)
    panel_die "Unsupported queue action: $action"
    ;;
esac
