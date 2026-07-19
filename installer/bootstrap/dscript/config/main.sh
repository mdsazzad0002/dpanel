#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/logs.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/paths.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../env.sh"

dscript_info "Applying main configuration..."

# Create panel user if not exists
if ! id "$PANEL_USER" >/dev/null 2>&1; then
  useradd -m -d "/home/${PANEL_USER}" -s /bin/bash -U "$PANEL_USER" 2>/dev/null || true
  dscript_info "Panel user '${PANEL_USER}' created."
fi

# Ensure panel directories exist
dscript_ensure_panel_dirs

# Configure Apache backend port
if [[ "$DISTROFamily" == "debian" ]]; then
  APACHE_PORTS="/etc/apache2/ports.conf"
  if [[ -f "$APACHE_PORTS" ]]; then
    sed -i "s/^Listen 80$/# Listen 80/" "$APACHE_PORTS" 2>/dev/null || true
    if ! grep -q "Listen ${PANEL_BACKEND_PORT}" "$APACHE_PORTS"; then
      echo "Listen ${PANEL_BACKEND_PORT}" >> "$APACHE_PORTS"
    fi
  fi
fi

# Configure Nginx
if [[ -d "$NGINX_CONF_DIR" ]]; then
  # Remove default config
  rm -f "${NGINX_ENABLED_DIR}/default"
fi

# Set panel permissions
dscript_set_panel_permissions

dscript_info "Main configuration applied."
