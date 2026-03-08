#!/usr/bin/env bash
set -euo pipefail

ACTION="${1:-}"
DOMAIN_RAW="${2:-}"
ROOT_PATH="${3:-}"
PHP_VERSION_RAW="${4:-8.3}"
OLD_DOMAIN_RAW="${5:-}"
APACHE_BACKEND_PORT_RAW="${APACHE_BACKEND_PORT:-8080}"
NGINX_PRIMARY_PORT_RAW="${NGINX_PRIMARY_PORT:-80}"

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

normalize_port() {
    local value="$1"
    local fallback="$2"
    if [[ "${value}" =~ ^[0-9]+$ ]] && (( 10#${value} >= 1 && 10#${value} <= 65535 )); then
        echo "$((10#${value}))"
    else
        echo "${fallback}"
    fi
}

short_hash() {
    local value="$1"
    local hash

    if command -v sha1sum >/dev/null 2>&1; then
        hash="$(printf '%s' "${value}" | sha1sum | awk '{print $1}')"
    elif command -v shasum >/dev/null 2>&1; then
        hash="$(printf '%s' "${value}" | shasum | awk '{print $1}')"
    else
        hash="$(printf '%s' "${value}" | cksum | awk '{print $1}')"
    fi

    echo "${hash:0:12}"
}

domain_token() {
    local domain="$1"
    local normalized
    normalized="$(echo "${domain}" | tr '[:upper:]' '[:lower:]')"
    normalized="$(echo "${normalized}" | sed -E 's/[^a-z0-9.-]+/-/g; s/^-+//; s/-+$//')"
    if [[ -z "${normalized}" ]]; then
        normalized="site"
    fi
    echo "${normalized}"
}

domain_conf_basename() {
    local domain="$1"
    local token hash
    token="$(domain_token "${domain}")"
    hash="$(short_hash "${domain}")"
    if (( ${#token} > 110 )); then
        token="${token:0:110}"
    fi
    echo "${token}-${hash}"
}

domain_log_basename() {
    local domain="$1"
    local token hash
    token="$(domain_token "${domain}")"
    hash="$(short_hash "${domain}")"
    if (( ${#token} > 52 )); then
        token="${token:0:52}"
    fi
    echo "${token}-${hash}"
}

apache_conf_path() {
    local domain="$1"
    echo "/etc/apache2/sites-available/$(domain_conf_basename "${domain}").conf"
}

apache_legacy_conf_path() {
    local domain="$1"
    echo "/etc/apache2/sites-available/${domain}.conf"
}

nginx_conf_path() {
    local domain="$1"
    echo "/etc/nginx/sites-available/$(domain_conf_basename "${domain}").conf"
}

nginx_enabled_path() {
    local domain="$1"
    echo "/etc/nginx/sites-enabled/$(domain_conf_basename "${domain}").conf"
}

nginx_legacy_conf_path() {
    local domain="$1"
    echo "/etc/nginx/sites-available/${domain}.conf"
}

nginx_legacy_enabled_path() {
    local domain="$1"
    echo "/etc/nginx/sites-enabled/${domain}.conf"
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
    local conf_path legacy_conf_path socket_path server_alias log_basename

    [[ -d /etc/apache2/sites-available ]] || return 1
    conf_path="$(apache_conf_path "${domain}")"
    legacy_conf_path="$(apache_legacy_conf_path "${domain}")"
    socket_path="/run/php/php${php_version}-fpm.sock"
    log_basename="$(domain_log_basename "${domain}")"
    server_alias=""
    if should_add_www_alias "${domain}"; then
        server_alias=$'\n'"    ServerAlias www.${domain}"
    fi

    if [[ "${legacy_conf_path}" != "${conf_path}" ]]; then
        a2dissite "$(basename "${legacy_conf_path}")" >/dev/null 2>&1 || true
        rm -f "${legacy_conf_path}" || true
    fi

    cat > "${conf_path}" <<EOF
<VirtualHost *:${APACHE_BACKEND_PORT}>
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

    ErrorLog \${APACHE_LOG_DIR}/${log_basename}_error.log
    CustomLog \${APACHE_LOG_DIR}/${log_basename}_access.log combined
</VirtualHost>
EOF

    chmod 644 "${conf_path}" || true
    a2ensite "$(basename "${conf_path}")" >/dev/null 2>&1 || true
    reload_apache_if_available
    return 0
}

remove_apache_vhost() {
    local domain="$1"
    local conf_path legacy_conf_path

    [[ -d /etc/apache2/sites-available ]] || return 1
    conf_path="$(apache_conf_path "${domain}")"
    legacy_conf_path="$(apache_legacy_conf_path "${domain}")"
    a2dissite "$(basename "${conf_path}")" >/dev/null 2>&1 || true
    rm -f "${conf_path}" || true
    if [[ "${legacy_conf_path}" != "${conf_path}" ]]; then
        a2dissite "$(basename "${legacy_conf_path}")" >/dev/null 2>&1 || true
        rm -f "${legacy_conf_path}" || true
    fi
    reload_apache_if_available
    return 0
}

sync_nginx_vhost() {
    local domain="$1"
    local root_path="$2"
    local php_version="$3"
    local conf_path enabled_path legacy_conf_path legacy_enabled_path server_names log_basename

    [[ -d /etc/nginx/sites-available && -d /etc/nginx/sites-enabled ]] || return 1
    conf_path="$(nginx_conf_path "${domain}")"
    enabled_path="$(nginx_enabled_path "${domain}")"
    legacy_conf_path="$(nginx_legacy_conf_path "${domain}")"
    legacy_enabled_path="$(nginx_legacy_enabled_path "${domain}")"
    log_basename="$(domain_log_basename "${domain}")"
    server_names="${domain}"
    if should_add_www_alias "${domain}"; then
        server_names="${server_names} www.${domain}"
    fi

    if [[ "${legacy_conf_path}" != "${conf_path}" ]]; then
        rm -f "${legacy_enabled_path}" || true
        rm -f "${legacy_conf_path}" || true
    fi

    cat > "${conf_path}" <<EOF
server {
    listen ${NGINX_PRIMARY_PORT};
    listen [::]:${NGINX_PRIMARY_PORT};
    server_name ${server_names};

    access_log /var/log/nginx/${log_basename}_access.log;
    error_log /var/log/nginx/${log_basename}_error.log;

    location / {
        proxy_pass http://127.0.0.1:${APACHE_BACKEND_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        proxy_next_upstream error timeout http_502 http_503 http_504;
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
    local conf_path enabled_path legacy_conf_path legacy_enabled_path

    [[ -d /etc/nginx/sites-available && -d /etc/nginx/sites-enabled ]] || return 1
    conf_path="$(nginx_conf_path "${domain}")"
    enabled_path="$(nginx_enabled_path "${domain}")"
    legacy_conf_path="$(nginx_legacy_conf_path "${domain}")"
    legacy_enabled_path="$(nginx_legacy_enabled_path "${domain}")"
    rm -f "${enabled_path}" || true
    rm -f "${conf_path}" || true
    if [[ "${legacy_conf_path}" != "${conf_path}" ]]; then
        rm -f "${legacy_enabled_path}" || true
        rm -f "${legacy_conf_path}" || true
    fi
    reload_nginx_if_available
    return 0
}

DOMAIN="$(normalize_domain "${DOMAIN_RAW}")"
OLD_DOMAIN="$(normalize_domain "${OLD_DOMAIN_RAW}")"
PHP_VERSION="$(normalize_php_version "${PHP_VERSION_RAW}")"
APACHE_BACKEND_PORT="$(normalize_port "${APACHE_BACKEND_PORT_RAW}" "8080")"
NGINX_PRIMARY_PORT="$(normalize_port "${NGINX_PRIMARY_PORT_RAW}" "80")"

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

log "Completed: action=${ACTION}, domain=${DOMAIN}, nginx_port=${NGINX_PRIMARY_PORT}, apache_backend_port=${APACHE_BACKEND_PORT}"
exit 0
