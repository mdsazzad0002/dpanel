#!/usr/bin/env bash
set -euo pipefail

APACHE_BACKEND_PORT="${1:-8080}"
NGINX_FRONTEND_PORT="${2:-80}"

log() {
    printf '[fix-web-stack] %s\n' "$*"
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

fix_debian_apache_ports() {
    local ports_conf="/etc/apache2/ports.conf"
    local target_port
    target_port="$(normalize_port "${APACHE_BACKEND_PORT}" "8080")"

    if [[ ! -f "${ports_conf}" ]]; then
        log "Apache ports file not found: ${ports_conf}"
        return 0
    fi

    backup_file "${ports_conf}"

    if ! grep -qE "^[[:space:]]*Listen[[:space:]]+${target_port}[[:space:]]*$" "${ports_conf}"; then
        echo "Listen ${target_port}" >> "${ports_conf}"
    fi

    sed -i -E \
        -e 's/^[[:space:]]*Listen[[:space:]]+80([[:space:]]*)$/# Listen 80/g' \
        -e 's/^[[:space:]]*Listen[[:space:]]+443([[:space:]]*)$/# Listen 443/g' \
        "${ports_conf}"

    local conf
    for conf in /etc/apache2/sites-available/*.conf; do
        [[ -f "${conf}" ]] || continue
        if grep -Eq '<VirtualHost[[:space:]]+\*:(80|8080)>' "${conf}"; then
            backup_file "${conf}"
            sed -i -E "s/<VirtualHost[[:space:]]+\*:(80|8080)>/<VirtualHost *:${target_port}>/g" "${conf}"
        fi
    done
}

fix_rpm_apache_ports() {
    local conf_file="/etc/httpd/conf/httpd.conf"
    local target_port
    target_port="$(normalize_port "${APACHE_BACKEND_PORT}" "8080")"

    if [[ ! -f "${conf_file}" ]]; then
        log "Apache config file not found: ${conf_file}"
        return 0
    fi

    backup_file "${conf_file}"

    if ! grep -qE "^[[:space:]]*Listen[[:space:]]+${target_port}[[:space:]]*$" "${conf_file}"; then
        echo "Listen ${target_port}" >> "${conf_file}"
    fi

    sed -i -E \
        -e 's/^[[:space:]]*Listen[[:space:]]+80([[:space:]]*)$/# Listen 80/g' \
        -e 's/^[[:space:]]*Listen[[:space:]]+443([[:space:]]*)$/# Listen 443/g' \
        "${conf_file}"

    local conf
    for conf in /etc/httpd/conf.d/*.conf; do
        [[ -f "${conf}" ]] || continue
        if grep -Eq '<VirtualHost[[:space:]]+\*:(80|8080)>' "${conf}"; then
            backup_file "${conf}"
            sed -i -E "s/<VirtualHost[[:space:]]+\*:(80|8080)>/<VirtualHost *:${target_port}>/g" "${conf}"
        fi
    done
}

restart_services() {
    if systemctl cat apache2.service >/dev/null 2>&1; then
        apache2ctl -t
        systemctl enable apache2 >/dev/null 2>&1 || true
        systemctl restart apache2
    elif systemctl cat httpd.service >/dev/null 2>&1; then
        httpd -t
        systemctl enable httpd >/dev/null 2>&1 || true
        systemctl restart httpd
    fi

    if systemctl cat nginx.service >/dev/null 2>&1 || command -v nginx >/dev/null 2>&1; then
        nginx -t
        systemctl enable nginx >/dev/null 2>&1 || true
        systemctl restart nginx
    fi
}

ensure_root

case "${ID:-}" in
    ubuntu|debian)
        fix_debian_apache_ports
        ;;
    rocky|almalinux|rhel|centos|fedora)
        fix_rpm_apache_ports
        ;;
    *)
        log "Unsupported distro ${ID:-unknown}; trying Debian-style fix best effort."
        fix_debian_apache_ports
        ;;
esac

restart_services
log "Apache/Nginx stack repaired successfully."
