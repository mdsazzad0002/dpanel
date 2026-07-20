#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TEMPLATE_ROOT="${REPO_ROOT}/repository/templates"
if [[ ! -d "$TEMPLATE_ROOT" && -d "${REPO_ROOT}/templates" ]]; then
    TEMPLATE_ROOT="${REPO_ROOT}/templates"
fi

PACKAGE_NAME="dovecot-mysql"
CHECK_ONLY=0
SKIP_UPDATE=0
PHPMYADMIN_ROOT="${PHPMYADMIN_ROOT:-}"
ROUNDCUBE_ROOT="${ROUNDCUBE_ROOT:-}"
ROUNDCUBE_DB_HOST="${ROUNDCUBE_DB_HOST:-127.0.0.1}"
ROUNDCUBE_DB_PORT="${ROUNDCUBE_DB_PORT:-3306}"
ROUNDCUBE_DB_NAME="${ROUNDCUBE_DB_NAME:-roundcube}"
ROUNDCUBE_DB_USER="${ROUNDCUBE_DB_USER:-roundcube}"
ROUNDCUBE_DB_PASSWORD="${ROUNDCUBE_DB_PASSWORD:-}"

usage() {
    cat <<'EOF'
Usage:
  install-roundcube-dovecot-mysql.sh [--check-only] [--skip-update]

Options:
  --check-only   Only verify whether dovecot-mysql is installed and usable.
  --skip-update  Skip apt-get update before installation.
  -h, --help     Show this help message.
EOF
}

log() {
    echo "[roundcube-dovecot] $*"
}

fail() {
    log "$*" >&2
    exit 1
}

is_installed() {
    dpkg-query -W -f='${Status}' "${PACKAGE_NAME}" 2>/dev/null | grep -q "install ok installed"
}

has_mysql_driver_module() {
    local path=""
    for path in \
        /usr/lib/dovecot/modules/auth/libdriver_mysql.so \
        /usr/lib/dovecot/modules/auth/libdriver_mysql.so.* \
        /usr/lib/dovecot/modules/auth/libauthdb_mysql.so \
        /usr/lib/dovecot/modules/auth/libauthdb_mysql.so.*
    do
        [[ -e "${path}" ]] && return 0
    done

    dpkg -L "${PACKAGE_NAME}" 2>/dev/null | grep -Eq 'mysql.*\.so'
}

render_template() {
    local src="$1"
    local dest="$2"
    shift 2

    mkdir -p "$(dirname "$dest")"

    if command -v python3 >/dev/null 2>&1; then
        python3 - "$src" "$dest" "$@" <<'PY'
import os
import pathlib
import sys

src = pathlib.Path(sys.argv[1])
dest = pathlib.Path(sys.argv[2])
pairs = sys.argv[3:]
content = src.read_text(encoding='utf-8')
for i in range(0, len(pairs), 2):
    key = pairs[i]
    value = pairs[i + 1] if i + 1 < len(pairs) else ''
    content = content.replace('{{' + key + '}}', value)
dest.write_text(content, encoding='utf-8')
PY
        return 0
    fi

    cp "$src" "$dest"
}

detect_phpmyadmin_root() {
    local candidate=""
    for candidate in /usr/share/phpmyadmin /var/www/phpmyadmin /var/www/html/phpmyadmin; do
        if [[ -d "$candidate" ]]; then
            echo "$candidate"
            return 0
        fi
    done

    return 1
}

detect_roundcube_root() {
    local candidate=""
    for candidate in /usr/share/roundcube /var/lib/roundcube /var/www/roundcube; do
        if [[ -d "$candidate" ]]; then
            echo "$candidate"
            return 0
        fi
    done

    return 1
}

generate_secret() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -hex 32
    else
        date +%s%N | sha256sum | awk '{print $1}'
    fi
}

find_mysql_cli() {
    local candidate=""
    for candidate in mariadb mysql; do
        if command -v "$candidate" >/dev/null 2>&1; then
            echo "$candidate"
            return 0
        fi
    done

    return 1
}

