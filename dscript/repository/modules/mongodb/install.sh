#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
[[ $# -gt 0 ]] && shift

# MongoDB is installed by default but left stopped and disabled, so it costs
# no RAM until an admin turns it on from the panel (Database Management >
# MongoDB) or with `dpanel mongodb start`.
MONGODB_SERVICE="mongod"
MONGODB_CONFIG="/etc/mongod.conf"
MONGODB_DATA_DIRS=(/var/lib/mongodb /var/lib/mongo)

# mongo-express: the web UI, served by the drust edge gateway on the panel
# domain at /mongo-express/ (see drust/src/mongo_express_sso.rs). npm ships
# it without its browser assets, so it is built from the release's source.
MONGO_EXPRESS_VERSION="${MONGO_EXPRESS_VERSION:-1.1.0-rc-4}"
MONGO_EXPRESS_HOME="/opt/dpanel/mongo-express"
MONGO_EXPRESS_SERVICE="dpanel-mongo-express"
MONGO_EXPRESS_USER="mongoexpress"
# Read by the systemd unit and by the edge gateway (for the basic-auth login).
MONGO_EXPRESS_ENV="/etc/dpanel/mongo-express.env"
MONGO_EXPRESS_PORT=8081

# MongoDB 8.x refuses to start on Linux 6.19 up to 7.0.13 (its TCMalloc breaks
# the kernel's rseq ABI, SERVER-121912). 7.0 predates that TCMalloc, so those
# kernels get 7.0 and every other kernel gets 8.0.
mongodb_kernel_breaks_8() {
  local release="${1:-$(uname -r)}" major minor patch
  IFS=. read -r major minor patch <<< "$(printf '%s' "$release" | grep -oE '^[0-9]+\.[0-9]+(\.[0-9]+)?')"
  major="${major:-0}" minor="${minor:-0}" patch="${patch:-0}"
  (( major == 6 && minor >= 19 )) || (( major == 7 && minor == 0 && patch < 14 ))
}

mongodb_pick_series() {
  if [[ -n "${MONGODB_SERIES:-}" ]]; then
    printf '%s' "$MONGODB_SERIES"
  elif mongodb_kernel_breaks_8; then
    printf '7.0'
  else
    printf '8.0'
  fi
}

MONGODB_SERIES="$(mongodb_pick_series)"
MONGODB_KEYRING="/usr/share/keyrings/mongodb-server-${MONGODB_SERIES}.gpg"

# "8.0" from `mongod --version` ("db version v8.0.32"), empty if not installed.
mongodb_installed_series() {
  command -v mongod >/dev/null 2>&1 || return 0
  mongod --version 2>/dev/null | grep -oE 'v[0-9]+\.[0-9]+' | head -n 1 | tr -d v
}

mongodb_has_data() {
  local dir
  for dir in "${MONGODB_DATA_DIRS[@]}"; do
    [[ -n "$(ls -A "$dir" 2>/dev/null)" ]] && return 0
  done
  return 1
}

mongodb_installed() {
  pkg_package_installed mongodb-org-server || command -v mongod >/dev/null 2>&1
}

mongodb_env_file() {
  local env_file
  env_file="$(panel_resolve_app_env_file)"
  if [[ -z "$env_file" ]]; then
    env_file="$(panel_ensure_app_env_file "${PANEL_APP_DIR:-/var/www/dpanel}/.env")"
  fi
  printf '%s' "$env_file"
}

mongodb_env_value() {
  local key="$1" env_file
  env_file="$(panel_resolve_app_env_file)"
  [[ -n "$env_file" && -f "$env_file" ]] || return 0
  awk -F= -v key="$key" '$1 == key { sub(/^[^=]*=/, ""); print }' "$env_file" | tail -n 1
}

# MongoDB 5+ is built for CPUs with AVX; on older or some virtual CPUs mongod
# dies with "Illegal instruction" on start.
mongodb_cpu_supported() {
  case "$(uname -m)" in
    x86_64) grep -qw avx /proc/cpuinfo ;;
    aarch64) return 0 ;;
    *) return 1 ;;
  esac
}

