#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
ENV_FILE="${PROJECT_DIR}/.env"
CLIENT_ID="whmcs-production"
ALLOWED_IP=""
ALLOWED_DOMAIN=""
ROTATE_SECRET=0
PROVIDED_SECRET=""

usage() {
    echo "Usage: sudo bash integrations/whmcs/configure.sh --allowed-ip IP_OR_CIDR --allowed-domain DOMAIN [--client-id ID] [--secret SECRET|--rotate-secret]"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --client-id) CLIENT_ID="${2:-}"; shift 2 ;;
        --allowed-ip) ALLOWED_IP="${2:-}"; shift 2 ;;
        --allowed-domain) ALLOWED_DOMAIN="${2:-}"; shift 2 ;;
        --secret) PROVIDED_SECRET="${2:-}"; shift 2 ;;
        --rotate-secret) ROTATE_SECRET=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 1 ;;
    esac
done

if [[ ! -f "$ENV_FILE" ]]; then
    echo "Missing ${ENV_FILE}. Create the Laravel .env file first." >&2
    exit 1
fi
if [[ ! "$CLIENT_ID" =~ ^[A-Za-z0-9._-]{3,100}$ ]]; then
    echo "Invalid client ID." >&2
    exit 1
fi
if [[ -z "$ALLOWED_IP" || "$ALLOWED_IP" == *$'\n'* || "$ALLOWED_IP" == *$'\r'* ]]; then
    echo "A valid --allowed-ip value is required." >&2
    exit 1
fi
ALLOWED_DOMAIN="${ALLOWED_DOMAIN,,}"
if [[ ! "$ALLOWED_DOMAIN" =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$ ]]; then
    echo "A valid hostname is required for --allowed-domain (no scheme or path)." >&2
    exit 1
fi

get_env() {
    sed -n "s/^${1}=//p" "$ENV_FILE" | tail -n 1
}

set_env() {
    local key="$1" value="$2" temp_file
    temp_file="$(mktemp "${PROJECT_DIR}/.env.whmcs.XXXXXX")"
    awk -v key="$key" -v value="$value" '
        BEGIN { replaced = 0 }
        $0 ~ "^" key "=" {
            if (!replaced) print key "=" value
            replaced = 1
            next
        }
        { print }
        END { if (!replaced) print key "=" value }
    ' "$ENV_FILE" > "$temp_file"
    chmod --reference="$ENV_FILE" "$temp_file"
    chown --reference="$ENV_FILE" "$temp_file"
    mv "$temp_file" "$ENV_FILE"
}

SECRET="$(get_env WHMCS_API_SECRET)"
SECRET="${SECRET%\"}"; SECRET="${SECRET#\"}"
if [[ -n "$PROVIDED_SECRET" ]]; then
    if [[ ${#PROVIDED_SECRET} -lt 32 || ! "$PROVIDED_SECRET" =~ ^[A-Za-z0-9._-]+$ ]]; then
        echo "The supplied secret must be at least 32 characters and contain only letters, numbers, dot, underscore or hyphen." >&2
        exit 1
    fi
    SECRET="$PROVIDED_SECRET"
    SECRET_GENERATED=0
    SECRET_PROVIDED=1
elif [[ ${#SECRET} -lt 32 || "$ROTATE_SECRET" -eq 1 ]]; then
    SECRET="$(openssl rand -hex 32)"
    SECRET_GENERATED=1
    SECRET_PROVIDED=0
else
    SECRET_GENERATED=0
    SECRET_PROVIDED=0
fi

set_env WHMCS_API_CLIENT_ID "$CLIENT_ID"
set_env WHMCS_API_SECRET "$SECRET"
set_env WHMCS_ALLOWED_IPS "$ALLOWED_IP"
set_env WHMCS_ALLOWED_DOMAINS "$ALLOWED_DOMAIN"
set_env WHMCS_TIMESTAMP_TOLERANCE "300"
set_env WHMCS_SSO_TTL "60"

cd "$PROJECT_DIR"
if id www-data >/dev/null 2>&1 && [[ "$(id -u)" -eq 0 ]]; then
    sudo -u www-data php artisan config:cache
else
    php artisan config:cache
fi

echo
echo "WHMCS integration configured and Laravel config cache synchronized."
echo "Client ID: ${CLIENT_ID}"
echo "Allowed IP: ${ALLOWED_IP}"
echo "Allowed domain: ${ALLOWED_DOMAIN}"
if [[ "$SECRET_GENERATED" -eq 1 ]]; then
    echo "API secret (shown once; copy it into WHMCS): ${SECRET}"
elif [[ "$SECRET_PROVIDED" -eq 1 ]]; then
    echo "The supplied API secret was saved. Store the same secret in the WHMCS backend."
else
    echo "Existing API secret preserved. Use --rotate-secret only when you intend to replace it."
fi
