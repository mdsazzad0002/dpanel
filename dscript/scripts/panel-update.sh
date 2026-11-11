#!/usr/bin/env bash
# Updates dPanel to another release, or rolls an update back (backlog U6).
#
#   panel-update.sh update   --run-dir DIR --root /var/www --ref v1.2.3 [--repo owner/name]
#   panel-update.sh rollback --run-dir DIR --root /var/www --from OTHER_RUN/backup [--keep-database]
#
# drust starts this as its own systemd unit (dpanel-update-<id>), because an
# update replaces the panel and restarts drust. It reports through the run
# folder: `state` holds key=value lines that drust reads, `log` the output.
#
# Before changing anything it backs up the panel's .env, its database and the
# code of dpanel, drust and dscript into DIR/backup. When the new code fails
# to install or fails the checks afterwards, that backup is put back at once.
#
# The update itself is the existing path: installer.sh of the chosen release
# with "update" (download, copy into place, `dpanel chain update`). A rollback
# puts the saved code, .env and database back and runs `dpanel chain update`
# on it.
#
# Tests replace the slow parts: PANEL_UPDATE_INSTALL_CMD installs the release,
# PANEL_UPDATE_REFRESH_CMD re-runs the update steps on restored code,
# PANEL_UPDATE_PHP is the php binary, and PANEL_UPDATE_DRUST_UNIT="" skips the
# drust service check.
set -uo pipefail
umask 077

ACTION="${1:-}"
shift || true
RUN_DIR=""
ROOT=""
REF=""
REPO="mdsazzad0002/dpanel"
FROM=""
RESTORE_DATABASE=1
ARGS=("$ACTION")
while [[ $# -gt 0 ]]; do
  ARGS+=("$1")
  case "$1" in
    --run-dir) RUN_DIR="${2:-}"; ARGS+=("${2:-}"); shift 2 ;;
    --root) ROOT="${2:-}"; ARGS+=("${2:-}"); shift 2 ;;
    --ref) REF="${2:-}"; ARGS+=("${2:-}"); shift 2 ;;
    --repo) REPO="${2:-}"; ARGS+=("${2:-}"); shift 2 ;;
    --from) FROM="${2:-}"; ARGS+=("${2:-}"); shift 2 ;;
    --keep-database) RESTORE_DATABASE=0; shift ;;
    *) printf 'Unknown option: %s\n' "$1" >&2; exit 2 ;;
  esac
done

[[ "$ACTION" == update || "$ACTION" == rollback ]] || { printf 'Usage: %s update|rollback --run-dir DIR --root DIR ...\n' "$0" >&2; exit 2; }
[[ -n "$RUN_DIR" && -d "$RUN_DIR" && -n "$ROOT" && -d "$ROOT/dpanel" ]] || { printf 'The run folder and the install root must exist.\n' >&2; exit 2; }

# An update overwrites dscript, this file included, and bash reads a script as
# it runs. Run from a copy in the run folder instead.
if [[ "${PANEL_UPDATE_COPY:-}" != 1 ]]; then
  cp -f "${BASH_SOURCE[0]}" "${RUN_DIR}/runner.sh" || exit 1
  PANEL_UPDATE_COPY=1 exec bash "${RUN_DIR}/runner.sh" "${ARGS[@]}"
fi

# A systemd unit starts with almost no environment; composer and npm need HOME.
export HOME="${HOME:-/root}" DEBIAN_FRONTEND=noninteractive DPANEL_NONINTERACTIVE=1

PANEL="${ROOT}/dpanel"
BACKUP="${RUN_DIR}/backup"
PHP="${PANEL_UPDATE_PHP:-php}"
DRUST_UNIT="${PANEL_UPDATE_DRUST_UNIT-drust}"
exec >>"${RUN_DIR}/log" 2>&1

declare -A STATE=()

state_load() {
  local key value
  [[ -f "${RUN_DIR}/state" ]] || return 0
  while IFS='=' read -r key value; do
    [[ -n "$key" ]] && STATE["$key"]="$value"
  done < "${RUN_DIR}/state"
}

