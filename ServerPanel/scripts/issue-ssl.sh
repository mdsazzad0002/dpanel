#!/usr/bin/env bash
set -euo pipefail

DOMAIN_RAW="${1:-}"
ROOT_PATH_RAW="${2:-}"
INCLUDE_WWW_RAW="${3:-0}"

log() {
    printf '[issue-ssl] %s\n' "$*"
}

normalize_domain() {
    local value="$1"
    value="$(echo "${value}" | tr '[:upper:]' '[:lower:]' | xargs)"
    echo "${value}"
}

should_add_www_alias() {
    local domain="$1"
    local include_www="$2"

    if [[ "${include_www}" != "1" ]]; then
        return 1
    fi

    if [[ "${domain}" == www.* ]]; then
        return 1
    fi

    if [[ "${domain}" != *.* ]]; then
        return 1
    fi

    return 0
}

DOMAIN="$(normalize_domain "${DOMAIN_RAW}")"
ROOT_PATH="$(echo "${ROOT_PATH_RAW}" | xargs)"
INCLUDE_WWW="0"
if [[ "${INCLUDE_WWW_RAW}" == "1" ]]; then
    INCLUDE_WWW="1"
fi

if [[ -z "${DOMAIN}" || -z "${ROOT_PATH}" ]]; then
    log "Usage: $0 <domain> <root_path> [include_www=0|1]"
    exit 64
fi

if [[ ! -d "${ROOT_PATH}" ]]; then
    log "Root path does not exist: ${ROOT_PATH}"
    exit 66
fi

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    log "This script must run as root."
    exit 77
fi

if ! CERTBOT_PATH="$(command -v certbot 2>/dev/null)"; then
    log "certbot not found. Install certbot first."
    exit 69
fi

domain_args=(-d "${DOMAIN}")
if should_add_www_alias "${DOMAIN}" "${INCLUDE_WWW}"; then
    domain_args+=(-d "www.${DOMAIN}")
fi

cmd=(
    "${CERTBOT_PATH}"
    certonly
    --non-interactive
    --agree-tos
    --register-unsafely-without-email
    --webroot
    -w "${ROOT_PATH}"
)
cmd+=("${domain_args[@]}")

log "Issuing certificate for ${DOMAIN} (webroot: ${ROOT_PATH})"
"${cmd[@]}"
log "SSL issue completed for ${DOMAIN}"
exit 0
