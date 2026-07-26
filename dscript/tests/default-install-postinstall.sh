#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/app" "$TEST_ROOT/base/runtime/scripts"
cat > "$TEST_ROOT/app/.env" <<'EOF'
APP_NAME=dpanel-test
EOF

cat > "$TEST_ROOT/base/runtime/scripts/configure-phpmyadmin-signon.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "phpmyadmin-ran" > "$TEST_ROOT/marker"
EOF
chmod +x "$TEST_ROOT/base/runtime/scripts/configure-phpmyadmin-signon.sh"

export TEST_ROOT
export DPANEL_BASE_DIR="$TEST_ROOT/base"
export DPANEL_RUNTIME_DIR="$TEST_ROOT/base/runtime"
export PANEL_APP_DIR="$TEST_ROOT/app"
export DPANEL_LAUNCHER="$TEST_ROOT/bin/panel"
export DPANEL_DUAL_LAUNCHER="$TEST_ROOT/bin/dpanel"
export DRUST_API_TOKEN="demo-token"
export PANEL_DOMAIN="installer.localhost"
export PANEL_PORT="80"

# shellcheck disable=SC1091
source "$ROOT/bootstrap/core.sh"

panel_finalize_default_install

[[ -f "$TEST_ROOT/app/.env" ]]
if ! grep -q '^SERVERPANEL_EXECUTION_API_BASE_URL=http://127.0.0.1:9500$' "$TEST_ROOT/app/.env"; then
  echo 'missing SERVERPANEL_EXECUTION_API_BASE_URL' >&2
  exit 1
fi
if ! grep -q '^SERVERPANEL_EXECUTION_API_TOKEN=demo-token$' "$TEST_ROOT/app/.env"; then
  echo 'missing SERVERPANEL_EXECUTION_API_TOKEN' >&2
  exit 1
fi
if ! grep -q '^PHPMYADMIN_URL=http://installer.localhost/phpmyadmin/$' "$TEST_ROOT/app/.env"; then
  echo 'missing PHPMYADMIN_URL' >&2
  exit 1
fi
if [[ ! -f "$TEST_ROOT/marker" ]]; then
  echo 'phpMyAdmin setup script was not run' >&2
  exit 1
fi

printf 'default install postinstall test passed.\n'
