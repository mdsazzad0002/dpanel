#!/usr/bin/env bash
set -euo pipefail

PANEL_DOMAIN="${1:-installer.localhost}"
PANEL_BACKEND_PORT="${2:-8080}"
PANEL_FRONTEND_PORT="${3:-80}"

log() {
    printf '[fix-panel-web-stack] %s\n' "$*"
}

backup_file() {
    local path="$1"
    if [[ -f "${path}" ]]; then
        cp -a "${path}" "${path}.bak.$(date +%Y%m%d-%H%M%S)"
    fi
}

ensure_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        log "This script must run as root."
        exit 77
    fi
}

normalize_port() {
    local value="$1"
    local fallback="$2"
    if [[ "${value}" =~ ^[0-9]+$ ]] && (( 10#${value} >= 1 && 10#${value} <= 65535 )); then
        printf '%s' "$((10#${value}))"
    else
        printf '%s' "${fallback}"
    fi
}

detect_app_root() {
    local candidate
    if [[ -n "${PANEL_APP_DIR:-}" ]]; then
        candidate="${PANEL_APP_DIR}"
        if [[ -f "${candidate}/public/index.php" ]]; then
            printf '%s' "${candidate}"
            return 0
        fi
    fi

    for candidate in \
        /home/bdsoft/likesoftbd_com/dpanel \
        /var/www/ServerPanel \
        /var/www/dpanel \
        /opt/likesoft/dpanel
    do
        if [[ -f "${candidate}/public/index.php" ]]; then
            printf '%s' "${candidate}"
            return 0
        fi
    done

    log "Unable to detect panel app root. Set PANEL_APP_DIR to the Laravel project directory."
    exit 66
}

write_apache_backend_vhost() {
    local app_root="$1"
    local port
    port="$(normalize_port "${PANEL_BACKEND_PORT}" "8080")"

    mkdir -p /etc/apache2/sites-available
    backup_file /etc/apache2/ports.conf

    if ! grep -qE "^[[:space:]]*Listen[[:space:]]+${port}[[:space:]]*$" /etc/apache2/ports.conf; then
        echo "Listen ${port}" >> /etc/apache2/ports.conf
    fi

    sed -i -E \
        -e 's/^[[:space:]]*Listen[[:space:]]+80([[:space:]]*)$/# Listen 80/g' \
        -e 's/^[[:space:]]*Listen[[:space:]]+443([[:space:]]*)$/# Listen 443/g' \
        /etc/apache2/ports.conf

    cat > /etc/apache2/sites-available/serverpanel-panel.conf <<EOF
<VirtualHost *:${port}>
    ServerName ${PANEL_DOMAIN}
    ServerAlias www.${PANEL_DOMAIN}
    DocumentRoot ${app_root}/public

    <Directory ${app_root}/public>
        AllowOverride All
        Require all granted
        Options FollowSymLinks
        FallbackResource /index.php
    </Directory>

    DirectoryIndex index.php index.html index.htm

    <FilesMatch \\.php$>
        SetHandler "proxy:unix:/run/php/php${PHP_VERSION:-8.3}-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/serverpanel_panel_error.log
    CustomLog \${APACHE_LOG_DIR}/serverpanel_panel_access.log combined
</VirtualHost>
EOF

    a2enmod proxy proxy_fcgi setenvif rewrite headers >/dev/null 2>&1 || true
    a2ensite serverpanel-panel.conf >/dev/null 2>&1 || true
    a2dissite 000-default.conf >/dev/null 2>&1 || true
}

write_nginx_frontend_vhost() {
    local port
    port="$(normalize_port "${PANEL_BACKEND_PORT}" "8080")"

    mkdir -p /etc/nginx/sites-available /etc/nginx/sites-enabled

    cat > /etc/nginx/sites-available/serverpanel-panel.conf <<EOF
server {
    listen ${PANEL_FRONTEND_PORT};
    listen [::]:${PANEL_FRONTEND_PORT};
    server_name ${PANEL_DOMAIN} www.${PANEL_DOMAIN};

    location / {
        proxy_pass http://127.0.0.1:${port};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
    }
}
EOF

    ln -sfn /etc/nginx/sites-available/serverpanel-panel.conf /etc/nginx/sites-enabled/serverpanel-panel.conf
    rm -f /etc/nginx/sites-enabled/default
}

restart_services() {
    apache2ctl -t
    nginx -t
    systemctl enable apache2 >/dev/null 2>&1 || true
    systemctl enable nginx >/dev/null 2>&1 || true
    systemctl restart apache2
    systemctl restart nginx
}

ensure_root
APP_ROOT="$(detect_app_root)"
write_apache_backend_vhost "$APP_ROOT"
write_nginx_frontend_vhost
restart_services

log "Panel web stack fixed for ${PANEL_DOMAIN} using ${APP_ROOT}/public."
