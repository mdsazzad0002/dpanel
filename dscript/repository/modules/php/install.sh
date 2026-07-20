#!/usr/bin/env bash
set -euo pipefail

DPANEL_BASE_DIR="${DPANEL_BASE_DIR:-/opt/dpanel}"
DPANEL_RUNTIME_DIR="${DPANEL_RUNTIME_DIR:-${DPANEL_BASE_DIR}/runtime}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../../../" && pwd)"

if [[ -f "${REPO_ROOT}/bootstrap/core.sh" && -f "${REPO_ROOT}/core/package-manager.sh" ]]; then
  # shellcheck disable=SC1091
  source "${REPO_ROOT}/bootstrap/core.sh"
  # shellcheck disable=SC1091
  source "${REPO_ROOT}/core/package-manager.sh"
else
  # shellcheck disable=SC1091
  source "${DPANEL_RUNTIME_DIR}/core.sh"
  # shellcheck disable=SC1091
  source "${DPANEL_RUNTIME_DIR}/package-manager.sh"
fi

action="${1:-install}"
php_version="${2:-${PHP_VERSION:-8.3}}"

if [[ "$php_version" == "$action" && -n "${3:-}" ]]; then
  php_version="$3"
fi

if [[ "$php_version" == "all" || -z "$php_version" ]]; then
  php_version="${PHP_VERSION:-8.3}"
fi

php_core_packages() {
  case "$(pkg_distro_family)" in
    debian)
      printf '%s\n' \
        "php${php_version}-cli" \
        "php${php_version}-common" \
        "php${php_version}-curl" \
        "php${php_version}-fpm" \
        "php${php_version}-mbstring" \
        "php${php_version}-mysql" \
        "php${php_version}-xml" \
        "php${php_version}-zip"
      ;;
    rpm)
      printf '%s\n' \
        php \
        php-cli \
        php-common \
        php-fpm \
        php-mbstring \
        php-mysqlnd \
        php-xml \
        php-zip \
        php-curl
      ;;
  esac
}

php_extension_packages() {
  case "$(pkg_distro_family)" in
    debian)
      printf '%s\n' \
        "php${php_version}-bcmath" \
        "php${php_version}-gd" \
        "php${php_version}-intl" \
        "php${php_version}-opcache" \
        "php${php_version}-readline" \
        "php${php_version}-soap"
      ;;
    rpm)
      printf '%s\n' \
        php-bcmath \
        php-gd \
        php-intl \
        php-opcache \
        php-readline \
        php-soap
      ;;
  esac
}

php_reload_service() {
  local service
  service="$(pkg_php_fpm_service "$php_version")"

  if systemctl cat "$service" >/dev/null 2>&1; then
    pkg_enable_service "$service"
    pkg_restart_service "$service"
  fi
}

php_stop_service() {
  local service
  service="$(pkg_php_fpm_service "$php_version")"

  if systemctl cat "$service" >/dev/null 2>&1; then
    systemctl disable --now "$service" >/dev/null 2>&1 || true
  fi
}

php_install() {
  local core_packages=()
  local extension_packages=()

  pkg_require_root
  pkg_install_php_stack "$php_version"
  mapfile -t extension_packages < <(php_extension_packages)
  pkg_install_available "${extension_packages[@]}"
  php_reload_service
  panel_info_log "php ${php_version} installed."
}

php_remove() {
  local core_packages=()
  local extension_packages=()

  pkg_require_root
  mapfile -t core_packages < <(php_core_packages)
  mapfile -t extension_packages < <(php_extension_packages)

  php_stop_service
  case "$(pkg_distro_family)" in
    debian)
      pkg_remove_if_installed "${extension_packages[@]}"
      pkg_remove_if_installed "${core_packages[@]}"
      ;;
    rpm)
      pkg_remove_if_installed "${extension_packages[@]}"
      pkg_remove_if_installed "${core_packages[@]}"
      ;;
  esac
  panel_info_log "php ${php_version} removed."
}

php_update() {
  php_install
  php_reload_service
  panel_info_log "php ${php_version} updated."
}

case "$action" in
  install)
    php_install
    ;;
  reinstall)
    php_remove
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
