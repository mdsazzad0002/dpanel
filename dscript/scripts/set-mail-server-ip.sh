#!/usr/bin/env bash
set -euo pipefail

usage() {
  echo "Usage: $0 [<ip> | --detect]"
  echo "  (no argument)  Show the configured and the detected public IP"
  echo "  <ip>           Set SERVERPANEL_MAIL_SERVER_IP to this public IP"
  echo "  --detect       Look up the current public IP and set it"
}

env_file="${PANEL_APP_ENV_FILE:-/var/www/dpanel/.env}"
backup_dir="${PANEL_ENV_BACKUP_DIR:-$(dirname "$env_file")/storage/app/env-backups}"
key="SERVERPANEL_MAIL_SERVER_IP"

detect_ip() {
  local url ip
  for url in https://api.ipify.org https://ifconfig.me/ip https://icanhazip.com; do
    ip="$(curl -4 -fsS --max-time 5 "$url" 2>/dev/null | tr -d '[:space:]')" || continue
    if is_public_ip "$ip"; then
      printf '%s' "$ip"
      return 0
    fi
  done
  return 1
}

is_public_ip() {
  php -r 'exit(filter_var($argv[1], FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) ? 0 : 1);' "$1"
}

current_ip() {
  [[ -f "$env_file" ]] || return 0
  sed -n "s/^${key}=//p" "$env_file" | tail -n 1 | tr -d '"'"'"
}

arg="${1:-}"
case "$arg" in
  -h|--help) usage; exit 0 ;;
esac

[[ -f "$env_file" ]] || { echo "Panel .env not found: $env_file" >&2; exit 1; }

current="$(current_ip)"
echo "Configured mail server IP: ${current:-(unset)}"

if [[ -z "$arg" ]]; then
  echo "Detected public IP:        $(detect_ip || echo '(lookup failed)')"
  exit 0
fi

if [[ "$arg" == "--detect" ]]; then
  ip="$(detect_ip)" || { echo "Could not detect the public IP. Pass it explicitly: dpanel mail:ip <ip>" >&2; exit 1; }
  echo "Detected public IP:        ${ip}"
else
  ip="$arg"
fi

if ! is_public_ip "$ip"; then
  echo "${ip} is not a valid public IP address." >&2
  exit 64
fi

if [[ "$ip" == "$current" ]]; then
  echo "Already set; nothing to change."
  exit 0
fi

[[ -w "$env_file" ]] || { echo "$env_file is not writable. Run with sudo: sudo dpanel mail:ip ${ip}" >&2; exit 1; }

mkdir -p "$backup_dir"
backup="${backup_dir}/.env.$(date +%Y%m%d_%H%M%S).bak"
cp -p "$env_file" "$backup"

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
awk -v key="$key" -v value="$ip" '
  BEGIN { found = 0 }
  $0 ~ "^" key "=" { print key "=" value; found = 1; next }
  { print }
  END { if (!found) print key "=" value }
' "$env_file" > "$tmp"
# Write in place (not mv) so .env keeps its owner, group and ACL.
cat "$tmp" > "$env_file"

if [[ -f "$(dirname "$env_file")/artisan" ]]; then
  (cd "$(dirname "$env_file")" && php artisan config:clear >/dev/null 2>&1 || true)
fi

echo "Mail server IP changed: ${current:-(unset)} -> ${ip}"
echo "Backup: ${backup}"
echo "Also update the mail A record, the SPF ip4: entry and the PTR (reverse DNS) at your ISP."
