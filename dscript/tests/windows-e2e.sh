#!/usr/bin/env bash
# Backlog F8: the E6 flows on a Windows PC, end to end, in Git Bash.
#
#   MARIADB_BIN=/c/wamp64/bin/mariadb/mariadb11.4.9/bin bash dscript/tests/windows-e2e.sh
#
# Everything runs isolated under $WORK (a new temporary folder unless given):
# its own MariaDB data on $DB_PORT, drust's API on $API_PORT and gateway on
# $GW_PORT with LOCALAPPDATA and USERPROFILE moved into $WORK, and a panel
# root whose .env and storage are $WORK's (the code is junctioned from this
# checkout). Nothing touches the checkout's .env, database or the real
# hosts file: the queue is the database with no worker, so the hosts job
# (which would show a UAC prompt) never runs. Needs network access (PHP
# from the F3 manifest, WordPress, Laravel through composer), composer and
# node on PATH, and the PHP CLI that runs this panel.
#
# Steps: create a site (API) and serve PHP through the gateway with the
# manifest's NTS PHP; a database (API) and its user's login; WordPress
# install and its wizard; Laravel 12 install and its page; dpanel:backup
# and dpanel:restore round-trip; delete every site (archive, homes, 404).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
MARIADB_BIN="${MARIADB_BIN:?set MARIADB_BIN to a folder with mariadbd.exe and mariadb-install-db.exe}"
WORK="${WORK:-$(mktemp -d)}"
DB_PORT="${DB_PORT:-3399}" API_PORT="${API_PORT:-9600}" GW_PORT="${GW_PORT:-8180}" PANEL_PORT="${PANEL_PORT:-8190}"
DB_ROOT_PW="e2e-$RANDOM-$RANDOM"
PIDS=()