roundcube_db_exec() {
    local sql="$1"
    local cli

    cli="$(find_mysql_cli)" || return 1
    "${cli}" --host="${ROUNDCUBE_DB_HOST}" --port="${ROUNDCUBE_DB_PORT}" \
        --user="${ROUNDCUBE_DB_USER}" --password="${ROUNDCUBE_DB_PASSWORD}" \
        --database="${ROUNDCUBE_DB_NAME}" -e "${sql}"
}

roundcube_db_admin_exec() {
    local sql="$1"
    local cli

    cli="$(find_mysql_cli)" || return 1
    "${cli}" --host="${ROUNDCUBE_DB_HOST}" --port="${ROUNDCUBE_DB_PORT}" \
        --user="${ROUNDCUBE_DB_USER}" --password="${ROUNDCUBE_DB_PASSWORD}" \
        -e "${sql}"
}

roundcube_db_is_initialized() {
    local out=""
    if ! out="$(roundcube_db_exec "SHOW TABLES LIKE 'users';" 2>/dev/null | tail -n +2)"; then
        return 1
    fi

    [[ -n "$out" ]]
}

provision_roundcube_database() {
    local db_password db_cli

    db_password="${ROUNDCUBE_DB_PASSWORD:-$(generate_secret)}"
    ROUNDCUBE_DB_PASSWORD="${db_password}"

    db_cli="$(find_mysql_cli)" || fail "No mysql/mariadb client found."

    "${db_cli}" --host="${ROUNDCUBE_DB_HOST}" --port="${ROUNDCUBE_DB_PORT}" \
        -u root -e "CREATE DATABASE IF NOT EXISTS \`${ROUNDCUBE_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

    "${db_cli}" --host="${ROUNDCUBE_DB_HOST}" --port="${ROUNDCUBE_DB_PORT}" \
        -u root -e "CREATE USER IF NOT EXISTS '${ROUNDCUBE_DB_USER}'@'${ROUNDCUBE_DB_HOST}' IDENTIFIED BY '${ROUNDCUBE_DB_PASSWORD}';"
    "${db_cli}" --host="${ROUNDCUBE_DB_HOST}" --port="${ROUNDCUBE_DB_PORT}" \
        -u root -e "ALTER USER '${ROUNDCUBE_DB_USER}'@'${ROUNDCUBE_DB_HOST}' IDENTIFIED BY '${ROUNDCUBE_DB_PASSWORD}';"
    "${db_cli}" --host="${ROUNDCUBE_DB_HOST}" --port="${ROUNDCUBE_DB_PORT}" \
        -u root -e "GRANT ALL PRIVILEGES ON \`${ROUNDCUBE_DB_NAME}\`.* TO '${ROUNDCUBE_DB_USER}'@'${ROUNDCUBE_DB_HOST}'; FLUSH PRIVILEGES;"

    if ! roundcube_db_is_initialized; then
        log "Initializing Roundcube schema in ${ROUNDCUBE_DB_NAME}..."
        "${db_cli}" --host="${ROUNDCUBE_DB_HOST}" --port="${ROUNDCUBE_DB_PORT}" \
            --user="${ROUNDCUBE_DB_USER}" --password="${ROUNDCUBE_DB_PASSWORD}" \
            "${ROUNDCUBE_DB_NAME}" < "${ROUNDCUBE_ROOT}/SQL/mysql.initial.sql"
    fi
}

deploy_phpmyadmin_templates() {
    local root="$1"
    local config_target helper_target secret panel_domain panel_port

    [[ -d "$root" ]] || return 0

    secret="${PHPMYADMIN_BLOWFISH_SECRET:-$(generate_secret)}"
    panel_domain="${PANEL_DOMAIN:-cp.example.com}"
    panel_port="${PANEL_PORT:-2083}"
    config_target="${PHPMYADMIN_CONFIG_TARGET:-}"
    helper_target="${root%/}/phpmyadminsignin.php"

    if [[ -z "$config_target" ]]; then
        if [[ -d /etc/phpmyadmin ]]; then
            config_target="/etc/phpmyadmin/config.inc.php"
        else
            config_target="${root%/}/config.inc.php"
        fi
    fi

    render_template \
        "${TEMPLATE_ROOT}/phpmyadmin/config.inc.php" \
        "$config_target" \
        blowfish_secret "$secret" \
        panel_domain "$panel_domain" \
        panel_port "$panel_port"

    render_template \
        "${TEMPLATE_ROOT}/phpmyadmin/phpmyadminsignin.php" \
        "$helper_target"
}