# MongoDB doesn't publish every series for every release (7.0 has no Ubuntu
# 24.04 build), so fall back to the next older release it does publish.
mongodb_older_codenames() {
  local id="$1" current="$2" known="" seen=false name
  case "$id" in
    ubuntu) known="noble jammy focal" ;;
    debian) known="bookworm bullseye" ;;
  esac
  [[ " $known " == *" $current "* ]] || seen=true
  for name in $known; do
    [[ "$seen" == true ]] && printf '%s\n' "$name"
    [[ "$name" == "$current" ]] && seen=true
  done
  return 0
}

# Adds MongoDB's own repository; distros don't ship mongodb-org. Returns 1 when
# MongoDB publishes nothing usable for this release, so the caller can skip.
mongodb_add_repo() {
  local id codename current
  # shellcheck disable=SC1091
  id="$(. /etc/os-release && printf '%s' "${ID:-}")"

  case "$(pkg_distro_family)" in
    debian)
      local component=main base candidate
      [[ "$id" == "ubuntu" ]] && component=multiverse
      base="https://repo.mongodb.org/apt/${id}"
      current="$(pkg_os_codename)"
      codename=""
      for candidate in "$current" $(mongodb_older_codenames "$id" "$current"); do
        if pkg_url_exists "${base}/dists/${candidate}/mongodb-org/${MONGODB_SERIES}/Release"; then
          codename="$candidate"
          break
        fi
      done
      if [[ -z "$codename" ]]; then
        panel_warn_log "MongoDB ${MONGODB_SERIES} has no packages for ${id} ${current}."
        return 1
      fi
      if [[ "$codename" != "$current" ]]; then
        panel_info_log "Using MongoDB ${MONGODB_SERIES} packages built for ${id} ${codename}."
      fi
      pkg_install gnupg curl
      if [[ ! -s "$MONGODB_KEYRING" ]]; then
        curl -fsSL --max-time 30 "https://pgp.mongodb.com/server-${MONGODB_SERIES}.asc" \
          | gpg --dearmor -o "$MONGODB_KEYRING" || return 1
      fi
      # One series at a time: drop the list for any other series.
      find /etc/apt/sources.list.d -maxdepth 1 -name 'mongodb-org-*.list' -delete
      printf 'deb [signed-by=%s] %s %s/mongodb-org/%s %s\n' \
        "$MONGODB_KEYRING" "$base" "$codename" "$MONGODB_SERIES" "$component" \
        > "/etc/apt/sources.list.d/mongodb-org-${MONGODB_SERIES}.list"
      DPANEL_APT_UPDATED=false pkg_update_index
      ;;
    rpm)
      find /etc/yum.repos.d -maxdepth 1 -name 'mongodb-org-*.repo' -delete
      cat > "/etc/yum.repos.d/mongodb-org-${MONGODB_SERIES}.repo" <<REPO
[mongodb-org-${MONGODB_SERIES}]
name=MongoDB Repository
baseurl=https://repo.mongodb.org/yum/redhat/\$releasever/mongodb-org/${MONGODB_SERIES}/\$basearch/
gpgcheck=1
enabled=1
gpgkey=https://pgp.mongodb.com/server-${MONGODB_SERIES}.asc
REPO
      ;;
    *)
      panel_warn_log "Unsupported distro for MongoDB: ${DISTRO:-unknown}"
      return 1
      ;;
  esac
}

mongodb_install_packages() {
  pkg_install mongodb-org
}

mongodb_remove_packages() {
  pkg_remove_if_installed mongodb-org mongodb-org-server mongodb-org-mongos mongodb-org-tools mongodb-org-database \
    mongodb-org-database-tools-extra mongodb-org-shell mongodb-mongosh mongodb-database-tools
}

mongodb_wait_ready() {
  local attempt
  for attempt in $(seq 1 30); do
    mongosh --quiet --norc --eval 'db.runCommand({ ping: 1 }).ok' >/dev/null 2>&1 && return 0
    sleep 1
  done
  return 1
}

