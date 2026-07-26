#!/usr/bin/env bash
set -euo pipefail

ACTION="${1:-}"
DB_NAME="${2:-}"
DB_USER="${3:-}"
# A password given as an argument is readable through /proc by every local
# account while this script runs. DPANEL_DB_PASSWORD is the supported path; the
# positional form stays for older callers.
DB_PASSWORD="${DPANEL_DB_PASSWORD:-${4:-}}"
if [[ -z "${DPANEL_DB_PASSWORD:-}" && -n "${4:-}" ]]; then
    echo "[database-request] WARNING: passing the password as an argument exposes it to other local users. Use DPANEL_DB_PASSWORD instead." >&2
fi
DB_HOST_RAW="${5:-127.0.0.1}"
DB_PORT_RAW="${6:-3306}"
DB_CHARSET="${7:-utf8mb4}"
DB_COLLATION="${8:-utf8mb4_unicode_ci}"
ALLOW_ADMIN_GRANTS="${PANEL_DB_ALLOW_ADMIN_GRANTS:-true}"
DB_ADMIN_USER="${PANEL_DB_ADMIN_USER:-root}"
DB_ADMIN_PASSWORD="${PANEL_DB_ADMIN_PASSWORD:-}"
DB_ADMIN_HOST="${PANEL_DB_ADMIN_HOST:-}"

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

# Both the admin password and the statements carry secrets, so neither may reach
# the client's command line: credentials go through a 0600 defaults file and the
# SQL arrives on stdin.
DB_DEFAULTS_FILE=""

cleanup_defaults_file() {
    if [[ -n "${DB_DEFAULTS_FILE}" ]]; then
        rm -f "${DB_DEFAULTS_FILE}"
    fi

    # A failing EXIT trap would overwrite the script's real exit status.
    return 0
}
trap cleanup_defaults_file EXIT

ensure_defaults_file() {
    [[ -n "${DB_DEFAULTS_FILE}" ]] && return 0

    DB_DEFAULTS_FILE="$(mktemp)" || fail "Unable to create a temporary credentials file."
    chmod 600 "${DB_DEFAULTS_FILE}"
    {
        printf '[client]\n'
        if [[ -n "${DB_ADMIN_USER}" ]]; then
            printf 'user=%s\n' "${DB_ADMIN_USER}"
        fi
        if [[ -n "${DB_ADMIN_PASSWORD}" ]]; then
            printf 'password=%s\n' "${DB_ADMIN_PASSWORD}"
        fi
    } > "${DB_DEFAULTS_FILE}"

    # An empty admin password must not make this function report failure under
    # `set -e`, which would stop the installer before any SQL runs.
    return 0
}

sql_exec() {
    local sql="$1"
    local args=()

    ensure_defaults_file
    args+=("--defaults-extra-file=${DB_DEFAULTS_FILE}")

    if [[ -n "${DB_ADMIN_HOST}" ]]; then
        args+=(--host="${DB_ADMIN_HOST}" --port="${DB_PORT}")
    elif [[ "${DB_HOST}" != "127.0.0.1" && "${DB_HOST,,}" != "localhost" ]]; then
        args+=(--host="${DB_HOST}" --port="${DB_PORT}")
    fi

    printf '%s\n' "${sql}" | "${DB_CLI}" "${args[@]}"
}

grant_for_host() {
    local host="$1"
    local sql_user sql_pass sql_host
    sql_user="$(escape_sql_string "${DB_USER}")"
    sql_pass="$(escape_sql_string "${DB_PASSWORD}")"
    sql_host="$(escape_sql_string "${host}")"
    sql_exec "CREATE USER IF NOT EXISTS '${sql_user}'@'${sql_host}' IDENTIFIED BY '${sql_pass}';"
    sql_exec "ALTER USER '${sql_user}'@'${sql_host}' IDENTIFIED BY '${sql_pass}';"
    # Panel database user needs full access to its own database for migrations,
    # recovery and partially-installed module repair. This does not modify the
    # database root account.
    sql_exec "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${sql_user}'@'${sql_host}';"

    # The panel also manages customer databases and database users. That requires
    # global database/user administration rights. Root is left untouched; these
    # privileges are granted only to the configured panel database user.
    if [[ "${ALLOW_ADMIN_GRANTS,,}" == "true" ]]; then
        sql_exec "GRANT ALL PRIVILEGES ON *.* TO '${sql_user}'@'${sql_host}' WITH GRANT OPTION;"
    fi
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
