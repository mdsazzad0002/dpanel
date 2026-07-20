#!/usr/bin/env bash
set -euo pipefail

DPANEL_PACKAGE_MANAGER_SOURCE="${BASH_SOURCE[0]}"
DPANEL_BASE_DIR="${DPANEL_BASE_DIR:-/opt/dpanel}"
DPANEL_RUNTIME_DIR="${DPANEL_RUNTIME_DIR:-${DPANEL_BASE_DIR}/runtime}"

pkg_require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "Package operations require root." >&2
    exit 1
  fi
}

pkg_distro_family() {
  if [[ -z "${DISTRO:-}" && -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
    DISTRO="${ID:-unknown}"
  fi

  case "${DISTRO:-}" in
    ubuntu|debian)
      printf 'debian'
      ;;
    rocky|almalinux)
      printf 'rpm'
      ;;
    *)
      printf 'unknown'
      ;;
  esac
}

pkg_update_index() {
  case "$(pkg_distro_family)" in
    debian)
      if [[ "${DPANEL_APT_UPDATED:-false}" != "true" ]]; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -y
        export DPANEL_APT_UPDATED=true
      fi
      ;;
    rpm)
      dnf makecache -y || true
      ;;
  esac
}

pkg_install() {
  pkg_require_root
  pkg_update_index

  case "$(pkg_distro_family)" in
    debian)
      export DEBIAN_FRONTEND=noninteractive
      apt-get install -y "$@"
      ;;
    rpm)
      dnf install -y "$@"
      ;;
    *)
      echo "Unsupported package manager for distro ${DISTRO:-unknown}" >&2
      exit 1
      ;;
  esac
}

pkg_package_installed() {
  local package="$1"

  case "$(pkg_distro_family)" in
    debian)
      dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -q "install ok installed"
      ;;
    rpm)
      rpm -q "$package" >/dev/null 2>&1
      ;;
    *)
      return 1
      ;;
  esac
}

pkg_package_available() {
  local package="$1"

  case "$(pkg_distro_family)" in
    debian)
      apt-cache show "$package" >/dev/null 2>&1
      ;;
    rpm)
      dnf list --available "$package" >/dev/null 2>&1
      ;;
    *)
      return 1
      ;;
  esac
}

pkg_install_available() {
  local package
  local packages=()

  for package in "$@"; do
    if pkg_package_available "$package"; then
      packages+=("$package")
    else
      echo "[WARN] Package not available: $package" >&2
    fi
  done

  [[ ${#packages[@]} -gt 0 ]] || return 0
  pkg_install "${packages[@]}"
}

pkg_remove_if_installed() {
  local package
  local packages=()

  for package in "$@"; do
    if pkg_package_installed "$package"; then
      packages+=("$package")
    fi
  done

  [[ ${#packages[@]} -gt 0 ]] || return 0
  pkg_remove "${packages[@]}"
}

pkg_remove() {
  pkg_require_root

  case "$(pkg_distro_family)" in
    debian)
      export DEBIAN_FRONTEND=noninteractive
      apt-get remove -y "$@"
      ;;
    rpm)
      dnf remove -y "$@"
      ;;
    *)
      echo "Unsupported package manager for distro ${DISTRO:-unknown}" >&2
      exit 1
      ;;
  esac
}

pkg_service_name() {
  local base="$1"
  case "$(pkg_distro_family)" in
    debian)
      printf '%s' "$base"
      ;;
    rpm)
      printf '%s' "$base"
      ;;
    *)
      printf '%s' "$base"
      ;;
  esac
}

pkg_reload_service() {
  local service="$1"
  systemctl daemon-reload || true
  systemctl reload "$service" || systemctl restart "$service"
}

pkg_enable_service() {
  local service="$1"
  systemctl enable "$service" >/dev/null 2>&1 || true
}

pkg_restart_service() {
  local service="$1"
  systemctl restart "$service"
}

pkg_install_php_stack() {
  local version="${1:-8.3}"

  case "$(pkg_distro_family)" in
    debian)
      pkg_install \
        "php${version}-cli" \
        "php${version}-common" \
        "php${version}-curl" \
        "php${version}-fpm" \
        "php${version}-mbstring" \
        "php${version}-mysql" \
        "php${version}-xml" \
        "php${version}-zip"
      ;;
    rpm)
      pkg_install \
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

pkg_php_fpm_service() {
  local version="${1:-8.3}"

  case "$(pkg_distro_family)" in
    debian)
      printf 'php%s-fpm' "$version"
      ;;
    rpm)
      printf 'php-fpm'
      ;;
    *)
      printf 'php-fpm'
      ;;
  esac
}

pkg_install_apache_stack() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_install apache2
      ;;
    rpm)
      pkg_install httpd
      ;;
  esac
}

pkg_install_nginx_stack() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_install nginx
      ;;
    rpm)
      pkg_install nginx
      ;;
  esac
}

pkg_install_web_stack() {
  pkg_install_apache_stack
  pkg_install_nginx_stack
}

pkg_configure_apache_backend_ports() {
  case "$(pkg_distro_family)" in
    debian)
      local ports_conf="/etc/apache2/ports.conf"
      if [[ -f "${ports_conf}" ]]; then
        if ! grep -qE "^[[:space:]]*Listen[[:space:]]+8080[[:space:]]*$" "${ports_conf}"; then
          echo "Listen 8080" >> "${ports_conf}"
        fi

        sed -i -E 's/^[[:space:]]*Listen[[:space:]]+80([[:space:]]*)$/# Listen 80/g' "${ports_conf}" || true
        sed -i -E 's/^[[:space:]]*Listen[[:space:]]+443([[:space:]]*)$/# Listen 443/g' "${ports_conf}" || true
      fi
      ;;
  esac
}

pkg_install_mariadb_stack() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_install mariadb-server mariadb-client
      ;;
    rpm)
      pkg_install mariadb-server mariadb
      ;;
  esac
}

pkg_install_redis_stack() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_install redis-server
      ;;
    rpm)
      pkg_install redis
      ;;
  esac
}

pkg_install_firewall_stack() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_install ufw
      ;;
    rpm)
      pkg_install firewalld
      ;;
  esac
}

pkg_install_supervisor_stack() {
  pkg_install supervisor
}

pkg_install_fail2ban_stack() {
  pkg_install fail2ban
}