deploy_roundcube_templates() {
    local root="$1"
    local config_target plugin_root secret

    [[ -d "$root" ]] || return 0

    secret="${ROUNDCUBE_DES_KEY:-$(generate_secret)}"
    config_target="${ROUNDCUBE_CONFIG_TARGET:-}"
    plugin_root="${root%/}/plugins/serverpanel_sso"

    if [[ -z "$config_target" ]]; then
        if [[ -d /etc/roundcube ]]; then
            config_target="/etc/roundcube/config.inc.php"
        else
            config_target="${root%/}/config.inc.php"
        fi
    fi

    render_template \
        "${TEMPLATE_ROOT}/roundcube/config.inc.php" \
        "$config_target" \
        roundcube_des_key "$secret" \
        roundcube_db_host "$ROUNDCUBE_DB_HOST" \
        roundcube_db_port "$ROUNDCUBE_DB_PORT" \
        roundcube_db_name "$ROUNDCUBE_DB_NAME" \
        roundcube_db_user "$ROUNDCUBE_DB_USER" \
        roundcube_db_password "$ROUNDCUBE_DB_PASSWORD"

    mkdir -p "$plugin_root"
    cp "${TEMPLATE_ROOT}/roundcube/plugins/serverpanel_sso/serverpanel_sso.php" "$plugin_root/serverpanel_sso.php"
    cp "${TEMPLATE_ROOT}/roundcube/plugins/serverpanel_sso/README.md" "$plugin_root/README.md"
}

check_installation() {
    if ! is_installed; then
        fail "Package not installed: ${PACKAGE_NAME}"
    fi

    if ! has_mysql_driver_module; then
        fail "Package is installed but MySQL auth driver module was not found."
    fi

    log "Package installed and MySQL auth module detected: ${PACKAGE_NAME}"

    if command -v doveconf >/dev/null 2>&1; then
        if doveconf -n 2>/dev/null | grep -Eqi 'passdb[[:space:]]*\{[^}]*driver[[:space:]]*=[[:space:]]*sql|userdb[[:space:]]*\{[^}]*driver[[:space:]]*=[[:space:]]*sql'; then
            log "Dovecot SQL auth config appears enabled."
        else
            log "Dovecot SQL auth config not detected yet (configure it for Roundcube login)."
        fi
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --check-only)
            CHECK_ONLY=1
            shift
            ;;
        --skip-update)
            SKIP_UPDATE=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            fail "Unknown argument: $1"
            ;;
    esac
done

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    fail "Run this script as root (sudo)."
fi

if [[ ! -f /etc/debian_version ]]; then
    fail "This script currently supports Debian/Ubuntu apt-based systems only."
fi

if ! command -v apt-get >/dev/null 2>&1; then
    fail "apt-get command not found."
fi

if [[ "${CHECK_ONLY}" -eq 1 ]]; then
    check_installation
    exit 0
fi

if [[ "${SKIP_UPDATE}" -eq 0 ]]; then
    log "Running apt-get update..."
    apt-get update
fi

log "Installing ${PACKAGE_NAME}..."
DEBIAN_FRONTEND=noninteractive apt-get install -y "${PACKAGE_NAME}"

check_installation
if phpmyadmin_root="$(detect_phpmyadmin_root 2>/dev/null || true)"; then
    if [[ -n "$phpmyadmin_root" ]]; then
        deploy_phpmyadmin_templates "$phpmyadmin_root"
    fi
fi

if roundcube_root="$(detect_roundcube_root 2>/dev/null || true)"; then
    if [[ -n "$roundcube_root" ]]; then
        ROUNDCUBE_ROOT="$roundcube_root"
        provision_roundcube_database
        deploy_roundcube_templates "$roundcube_root"
    fi
fi

log "Done."
