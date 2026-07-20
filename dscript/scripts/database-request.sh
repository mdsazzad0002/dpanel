#!/usr/bin/env bash
set -euo pipefail

ACTION="${1:-}"
DB_NAME="${2:-}"
DB_USER="${3:-}"
DB_PASSWORD="${4:-}"
DB_HOST_RAW="${5:-127.0.0.1}"
DB_PORT_RAW="${6:-3306}"
DB_CHARSET="${7:-utf8mb4}"
DB_COLLATION="${8:-utf8mb4_unicode_ci}"

fail() {
    echo "[database-request] $*" >&2
    exit 1
}

escape_sql_string() {
    printf '%s' "$1" | sed "s/'/''/g"
}

normalize_host() {
    local host="$1"
    host="$(echo "${host}" | xargs)"
    if [[ -z "${host}" || "${host,,}" == "localhost" ]]; then
        echo "127.0.0.1"
        return 0
    fi
    echo "${host}"
}

if [[ "${ACTION}" != "create" && "${ACTION}" != "upsert" ]]; then
    fail "Unsupported action: ${ACTION}. Allowed: create|upsert"
fi

if [[ ! "${DB_NAME}" =~ ^[A-Za-z0-9_]{1,64}$ ]]; then
    fail "Invalid database name. Use only letters, numbers, underscore (max 64)."
fi
if [[ ! "${DB_USER}" =~ ^[A-Za-z0-9_]{1,64}$ ]]; then
    fail "Invalid database user. Use only letters, numbers, underscore (max 64)."
fi
if [[ ! "${DB_CHARSET}" =~ ^[A-Za-z0-9_]{1,32}$ ]]; then
    fail "Invalid charset value."
fi
if [[ ! "${DB_COLLATION}" =~ ^[A-Za-z0-9_]{1,64}$ ]]; then
    fail "Invalid collation value."
fi
if [[ ! "${DB_PORT_RAW}" =~ ^[0-9]{1,5}$ ]] || (( DB_PORT_RAW < 1 || DB_PORT_RAW > 65535 )); then
    fail "Invalid database port value."
fi
if [[ -z "${DB_PASSWORD}" ]]; then
    fail "Database password is required."
fi

DB_HOST="$(normalize_host "${DB_HOST_RAW}")"
if [[ ! "${DB_HOST}" =~ ^[A-Za-z0-9._%-]{1,255}$ ]]; then
    fail "Invalid database host value."
fi
DB_PORT="${DB_PORT_RAW}"

DB_CLI=""
if command -v mariadb >/dev/null 2>&1; then
    DB_CLI="mariadb"
elif command -v mysql >/dev/null 2>&1; then
    DB_CLI="mysql"
fi
if [[ -z "${DB_CLI}" ]]; then
    fail "No database CLI found (mariadb/mysql)."
fi

sql_exec() {
    local sql="$1"
    "${DB_CLI}" --host="${DB_HOST}" --port="${DB_PORT}" -e "${sql}"
}

grant_for_host() {
    local host="$1"
    local sql_user sql_pass sql_host
    sql_user="$(escape_sql_string "${DB_USER}")"
    sql_pass="$(escape_sql_string "${DB_PASSWORD}")"
    sql_host="$(escape_sql_string "${host}")"
    sql_exec "CREATE USER IF NOT EXISTS '${sql_user}'@'${sql_host}' IDENTIFIED BY '${sql_pass}';"
    sql_exec "ALTER USER '${sql_user}'@'${sql_host}' IDENTIFIED BY '${sql_pass}';"
    sql_exec "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${sql_user}'@'${sql_host}';"
}

sql_exec "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET ${DB_CHARSET} COLLATE ${DB_COLLATION};"

grant_for_host "${DB_HOST}"
if [[ "${DB_HOST}" == "127.0.0.1" ]]; then
    grant_for_host "localhost"
fi
if [[ "${DB_HOST,,}" == "localhost" ]]; then
    grant_for_host "127.0.0.1"
fi

sql_exec "FLUSH PRIVILEGES;"
echo "[database-request] Database/user synced successfully: ${DB_NAME} / ${DB_USER}@${DB_HOST}"