say() { printf '\n== %s\n' "$*"; }
fail() { printf 'FAILED: %s\n' "$*" >&2; exit 1; }
win() { cygpath -w "$1"; }
cleanup() {
  for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
  # Unlink the junctions (rmdir removes only the link), so that deleting
  # $WORK later can never reach into this checkout.
  local link
  for link in "$WORK"/panel/* "$WORK"/panel/public/*; do
    if [[ -d "$link" ]] && powershell -NoProfile -Command "if ((Get-Item -LiteralPath '$(win "$link")').LinkType -eq 'Junction') { exit 0 } else { exit 1 }"; then
      MSYS_NO_PATHCONV=1 cmd /c rmdir "$(win "$link")" || true
    fi
  done
  powershell -NoProfile -Command "Get-Process drust,mariadbd -ErrorAction SilentlyContinue | Where-Object { \$_.Path -like '$(win "$WORK")*' } | Stop-Process -Force" || true
}
trap cleanup EXIT
mkdir -p "$WORK"/{home,local,logs,bin}
echo "work folder: $WORK"

sql() { "$MARIADB_BIN/mariadb.exe" -h127.0.0.1 -P"$DB_PORT" -uroot -p"$DB_ROOT_PW" -N -e "$1"; }
wait_for() { # url, what
  for _ in $(seq 1 60); do curl -s -o /dev/null -m 2 "$1" && return 0; sleep 1; done
  fail "$2 did not start"
}
http_code() { curl -s -m 300 -o "$WORK/last.html" -w '%{http_code}' -H "Host: $1" "http://127.0.0.1:$GW_PORT$2"; }
title() { grep -o '<title>[^<]*</title>' "$WORK/last.html" | head -1; }

say "MariaDB on $DB_PORT"
"$MARIADB_BIN/mariadb-install-db.exe" --datadir="$(win "$WORK/mysql")" --port="$DB_PORT" --password="$DB_ROOT_PW" > "$WORK/logs/mariadb-init.log"
"$MARIADB_BIN/mariadbd.exe" --defaults-file="$(win "$WORK/mysql/my.ini")" --console > "$WORK/logs/mariadb.log" 2>&1 &
PIDS+=($!)
for _ in $(seq 1 60); do grep -q 'ready for connections' "$WORK/logs/mariadb.log" && break; sleep 1; done
sql "CREATE DATABASE dpanel_e2e"

say "Panel root"
P="$WORK/panel"
mkdir -p "$P/bootstrap/cache" "$P/public" "$P/storage/app/public" "$P/storage/framework/"{cache/data,sessions,views} "$P/storage/logs"
# Git Bash would read /J as a path; a junction needs no administrator.
junction() { MSYS_NO_PATHCONV=1 cmd /c mklink /J "$(win "$1")" "$(win "$2")" > /dev/null || fail "junction $1"; }
for dir in app config database lang resources routes vendor; do
  if [[ -d "$ROOT/dpanel/$dir" ]]; then junction "$P/$dir" "$ROOT/dpanel/$dir"; fi
done
# Real copies: PHP resolves __DIR__ through a junction, and base_path() would be the checkout's.
cp "$ROOT/dpanel/bootstrap/"{app.php,providers.php} "$P/bootstrap/"
cp "$ROOT/dpanel/"{artisan,composer.json,composer.lock} "$P/"
for entry in "$ROOT/dpanel/public/"* "$ROOT/dpanel/public/".htaccess; do
  name="$(basename "$entry")"; [[ "$name" == storage ]] && continue
  if [[ -d "$entry" ]]; then junction "$P/public/$name" "$entry"; else cp "$entry" "$P/public/"; fi
done
DRUST_TOKEN="$(openssl rand -hex 24)"
cat > "$P/.env" <<EOF
APP_NAME=dPanel
APP_ENV=local
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=true
APP_URL=http://127.0.0.1:$PANEL_PORT
DPANEL_PROFILE=local
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=$DB_PORT
DB_DATABASE=dpanel_e2e
DB_USERNAME=root
DB_PASSWORD=$DB_ROOT_PW
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=database
LOG_CHANNEL=single
dPanel_EXECUTION_API_BASE_URL=http://127.0.0.1:$API_PORT
dPanel_EXECUTION_API_TOKEN=$DRUST_TOKEN
DRUST_EDGE_GATEWAY_INTERNAL_URL=http://127.0.0.1:$GW_PORT
EOF
artisan() { (cd "$P" && php -d max_execution_time=0 artisan "$@" < /dev/null); }
tinker() { (cd "$P" && E2E_WORK="$WORK" php -d max_execution_time=0 artisan tinker --execute="$1" < /dev/null); }
artisan migrate --force > "$WORK/logs/migrate.log"
artisan db:seed --force > /dev/null
[[ "$(tinker 'echo base_path();' | tr -d '\r' | tail -1)" == "$(win "$P")" ]] || fail "the panel is not using the isolated root"

say "drust"
(cd "$ROOT/drust" && cargo build --no-default-features --bin drust 2>&1 | tail -1)
cp -f "$ROOT/drust/target/debug/drust.exe" "$WORK/bin/drust.exe"
export LOCALAPPDATA="$(win "$WORK/local")" USERPROFILE="$(win "$WORK/home")" DPANEL_PROFILE=local \
  DPANEL_PANEL_ROOT="$(win "$P")" DRUST_API_TOKEN="$DRUST_TOKEN" \
  DRUST_HTTP_BIND="127.0.0.1:$GW_PORT" DRUST_HTTPS_BIND="127.0.0.1:$((GW_PORT + 363))" \
  DRUST_DATABASE_ADMIN_USER=root DRUST_DATABASE_ADMIN_PASSWORD="$DB_ROOT_PW" \
  DRUST_DATABASE_ADMIN_HOST=127.0.0.1 DRUST_DATABASE_ADMIN_PORT="$DB_PORT"
export PATH="$MARIADB_BIN:$PATH"
"$WORK/bin/drust.exe" runtime install php 8.3 > "$WORK/logs/php-install.log" 2>&1 || fail "PHP 8.3 from the manifest"
"$WORK/bin/drust.exe" --port "$API_PORT" > "$WORK/logs/api.log" 2>&1 & PIDS+=($!)
"$WORK/bin/drust.exe" edge-gateway > "$WORK/logs/gateway.log" 2>&1 & PIDS+=($!)
(cd "$P" && php artisan serve --host=127.0.0.1 --port="$PANEL_PORT" > "$WORK/logs/panel.log" 2>&1) & PIDS+=($!)
wait_for "http://127.0.0.1:$API_PORT/health" "drust API"
wait_for "http://127.0.0.1:$PANEL_PORT/up" "panel"

tinker "\$u = \App\Models\User::query()->firstOrCreate(['email' => 'e2e@example.test'], ['name' => 'E2E', 'password' => bcrypt(\Illuminate\Support\Str::random(32))]);
\$u->assignRole('admin'); \$t = \App\Models\ApiToken::generate();
\App\Models\ApiToken::create(['user_id' => \$u->id, 'name' => 'e2e', 'token_prefix' => \$t['prefix'], 'token_hash' => \$t['hash'], 'scopes' => ['websites:read', 'websites:write', 'databases:read', 'databases:write']]);
file_put_contents(getenv('E2E_WORK').'/api-token', \$t['plain']);" > /dev/null
API_TOKEN="$(cat "$WORK/api-token")"
api() { curl -s -m 600 -X "$1" -H "Authorization: Bearer $API_TOKEN" -H 'Accept: application/json' -H 'Content-Type: application/json' ${3:+-d "$3"} "http://127.0.0.1:$PANEL_PORT/api/v1$2"; }
reload() { tinker "app(\App\Services\EdgeGatewayReloader::class)->reloadDomains(['$1']);" > /dev/null; }
site_id() { sql "SELECT id FROM dpanel_e2e.websites WHERE domain='$1'"; }
SITES="$WORK/home/dPanel/sites"

say "A PHP site"
api POST /websites '{"domain":"shop.test","php_version":"8.3"}' | grep -q 'Website created' || fail "creating shop.test"
printf '<?php echo PHP_VERSION, " ", PHP_ZTS ? "zts" : "nts";' > "$SITES/shop_test/public_html/probe.php"
reload shop.test
[[ "$(http_code shop.test /probe.php)" == 200 ]] && grep -q 'nts' "$WORK/last.html" || fail "PHP through the gateway: $(cat "$WORK/last.html")"
echo "served: $(cat "$WORK/last.html")"

say "A database and its login"
api POST /databases '{"website":"shop.test","name":"extra","user":"extra","password":"e2e-password-1"}' | grep -q 'Database created' || fail "creating a database"
"$MARIADB_BIN/mariadb.exe" -h127.0.0.1 -P"$DB_PORT" -ushop_test_extra -pe2e-password-1 shop_test_extra -e 'SELECT 1' > /dev/null || fail "the database user cannot log in"

say "WordPress"
install_wp="\$s = \App\Models\Website::query()->where('domain', 'shop.test')->firstOrFail();
\$r = app(\App\Services\Website\WordpressInstallService::class)->install(\$s->toArray(), ['database_id' => 'new', 'wordpress_version' => 'latest'], \App\Models\User::query()->where('email', 'e2e@example.test')->first());
echo \$r['success'] ? 'ok' : \$r['message'];"
[[ "$(tinker "$install_wp" | tail -1)" == ok ]] || fail "WordPress install"
reload shop.test
[[ "$(http_code shop.test /wp-admin/install.php)" == 200 ]] || fail "WordPress install page: $(title)"
curl -s -m 300 -o "$WORK/last.html" -H 'Host: shop.test' --data-urlencode 'weblog_title=E2E Shop' --data-urlencode 'user_name=e2eadmin' \
  --data-urlencode 'admin_password=E2e-Strong-Pass-2026!' --data-urlencode 'admin_password2=E2e-Strong-Pass-2026!' --data-urlencode 'pw_weak=1' \
  --data-urlencode 'admin_email=e2e@example.test' --data-urlencode 'blog_public=0' "http://127.0.0.1:$GW_PORT/wp-admin/install.php?step=2"
grep -q '<h1>Success!</h1>' "$WORK/last.html" || fail "WordPress wizard"
[[ "$(http_code shop.test /)" == 200 && "$(title)" == '<title>E2E Shop</title>' ]] || fail "WordPress home: $(title)"

say "Laravel"
api POST /websites '{"domain":"lara.test","php_version":"8.3"}' | grep -q 'Website created' || fail "creating lara.test"
install_laravel="\$s = \App\Models\Website::query()->where('domain', 'lara.test')->firstOrFail();
\$r = app(\App\Services\Website\LaravelInstallService::class)->install(\$s, ['stack' => 'blank', 'laravel_version' => '12', 'database_id' => 'new', 'database_engine' => 'mariadb'], \App\Models\User::query()->where('email', 'e2e@example.test')->first());
echo \$r['success'] ? 'ok' : \$r['message'];"
result="$(tinker "$install_laravel" | tail -1)"
[[ "$result" == ok ]] || fail "Laravel install: $result"
reload lara.test
[[ "$(http_code lara.test /)" == 200 && "$(title)" == '<title>Laravel</title>' ]] || fail "Laravel page: $(title)"

say "Panel backup and restore"
artisan dpanel:backup | grep -q 'Backup completed successfully' || fail "dpanel:backup"
archive="$(ls "$P"/storage/app/backups/*/backup-*.tar.gz | head -1)"
sql "UPDATE dpanel_e2e.websites SET php_version='7.4' WHERE domain='shop.test'"
artisan dpanel:restore "$archive" --force | grep -q 'Restore completed' || fail "dpanel:restore"
[[ "$(sql "SELECT php_version FROM dpanel_e2e.websites WHERE domain='shop.test'")" == 8.3 ]] || fail "restore did not bring the row back"

say "Delete"
for domain in shop.test lara.test; do
  delete="\$s = \App\Models\Website::query()->where('domain', '$domain')->firstOrFail();
  app(\App\Services\Website\WebsiteDeletionService::class)->delete(\$s, null); echo 'ok';"
  [[ "$(tinker "$delete" | tail -1)" == ok ]] || fail "deleting $domain"
done
[[ -z "$(ls -A "$SITES")" ]] || fail "site homes left: $(ls "$SITES")"
[[ "$(sql 'SELECT COUNT(*) FROM dpanel_e2e.websites')" == 0 ]] || fail "website rows left"
[[ "$(http_code shop.test /)" == 404 ]] || fail "a deleted site still answers"
unzip -l "$P"/storage/app/website-trash/deleted-shop.test-*.zip | grep -q 'mysql/.*\.sql' || fail "the archive has no database dump"

say "All Windows e2e steps passed"
