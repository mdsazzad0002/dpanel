#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
[[ $# -gt 0 ]] && shift

# PostgreSQL and pgAdmin are installed by default but left stopped and
# disabled, so they cost no RAM until an admin turns them on from the panel
# (Database Management > PostgreSQL) or with `dpanel postgresql start`.
PGADMIN_HOME="${PGADMIN_HOME:-/opt/dpanel/pgadmin4}"
PGADMIN_VENV="${PGADMIN_HOME}/venv"
PGADMIN_CONFIG_DIR="/etc/pgadmin"
PGADMIN_DATA_DIR="/var/lib/pgadmin"
PGADMIN_LOG_DIR="/var/log/pgadmin"
PGADMIN_USER="pgadmin"
PGADMIN_SERVICE="dpanel-pgadmin"
PGADMIN_BIND="127.0.0.1:5050"
# Must match the fixed system path the drust edge gateway proxies.
PGADMIN_SCRIPT_NAME="/pgadmin4"
PGADMIN_DEFAULT_EMAIL="admin@dpanel.local"
# Shared with the drust edge gateway, which injects it (plus the user header)
# only on a dPanel single sign-on request. Keep the paths/names in sync with
# drust/src/pgadmin_sso.rs.
PGADMIN_SSO_SECRET_FILE="${PGADMIN_CONFIG_DIR}/dpanel-sso.secret"
PGADMIN_SSO_USER_HEADER="X-Dpanel-Pgadmin-User"

postgresql_service() {
  printf '%s' postgresql
}

postgresql_installed() {
  pkg_package_installed postgresql || pkg_package_installed postgresql-server
}

pgadmin_installed() {
  [[ -x "${PGADMIN_VENV}/bin/gunicorn" && -f "/etc/systemd/system/${PGADMIN_SERVICE}.service" ]]
}

postgresql_env_file() {
  local env_file
  env_file="$(panel_resolve_app_env_file)"
  if [[ -z "$env_file" ]]; then
    env_file="$(panel_ensure_app_env_file "${PANEL_APP_DIR:-/var/www/dpanel}/.env")"
  fi
  printf '%s' "$env_file"
}

postgresql_env_value() {
  local key="$1" env_file
  env_file="$(panel_resolve_app_env_file)"
  [[ -n "$env_file" && -f "$env_file" ]] || return 0
  awk -F= -v key="$key" '$1 == key { sub(/^[^=]*=/, ""); print }' "$env_file" | tail -n 1
}

postgresql_install_packages() {
  case "$(pkg_distro_family)" in
    debian)
      pkg_install postgresql postgresql-contrib
      ;;
    rpm)
      pkg_install postgresql-server postgresql-contrib
      if [[ ! -f /var/lib/pgsql/data/PG_VERSION ]]; then
        postgresql-setup --initdb
      fi
      # RHEL defaults to ident for TCP; pgAdmin logs in with a password.
      local hba=/var/lib/pgsql/data/pg_hba.conf
      if [[ -f "$hba" ]] && grep -Eq '^host\s+all\s+all\s+(127\.0\.0\.1/32|::1/128)\s+ident' "$hba"; then
        sed -i -E 's/^(host\s+all\s+all\s+(127\.0\.0\.1\/32|::1\/128)\s+)ident/\1scram-sha-256/' "$hba"
      fi
      ;;
    *)
      panel_die "Unsupported distro for PostgreSQL: ${DISTRO:-unknown}"
      ;;
  esac
}

postgresql_set_superuser_password() {
  local password="$1"
  # The password travels on stdin so it never shows up in the process list.
  printf "ALTER USER postgres WITH PASSWORD '%s';\n" "${password//\'/\'\'}" \
    | (cd / && runuser -u postgres -- psql -v ON_ERROR_STOP=1 -q -d postgres -f -) >/dev/null
}

postgresql_setup() {
  local service env_file password
  service="$(postgresql_service)"

  postgresql_install_packages
  systemctl daemon-reload || true

  env_file="$(postgresql_env_file)"
  password="$(postgresql_env_value PGSQL_ADMIN_PASSWORD)"
  if [[ -z "$password" ]]; then
    password="$(panel_generate_token | cut -c1-24)"
  fi

  # The service must run briefly to set the superuser password.
  systemctl start "$service"
  local attempt
  for attempt in $(seq 1 20); do
    runuser -u postgres -- pg_isready -q -h /var/run/postgresql 2>/dev/null && break
    sleep 1
  done
  postgresql_set_superuser_password "$password"

  panel_env_set "$env_file" PGSQL_HOST 127.0.0.1
  panel_env_set "$env_file" PGSQL_PORT 5432
  panel_env_set "$env_file" PGSQL_ADMIN_USERNAME postgres
  panel_env_set "$env_file" PGSQL_ADMIN_PASSWORD "$password"
  panel_info_log "PostgreSQL installed. Superuser credentials saved to ${env_file}."
}

