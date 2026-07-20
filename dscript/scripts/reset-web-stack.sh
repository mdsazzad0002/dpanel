#!/usr/bin/env bash
set -euo pipefail

YES=0
SHOW_HELP=0
BACKUP_ROOT="${BACKUP_ROOT:-/var/backups/dpanel-web-reset}"
APACHE_BACKEND_PORT="${APACHE_BACKEND_PORT:-8080}"
NGINX_FRONTEND_PORT="${NGINX_FRONTEND_PORT:-80}"

for arg in "$@"; do
    case "$arg" in
        -y|--yes)
            YES=1
            ;;
        -h|--help)
            SHOW_HELP=1
            ;;
    esac
done

usage() {
    cat <<'EOF'
Usage:
  sudo bash reset-web-stack.sh [--yes]

What it does:
  - backs up Apache/Nginx config files
  - disables all enabled vhosts
  - removes existing site config files
  - writes clean default Apache/Nginx server blocks
  - restarts the services
EOF
}

if [[ "${SHOW_HELP}" -eq 1 ]]; then
    usage
    exit 0
fi

log() {
    printf '[reset-web-stack] %s\n' "$*"
}

ensure_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        log "This script must run as root."
        exit 77
    fi
}

confirm() {
    if [[ "${YES}" -eq 1 ]]; then
        return 0
    fi

    printf '%s' "This will reset Apache/Nginx vhosts and web server config. Continue? [y/N] "
    read -r answer
    case "${answer}" in
        y|Y|yes|YES)
            return 0
            ;;
    esac

    log "Cancelled."
    exit 0
}

backup_configs() {
    local timestamp="$1"
    local target="${BACKUP_ROOT}-${timestamp}"

    mkdir -p "${target}"

    if [[ -d /etc/apache2 ]]; then
        cp -a /etc/apache2 "${target}/apache2"
    fi

    if [[ -d /etc/nginx ]]; then
        cp -a /etc/nginx "${target}/nginx"
    fi

    log "Backup saved at ${target}"
}

reset_apache2_sites() {
    local path
    local site_name

    [[ -d /etc/apache2/sites-enabled ]] || return 0

    log "Step 1/6: disabling Apache sites"
    for path in /etc/apache2/sites-enabled/*; do
        [[ -e "${path}" ]] || continue
        site_name="$(basename "${path}")"
        if command -v a2dissite >/dev/null 2>&1; then
            a2dissite "${site_name}" >/dev/null 2>&1 || true
        fi
        rm -f "${path}" || true
    done

    log "Step 2/6: removing Apache site files"
    find /etc/apache2/sites-available -maxdepth 1 -type f -name '*.conf' -delete 2>/dev/null || true

    log "Step 3/6: restoring Apache ports.conf"
    cat > /etc/apache2/ports.conf <<'EOF'
# ServerPanel reset generated this file.
Listen 8080

<IfModule ssl_module>
    Listen 443
</IfModule>

<IfModule mod_gnutls.c>
    Listen 443
</IfModule>
EOF

    log "Step 3b/6: setting Apache global ServerName"
    mkdir -p /etc/apache2/conf-available
    cat > /etc/apache2/conf-available/servername.conf <<'EOF'
ServerName localhost
EOF
    if command -v a2enconf >/dev/null 2>&1; then
        a2enconf servername.conf >/dev/null 2>&1 || true
    fi

    log "Step 4/6: writing Apache default vhost"
    cat > /etc/apache2/sites-available/000-default.conf <<'EOF'
<VirtualHost *:8080>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

    if command -v a2ensite >/dev/null 2>&1; then
        a2ensite 000-default.conf >/dev/null 2>&1 || true
    fi
}

reset_httpd_sites() {
    local path
    local conf

    [[ -d /etc/httpd/conf.d ]] || return 0

    log "Step 1/6: removing httpd vhost files"
    for path in /etc/httpd/conf.d/*.conf; do
        [[ -e "${path}" ]] || continue
        rm -f "${path}" || true
    done

    log "Step 2/6: restoring httpd main config listener"
    if [[ -f /etc/httpd/conf/httpd.conf ]]; then
        sed -i -E '/^[[:space:]]*Listen[[:space:]]+[0-9]+[[:space:]]*$/d' /etc/httpd/conf/httpd.conf || true
    if ! grep -qE '^[[:space:]]*Listen[[:space:]]+8080[[:space:]]*$' /etc/httpd/conf/httpd.conf; then
        echo 'Listen 8080' >> /etc/httpd/conf/httpd.conf
    fi
    fi

    log "Step 3/6: writing httpd default vhost"
    mkdir -p /etc/httpd/conf.d
    cat > /etc/httpd/conf.d/000-default.conf <<'EOF'
<VirtualHost *:8080>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html

    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog logs/error_log
    CustomLog logs/access_log combined
</VirtualHost>
EOF

    for conf in /etc/httpd/conf.d/*.conf; do
        [[ -e "${conf}" ]] || continue
        break
    done
}

reset_nginx_sites() {
    local path

    [[ -d /etc/nginx/sites-enabled ]] || return 0

    log "Step 4/6: disabling Nginx sites"
    for path in /etc/nginx/sites-enabled/*; do
        [[ -e "${path}" ]] || continue
        rm -f "${path}" || true
    done

    log "Step 5/6: removing Nginx site files"
    find /etc/nginx/sites-available -maxdepth 1 -type f -delete 2>/dev/null || true

    log "Step 6/6: writing Nginx default server block"
    cat > /etc/nginx/sites-available/default <<'EOF'
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root /var/www/html;
    index index.php index.html index.htm;

    location / {
        try_files $uri $uri/ =404;
    }
}
EOF

    ln -sfn /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
}

restart_services() {
    if command -v apache2ctl >/dev/null 2>&1 && systemctl cat apache2.service >/dev/null 2>&1; then
        apache2ctl -t
        systemctl restart apache2
    elif command -v httpd >/dev/null 2>&1 && systemctl cat httpd.service >/dev/null 2>&1; then
        httpd -t
        systemctl restart httpd
    fi

    if command -v nginx >/dev/null 2>&1 && systemctl cat nginx.service >/dev/null 2>&1; then
        nginx -t
        systemctl restart nginx
    fi
}

ensure_root
confirm

timestamp="$(date +%Y%m%d-%H%M%S)"
log "Starting web stack reset"
log "This will remove existing vhost configs and recreate clean defaults."
backup_configs "${timestamp}"

if [[ -d /etc/apache2 ]]; then
    reset_apache2_sites
elif [[ -d /etc/httpd ]]; then
    reset_httpd_sites
fi

reset_nginx_sites
restart_services

log "Web stack reset completed successfully."