# The reason from mongod's last log line (its logs are JSON).
mongodb_last_error() {
  journalctl -u "$MONGODB_SERVICE" -n 20 -o cat --no-pager 2>/dev/null \
    | grep -o '"msg":"[^"]*"' | tail -n 1 | sed 's/^"msg":"//; s/"$//'
}

mongodb_auth_enabled() {
  grep -Eq '^[[:space:]]*authorization:[[:space:]]*enabled' "$MONGODB_CONFIG" 2>/dev/null
}

# Turn on access control. Debian/RPM packages ship `#security:` commented out.
mongodb_enable_auth() {
  mongodb_auth_enabled && return 0
  if grep -Eq '^security:' "$MONGODB_CONFIG"; then
    sed -i -E '/^security:/a\  authorization: enabled' "$MONGODB_CONFIG"
  else
    printf '\nsecurity:\n  authorization: enabled\n' >> "$MONGODB_CONFIG"
  fi
}

mongodb_setup() {
  local env_file username password
  env_file="$(mongodb_env_file)"
  username="$(mongodb_env_value MONGODB_ADMIN_USERNAME)"
  password="$(mongodb_env_value MONGODB_ADMIN_PASSWORD)"
  [[ -n "$username" ]] || username=admin

  if [[ -z "$password" ]]; then
    if mongodb_auth_enabled; then
      panel_warn_log "MongoDB already has access control on but no admin password is saved in ${env_file}; set MONGODB_ADMIN_PASSWORD there by hand."
    else
      password="$(panel_generate_token | cut -c1-24)"
      # Before auth is on, the localhost exception lets us create the first
      # user. The password travels in the environment, not on the command line.
      systemctl start "$MONGODB_SERVICE" || true
      if ! mongodb_wait_ready; then
        # e.g. MongoDB 8 refuses to start on Linux 6.19 - 7.0.13 (SERVER-121912).
        # Leave it installed and off; re-running install finishes the setup.
        mongodb_off
        panel_warn_log "mongod would not start, so no admin user was created: $(mongodb_last_error)"
        panel_warn_log "Fix that, then run: sudo dpanel mongodb install"
        return 0
      fi
      MONGODB_SETUP_USER="$username" MONGODB_SETUP_PASSWORD="$password" mongosh --quiet --norc admin --eval '
        db.createUser({
          user: process.env.MONGODB_SETUP_USER,
          pwd: process.env.MONGODB_SETUP_PASSWORD,
          roles: [{ role: "root", db: "admin" }]
        })' >/dev/null
      mongodb_enable_auth
    fi
  fi

  panel_env_set "$env_file" MONGODB_HOST 127.0.0.1
  panel_env_set "$env_file" MONGODB_PORT 27017
  panel_env_set "$env_file" MONGODB_ADMIN_USERNAME "$username"
  [[ -n "$password" ]] && panel_env_set "$env_file" MONGODB_ADMIN_PASSWORD "$password"
  panel_info_log "MongoDB installed. Admin credentials saved to ${env_file}."
}

mongodb_off() {
  systemctl disable --now "$MONGODB_SERVICE" >/dev/null 2>&1 || true
}

mongo_express_installed() {
  [[ -f "${MONGO_EXPRESS_HOME}/current/app.js" && -f "/etc/systemd/system/${MONGO_EXPRESS_SERVICE}.service" ]]
}

mongo_express_off() {
  systemctl disable --now "$MONGO_EXPRESS_SERVICE" >/dev/null 2>&1 || true
}

# mongo-express 1.1 needs Node.js 18.18 or newer.
mongo_express_node() {
  command -v node >/dev/null 2>&1 || pkg_install nodejs >/dev/null 2>&1 || true
  command -v node >/dev/null 2>&1 || return 1
  node -e 'const [a, b] = process.versions.node.split(".").map(Number); process.exit(a > 18 || (a === 18 && b >= 18) ? 0 : 1)' || return 1
  # Distro nodejs packages ship npm separately; NodeSource's include it.
  command -v npm >/dev/null 2>&1 || pkg_install npm >/dev/null 2>&1 || true
  command -v npm >/dev/null 2>&1
}

