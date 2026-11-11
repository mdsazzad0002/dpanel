#!/usr/bin/env bash
# Backlog U6: panel-update.sh backs up, updates, rolls back on a failed check,
# and restores a backup, all against a fake install in a temp folder. The
# release download, the chain, php and systemd are stand-ins.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$TEST_ROOT"' EXIT

WWW="$TEST_ROOT/www"
RUNS="$TEST_ROOT/runs"
PANEL="$WWW/dpanel"
mkdir -p "$PANEL/database/migrations" "$PANEL/vendor" "$PANEL/storage/logs" "$PANEL/public/build" \
  "$WWW/drust/src" "$WWW/drust/target" "$WWW/dscript" "$RUNS" "$TEST_ROOT/bin"
cat > "$PANEL/.env" <<EOF
APP_VERSION=1.0.0
DPANEL_RELEASE_REF=v1.0.0
DB_CONNECTION=sqlite
DB_DATABASE="$PANEL/database/database.sqlite"
EOF
printf 'old-db\n' > "$PANEL/database/database.sqlite"
printf 'old\n' > "$PANEL/app.txt"
printf 'vendor\n' > "$PANEL/vendor/keep.txt"
printf 'log\n' > "$PANEL/storage/logs/panel.log"
printf 'fn main() {}\n' > "$WWW/drust/src/main.rs"
printf 'binary\n' > "$WWW/drust/target/drust"
printf '#!/bin/bash\n' > "$WWW/dscript/dpanel"

# php artisan: migrations fail while a broken migration is in place.
cat > "$TEST_ROOT/bin/php" <<'EOF'
#!/usr/bin/env bash
case "$2" in
  migrate) [[ ! -f database/migrations/broken.php ]] ;;
  migrate:status) printf 'Migration name .... Ran\n' ;;
esac
EOF
chmod 0755 "$TEST_ROOT/bin/php"

# Stands in for installer.sh: new code, a new migration, a migrated database.
cat > "$TEST_ROOT/install" <<'EOF'
set -e
printf 'new\n' > "$PANEL/app.txt"
printf '<?php\n' > "$PANEL/database/migrations/${MIGRATION:-new}.php"
printf 'new-db\n' > "$PANEL/database/database.sqlite"
sed -i 's/^APP_VERSION=.*/APP_VERSION=1.1.0/; s/^DPANEL_RELEASE_REF=.*/DPANEL_RELEASE_REF=v1.1.0/' "$PANEL/.env"
printf '{}' > "$PANEL/public/build/manifest.json"
touch -d "@$(( $(date +%s) + 5 ))" "$PANEL/public/build/manifest.json"
printf 'installed %s from %s\n' "$1" "$2" >> "$TEST_ROOT/actions.log"
EOF
cat > "$TEST_ROOT/refresh" <<'EOF'
printf '{}' > "$PANEL/public/build/manifest.json"
touch -d "@$(( $(date +%s) + 5 ))" "$PANEL/public/build/manifest.json"
printf 'refreshed\n' >> "$TEST_ROOT/actions.log"
EOF

export PANEL TEST_ROOT
export PATH="$TEST_ROOT/bin:$PATH"
export PANEL_UPDATE_INSTALL_CMD="source $TEST_ROOT/install"
export PANEL_UPDATE_REFRESH_CMD="source $TEST_ROOT/refresh"
export PANEL_UPDATE_DRUST_UNIT=""