pgadmin_write_sso_secret() {
  if [[ ! -s "$PGADMIN_SSO_SECRET_FILE" ]]; then
    ( umask 077; panel_generate_token > "$PGADMIN_SSO_SECRET_FILE" )
  fi
  chown "root:${PGADMIN_USER}" "$PGADMIN_SSO_SECRET_FILE"
  chmod 0640 "$PGADMIN_SSO_SECRET_FILE"
}

pgadmin_write_config() {
  install -d -m 0755 "$PGADMIN_CONFIG_DIR"
  pgadmin_write_sso_secret || return 1
  cat > "${PGADMIN_CONFIG_DIR}/config_system.py" <<PY
# Managed by dpanel. pgAdmin reads this file on every start.
SERVER_MODE = True
DATA_DIR = '${PGADMIN_DATA_DIR}'
LOG_FILE = '${PGADMIN_LOG_DIR}/pgadmin4.log'
SQLITE_PATH = '${PGADMIN_DATA_DIR}/pgadmin4.db'
SESSION_DB_PATH = '${PGADMIN_DATA_DIR}/sessions'
STORAGE_DIR = '${PGADMIN_DATA_DIR}/storage'
AZURE_CREDENTIAL_CACHE_DIR = '${PGADMIN_DATA_DIR}/azurecredentialcache'
KERBEROS_CCACHE_DIR = '${PGADMIN_DATA_DIR}/kerberoscache'
# Served behind the drust edge gateway at ${PGADMIN_SCRIPT_NAME}.
PROXY_X_FOR_COUNT = 1
PROXY_X_PROTO_COUNT = 1
PROXY_X_HOST_COUNT = 1
ALLOW_SPECIAL_EMAIL_DOMAINS = ['local']
UPGRADE_CHECK_ENABLED = False
# Login only through dPanel: the edge gateway turns a one-time panel ticket
# into these headers. pgAdmin trusts them only from loopback and only with the
# shared secret, and the gateway strips both from every browser request.
AUTHENTICATION_SOURCES = ['webserver']
WEBSERVER_AUTO_CREATE_USER = True
WEBSERVER_REMOTE_USER = '${PGADMIN_SSO_USER_HEADER}'
WEBSERVER_REMOTE_USER_FROM_HEADER = True
WEBSERVER_TRUSTED_PROXIES = ['127.0.0.1/32', '::1/128']
with open('${PGADMIN_SSO_SECRET_FILE}') as _secret_file:
    WEBSERVER_SHARED_SECRET = _secret_file.read().strip()
del _secret_file
# Server passwords come from per-user pgpass files written by drust, so there
# is nothing for a master password to protect.
MASTER_PASSWORD_REQUIRED = False
PY
  chmod 0644 "${PGADMIN_CONFIG_DIR}/config_system.py"
}

pgadmin_write_unit() {
  local package_dir="$1"
  cat > "/etc/systemd/system/${PGADMIN_SERVICE}.service" <<UNIT
[Unit]
Description=pgAdmin 4 web (managed by dpanel)
After=network.target postgresql.service

[Service]
Type=simple
User=${PGADMIN_USER}
Group=${PGADMIN_USER}
Environment=SCRIPT_NAME=${PGADMIN_SCRIPT_NAME}
WorkingDirectory=${package_dir}
ExecStart=${PGADMIN_VENV}/bin/gunicorn --bind ${PGADMIN_BIND} --workers 1 --threads 25 --timeout 300 --chdir ${package_dir} pgAdmin4:app
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
}

pgadmin_package_dir() {
  "${PGADMIN_VENV}/bin/python" - <<'PY'
# pgadmin4 ships as a namespace package, so it has __path__ but no __file__.
import pgadmin4
print(list(pgadmin4.__path__)[0])
PY
}