mongo_express_env_value() {
  [[ -f "$MONGO_EXPRESS_ENV" ]] || return 0
  awk -F= -v key="$1" '$1 == key { sub(/^[^=]*=/, ""); print }' "$MONGO_EXPRESS_ENV" | tail -n 1
}

# Downloads the release source and builds its browser assets. Returns 1 on
# any failure; the caller keeps MongoDB either way.
mongo_express_build() {
  local release="${MONGO_EXPRESS_HOME}/${MONGO_EXPRESS_VERSION}" work
  if [[ -f "${release}/app.js" && -d "${release}/build" ]]; then
    return 0
  fi
  install -d -m 0755 "$MONGO_EXPRESS_HOME" || return 1
  work="$(mktemp -d "${MONGO_EXPRESS_HOME}/.build.XXXXXX")" || return 1
  panel_info_log "Building mongo-express ${MONGO_EXPRESS_VERSION}…"
  # An explicit && chain: errexit does nothing inside an `if` condition.
  # --ignore-scripts skips dev tools (cypress, mongodb-memory-server) that
  # would download large binaries on install.
  if ! (
    cd "$work" \
      && curl -fsSL --max-time 120 \
        "https://github.com/mongo-express/mongo-express/archive/refs/tags/v${MONGO_EXPRESS_VERSION}.tar.gz" \
        | tar xz --strip-components=1 \
      && npm install --ignore-scripts --no-audit --no-fund --loglevel=error \
      && NODE_ENV=production ./node_modules/.bin/webpack \
      && npm prune --omit=dev --ignore-scripts --no-audit --no-fund --loglevel=error \
      && [[ -d build && -f app.js ]]
  ) >/dev/null 2>&1; then
    rm -rf -- "$work"
    return 1
  fi
  rm -rf -- "$release"
  mv "$work" "$release"
  chmod 0755 "$release"
}

mongo_express_write_env() {
  local mongo_user="$1" mongo_password="$2" username password cookie session url
  username="$(mongo_express_env_value ME_CONFIG_BASICAUTH_USERNAME)"
  password="$(mongo_express_env_value ME_CONFIG_BASICAUTH_PASSWORD)"
  cookie="$(mongo_express_env_value ME_CONFIG_SITE_COOKIESECRET)"
  session="$(mongo_express_env_value ME_CONFIG_SITE_SESSIONSECRET)"
  [[ -n "$username" ]] || username=dpanel
  [[ -n "$password" ]] || password="$(panel_generate_token | cut -c1-32)"
  [[ -n "$cookie" ]] || cookie="$(panel_generate_token)"
  [[ -n "$session" ]] || session="$(panel_generate_token)"
  url="$(MONGO_USER="$mongo_user" MONGO_PASSWORD="$mongo_password" node -e '
    const e = encodeURIComponent;
    console.log(`mongodb://${e(process.env.MONGO_USER)}:${e(process.env.MONGO_PASSWORD)}@127.0.0.1:27017/?authSource=admin`);')"

  install -d -m 0755 "$(dirname "$MONGO_EXPRESS_ENV")"
  ( umask 027
    cat > "$MONGO_EXPRESS_ENV" <<ENV
# Managed by dpanel (mongodb module). The edge gateway signs panel admins in
# with the basic-auth login below; nobody types it.
NODE_ENV=production
VCAP_APP_HOST=127.0.0.1
PORT=${MONGO_EXPRESS_PORT}
ME_CONFIG_SITE_BASEURL=/mongo-express/
ME_CONFIG_MONGODB_URL=${url}
ME_CONFIG_MONGODB_ENABLE_ADMIN=true
ME_CONFIG_BASICAUTH_ENABLED=true
ME_CONFIG_BASICAUTH_USERNAME=${username}
ME_CONFIG_BASICAUTH_PASSWORD=${password}
ME_CONFIG_SITE_COOKIESECRET=${cookie}
ME_CONFIG_SITE_SESSIONSECRET=${session}
ME_CONFIG_OPTIONS_CONFIRM_DELETE=true
ENV
  )
  chown "root:${MONGO_EXPRESS_USER}" "$MONGO_EXPRESS_ENV"
  chmod 0640 "$MONGO_EXPRESS_ENV"
}

