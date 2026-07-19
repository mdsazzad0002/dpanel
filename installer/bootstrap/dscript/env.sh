#!/usr/bin/env bash
set -euo pipefail

# Minimal bootstrap environment.
# Keep only the values the installer phases actually consume.

DSCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DSCRIPT_BASE_DIR="${DSCRIPT_BASE_DIR:-/var/www}"

# Database bootstrap values.
PANEL_DB_NAME="${PANEL_DB_NAME:-dpanel}"
PANEL_DB_USER="${PANEL_DB_USER:-dpanel}"
PANEL_DB_HOST="${PANEL_DB_HOST:-127.0.0.1}"
PANEL_DB_PORT="${PANEL_DB_PORT:-3306}"
PANEL_DB_PASSWORD="${PANEL_DB_PASSWORD:-}"
PANEL_DB_CHARSET="${PANEL_DB_CHARSET:-utf8mb4}"
PANEL_DB_COLLATION="${PANEL_DB_COLLATION:-utf8mb4_unicode_ci}"

# Supported PHP versions for initial install.
SUPPORTED_PHP_VERSIONS="${SUPPORTED_PHP_VERSIONS:-7.4 8.0 8.2 8.3 8.4 8.5}"

# OS detection output.
DISTROFamily=""
DISTRO_ID=""
DISTRO_VERSION=""