# Pairs of key and value. The file is replaced whole, so drust never reads half.
state_set() {
  local key temp="${RUN_DIR}/state.tmp"
  while [[ $# -ge 2 ]]; do
    STATE["$1"]="${2//$'\n'/ }"
    shift 2
  done
  : > "$temp"
  for key in "${!STATE[@]}"; do
    printf '%s=%s\n' "$key" "${STATE[$key]}" >> "$temp"
  done
  chmod 0644 "$temp"
  mv -f "$temp" "${RUN_DIR}/state"
}

log() {
  printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"
}

step() {
  state_set step "$1"
  log "== $2"
}

now() {
  date -u +%Y-%m-%dT%H:%M:%SZ
}

# A value from a .env file, without quotes.
env_value() {
  local line value
  line="$(grep -E "^${2}=" "$1" 2>/dev/null | tail -n 1)"
  value="${line#*=}"
  value="${value%$'\r'}"
  if [[ ${#value} -ge 2 && ( "$value" == \"*\" || "$value" == \'*\' ) ]]; then
    value="${value:1:${#value}-2}"
  fi
  printf '%s' "$value"
}

release_fields() {
  local prefix="$1" env_file="${PANEL}/.env"
  state_set "${prefix}_version" "$(env_value "$env_file" APP_VERSION)" \
    "${prefix}_ref" "$(env_value "$env_file" DPANEL_RELEASE_REF)" \
    "${prefix}_commit" "$(env_value "$env_file" DPANEL_RELEASE_COMMIT)"
}

download() {
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --retry 3 --connect-timeout 15 "$1" -o "$2"
  else
    wget -q --tries=3 --timeout=15 -O "$2" "$1"
  fi
}

mysql_client() {
  local name
  for name in "$@"; do
    command -v "$name" >/dev/null 2>&1 && { printf '%s' "$name"; return 0; }
  done
  return 1
}

# Connection options for the mysql tools, from a .env file.
mysql_options() {
  local env_file="$1" host port
  host="$(env_value "$env_file" DB_HOST)"
  port="$(env_value "$env_file" DB_PORT)"
  printf '%s\n' "--user=$(env_value "$env_file" DB_USERNAME)"
  [[ -n "$host" ]] && printf '%s\n' "--host=${host}"
  [[ -n "$port" ]] && printf '%s\n' "--port=${port}"
}

sqlite_path() {
  local env_file="$1" path
  path="$(env_value "$env_file" DB_DATABASE)"
  [[ -n "$path" ]] || path="database/database.sqlite"
  [[ "$path" == /* ]] || path="${PANEL}/${path}"
  printf '%s' "$path"
}

database_dump() {
  local env_file="${PANEL}/.env" connection dump options=()
  connection="$(env_value "$env_file" DB_CONNECTION)"
  connection="${connection:-mysql}"
  case "$connection" in
    mysql|mariadb)
      dump="$(mysql_client mysqldump mariadb-dump)" || { log "mysqldump is not installed."; return 1; }
      mapfile -t options < <(mysql_options "$env_file")
      MYSQL_PWD="$(env_value "$env_file" DB_PASSWORD)" "$dump" "${options[@]}" --single-transaction --quick \
        --routines --triggers --no-tablespaces "$(env_value "$env_file" DB_DATABASE)" \
        | gzip > "${BACKUP}/database.sql.gz"
      [[ "${PIPESTATUS[0]}" -eq 0 && "${PIPESTATUS[1]}" -eq 0 ]] || { log "The database dump failed."; return 1; }
      ;;
    sqlite)
      cp -p "$(sqlite_path "$env_file")" "${BACKUP}/database.sqlite" || return 1
      ;;
    *)
      log "Backing up a ${connection} database is not supported."
      return 1
      ;;
  esac
  printf '%s\n' "$connection" > "${BACKUP}/database.type"
}

# Every table and view goes first: a table the newer release added would
# otherwise stay, and its migration would fail on the next update.
database_restore() {
  local from="$1" env_file="$1/env" connection client database options=()
  connection="$(cat "${from}/database.type" 2>/dev/null)"
  case "$connection" in
    mysql|mariadb)
      client="$(mysql_client mysql mariadb)" || { log "The mysql client is not installed."; return 1; }
      mapfile -t options < <(mysql_options "$env_file")
      database="$(env_value "$env_file" DB_DATABASE)"
      export MYSQL_PWD
      MYSQL_PWD="$(env_value "$env_file" DB_PASSWORD)"
      {
        printf 'SET FOREIGN_KEY_CHECKS=0;\n'
        "$client" "${options[@]}" -N -B -e "SELECT CONCAT(IF(table_type = 'VIEW', 'DROP VIEW IF EXISTS ', 'DROP TABLE IF EXISTS '), '\`', table_name, '\`;') FROM information_schema.tables WHERE table_schema = DATABASE()" "$database"
      } | "$client" "${options[@]}" "$database" || { unset MYSQL_PWD; return 1; }
      gunzip -c "${from}/database.sql.gz" | "$client" "${options[@]}" "$database"
      local result=$?
      unset MYSQL_PWD
      return "$result"
      ;;
    sqlite)
      cp -p "${from}/database.sqlite" "$(sqlite_path "$env_file")"
      ;;
    *)
      log "The backup has no database."
      return 1
      ;;
  esac
}

# Left out of the code backup, and never removed on restore: dependencies,
# build output, uploads, logs, secrets and a SQLite database (that one has its
# own backup). A restore rebuilds what it needs.
CODE_EXCLUDES=(
  --exclude=dpanel/vendor --exclude=dpanel/node_modules --exclude=dpanel/storage
  --exclude=dpanel/public/build --exclude=dpanel/public/storage --exclude=dpanel/bootstrap/cache
  --exclude=dpanel/.env --exclude='dpanel/database/*.sqlite' --exclude='drust/target*'
)

backup_create() {
  local components=() component
  mkdir -p "$BACKUP" && chmod 0700 "$BACKUP" || return 1
  cp -p "${PANEL}/.env" "${BACKUP}/env" || { log "The panel has no .env to back up."; return 1; }
  database_dump || return 1
  for component in dpanel drust dscript; do
    [[ -d "${ROOT}/${component}" ]] && components+=("$component")
  done
  tar --anchored "${CODE_EXCLUDES[@]}" -czf "${BACKUP}/code.tar.gz" -C "$ROOT" "${components[@]}" \
    || { log "Archiving the code failed."; return 1; }
  : > "${BACKUP}/complete"
  log "Backup written to ${BACKUP}."
}

# Removes files the newer code added, so that, for example, a new migration
# does not stay behind. What the backup left out is kept.
remove_added_files() {
  local live="$1" saved="$2" path
  [[ -d "$live" ]] || return 0
  while IFS= read -r -d '' path; do
    [[ -e "${saved}/${path}" || -L "${saved}/${path}" ]] || rm -f -- "${live}/${path}"
  done < <(cd "$live" && find . \( -path ./vendor -o -path ./node_modules -o -path ./storage -o -path ./public/build \
    -o -path ./public/storage -o -path ./bootstrap/cache -o -path ./.env -o -path './database/*.sqlite' \
    -o -path './target*' \) -prune \
    -o \( -type f -o -type l \) -print0)
}

code_restore() {
  local from="$1" stage="${RUN_DIR}/restore" component
  rm -rf "$stage"
  mkdir -p "$stage"
  tar -xzf "${from}/code.tar.gz" -C "$stage" || { log "The code backup cannot be read."; return 1; }
  for component in dpanel drust dscript; do
    [[ -d "${stage}/${component}" ]] || continue
    mkdir -p "${ROOT}/${component}"
    remove_added_files "${ROOT}/${component}" "${stage}/${component}"
    cp -a "${stage}/${component}/." "${ROOT}/${component}/" || return 1
  done
  rm -rf "$stage"
}

install_release() {
  if [[ -n "${PANEL_UPDATE_INSTALL_CMD:-}" ]]; then
    bash -c "$PANEL_UPDATE_INSTALL_CMD" panel-update "$REF" "$REPO" </dev/null
    return
  fi
  local installer="${RUN_DIR}/installer.sh"
  log "Downloading installer.sh of ${REPO}@${REF}."
  download "https://raw.githubusercontent.com/${REPO}/${REF}/installer.sh" "$installer" \
    || { log "installer.sh of ${REF} could not be downloaded."; return 1; }
  DPANEL_NONINTERACTIVE=1 DPANEL_REPO="$REPO" DPANEL_VERSION="$REF" DSCRIPT_DIR="${ROOT}/dscript" \
    bash "$installer" update </dev/null
}

# The existing "run the update steps on the code already here" path.
refresh_release() {
  if [[ -n "${PANEL_UPDATE_REFRESH_CMD:-}" ]]; then
    bash -c "$PANEL_UPDATE_REFRESH_CMD" </dev/null
    return
  fi
  DPANEL_NONINTERACTIVE=1 bash "${ROOT}/dscript/dpanel" chain update </dev/null
}

# installer.sh and the chain only warn when composer, a migration or the
# frontend build fails, so the result is checked here.
verify_panel() {
  local status owner
  # artisan runs as root here; a log or cache file it creates must stay
  # writable for the panel's own user.
  owner="$(stat -c '%u:%g' "${PANEL}/storage" 2>/dev/null || true)"
  (cd "$PANEL" && "$PHP" artisan migrate --force --no-interaction) || { log "Migrations failed."; return 1; }
  status="$(cd "$PANEL" && "$PHP" artisan migrate:status --no-interaction 2>&1)" || { log "The panel does not start: ${status}"; return 1; }
  if grep -q 'Pending' <<< "$status"; then
    log "Some migrations are still pending."
    return 1
  fi
  if [[ -n "$owner" && "$owner" != "0:0" ]]; then
    find "${PANEL}/storage" "${PANEL}/bootstrap/cache" -user 0 -newer "${RUN_DIR}/started" \
      -exec chown "$owner" {} + 2>/dev/null || true
  fi
  if [[ ! -s "${PANEL}/public/build/manifest.json" || ! "${PANEL}/public/build/manifest.json" -nt "${RUN_DIR}/started" ]]; then
    log "The frontend was not rebuilt (public/build/manifest.json is missing or old)."
    return 1
  fi
  if [[ -n "$DRUST_UNIT" ]] && ! systemctl is-active --quiet "$DRUST_UNIT"; then
    log "${DRUST_UNIT} is not running."
    return 1
  fi
  log "Checks passed."
}

# Puts a backup back: code, .env, database (unless kept), then the update steps.
restore_backup() {
  local from="$1" with_database="$2"
  [[ -f "${from}/complete" ]] || { log "The backup in ${from} is incomplete."; return 1; }
  code_restore "$from" || return 1
  cp -p "${from}/env" "${PANEL}/.env" || return 1
  if [[ "$with_database" == 1 ]]; then
    log "Restoring the database."
    database_restore "$from" || { log "Restoring the database failed."; return 1; }
  fi
  refresh_release || log "The update steps reported an error; checking the result."
  verify_panel
}

finish() {
  state_set status "$1" step done message "$2" finished_at "$(now)"
  log "$2"
  exit 0
}

# The new code is broken: put this run's backup back.
undo() {
  local reason="$1"
  step undo "${reason} Restoring the backup taken before this run."
  if restore_backup "$BACKUP" 1; then
    release_fields to
    finish rolled_back "${reason} The previous version was restored."
  fi
  finish failed "${reason} Restoring the backup also failed. Restore it by hand from ${BACKUP} (see docs/updates.md)."
}

state_load
: > "${RUN_DIR}/started"
exec 9>"$(dirname "$RUN_DIR")/lock"
if ! flock -n 9; then
  finish failed "Another update is running."
fi
state_set status running started_at "${STATE[started_at]:-$(now)}"
release_fields from
log "dPanel ${ACTION} started (run $(basename "$RUN_DIR"))."

step backup "Backing up .env, the database and the code."
if ! backup_create; then
  rm -rf "$BACKUP"
  finish failed "The backup failed, so nothing was changed."
fi

if [[ "$ACTION" == update ]]; then
  step install "Installing ${REPO}@${REF}."
  install_release || undo "Installing ${REF} failed."
  step verify "Checking the updated panel."
  verify_panel || undo "The updated panel failed its checks."
  release_fields to
  finish succeeded "Updated to ${STATE[to_version]:-$REF}."
fi

step restore "Restoring the backup in ${FROM}."
if ! restore_backup "$FROM" "$RESTORE_DATABASE"; then
  undo "The rollback failed."
fi
release_fields to
finish succeeded "Rolled back to ${STATE[to_version]:-the previous version}."