mongo_express_write_unit() {
  cat > "/etc/systemd/system/${MONGO_EXPRESS_SERVICE}.service" <<UNIT
[Unit]
Description=mongo-express web UI (managed by dpanel)
After=network.target ${MONGODB_SERVICE}.service

[Service]
Type=simple
User=${MONGO_EXPRESS_USER}
Group=${MONGO_EXPRESS_USER}
EnvironmentFile=${MONGO_EXPRESS_ENV}
WorkingDirectory=${MONGO_EXPRESS_HOME}/current
ExecStart=$(command -v node) app.js
Restart=on-failure
RestartSec=5
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
}

# Installs (or rebuilds after a version bump) mongo-express, left off.
mongo_express_setup() {
  local mongo_user mongo_password
  mongo_user="$(mongodb_env_value MONGODB_ADMIN_USERNAME)"
  mongo_password="$(mongodb_env_value MONGODB_ADMIN_PASSWORD)"
  if [[ -z "$mongo_password" ]]; then
    panel_warn_log "Skipping mongo-express: MongoDB has no admin login yet. Re-run: sudo dpanel mongodb install"
    return 0
  fi
  if ! mongo_express_node; then
    panel_warn_log "Skipping mongo-express: it needs Node.js 18.18+ and npm. Install them, then re-run: sudo dpanel mongodb install"
    return 0
  fi
  if ! id -u "$MONGO_EXPRESS_USER" >/dev/null 2>&1; then
    useradd --system --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin "$MONGO_EXPRESS_USER" || return 1
  fi
  if ! mongo_express_build; then
    panel_warn_log "mongo-express build failed; MongoDB is still available. Retry with: sudo dpanel mongodb install"
    return 0
  fi
  ln -sfn "${MONGO_EXPRESS_HOME}/${MONGO_EXPRESS_VERSION}" "${MONGO_EXPRESS_HOME}/current"
  mongo_express_write_env "${mongo_user:-admin}" "$mongo_password"
  mongo_express_write_unit
  panel_info_log "mongo-express ${MONGO_EXPRESS_VERSION} installed at /mongo-express/ on the panel domain."
}

service_units() {
  case "${1:-all}" in
    mongodb|mongod) printf '%s\n' "$MONGODB_SERVICE" ;;
    mongo-express|express) printf '%s\n' "$MONGO_EXPRESS_SERVICE" ;;
    all|'') printf '%s\n' "$MONGODB_SERVICE" "$MONGO_EXPRESS_SERVICE" ;;
    *) panel_die "Unknown service '${1}'. Use mongodb, mongo-express or all." ;;
  esac
}

mongodb_install() {
  # Default install: a MongoDB that can't run here is skipped, not fatal, so
  # the rest of the chain still installs.
  if ! mongodb_cpu_supported; then
    panel_warn_log "Skipping MongoDB: this CPU lacks AVX, which MongoDB ${MONGODB_SERIES} needs."
    return 0
  fi
  local installed_series
  installed_series="$(mongodb_installed_series)"
  if [[ -n "$installed_series" && "$installed_series" != "$MONGODB_SERIES" ]]; then
    # A different major version may not open existing data files, so only
    # switch a MongoDB that never stored anything.
    if mongodb_has_data; then
      panel_warn_log "MongoDB ${installed_series} is installed with data, but kernel $(uname -r) needs ${MONGODB_SERIES}. Not switching automatically."
    else
      panel_info_log "Kernel $(uname -r) needs MongoDB ${MONGODB_SERIES}; replacing ${installed_series}."
      mongodb_off
      mongodb_remove_packages
    fi
  fi
  if ! mongodb_installed; then
    panel_info_log "Installing MongoDB ${MONGODB_SERIES} for kernel $(uname -r)."
    if ! mongodb_add_repo; then
      panel_warn_log "Skipping MongoDB on this server."
      return 0
    fi
    mongodb_install_packages
  fi
  # A fresh install leaves both off; re-running install keeps what an admin
  # turned on.
  local was_active=false express_was_active=false
  systemctl is-active --quiet "$MONGODB_SERVICE" && was_active=true
  systemctl is-active --quiet "$MONGO_EXPRESS_SERVICE" && express_was_active=true

  systemctl daemon-reload || true
  mongodb_setup
  mongo_express_setup
  if [[ "$was_active" == true ]]; then
    systemctl start "$MONGODB_SERVICE" || true
  else
    mongodb_off
  fi
  if [[ "$express_was_active" == true ]]; then
    systemctl restart "$MONGO_EXPRESS_SERVICE" || true
  else
    mongo_express_off
  fi
  panel_info_log "mongodb + mongo-express installed. Services that were off stay OFF; turn on from the panel or with: dpanel mongodb start"
}