pgadmin_setup() {
  local env_file email password package_dir

  case "$(pkg_distro_family)" in
    debian) pkg_install python3 python3-venv python3-dev libpq-dev build-essential || return 1 ;;
    rpm) pkg_install python3 python3-devel libpq-devel gcc || return 1 ;;
  esac

  if ! id -u "$PGADMIN_USER" >/dev/null 2>&1; then
    useradd --system --home-dir "$PGADMIN_DATA_DIR" --shell /usr/sbin/nologin "$PGADMIN_USER" || return 1
  fi
  install -d -m 0750 -o "$PGADMIN_USER" -g "$PGADMIN_USER" "$PGADMIN_DATA_DIR" "$PGADMIN_LOG_DIR" || return 1
  install -d -m 0755 "$PGADMIN_HOME" || return 1

  if [[ ! -x "${PGADMIN_VENV}/bin/python" ]]; then
    python3 -m venv "$PGADMIN_VENV" || return 1
  fi
  # Called from an `if`, where errexit is off: check each step explicitly and
  # return instead of exiting so a pgAdmin failure never aborts PostgreSQL.
  "${PGADMIN_VENV}/bin/pip" install --quiet --upgrade pip || return 1
  "${PGADMIN_VENV}/bin/pip" install --quiet --upgrade pgadmin4 gunicorn || return 1

  pgadmin_write_config || return 1
  package_dir="$(pgadmin_package_dir)" || return 1
  if [[ ! -f "${package_dir}/pgAdmin4.py" ]]; then
    panel_warn_log "pgAdmin package not found in ${PGADMIN_VENV}."
    return 1
  fi

  env_file="$(postgresql_env_file)"
  email="$(postgresql_env_value PGADMIN_EMAIL)"
  password="$(postgresql_env_value PGADMIN_PASSWORD)"
  [[ -n "$email" ]] || email="${PGADMIN_EMAIL:-$PGADMIN_DEFAULT_EMAIL}"
  [[ -n "$password" ]] || password="$(panel_generate_token | cut -c1-20)"

  # First run only: create pgAdmin's config database and initial admin login.
  if [[ ! -f "${PGADMIN_DATA_DIR}/pgadmin4.db" ]]; then
    (
      cd "$package_dir"
      export PGADMIN_SETUP_EMAIL="$email" PGADMIN_SETUP_PASSWORD="$password"
      # pgAdmin 8+ has `setup-db`; older releases run setup.py without a
      # subcommand. Both read the initial login from the environment.
      runuser -u "$PGADMIN_USER" --preserve-environment -- "${PGADMIN_VENV}/bin/python" setup.py setup-db \
        || runuser -u "$PGADMIN_USER" --preserve-environment -- "${PGADMIN_VENV}/bin/python" setup.py
    ) </dev/null >/dev/null || return 1
    pgadmin_register_local_server "$package_dir" "$email"
  fi

  pgadmin_write_unit "$package_dir" || return 1

  panel_env_set "$env_file" PGADMIN_EMAIL "$email"
  panel_env_set "$env_file" PGADMIN_PASSWORD "$password"
  panel_env_set "$env_file" PGADMIN_PATH "${PGADMIN_SCRIPT_NAME}/"
  panel_info_log "pgAdmin installed at ${PGADMIN_SCRIPT_NAME}/. Login saved to ${env_file}."
}

# Pre-register the local server so pgAdmin opens with it in the tree. The admin
# still types the postgres password once; it is never written to pgAdmin's DB.
pgadmin_register_local_server() {
  local package_dir="$1" email="$2" servers_file
  servers_file="$(mktemp "${TMPDIR:-/tmp}/pgadmin-servers.XXXXXX.json")"
  cat > "$servers_file" <<'JSON'
{"Servers": {"1": {"Name": "Local PostgreSQL", "Group": "Servers", "Host": "127.0.0.1", "Port": 5432, "MaintenanceDB": "postgres", "Username": "postgres", "SSLMode": "prefer"}}}
JSON
  chmod 0644 "$servers_file"
  (
    cd "$package_dir"
    runuser -u "$PGADMIN_USER" -- "${PGADMIN_VENV}/bin/python" setup.py load-servers "$servers_file" --user "$email" \
      || runuser -u "$PGADMIN_USER" -- "${PGADMIN_VENV}/bin/python" setup.py --load-servers "$servers_file" --user "$email"
  ) >/dev/null 2>&1 || panel_warn_log "Could not pre-register the local server in pgAdmin; add it manually (127.0.0.1:5432)."
  rm -f "$servers_file"
}

