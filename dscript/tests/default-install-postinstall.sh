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

cat > "$TEST_ROOT/base/runtime/scripts/reconcile-system-records.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" > "$TEST_ROOT/reconcile-args"
EOF
chmod +x "$TEST_ROOT/base/runtime/scripts/reconcile-system-records.sh"

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

# Keep this regression test focused on finalization wiring. Package builds,
# migrations, daemon restarts and permission repair have their own coverage.
panel_collect_first_install_config() { :; }
panel_ensure_swap() { :; }
panel_install_app_dependencies() { :; }
panel_run_app_migrations() { :; }
panel_install_frontend_assets() { :; }
panel_refresh_drust_service() { :; }
panel_refresh_app_config_cache() { :; }
panel_fix_app_permissions() { :; }

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
if ! grep -Fq -- "--domain installer.localhost --root $TEST_ROOT/app" "$TEST_ROOT/reconcile-args"; then
  echo 'panel system records were not reconciled against the panel app root' >&2
  exit 1
fi
if find "$TEST_ROOT/base" -path '*/generated/pools/*' -type f -print -quit | grep -q .; then
  echo 'panel domain was incorrectly scaffolded as a customer website' >&2
  exit 1
fi

printf 'default install postinstall test passed.\n'
