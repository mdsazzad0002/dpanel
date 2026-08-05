#!/usr/bin/env bash
set -euo pipefail

DOMAIN_RAW="${1:-}"
ROOT_PATH_RAW="${2:-}"
INCLUDE_WWW_RAW="${3:-0}"
shift $(( $# >= 3 ? 3 : $# ))

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
ALIASES=()
if [[ "${INCLUDE_WWW_RAW}" == "1" ]]; then
    INCLUDE_WWW="1"
fi

while (($#)); do
    case "$1" in
        --alias)
            [[ $# -ge 2 ]] || {
                log "Missing value for --alias"
                exit 64
            }
            ALIASES+=("$(normalize_domain "$2")")
            shift 2
            ;;
        *)
            log "Unknown option: $1"
            exit 64
            ;;
    esac
done

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
for alias_domain in "${ALIASES[@]}"; do
    [[ -n "$alias_domain" ]] && domain_args+=(-d "$alias_domain")
done

challenge_dir="${ROOT_PATH}/.well-known/acme-challenge"
well_known_dir="${ROOT_PATH}/.well-known"
printf -v auth_script 'umask 022; mkdir -p %q && printf %%s "$CERTBOT_VALIDATION" > %q/"$CERTBOT_TOKEN"' "$challenge_dir" "$challenge_dir"
printf -v auth_hook '/bin/sh -c %q' "$auth_script"
printf -v cleanup_script 'rm -f -- %q/"$CERTBOT_TOKEN"; rmdir -- %q %q 2>/dev/null || true' "$challenge_dir" "$challenge_dir" "$well_known_dir"
printf -v cleanup_hook '/bin/sh -c %q' "$cleanup_script"

cmd=(
    "${CERTBOT_PATH}"
    certonly
    --non-interactive
    --agree-tos
    --manual
    --preferred-challenges http
    --manual-auth-hook "$auth_hook"
    --manual-cleanup-hook "$cleanup_hook"
    # The vhost points at /etc/letsencrypt/live/<domain>. Without --cert-name a
    # changed domain set makes certbot open a <domain>-0001 lineage instead, and
    # the web server keeps serving the stale certificate from the old path.
    --cert-name "${DOMAIN}"
    --expand
)

if [[ -n "${LETSENCRYPT_EMAIL:-}" ]]; then
    cmd+=(--email "${LETSENCRYPT_EMAIL}")
else
    cmd+=(--register-unsafely-without-email)
fi
cmd+=("${domain_args[@]}")

log "Issuing certificate for ${DOMAIN} (webroot: ${ROOT_PATH})"
"${cmd[@]}"
if ! systemd-run --quiet --collect --on-active=2s systemctl restart edge-gateway.service; then
    log "Certificate issued, but the edge gateway restart could not be scheduled."
    exit 1
fi
log "SSL issue completed for ${DOMAIN}"
exit 0
