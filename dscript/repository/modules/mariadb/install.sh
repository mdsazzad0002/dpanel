#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

MARIADB_TUNING_CONF_DEBIAN='/etc/mysql/mariadb.conf.d/60-dpanel-small-server.cnf'
MARIADB_TUNING_CONF_RPM='/etc/my.cnf.d/60-dpanel-small-server.cnf'

mariadb_tuning_conf_path() {
  case "$(pkg_distro_family)" in
    debian) printf '%s' "${MARIADB_TUNING_CONF_DEBIAN}" ;;
    rpm) printf '%s' "${MARIADB_TUNING_CONF_RPM}" ;;
    *) printf '' ;;
  esac
}

# Stock defaults assume the database owns the machine. On a shared 1-2 GB server
# it has to leave room for PHP-FPM pools, nginx and Apache, so the buffer pool
# and connection ceiling are sized down instead of the box swapping under load.
mariadb_configure_small_server() {
  local conf_path memory
  conf_path="$(mariadb_tuning_conf_path)"
  [[ -n "$conf_path" ]] || return 0

  memory="$(awk '/^MemTotal:/ {printf "%d", $2 / 1024; found = 1} END {if (!found) print 0}' /proc/meminfo 2>/dev/null || printf '0')"
  [[ "$memory" =~ ^[0-9]+$ ]] || return 0

  if (( memory == 0 || memory >= 4096 )); then
    # Enough RAM for the packaged defaults; remove a profile written earlier on
    # a smaller machine so an upgraded server is not held back.
    [[ -f "$conf_path" ]] && rm -f "$conf_path" && panel_info_log "Removed the small-server database profile (${memory} MB RAM)."
    return 0
  fi

  local buffer_pool=128 max_connections=80
  if (( memory < 2048 )); then
    buffer_pool=64
    max_connections=50
  fi

  mkdir -p "$(dirname "$conf_path")"
  cat > "$conf_path" <<CONF
# Managed by dpanel for servers with ${memory} MB RAM.
[mysqld]
innodb_buffer_pool_size = ${buffer_pool}M
innodb_log_buffer_size = 8M
max_connections = ${max_connections}
table_open_cache = 256
tmp_table_size = 16M
max_heap_table_size = 16M
performance_schema = 0
CONF
  panel_info_log "Database tuned for a ${memory} MB server (buffer pool ${buffer_pool}M)."
}

mariadb_install() {
  pkg_install_mariadb_stack
  mariadb_configure_small_server
  pkg_enable_service mariadb
  panel_info_log "mariadb installed."
}

mariadb_remove() {
  rm -f "${MARIADB_TUNING_CONF_DEBIAN}" "${MARIADB_TUNING_CONF_RPM}"
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
