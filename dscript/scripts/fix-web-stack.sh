#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

apache_port="${1:-8080}"
nginx_port="${2:-80}"
[[ "$apache_port" =~ ^[0-9]+$ && "$nginx_port" =~ ^[0-9]+$ ]] || drust_api_die "Usage: $0 [apache-backend-port] [nginx-frontend-port]"
drust_api_post /api/v1/fix-web-stack "{\"apache_backend_port\":${apache_port},\"nginx_frontend_port\":${nginx_port}}"
