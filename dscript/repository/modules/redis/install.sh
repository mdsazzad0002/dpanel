#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

# Memory cap and eviction policy. One Redis holds the panel cache and website
# caches (DB 1) and the queues (DB 0). volatile-lfu evicts only keys that carry
# a TTL, least-used first, so cache entries go when memory is full while queue
# lists (no TTL) are never dropped. The cap keeps Redis from starving the
# server: 25% of RAM, between 128 MB and 4 GB, or DPANEL_REDIS_MAXMEMORY_MB.
REDIS_DPANEL_CONF_NAME="dpanel-memory.conf"

redis_main_conf() {
  local candidate
  for candidate in /etc/redis/redis.conf /etc/redis.conf; do
    [[ -f "$candidate" ]] && { printf '%s' "$candidate"; return 0; }
  done
  return 1
}

redis_maxmemory_mb() {
  local mb="${DPANEL_REDIS_MAXMEMORY_MB:-}"
  if [[ -z "$mb" ]]; then
    local total_kb
    total_kb="$(awk '/^MemTotal:/ {print $2}' /proc/meminfo 2>/dev/null || true)"
    mb=$(( ${total_kb:-1048576} / 1024 / 4 ))
    (( mb < 128 )) && mb=128
    (( mb > 4096 )) && mb=4096
  fi
  printf '%s' "$mb"
}

redis_apply_memory_policy() {
  local main_conf conf_dir dpanel_conf mb
  main_conf="$(redis_main_conf)" || { panel_info_log "redis config not found; memory policy skipped."; return 0; }
  conf_dir="$(dirname "$main_conf")"
  [[ "$conf_dir" == "/etc" ]] && conf_dir="/etc/redis" && mkdir -p "$conf_dir"
  dpanel_conf="${conf_dir}/${REDIS_DPANEL_CONF_NAME}"
  mb="$(redis_maxmemory_mb)"

  cat > "$dpanel_conf" <<CONF
# Managed by dPanel (redis module). Edit DPANEL_REDIS_MAXMEMORY_MB and rerun
# "dpanel module redis update" instead of changing this file.
maxmemory ${mb}mb
maxmemory-policy volatile-lfu
maxmemory-samples 10
CONF
  chmod 644 "$dpanel_conf"

  # Directives later in redis.conf win, so the include goes at the end.
  if ! grep -qxF "include ${dpanel_conf}" "$main_conf"; then
    printf '\n# dPanel memory policy\ninclude %s\n' "$dpanel_conf" >> "$main_conf"
  fi

  # Apply live so a running Redis (and its queued jobs) need no restart.
  if command -v redis-cli >/dev/null 2>&1 \
    && redis-cli CONFIG SET maxmemory "${mb}mb" >/dev/null 2>&1 \
    && redis-cli CONFIG SET maxmemory-policy volatile-lfu >/dev/null 2>&1 \
    && redis-cli CONFIG SET maxmemory-samples 10 >/dev/null 2>&1; then
    panel_info_log "redis memory policy applied: maxmemory ${mb}mb, volatile-lfu."
  else
    pkg_restart_service redis-server || pkg_restart_service redis || true
    panel_info_log "redis memory policy written: maxmemory ${mb}mb, volatile-lfu (service restarted)."
  fi
}

redis_install() {
  pkg_install_redis_stack
  pkg_enable_service redis-server || pkg_enable_service redis
  redis_apply_memory_policy
  panel_info_log "redis installed."
}

redis_remove() {
  pkg_remove redis-server redis
  panel_info_log "redis removed."
}

redis_update() {
  redis_install
  pkg_restart_service redis-server || pkg_restart_service redis
  panel_info_log "redis updated."
}

case "$action" in
  install)
    redis_install
    ;;
  remove)
    redis_remove
    ;;
  update)
    redis_update
    ;;
  *)
    panel_die "Unsupported redis action: $action"
    ;;
esac
