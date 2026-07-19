#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/logs.sh"

dscript_info "Installing services..."

# Apache
if ! dscript_program_exists apache2 && ! dscript_program_exists httpd; then
  dscript_info "Installing Apache..."
  if [[ "$DISTROFamily" == "debian" ]]; then
    dscript_pkg_install apache2 libapache2-mod-proxy-fcgi
    a2enmod proxy proxy_fcgi rewrite headers setenvif 2>/dev/null || true
  elif [[ "$DISTROFamily" == "rpm" ]]; then
    dscript_pkg_install httpd mod_fcgid
  fi
fi

# Nginx
if ! dscript_program_exists nginx; then
  dscript_info "Installing Nginx..."
  dscript_pkg_install nginx
fi

# MariaDB
if ! dscript_program_exists mariadb && ! dscript_program_exists mysql; then
  dscript_info "Installing MariaDB..."
  if [[ "$DISTROFamily" == "debian" ]]; then
    dscript_pkg_install mariadb-server mariadb-client
  elif [[ "$DISTROFamily" == "rpm" ]]; then
    dscript_pkg_install mariadb-server mariadb
  fi
fi

# Redis
if ! dscript_program_exists redis-server; then
  dscript_info "Installing Redis..."
  dscript_pkg_install redis-server || dscript_pkg_install redis
fi

# Supervisor
if ! dscript_program_exists supervisord; then
  dscript_info "Installing Supervisor..."
  dscript_pkg_install supervisor
fi

# Firewall
if ! dscript_program_exists ufw && ! dscript_program_exists firewall-cmd; then
  dscript_info "Installing firewall..."
  if [[ "$DISTROFamily" == "debian" ]]; then
    dscript_pkg_install ufw
  elif [[ "$DISTROFamily" == "rpm" ]]; then
    dscript_pkg_install firewalld
  fi
fi

# Fail2ban
if ! dscript_program_exists fail2ban-client; then
  dscript_info "Installing Fail2ban..."
  dscript_pkg_install fail2ban || true
fi

# Enable and start services
for svc in apache2 httpd nginx mariadb mysql redis-server redis supervisord; do
  if systemctl list-unit-files "${svc}.service" >/dev/null 2>&1; then
    dscript_service_enable "$svc"
    dscript_service_restart "$svc"
  fi
done

dscript_info "Services installation completed."
