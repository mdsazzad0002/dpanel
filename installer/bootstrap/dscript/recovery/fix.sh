#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/logs.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/paths.sh"

dscript_info "Running recovery fixes..."

fix_service() {
  local service="$1"
  if systemctl list-unit-files "${service}.service" >/dev/null 2>&1; then
    dscript_service_enable "$service"
    dscript_service_restart "$service"
    dscript_info "Restarted ${service}."
  fi
}

# Detect and fix issues
ISSUES=$("${SCRIPT_DIR}/detect.sh" 2>/dev/null || true)

if echo "$ISSUES" | grep -q "apache_not_running"; then
  fix_service apache2
  fix_service httpd
fi

if echo "$ISSUES" | grep -q "nginx_not_running"; then
  fix_service nginx
fi

if echo "$ISSUES" | grep -q "mariadb_not_running"; then
  fix_service mariadb
  fix_service mysql
fi

if echo "$ISSUES" | grep -q "php_fpm_not_running"; then
  for ver in 8.3 8.2 8.0 7.4; do
    fix_service "php${ver}-fpm"
  done
fi

dscript_info "Recovery fixes applied."
