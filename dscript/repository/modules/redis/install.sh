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

# Boot preflight. A power cut while Redis is appending leaves a half-written
# record in the AOF (or a short RDB), and Redis then refuses to start, which
# takes the panel's login, cache and queues down with it. A systemd drop-in
# runs this check before every start: a healthy file is left alone, a broken
# one is copied aside and repaired (redis-check-aof drops only the damaged
# tail), so Redis comes back by itself after the reboot.
REDIS_PREFLIGHT_BIN="/usr/local/sbin/dpanel-redis-preflight"
REDIS_PREFLIGHT_DROPIN_NAME="dpanel-preflight.conf"
REDIS_SYSCTL_CONF="/etc/sysctl.d/60-dpanel-redis.conf"

redis_service_unit() {
  local unit
  for unit in redis-server redis; do
    if systemctl cat "${unit}.service" >/dev/null 2>&1; then
      printf '%s' "$unit"
      return 0
    fi
  done
  return 1
}

redis_install_preflight() {
  local main_conf unit dropin_dir
  main_conf="$(redis_main_conf)" || { panel_info_log "redis config not found; boot preflight skipped."; return 0; }
  command -v systemctl >/dev/null 2>&1 || { panel_info_log "systemd not found; redis boot preflight skipped."; return 0; }
  unit="$(redis_service_unit)" || { panel_info_log "redis unit not found; boot preflight skipped."; return 0; }

  cat > "$REDIS_PREFLIGHT_BIN" <<'PREFLIGHT'
#!/usr/bin/env bash
# Managed by dPanel (redis module). Runs before redis starts (systemd
# ExecStartPre) and repairs AOF/RDB files left broken by a power cut.
# It never blocks the start: every path exits 0.
set -uo pipefail

CONF="${1:-/etc/redis/redis.conf}"
KEEP_BACKUPS=3
TAG="dpanel-redis-preflight"

# stderr lands in the redis unit's journal (journalctl -u redis-server).
log() { printf '%s: %s\n' "$TAG" "$*" >&2; }

# Last value of a directive in redis.conf, quotes stripped.
conf_get() {
  awk -v key="$1" 'tolower($1) == key { $1 = ""; sub(/^[ \t]+/, ""); v = $0 } END { print v }' "$CONF" 2>/dev/null \
    | sed -e 's/^"\(.*\)"$/\1/' -e "s/^'\(.*\)'$/\1/"
}

owner_of() { stat -c '%U:%G' "$1" 2>/dev/null || printf 'redis:redis'; }

backup() {
  local src="$1" stamp dest
  stamp="$(date +%Y%m%d-%H%M%S)"
  dest="${src}.bak-${stamp}"
  cp -a "$src" "$dest" || { log "backup of ${src} failed; leaving it untouched."; return 1; }
  log "backed up ${src} to ${dest}"
  # Keep only the newest backups so repeated crashes do not fill the disk.
  ls -1dt "${src}".bak-* 2>/dev/null | tail -n +$((KEEP_BACKUPS + 1)) | xargs -r rm -rf --
  return 0
}

[[ -r "$CONF" ]] || { log "config ${CONF} not readable; skipped."; exit 0; }

dir="$(conf_get dir)"; dir="${dir:-/var/lib/redis}"
[[ -d "$dir" ]] || exit 0

appendonly="$(conf_get appendonly)"
if [[ "${appendonly,,}" == "yes" ]] && command -v redis-check-aof >/dev/null 2>&1; then
  aof_dirname="$(conf_get appenddirname)"; aof_dirname="${aof_dirname:-appendonlydir}"
  aof_name="$(conf_get appendfilename)"; aof_name="${aof_name:-appendonly.aof}"
  aof_dir="${dir}/${aof_dirname}"
  manifest="${aof_dir}/${aof_name}.manifest"
  if [[ -f "$manifest" ]]; then
    if redis-check-aof "$manifest" >/dev/null 2>&1; then
      log "AOF ok."
    else
      log "AOF damaged (likely an unclean shutdown); repairing."
      if backup "$aof_dir"; then
        owner="$(owner_of "$aof_dir")"
        out="$(printf 'y\n' | redis-check-aof --fix "$manifest" 2>&1)"
        chown -R "$owner" "$aof_dir" 2>/dev/null || true
        log "$(printf '%s' "$out" | grep -E 'shrink|truncated|valid|error' | tr '\n' ' ')"
        if redis-check-aof "$manifest" >/dev/null 2>&1; then
          log "AOF repaired."
        else
          log "AOF still invalid after repair; redis may fail to start. Backup kept."
        fi
      fi
    fi
  fi
fi

# Plain RDB snapshot (used when AOF is off, or as a leftover). A corrupt one
# is moved aside rather than deleted, so redis starts and nothing is lost.
dbfilename="$(conf_get dbfilename)"; dbfilename="${dbfilename:-dump.rdb}"
rdb="${dir}/${dbfilename}"
if [[ "${appendonly,,}" != "yes" && -s "$rdb" ]] && command -v redis-check-rdb >/dev/null 2>&1; then
  if redis-check-rdb "$rdb" >/dev/null 2>&1; then
    log "RDB ok."
  else
    stamp="$(date +%Y%m%d-%H%M%S)"
    mv -- "$rdb" "${rdb}.corrupt-${stamp}" && log "RDB damaged; moved to ${rdb}.corrupt-${stamp} so redis can start."
  fi
fi

exit 0
PREFLIGHT
  chmod 755 "$REDIS_PREFLIGHT_BIN"

  # "+" runs the check as root outside the unit's sandbox (User=redis,
  # ProtectSystem=strict) so it can back up and chown the data files.
  dropin_dir="/etc/systemd/system/${unit}.service.d"
  mkdir -p "$dropin_dir"
  cat > "${dropin_dir}/${REDIS_PREFLIGHT_DROPIN_NAME}" <<DROPIN
# Managed by dPanel (redis module): repair AOF/RDB after an unclean shutdown.
[Service]
ExecStartPre=+${REDIS_PREFLIGHT_BIN} ${main_conf}
DROPIN
  systemctl daemon-reload >/dev/null 2>&1 || true

  # Redis warns at every start without this, and background saves (which
  # rewrite the AOF) can fail under memory pressure.
  printf '# Managed by dPanel (redis module).\nvm.overcommit_memory = 1\n' > "$REDIS_SYSCTL_CONF"
  sysctl -q -w vm.overcommit_memory=1 >/dev/null 2>&1 || true

  panel_info_log "redis boot preflight installed (${unit}.service)."
}

redis_install() {
  pkg_install_redis_stack
  pkg_enable_service redis-server || pkg_enable_service redis
  redis_apply_memory_policy
  redis_install_preflight
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
