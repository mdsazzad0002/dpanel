#!/usr/bin/env bash
set -euo pipefail

PACKAGE_NAME="dovecot-mysql"
CHECK_ONLY=0
SKIP_UPDATE=0

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
log "Done."
