#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$TEST_ROOT"' EXIT

mkdir -p "$TEST_ROOT/app" "$TEST_ROOT/bin"
touch "$TEST_ROOT/app/.env"
touch "$TEST_ROOT/app/artisan"
chmod 0755 "$TEST_ROOT/app/artisan"

cat > "$TEST_ROOT/bin/php" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
code="${3:-}"
case "$code" in
  *'optional(App\Models\Website::query()->find("1"))'*) printf '\n' ;;
  *'optional(App\Models\User::query()->find(1))'*) printf '\n' ;;
  *'Website::query()->updateOrCreate(["id" => "1"]'*)
    [[ "$code" == *'"scope" => "system"'* ]] || {
      echo 'reserved panel records are missing system scope' >&2
      exit 1
    }
    printf 'website:%s:%s:%s\n' "$DPANEL_SYSTEM_DOMAIN" "$DPANEL_SYSTEM_ALIASES" "$DPANEL_SYSTEM_ROOT" >> "$TEST_LOG"
    ;;
  *'User::query()->find(1) ?? new User()'*)
    printf 'user:%s:%s\n' "$DPANEL_SYSTEM_USER_NAME" "$DPANEL_SYSTEM_USER_EMAIL" >> "$TEST_LOG"
    ;;
esac
EOF
chmod 0755 "$TEST_ROOT/bin/php"

export TEST_LOG="$TEST_ROOT/actions.log"
PATH="$TEST_ROOT/bin:$PATH" PANEL_APP_DIR="$TEST_ROOT/app" \
  "$ROOT/scripts/reconcile-system-records.sh" \
  --non-interactive \
  --domain panel.localhost \
  --alias admin.localhost \
  --root "$TEST_ROOT/app" \
  --user-name "Panel System" \
  --user-email system@panel.localhost

grep -Fq "website:panel.localhost:admin.localhost:$TEST_ROOT/app" "$TEST_LOG"
grep -Fq 'user:Panel System:system@panel.localhost' "$TEST_LOG"

printf 'system records reconcile test passed.\n'