mongodb_update() {
  if ! mongodb_installed; then
    panel_info_log "mongodb not installed; skipping update. Install with: dpanel mongodb install"
    return 0
  fi
  local was_active=false express_was_active=false
  systemctl is-active --quiet "$MONGODB_SERVICE" && was_active=true
  systemctl is-active --quiet "$MONGO_EXPRESS_SERVICE" && express_was_active=true
  mongodb_install_packages
  mongo_express_setup
  # Keep whatever on/off state the admin chose.
  [[ "$was_active" == true ]] && systemctl restart "$MONGODB_SERVICE"
  [[ "$express_was_active" == true ]] && systemctl restart "$MONGO_EXPRESS_SERVICE"
  panel_info_log "mongodb + mongo-express updated."
}

mongodb_remove() {
  mongo_express_off
  rm -f "/etc/systemd/system/${MONGO_EXPRESS_SERVICE}.service"
  systemctl daemon-reload || true
  rm -rf -- "$MONGO_EXPRESS_HOME"
  mongodb_off
  mongodb_remove_packages
  panel_info_log "mongodb removed. Data in /var/lib/mongodb (or /var/lib/mongo) was kept."
}

mongodb_status() {
  local unit active enabled
  while IFS= read -r unit; do
    active="$(systemctl is-active "$unit" 2>/dev/null || true)"
    enabled="$(systemctl is-enabled "$unit" 2>/dev/null || true)"
    printf '%-22s active=%-10s enabled=%s\n' "$unit" "${active:-unknown}" "${enabled:-not-found}"
  done < <(service_units "${1:-all}")
}

mongodb_start() {
  local unit
  while IFS= read -r unit; do
    if [[ "$unit" == "$MONGODB_SERVICE" ]]; then
      mongodb_installed || panel_die "MongoDB is not installed. Run: dpanel mongodb install"
      systemctl enable --now "$MONGODB_SERVICE"
      if ! mongodb_wait_ready; then
        mongodb_off
        panel_die "mongod stopped right after starting: $(mongodb_last_error)"
      fi
    else
      mongo_express_installed || panel_die "mongo-express is not installed. Run: dpanel mongodb install"
      systemctl enable --now "$MONGO_EXPRESS_SERVICE"
    fi
  done < <(service_units "${1:-all}")
  mongodb_status "${1:-all}"
}

mongodb_stop() {
  local unit
  # mongo-express first, so it doesn't log a lost connection.
  while IFS= read -r unit; do
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
  done < <(service_units "${1:-all}" | tac)
  mongodb_status "${1:-all}"
}

case "$action" in
  install) mongodb_install ;;
  update) mongodb_update ;;
  remove) mongodb_remove ;;
  start|enable|on) mongodb_start "${1:-all}" ;;
  stop|disable|off) mongodb_stop "${1:-all}" ;;
  restart)
    while IFS= read -r unit; do
      systemctl is-active --quiet "$unit" && systemctl restart "$unit"
    done < <(service_units "${1:-all}")
    mongodb_status "${1:-all}"
    ;;
  status) mongodb_status "${1:-all}" ;;
  *) panel_die "Unsupported mongodb action: $action" ;;
esac
