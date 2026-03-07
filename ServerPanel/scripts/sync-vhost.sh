#!/usr/bin/env bash
set -euo pipefail

ACTION="${1:-}"
DOMAIN_RAW="${2:-}"
ROOT_PATH="${3:-}"
PHP_VERSION_RAW="${4:-8.3}"
OLD_DOMAIN_RAW="${5:-}"

log() {
    printf '[sync-vhost] %s\n' "$*"
}

normalize_domain() {
    local value="$1"
    value="$(echo "${value}" | tr '[:upper:]' '[:lower:]' | xargs)"
    echo "${value}"
}

normalize_php_version() {
    local value="$1"
    if [[ "${value}" =~ ^[0-9]+\.[0-9]+$ ]]; then
        echo "${value}"
    else
        echo "8.3"
    fi
}

should_add_www_alias() {
    local domain="$1"
    local dots
    dots="$(awk -F'.' '{print NF-1}' <<< "${domain}")"
    if [[ "${dots}" -lt 1 ]]; then
        return 1
    fi

    if [[ "${domain}" == www.* ]]; then
        return 1
    fi

    return 0
}

reload_apache_if_available() {
    if command -v apache2ctl >/dev/null 2>&1 && systemctl cat apache2.service >/dev/null 2>&1; then
        apache2ctl -t >/dev/null 2>&1 && systemctl reload apache2 >/dev/null 2>&1 || true
    fi
}

reload_nginx_if_available() {
    if command -v nginx >/dev/null 2>&1 && systemctl cat nginx.service >/dev/null 2>&1; then
        nginx -t >/dev/null 2>&1 && systemctl reload nginx >/dev/null 2>&1 || true
    fi
}

sync_apache_vhost() {
    local domain="$1"
    local root_path="$2"
    local php_version="$3"
    local conf_path socket_path server_alias

    [[ -d /etc/apache2/sites-available ]] || return 1
    conf_path="/etc/apache2/sites-available/${domain}.conf"
    socket_path="/run/php/php${php_version}-fpm.sock"
    server_alias=""
    if should_add_www_alias "${domain}"; then
        server_alias=$'\n'"    ServerAlias www.${domain}"
    fi

    cat > "${conf_path}" <<EOF
<VirtualHost *:80>
    ServerName ${domain}${server_alias}
    DocumentRoot ${root_path}

    <Directory ${root_path}>
        AllowOverride All
        Require all granted
    </Directory>

    DirectoryIndex index.php index.html

    <FilesMatch \\.php$>
        SetHandler "proxy:unix:${socket_path}|fcgi://localhost/"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/${domain}_error.log
    CustomLog \${APACHE_LOG_DIR}/${domain}_access.log combined
</VirtualHost>
EOF

    chmod 644 "${conf_path}" || true
    a2ensite "$(basename "${conf_path}")" >/dev/null 2>&1 || true
    reload_apache_if_available
    return 0
}

remove_apache_vhost() {
    local domain="$1"
    local conf_path

    [[ -d /etc/apache2/sites-available ]] || return 1
    conf_path="/etc/apache2/sites-available/${domain}.conf"
    a2dissite "$(basename "${conf_path}")" >/dev/null 2>&1 || true
    rm -f "${conf_path}" || true
    reload_apache_if_available
    return 0
}

sync_nginx_vhost() {
    local domain="$1"
    local root_path="$2"
    local php_version="$3"
    local conf_path enabled_path socket_path server_names

    [[ -d /etc/nginx/sites-available && -d /etc/nginx/sites-enabled ]] || return 1
    conf_path="/etc/nginx/sites-available/${domain}.conf"
    enabled_path="/etc/nginx/sites-enabled/${domain}.conf"
    socket_path="/run/php/php${php_version}-fpm.sock"
    server_names="${domain}"
    if should_add_www_alias "${domain}"; then
        server_names="${server_names} www.${domain}"
    fi

    cat > "${conf_path}" <<EOF
server {
    listen 80;
    server_name ${server_names};
    root ${root_path};
    index index.php index.html index.htm;

    access_log /var/log/nginx/${domain}_access.log;
    error_log /var/log/nginx/${domain}_error.log;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
        fastcgi_index index.php;
        fastcgi_pass unix:${socket_path};
    }

    location ~ /\.ht {
        deny all;
    }
}
EOF

    chmod 644 "${conf_path}" || true
    ln -sfn "${conf_path}" "${enabled_path}"
    reload_nginx_if_available
    return 0
}

remove_nginx_vhost() {
    local domain="$1"
    local conf_path enabled_path

    [[ -d /etc/nginx/sites-available && -d /etc/nginx/sites-enabled ]] || return 1
    conf_path="/etc/nginx/sites-available/${domain}.conf"
    enabled_path="/etc/nginx/sites-enabled/${domain}.conf"
    rm -f "${enabled_path}" || true
    rm -f "${conf_path}" || true
    reload_nginx_if_available
    return 0
}

DOMAIN="$(normalize_domain "${DOMAIN_RAW}")"
OLD_DOMAIN="$(normalize_domain "${OLD_DOMAIN_RAW}")"
PHP_VERSION="$(normalize_php_version "${PHP_VERSION_RAW}")"

if [[ "${ACTION}" != "sync" && "${ACTION}" != "remove" ]]; then
    log "Usage: $0 <sync|remove> <domain> [root_path] [php_version] [old_domain]"
    exit 64
fi

if [[ -z "${DOMAIN}" ]]; then
    log "Domain is required."
    exit 64
fi

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    log "This script must run as root."
    exit 77
fi

did_anything=0

if [[ "${ACTION}" == "sync" ]]; then
    if [[ -z "${ROOT_PATH}" ]]; then
        log "root_path is required for sync action."
        exit 64
    fi

    if [[ -n "${OLD_DOMAIN}" && "${OLD_DOMAIN}" != "${DOMAIN}" ]]; then
        remove_apache_vhost "${OLD_DOMAIN}" && did_anything=1 || true
        remove_nginx_vhost "${OLD_DOMAIN}" && did_anything=1 || true
    fi

    sync_apache_vhost "${DOMAIN}" "${ROOT_PATH}" "${PHP_VERSION}" && did_anything=1 || true
    sync_nginx_vhost "${DOMAIN}" "${ROOT_PATH}" "${PHP_VERSION}" && did_anything=1 || true
else
    remove_apache_vhost "${DOMAIN}" && did_anything=1 || true
    remove_nginx_vhost "${DOMAIN}" && did_anything=1 || true
fi

if [[ "${did_anything}" -eq 0 ]]; then
    log "No Apache/Nginx target found on this server."
    exit 2
fi

log "Completed: action=${ACTION}, domain=${DOMAIN}"
exit 0
