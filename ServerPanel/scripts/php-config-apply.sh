#!/usr/bin/env bash
set -euo pipefail

VERSION=""
MEMORY_LIMIT=""
UPLOAD_MAX_FILESIZE=""
POST_MAX_SIZE=""
MAX_EXECUTION_TIME=""
MAX_INPUT_VARS=""
DISPLAY_ERRORS=""
LOG_ERRORS=""
ALLOW_URL_FOPEN=""

usage() {
    cat <<EOF
Usage:
  php-config-apply.sh --version 8.3 \\
    --memory-limit 512M \\
    --upload-max-filesize 256M \\
    --post-max-size 256M \\
    --max-execution-time 300 \\
    --max-input-vars 5000 \\
    --display-errors Off \\
    --log-errors On \\
    --allow-url-fopen On
EOF
}

set_ini_kv() {
    local ini_file="$1"
    local key="$2"
    local value="$3"

    if grep -Eq "^[[:space:]]*;?[[:space:]]*${key}[[:space:]]*=" "${ini_file}"; then
        sed -i -E "s|^[[:space:]]*;?[[:space:]]*${key}[[:space:]]*=.*|${key} = ${value}|g" "${ini_file}"
    else
        printf "\n%s = %s\n" "${key}" "${value}" >> "${ini_file}"
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)
            VERSION="${2:-}"
            shift 2
            ;;
        --memory-limit)
            MEMORY_LIMIT="${2:-}"
            shift 2
            ;;
        --upload-max-filesize)
            UPLOAD_MAX_FILESIZE="${2:-}"
            shift 2
            ;;
        --post-max-size)
            POST_MAX_SIZE="${2:-}"
            shift 2
            ;;
        --max-execution-time)
            MAX_EXECUTION_TIME="${2:-}"
            shift 2
            ;;
        --max-input-vars)
            MAX_INPUT_VARS="${2:-}"
            shift 2
            ;;
        --display-errors)
            DISPLAY_ERRORS="${2:-}"
            shift 2
            ;;
        --log-errors)
            LOG_ERRORS="${2:-}"
            shift 2
            ;;
        --allow-url-fopen)
            ALLOW_URL_FOPEN="${2:-}"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "[php-config-apply] Unknown argument: $1" >&2
            usage >&2
            exit 64
            ;;
    esac
done

if [[ -z "${VERSION}" || -z "${MEMORY_LIMIT}" || -z "${UPLOAD_MAX_FILESIZE}" || -z "${POST_MAX_SIZE}" || -z "${MAX_EXECUTION_TIME}" || -z "${MAX_INPUT_VARS}" || -z "${DISPLAY_ERRORS}" || -z "${LOG_ERRORS}" || -z "${ALLOW_URL_FOPEN}" ]]; then
    echo "[php-config-apply] Missing required arguments." >&2
    usage >&2
    exit 64
fi

if [[ ! "${VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "[php-config-apply] Invalid --version: ${VERSION}" >&2
    exit 64
fi

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "[php-config-apply] This script must run as root." >&2
    exit 77
fi

targets=(
    "/etc/php/${VERSION}/apache2/php.ini"
    "/etc/php/${VERSION}/fpm/php.ini"
    "/etc/php/${VERSION}/cli/php.ini"
)

applied_count=0
for ini_file in "${targets[@]}"; do
    [[ -f "${ini_file}" ]] || continue

    set_ini_kv "${ini_file}" "memory_limit" "${MEMORY_LIMIT}"
    set_ini_kv "${ini_file}" "upload_max_filesize" "${UPLOAD_MAX_FILESIZE}"
    set_ini_kv "${ini_file}" "post_max_size" "${POST_MAX_SIZE}"
    set_ini_kv "${ini_file}" "max_execution_time" "${MAX_EXECUTION_TIME}"
    set_ini_kv "${ini_file}" "max_input_vars" "${MAX_INPUT_VARS}"
    set_ini_kv "${ini_file}" "display_errors" "${DISPLAY_ERRORS}"
    set_ini_kv "${ini_file}" "log_errors" "${LOG_ERRORS}"
    set_ini_kv "${ini_file}" "allow_url_fopen" "${ALLOW_URL_FOPEN}"

    applied_count=$((applied_count + 1))
done

if [[ "${applied_count}" -eq 0 ]]; then
    echo "[php-config-apply] No php.ini targets found for PHP ${VERSION}." >&2
    exit 2
fi

if systemctl cat "php${VERSION}-fpm.service" >/dev/null 2>&1; then
    systemctl restart "php${VERSION}-fpm"
fi

if systemctl cat apache2.service >/dev/null 2>&1; then
    systemctl restart apache2
fi

echo "[php-config-apply] Applied PHP ${VERSION} config and restarted related services."
exit 0
