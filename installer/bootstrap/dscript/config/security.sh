#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/logs.sh"

dscript_info "Applying security configuration..."

# Configure UFW
if command -v ufw >/dev/null 2>&1; then
  ufw allow 22/tcp 2>/dev/null || true
  ufw allow 80/tcp 2>/dev/null || true
  ufw allow 443/tcp 2>/dev/null || true
  ufw --force enable 2>/dev/null || true
  dscript_info "UFW configured."
fi

# Configure Fail2ban
if command -v fail2ban-client >/dev/null 2>&1; then
  if [[ -d /etc/fail2ban ]]; then
    cat > /etc/fail2ban/jail.local <<'CONF'
[DEFAULT]
bantime = 3600
findtime = 600
maxretry = 5
backend = systemd

[sshd]
enabled = true
port = ssh
CONF
    dscript_service_restart fail2ban
    dscript_info "Fail2ban configured."
  fi
fi

# Secure MariaDB
if command -v mysql_secure_installation >/dev/null 2>&1; then
  dscript_info "MariaDB secure installation available (run manually if needed)."
fi

dscript_info "Security configuration applied."
