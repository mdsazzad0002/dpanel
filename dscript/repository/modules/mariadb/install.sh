#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

MARIADB_TUNING_CONF_DEBIAN='/etc/mysql/mariadb.conf.d/60-dpanel-performance.cnf'
MARIADB_TUNING_CONF_RPM='/etc/my.cnf.d/60-dpanel-performance.cnf'
MARIADB_SLOW_LOGROTATE='/etc/logrotate.d/dpanel-mysql-slow'

mariadb_tuning_conf_path() {
  case "$(pkg_distro_family)" in
    debian) printf '%s' "${MARIADB_TUNING_CONF_DEBIAN}" ;;
    rpm) printf '%s' "${MARIADB_TUNING_CONF_RPM}" ;;
    *) printf '' ;;
  esac
}

# This is a mixed PHP/database host, not a dedicated database server. Allocate a
# bounded fraction of physical RAM so PHP-FPM, Redis and the OS always retain room.
mariadb_configure_small_server() {
  local conf_path memory buffer_pool max_connections tmp_tables pool_instances
  conf_path="$(mariadb_tuning_conf_path)"
  [[ -n "$conf_path" ]] || return 0

  memory="$(awk '/^MemTotal:/ {printf "%d", $2 / 1024; found = 1} END {if (!found) print 0}' /proc/meminfo 2>/dev/null || printf '0')"
  [[ "$memory" =~ ^[0-9]+$ ]] || return 0

  (( memory > 0 )) || return 0
  rm -f /etc/mysql/mariadb.conf.d/60-dpanel-small-server.cnf /etc/my.cnf.d/60-dpanel-small-server.cnf
  buffer_pool=$((memory * 25 / 100))
  (( buffer_pool < 128 )) && buffer_pool=128
  (( buffer_pool > 8192 )) && buffer_pool=8192
  max_connections=$((memory / 32))
  (( max_connections < 50 )) && max_connections=50
  (( max_connections > 250 )) && max_connections=250
  tmp_tables=32
  (( memory >= 8192 )) && tmp_tables=64
  pool_instances=1
  (( buffer_pool >= 1024 )) && pool_instances=$((buffer_pool / 1024))
  (( pool_instances > 8 )) && pool_instances=8

  mkdir -p "$(dirname "$conf_path")"
  cat > "$conf_path" <<CONF
# Managed by dpanel for servers with ${memory} MB RAM.
[mysqld]
innodb_buffer_pool_size = ${buffer_pool}M
innodb_buffer_pool_instances = ${pool_instances}
innodb_log_buffer_size = 8M
max_connections = ${max_connections}
table_open_cache = 256
tmp_table_size = ${tmp_tables}M
max_heap_table_size = ${tmp_tables}M

# Actionable slow-query telemetry with bounded log retention.
slow_query_log = ON
slow_query_log_file = /var/log/mysql/dpanel-slow.log
long_query_time = 0.2
min_examined_row_limit = 0
log_slow_admin_statements = ON
CONF

  cat > "$MARIADB_SLOW_LOGROTATE" <<'ROTATE'
/var/log/mysql/dpanel-slow.log {
    weekly
    rotate 8
    size 100M
    missingok
    notifempty
    compress
    delaycompress
    create 0640 mysql mysql
    sharedscripts
    postrotate
        /usr/bin/mariadb-admin --defaults-file=/etc/mysql/debian.cnf flush-logs >/dev/null 2>&1 || /usr/bin/mysqladmin flush-logs >/dev/null 2>&1 || true
    endscript
}
ROTATE
  panel_info_log "Database tuned for ${memory} MB RAM (buffer pool ${buffer_pool}M, max connections ${max_connections})."
}

mariadb_install() {
  pkg_install_mariadb_stack
  mariadb_configure_small_server
  pkg_enable_service mariadb
  panel_info_log "mariadb installed."
}

mariadb_remove() {
  rm -f "${MARIADB_TUNING_CONF_DEBIAN}" "${MARIADB_TUNING_CONF_RPM}" "$MARIADB_SLOW_LOGROTATE"
  pkg_remove mariadb-server mariadb-client mariadb
  panel_info_log "mariadb removed."
}

# A tuning profile the local build refuses would leave the server without a
# database, so a failed start drops the profile and tries once more.
mariadb_restart_guarded() {
  local conf_path

  if pkg_restart_service mariadb; then
    return 0
  fi

  conf_path="$(mariadb_tuning_conf_path)"
  if [[ -n "$conf_path" && -f "$conf_path" ]]; then
    panel_warn_log "MariaDB did not start; removing the dpanel tuning profile and retrying."
    rm -f "$conf_path"
    pkg_restart_service mariadb
    return 0
  fi

  return 1
}

mariadb_update() {
  mariadb_install
  mariadb_restart_guarded
  panel_info_log "mariadb updated."
}

case "$action" in
  install)
    mariadb_install
    ;;
  remove)
    mariadb_remove
    ;;
  update)
    mariadb_update
    ;;
  *)
    panel_die "Unsupported mariadb action: $action"
    ;;
esac
