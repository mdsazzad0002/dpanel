#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/logs.sh"

dscript_info "Running recovery detection..."

ISSUES=()

# Check Apache
if ! systemctl is-active --quiet apache2 2>/dev/null && ! systemctl is-active --quiet httpd 2>/dev/null; then
  ISSUES+=("apache_not_running")
fi

# Check Nginx
if ! systemctl is-active --quiet nginx 2>/dev/null; then
  ISSUES+=("nginx_not_running")
fi

# Check MariaDB
if ! systemctl is-active --quiet mariadb 2>/dev/null && ! systemctl is-active --quiet mysql 2>/dev/null; then
  ISSUES+=("mariadb_not_running")
fi

# Check PHP-FPM
php_fpm_running=false
for ver in 8.3 8.2 8.0 7.4; do
  if systemctl is-active --quiet "php${ver}-fpm" 2>/dev/null; then
    php_fpm_running=true
    break
  fi
done
if [[ "$php_fpm_running" == "false" ]]; then
  ISSUES+=("php_fpm_not_running")
fi

# Check panel directory
if [[ ! -d /var/www/dpanel ]]; then
  ISSUES+=("panel_directory_missing")
fi

if [[ ${#ISSUES[@]} -eq 0 ]]; then
  dscript_info "No issues detected."
else
  dscript_warn "Issues detected: ${ISSUES[*]}"
  printf '%s\n' "${ISSUES[@]}"
fi
