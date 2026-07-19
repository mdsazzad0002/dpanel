#!/usr/bin/env bash
# Path constants and helpers for dscript

DSCRIPT_BASE_DIR="${DSCRIPT_BASE_DIR:-/var/www}"

# Panel paths
PANEL_APP_DIR="${PANEL_APP_DIR:-${DSCRIPT_BASE_DIR}/dpanel}"
PANEL_STORAGE_DIR="${PANEL_APP_DIR}/storage"
PANEL_CACHE_DIR="${PANEL_APP_DIR}/bootstrap/cache"

# User paths
PANEL_USER="${PANEL_USER:-dpanel}"
PANEL_GROUP="${PANEL_GROUP:-www-data}"

# Web server paths
APACHE_CONF_DIR="/etc/apache2/sites-available"
APACHE_ENABLED_DIR="/etc/apache2/sites-enabled"
NGINX_CONF_DIR="/etc/nginx/sites-available"
NGINX_ENABLED_DIR="/etc/nginx/sites-enabled"

# PHP paths
PHP_CONF_DIR="/etc/php"

# Database paths
MYSQL_CONF="/etc/mysql/mariadb.conf.d/99-serverpanel.cnf"

dscript_ensure_panel_dirs() {
  mkdir -p "$PANEL_STORAGE_DIR" "$PANEL_CACHE_DIR"
}

dscript_set_panel_permissions() {
  if id "$PANEL_USER" >/dev/null 2>&1; then
    chown -R "${PANEL_USER}:${PANEL_GROUP}" "$PANEL_STORAGE_DIR" "$PANEL_CACHE_DIR"
    chmod -R 0775 "$PANEL_STORAGE_DIR" "$PANEL_CACHE_DIR"
  fi
}