fail() {
  printf 'FAIL: %s\n' "$*" >&2
  for log in "$RUNS"/*/log; do printf -- '--- %s\n' "$log" >&2; cat "$log" >&2; done
  exit 1
}

state() {
  grep -E "^$2=" "$RUNS/$1/state" | tail -n 1 | cut -d= -f2-
}

run() {
  local id="$1"
  shift
  mkdir -p "$RUNS/$id"
  printf 'action=%s\nstatus=queued\nstarted_at=2026-11-11T00:00:00Z\n' "$1" > "$RUNS/$id/state"
  bash "$ROOT/scripts/panel-update.sh" "$1" --run-dir "$RUNS/$id" --root "$WWW" "${@:2}"
}

# 1. A good update: backed up first, then installed and checked.
run 1 update --ref v1.1.0 --repo me/dpanel
[[ "$(state 1 status)" == succeeded ]] || fail "update status is $(state 1 status)"
[[ "$(state 1 from_version)" == 1.0.0 && "$(state 1 to_version)" == 1.1.0 ]] || fail "versions not recorded"
[[ "$(state 1 from_ref)" == v1.0.0 ]] || fail "previous ref not recorded"
[[ -f "$RUNS/1/backup/complete" ]] || fail "no complete backup"
grep -qx 'old-db' "$RUNS/1/backup/database.sqlite" || fail "database backup is not the old database"
grep -q 'APP_VERSION=1.0.0' "$RUNS/1/backup/env" || fail ".env backup is not the old one"
listing="$(tar -tzf "$RUNS/1/backup/code.tar.gz")"
grep -q '^dpanel/app.txt$' <<< "$listing" || fail "code backup misses the panel"
grep -q '^drust/src/main.rs$' <<< "$listing" || fail "code backup misses drust"
if grep -qE '^dpanel/(vendor|storage|\.env|database/database\.sqlite)|^drust/target' <<< "$listing"; then fail "code backup holds what it should leave out"; fi
grep -qx 'installed v1.1.0 from me/dpanel' "$TEST_ROOT/actions.log" || fail "installer not run with the ref and repo"
[[ "$(stat -c %a "$RUNS/1/backup")" == 700 ]] || fail "backup folder is readable by others"

# 2. A manual rollback of that update: code, .env and database come back,
#    the added migration goes, and what the backup left out stays.
printf 'log2\n' >> "$PANEL/storage/logs/panel.log"
run 2 rollback --from "$RUNS/1/backup"
[[ "$(state 2 status)" == succeeded ]] || fail "rollback status is $(state 2 status)"
grep -qx 'old' "$PANEL/app.txt" || fail "code not restored"
[[ ! -e "$PANEL/database/migrations/new.php" ]] || fail "the added migration stayed"
grep -qx 'old-db' "$PANEL/database/database.sqlite" || fail "database not restored"
grep -q '^APP_VERSION=1.0.0$' "$PANEL/.env" || fail ".env not restored"
[[ "$(state 2 to_version)" == 1.0.0 ]] || fail "rollback target not recorded"
grep -qx 'vendor' "$PANEL/vendor/keep.txt" || fail "vendor was touched"
grep -qx 'log2' "$PANEL/storage/logs/panel.log" || fail "storage was touched"
grep -qx 'binary' "$WWW/drust/target/drust" || fail "the drust build folder was touched"
[[ -f "$RUNS/2/backup/complete" ]] || fail "a rollback backs up first too"

# 3. An update whose migration fails is undone on its own.
MIGRATION=broken run 3 update --ref v1.1.0
[[ "$(state 3 status)" == rolled_back ]] || fail "failed update status is $(state 3 status)"
grep -q 'previous version was restored' "$RUNS/3/state" || fail "no message about the restore"
grep -qx 'old' "$PANEL/app.txt" || fail "failed update left new code"
[[ ! -e "$PANEL/database/migrations/broken.php" ]] || fail "failed update left its migration"
grep -qx 'old-db' "$PANEL/database/database.sqlite" || fail "failed update left its database"

# 4. Keeping the database on a rollback leaves it alone.
run 4 update --ref v1.1.0
printf 'changed-later\n' > "$PANEL/database/database.sqlite"
run 5 rollback --from "$RUNS/4/backup" --keep-database
[[ "$(state 5 status)" == succeeded ]] || fail "rollback without database status is $(state 5 status)"
grep -qx 'changed-later' "$PANEL/database/database.sqlite" || fail "--keep-database restored the database"
grep -qx 'old' "$PANEL/app.txt" || fail "code not restored without the database"

# 5. A database that cannot be backed up stops the update before any change.
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlsrv/' "$PANEL/.env"
run 6 update --ref v1.1.0
[[ "$(state 6 status)" == failed ]] || fail "unsupported database status is $(state 6 status)"
grep -qx 'old' "$PANEL/app.txt" || fail "an update without a backup changed the code"
[[ ! -d "$RUNS/6/backup" ]] || fail "a half backup stayed"

echo "panel-update tests passed"
