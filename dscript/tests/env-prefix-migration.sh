#!/usr/bin/env bash
# A server's .env from before the rename (SERVERPANEL_* keys) gets the new
# dPanel_* names on update; a key already set under the new name wins.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf "$TEST_ROOT"' EXIT

# shellcheck disable=SC1091
source "$ROOT/bootstrap/core.sh"

env_file="$TEST_ROOT/.env"
cat > "$env_file" <<'EOF'
APP_NAME=dPanel
SERVERPANEL_EXECUTION_API_BASE_URL=http://127.0.0.1:9500
SERVERPANEL_EXECUTION_API_TOKEN=a=b=c
SERVERPANEL_MAIL_SERVER_IP=203.0.113.25
dPanel_MAIL_SERVER_IP=198.51.100.7
DB_PASSWORD=x
EOF
chmod 600 "$env_file"

panel_migrate_env_prefix "$env_file"

expected='APP_NAME=dPanel
dPanel_EXECUTION_API_BASE_URL=http://127.0.0.1:9500
dPanel_EXECUTION_API_TOKEN=a=b=c
SERVERPANEL_MAIL_SERVER_IP=203.0.113.25
dPanel_MAIL_SERVER_IP=198.51.100.7
DB_PASSWORD=x'
actual="$(cat "$env_file")"
if [[ "$actual" != "$expected" ]]; then
  printf 'unexpected .env after migration:\n%s\n' "$actual" >&2
  exit 1
fi

# The secret file keeps its mode.
if [[ "$(uname -s)" == Linux && "$(stat -c '%a' "$env_file")" != 600 ]]; then
  echo 'the .env lost its mode' >&2
  exit 1
fi

# A second run changes nothing.
before="$(cat "$env_file")"
panel_migrate_env_prefix "$env_file"
[[ "$(cat "$env_file")" == "$before" ]] || { echo 'not idempotent' >&2; exit 1; }

# No old keys: the file is left alone.
printf 'APP_NAME=dPanel\n' > "$env_file"
panel_migrate_env_prefix "$env_file"
[[ "$(cat "$env_file")" == 'APP_NAME=dPanel' ]] || { echo 'changed a clean file' >&2; exit 1; }

echo 'env prefix migration ok'
