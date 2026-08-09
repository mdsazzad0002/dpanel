#!/usr/bin/env bash
set -Eeuo pipefail

DRUST_ROOT="${DRUST_ROOT:-/var/www/drust}"
DRUST_ENV="${DRUST_ENV:-/etc/drust/drust.env}"
PANEL_ENV="${PANEL_APP_ENV_FILE:-/var/www/dpanel/.env}"
reset_binary=false
reset_secret=false
confirmed=false

usage() {
  cat <<'EOF'
Usage: reset-drust.sh <--binary|--secret|--all> --yes

  --binary  Cleanly rebuild and reinstall the drust binary and services
  --secret  Rotate the API secret and synchronize Laravel with the daemon
  --all     Reset both the binary and API secret
  --yes     Confirm the reset
EOF
}

while (($#)); do
  case "$1" in
    --binary) reset_binary=true ;;
    --secret) reset_secret=true ;;
    --all) reset_binary=true; reset_secret=true ;;
    --yes) confirmed=true ;;
    -h|--help) usage; exit 0 ;;
    *) printf '[ERROR] Unknown option: %s\n' "$1" >&2; usage; exit 64 ;;
  esac
  shift
done

[[ "$(id -u)" -eq 0 ]] || { echo '[ERROR] Run with sudo/root.' >&2; exit 1; }
[[ "$reset_binary" == true || "$reset_secret" == true ]] || { usage; exit 64; }
[[ "$confirmed" == true ]] || { echo '[ERROR] Add --yes to confirm the reset.' >&2; exit 64; }

upsert_secret() {
  local file="$1" key="$2" value="$3" tmp
  [[ -f "$file" ]] || { echo "[ERROR] Required environment file is missing: $file" >&2; exit 1; }
  tmp="$(mktemp "${file}.tmp.XXXXXX")"
  awk -v key="$key" -v value="$value" '
    BEGIN { found = 0 }
    $0 ~ "^" key "=" { print key "=" value; found = 1; next }
    { print }
    END { if (!found) print key "=" value }
  ' "$file" > "$tmp"
  chown --reference="$file" "$tmp"
  chmod --reference="$file" "$tmp"
  mv -f "$tmp" "$file"
}

if [[ "$reset_secret" == true ]]; then
  command -v openssl >/dev/null 2>&1 || { echo '[ERROR] openssl is required.' >&2; exit 1; }
  new_secret="$(openssl rand -hex 32)"
  upsert_secret "$DRUST_ENV" DRUST_API_TOKEN "$new_secret"
  upsert_secret "$PANEL_ENV" SERVERPANEL_EXECUTION_API_TOKEN "$new_secret"
  unset new_secret
  if [[ -x "$(dirname "$PANEL_ENV")/artisan" ]]; then
    (cd "$(dirname "$PANEL_ENV")" && php artisan config:clear >/dev/null 2>&1 || true)
  fi
  printf '[INFO] Drust API secret rotated and synchronized.\n'
fi

if [[ "$reset_binary" == true ]]; then
  [[ -f "$DRUST_ROOT/Cargo.toml" && -x "$DRUST_ROOT/deploy/install-service.sh" ]] || {
    echo "[ERROR] Drust source or installer is missing under $DRUST_ROOT." >&2
    exit 1
  }
  command -v cargo >/dev/null 2>&1 && cargo clean --manifest-path "$DRUST_ROOT/Cargo.toml"
  bash "$DRUST_ROOT/deploy/install-service.sh"
else
  systemctl restart drust.service edge-gateway.service
fi

systemctl is-active --quiet drust.service
systemctl is-active --quiet edge-gateway.service
printf '[INFO] Drust reset completed; services are active.\n'
