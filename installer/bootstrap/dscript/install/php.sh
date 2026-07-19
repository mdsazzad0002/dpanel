#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
source "${SCRIPT_DIR}/../shared/logs.sh"

# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../env.sh"

dscript_info "Installing PHP versions..."

add_php_repository() {
  if [[ "$DISTROFamily" == "debian" ]]; then
    if ! command -v add-apt-repository >/dev/null 2>&1; then
      dscript_pkg_install software-properties-common
    fi
    add-apt-repository -y ppa:ondrej/php 2>/dev/null || true
    apt-get update -qq
  fi
}

install_php_version() {
  local version="$1"
  local pkg_suffix="${version//./}"

  if php -v 2>/dev/null | grep -q "PHP ${version}"; then
    dscript_info "PHP ${version} already installed, skipping."
    return 0
  fi

  dscript_info "Installing PHP ${version}..."

  local php_packages=(
    "php${version}-fpm"
    "php${version}-mysql"
    "php${version}-curl"
    "php${version}-gd"
    "php${version}-mbstring"
    "php${version}-xml"
    "php${version}-zip"
    "php${version}-bcmath"
    "php${version}-intl"
    "php${version}-soap"
    "php${version}-redis"
    "php${version}-imagick"
  )

  dscript_pkg_install "${php_packages[@]}" || {
    dscript_warn "Some PHP ${version} packages may not be available, installing minimal set..."
    dscript_pkg_install "php${version}-fpm" "php${version}-mysql" "php${version}-curl" "php${version}-mbstring" "php${version}-xml" || true
  }

  dscript_info "PHP ${version} installed."
}

add_php_repository

for ver in $SUPPORTED_PHP_VERSIONS; do
  install_php_version "$ver"
done

# Set default PHP version to 8.3 if available
if php -v 2>/dev/null | grep -q "PHP 8.3"; then
  update-alternatives --set php /usr/bin/php8.3 2>/dev/null || true
fi

dscript_info "PHP installation completed."
