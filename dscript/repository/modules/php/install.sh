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

php_version_below() {
  [[ "$(printf '%s\n%s\n' "$php_version" "$1" | sort -V | head -n 1)" == "$php_version" && "$php_version" != "$1" ]]
}

php_binary_name() {
  case "$(pkg_distro_family)" in
    debian) printf 'php%s' "$php_version" ;;
    *) printf 'php' ;;
  esac
}

# Full patch version (e.g. 8.3.14), empty when this PHP is not installed.
php_patch_version() {
  local php_binary
  php_binary="$(php_binary_name)"

  command -v "$php_binary" >/dev/null 2>&1 || return 0
  "$php_binary" -r 'echo PHP_VERSION;' 2>/dev/null || true
}

php_core_packages() {
  case "$(pkg_distro_family)" in
    debian)
      printf '%s\n' \
        "php${php_version}-cli" \
        "php${php_version}-common" \
        "php${php_version}-curl" \
        "php${php_version}-fpm" \
        "php${php_version}-imap" \
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
        php-imap \
        php-mbstring \
        php-mysqlnd \
        php-xml \
        php-zip \
        php-curl
      ;;
  esac
}

# Optional extensions, installed when the distro offers them for this PHP
# version (pkg_install_available). pgsql is the PDO/native PostgreSQL driver
# that sites connected to a panel PostgreSQL database need.
php_extension_packages() {
  case "$(pkg_distro_family)" in
    debian)
      printf '%s\n' \
        "php${php_version}-bcmath" \
        "php${php_version}-gd" \
        "php${php_version}-intl" \
        "php${php_version}-pgsql" \
        "php${php_version}-readline" \
        "php${php_version}-redis" \
        "php${php_version}-soap"
      # PHP 8.5 compiles opcache into core; there is no package to install.
      if php_version_below 8.5; then
        printf '%s\n' "php${php_version}-opcache"
      fi
      ;;
    rpm)
      printf '%s\n' \
        php-bcmath \
        php-gd \
        php-intl \
        php-opcache \
        php-pgsql \
        php-readline \
        php-redis \
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

php_enable_and_verify_imap() {
  local php_binary

  case "$(pkg_distro_family)" in
    debian|rpm) php_binary="$(php_binary_name)" ;;
    *) panel_die "Unsupported distro for PHP IMAP verification." ;;
  esac

  if [[ "$(pkg_distro_family)" == "debian" ]] && command -v phpenmod >/dev/null 2>&1; then
    phpenmod -v "$php_version" imap
  fi

  php_reload_service

  command -v "$php_binary" >/dev/null 2>&1 || panel_die "PHP ${php_version} binary is unavailable after installation."
  "$php_binary" -r 'exit(extension_loaded("imap") ? 0 : 1);' || \
    panel_die "PHP ${php_version} IMAP extension failed to load after installation."
}

php_stop_service() {
  local service
  service="$(pkg_php_fpm_service "$php_version")"

  if systemctl cat "$service" >/dev/null 2>&1; then
    systemctl disable --now "$service" >/dev/null 2>&1 || true
  fi
}

php_install_packages() {
  local extension_packages=()

  pkg_require_root
  pkg_install_php_stack "$php_version"
  mapfile -t extension_packages < <(php_extension_packages)
  pkg_install_available "${extension_packages[@]}"
}

php_install() {
  php_install_packages
  php_enable_and_verify_imap
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
  local core_packages=()
  local extension_packages=()
  local before after

  before="$(php_patch_version)"
  php_install_packages
  mapfile -t core_packages < <(php_core_packages)
  mapfile -t extension_packages < <(php_extension_packages)
  pkg_upgrade_installed "${core_packages[@]}" "${extension_packages[@]}"
  # Restarts FPM too, so sites run the upgraded binary.
  php_enable_and_verify_imap
  after="$(php_patch_version)"

  if [[ -z "$before" ]]; then
    panel_info_log "php ${php_version} installed (${after:-unknown})."
  elif [[ "$before" == "$after" ]]; then
    panel_info_log "php ${php_version} already up to date (${after})."
  else
    panel_info_log "php ${php_version} updated: ${before} -> ${after}."
  fi
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
