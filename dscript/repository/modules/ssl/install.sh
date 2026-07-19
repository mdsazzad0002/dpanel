#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

action="${1:-install}"

ssl_install() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_install certbot python3-certbot-nginx python3-certbot-apache
      ;;
    rpm)
      pkg_install certbot python3-certbot-nginx
      ;;
  esac
  panel_info_log "ssl tooling installed."
}

ssl_remove() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_remove certbot python3-certbot-nginx python3-certbot-apache
      ;;
    rpm)
      pkg_remove certbot python3-certbot-nginx
      ;;
  esac
  panel_info_log "ssl tooling removed."
}

ssl_update() {
  ssl_install
  panel_info_log "ssl tooling updated."
}

case "$action" in
  install)
    ssl_install
    ;;
  remove)
    ssl_remove
    ;;
  update)
    ssl_update
    ;;
  *)
    panel_die "Unsupported ssl action: $action"
    ;;
esac
