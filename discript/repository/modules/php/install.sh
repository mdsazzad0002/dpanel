#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

action="${1:-install}"
php_version="${2:-${PHP_VERSION:-8.3}}"

php_install() {
  pkg_install_php_stack "$php_version"
  pkg_enable_service "$(pkg_php_fpm_service "$php_version")"
  panel_info_log "php ${php_version} installed."
}

php_remove() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_remove "php${php_version}-cli" "php${php_version}-common" "php${php_version}-curl" "php${php_version}-fpm" "php${php_version}-mbstring" "php${php_version}-mysql" "php${php_version}-xml" "php${php_version}-zip"
      ;;
    rpm)
      pkg_remove php php-cli php-common php-fpm php-mbstring php-mysqlnd php-xml php-zip php-curl
      ;;
  esac
  panel_info_log "php ${php_version} removed."
}

php_update() {
  php_install
  pkg_restart_service "$(pkg_php_fpm_service "$php_version")"
  panel_info_log "php ${php_version} updated."
}

case "$action" in
  install)
    php_install
    ;;
  remove)
    php_remove
    ;;
  update)
    php_update
    ;;
  *)
    panel_die "Unsupported php action: $action"
    ;;
esac