service_units() {
  case "${1:-all}" in
    postgresql|postgres|pgsql) printf '%s\n' "$(postgresql_service)" ;;
    pgadmin|pgadmin4) printf '%s\n' "$PGADMIN_SERVICE" ;;
    all|'') printf '%s\n' "$(postgresql_service)" "$PGADMIN_SERVICE" ;;
    *) panel_die "Unknown service '${1}'. Use postgresql, pgadmin or all." ;;
  esac
}

services_off() {
  local unit
  for unit in "$@"; do
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
  done
}

postgresql_install() {
  postgresql_setup
  if ! pgadmin_setup; then
    panel_warn_log "pgAdmin install failed; PostgreSQL is still available. Retry with: dpanel postgresql install"
  fi
  services_off "$(postgresql_service)" "$PGADMIN_SERVICE"
  panel_info_log "postgresql + pgAdmin installed and left OFF. Turn on from the panel or with: dpanel postgresql start"
}

postgresql_update() {
  if ! postgresql_installed; then
    panel_info_log "postgresql not installed; skipping update. Install with: dpanel postgresql install"
    return 0
  fi
  local pg_was_active=false pga_was_active=false
  systemctl is-active --quiet "$(postgresql_service)" && pg_was_active=true
  systemctl is-active --quiet "$PGADMIN_SERVICE" && pga_was_active=true

  postgresql_install_packages
  if pgadmin_installed; then
    "${PGADMIN_VENV}/bin/pip" install --quiet --upgrade pgadmin4 gunicorn
    pgadmin_write_config
    pgadmin_write_unit "$(pgadmin_package_dir)"
  fi

  # Keep whatever on/off state the admin chose.
  [[ "$pg_was_active" == true ]] && systemctl restart "$(postgresql_service)"
  [[ "$pga_was_active" == true ]] && systemctl restart "$PGADMIN_SERVICE"
  panel_info_log "postgresql updated."
}

postgresql_remove() {
  services_off "$PGADMIN_SERVICE" "$(postgresql_service)"
  rm -f "/etc/systemd/system/${PGADMIN_SERVICE}.service"
  systemctl daemon-reload || true
  rm -rf "$PGADMIN_HOME"
  case "$(pkg_distro_family)" in
    debian) pkg_remove postgresql postgresql-contrib ;;
    rpm) pkg_remove postgresql-server postgresql-contrib ;;
  esac
  panel_info_log "postgresql + pgAdmin removed. Data in /var/lib/postgresql and ${PGADMIN_DATA_DIR} was kept."
}

postgresql_start() {
  local unit
  while IFS= read -r unit; do
    if [[ "$unit" == "$PGADMIN_SERVICE" ]] && ! pgadmin_installed; then
      panel_die "pgAdmin is not installed. Run: dpanel postgresql install"
    fi
    systemctl enable --now "$unit"
  done < <(service_units "${1:-all}")
  postgresql_status "${1:-all}"
}

postgresql_stop() {
  local units=()
  mapfile -t units < <(service_units "${1:-all}")
  services_off "${units[@]}"
  postgresql_status "${1:-all}"
}

postgresql_status() {
  local unit active enabled
  while IFS= read -r unit; do
    active="$(systemctl is-active "$unit" 2>/dev/null || true)"
    enabled="$(systemctl is-enabled "$unit" 2>/dev/null || true)"
    printf '%-18s active=%-10s enabled=%s\n' "$unit" "${active:-unknown}" "${enabled:-not-found}"
  done < <(service_units "${1:-all}")
}

case "$action" in
  install) postgresql_install ;;
  update) postgresql_update ;;
  remove) postgresql_remove ;;
  start|enable|on) postgresql_start "${1:-all}" ;;
  stop|disable|off) postgresql_stop "${1:-all}" ;;
  restart)
    while IFS= read -r unit; do
      systemctl is-active --quiet "$unit" && systemctl restart "$unit"
    done < <(service_units "${1:-all}")
    postgresql_status "${1:-all}"
    ;;
  configure)
    # Rewrite pgAdmin's config (dPanel sign-on) without reinstalling anything.
    pgadmin_installed || panel_die "pgAdmin is not installed. Run: dpanel postgresql install"
    pgadmin_write_config
    systemctl is-active --quiet "$PGADMIN_SERVICE" && systemctl restart "$PGADMIN_SERVICE"
    panel_info_log "pgAdmin configured for dPanel sign-on."
    ;;
  status) postgresql_status "${1:-all}" ;;
  *) panel_die "Unsupported postgresql action: $action" ;;
esac
