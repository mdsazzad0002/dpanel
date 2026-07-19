#!/usr/bin/env bash
set -euo pipefail

PANEL_DOMAIN="${1:-installer.localhost}"
PANEL_APP_DIR="${PANEL_APP_DIR:-/var/www/dpanel}"
LIKESOFT_REPOSITORY_DIR="${LIKESOFT_REPOSITORY_DIR:-/var/www/dscript}"
SERVER_BASE_DIR="${SERVER_BASE_DIR:-/var/www}"
PANEL_SERVER_ALIAS="${PANEL_SERVER_ALIAS:-dev.${PANEL_DOMAIN}}"

export PANEL_APP_DIR
export LIKESOFT_REPOSITORY_DIR
export SERVER_BASE_DIR
export PANEL_SERVER_ALIAS

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "${SCRIPT_DIR}/fix-panel-web-stack.sh" "${PANEL_DOMAIN}"
