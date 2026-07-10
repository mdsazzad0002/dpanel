#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_PACKAGE_MANAGER_SOURCE="${BASH_SOURCE[0]}"
LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

pkg_require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "Package operations require root." >&2
    exit 1
  fi
}

pkg_distro_family() {
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
      if [[ "${LIKESOFT_APT_UPDATED:-false}" != "true" ]]; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -y
        export LIKESOFT_APT_UPDATED=true
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

pkg_install_web_stack() {
  pkg_install nginx
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
