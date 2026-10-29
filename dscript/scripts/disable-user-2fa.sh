#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${PANEL_APP_DIR:-/var/www/dpanel}"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || $# -lt 1 ]]; then
  cat <<'EOF'
Usage: disable-user-2fa.sh <email> [--force]

Turns off two-factor authentication for a panel user who lost their
authenticator app or Telegram. The next login needs only email and password.
EOF
  [[ $# -ge 1 ]] && exit 0 || exit 64
fi

if [[ ! -f "${APP_DIR}/artisan" ]]; then
  echo "[ERROR] Laravel artisan not found at ${APP_DIR}/artisan" >&2
  exit 1
fi

cd "$APP_DIR"
php artisan serverpanel:2fa-disable "$@"
