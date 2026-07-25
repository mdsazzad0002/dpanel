#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

usage() {
  echo "Usage: $0 <domain> [port] [--alias domain] [--no-web-stack]"
}

domain="${1:-}"
if [[ -z "$domain" || "$domain" == "-h" || "$domain" == "--help" ]]; then
  usage
  [[ -n "$domain" ]] && exit 0 || exit 64
fi
shift || true

port="${1:-80}"
if [[ "${port}" =~ ^[0-9]+$ ]]; then
  shift || true
else
  port="80"
fi

aliases=()
run_web_stack=true
while [[ $# -gt 0 ]]; do
  case "$1" in
    --alias)
      shift || true
      [[ -n "${1:-}" ]] || { echo "--alias requires a domain" >&2; exit 64; }
      aliases+=("--alias" "$1")
      ;;
    --no-web-stack)
      run_web_stack=false
      ;;
    *)
      echo "Unknown option: $1" >&2
      usage
      exit 64
      ;;
  esac
  shift || true
done

env_file="${PANEL_APP_ENV_FILE:-/var/www/dpanel/.env}"
server_json="${DPANEL_SERVER_JSON:-/opt/dpanel/server.json}"
scheme="http"
if [[ "$port" == "443" ]]; then
  scheme="https"
fi
app_url="${scheme}://${domain}"
if [[ "$port" != "80" && "$port" != "443" ]]; then
  app_url="${app_url}:${port}"
fi

set_env() {
  local file="$1" key="$2" value="$3" tmp="${file}.tmp"
  [[ -f "$file" ]] || touch "$file"
  awk -v key="$key" -v value="$value" '
    BEGIN { found = 0 }
    $0 ~ "^" key "=" {
      print key "=" value
      found = 1
      next
    }
    { print }
    END {
      if (!found) print key "=" value
    }
  ' "$file" > "$tmp"
  mv "$tmp" "$file"
}

if [[ -f "$env_file" ]]; then
  set_env "$env_file" PANEL_DOMAIN "$domain"
  set_env "$env_file" PANEL_PORT "$port"
  set_env "$env_file" APP_URL "$app_url"
  if [[ -x "$(dirname "$env_file")/artisan" ]]; then
    (cd "$(dirname "$env_file")" && php artisan config:clear >/dev/null 2>&1 || true)
  fi
else
  echo "Panel .env not found, skipped env update: $env_file" >&2
fi

if [[ -f "$server_json" ]] && command -v python3 >/dev/null 2>&1; then
  python3 - "$server_json" "$domain" "$port" <<'PY'
import json, sys
path, domain, port = sys.argv[1:4]
with open(path, "r", encoding="utf-8") as handle:
    data = json.load(handle)
data["panel_domain"] = domain
data["panel_port"] = port
with open(path, "w", encoding="utf-8") as handle:
    json.dump(data, handle, indent=2)
    handle.write("\n")
PY
fi

if [[ "$run_web_stack" == "true" ]]; then
  bash "${SCRIPT_DIR}/fix-panel-web-stack.sh" "$domain" "${aliases[@]}"
fi

echo "Panel domain updated:"
echo "  PANEL_DOMAIN=${domain}"
echo "  PANEL_PORT=${port}"
echo "  APP_URL=${app_url}"
