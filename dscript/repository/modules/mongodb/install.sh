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
MONGODB_SERIES="${MONGODB_SERIES:-8.0}"
MONGODB_SERVICE="mongod"
MONGODB_CONFIG="/etc/mongod.conf"
MONGODB_KEYRING="/usr/share/keyrings/mongodb-server-${MONGODB_SERIES}.gpg"

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

# Adds MongoDB's own repository; distros don't ship mongodb-org. Returns 1 when
# MongoDB publishes nothing for this release, so the caller can skip cleanly.
mongodb_add_repo() {
  local id codename
  # shellcheck disable=SC1091
  id="$(. /etc/os-release && printf '%s' "${ID:-}")"

  case "$(pkg_distro_family)" in
    debian)
      local component=main base
      codename="$(pkg_os_codename)"
      [[ "$id" == "ubuntu" ]] && component=multiverse
      base="https://repo.mongodb.org/apt/${id}"
      if ! pkg_url_exists "${base}/dists/${codename}/mongodb-org/${MONGODB_SERIES}/Release"; then
        panel_warn_log "MongoDB ${MONGODB_SERIES} has no packages for ${id} ${codename}."
        return 1
      fi
      pkg_install gnupg curl
      if [[ ! -s "$MONGODB_KEYRING" ]]; then
        curl -fsSL --max-time 30 "https://pgp.mongodb.com/server-${MONGODB_SERIES}.asc" \
          | gpg --dearmor -o "$MONGODB_KEYRING" || return 1
      fi
      printf 'deb [signed-by=%s] %s %s/mongodb-org/%s %s\n' \
        "$MONGODB_KEYRING" "$base" "$codename" "$MONGODB_SERIES" "$component" \
        > "/etc/apt/sources.list.d/mongodb-org-${MONGODB_SERIES}.list"
      DPANEL_APT_UPDATED=false pkg_update_index
      ;;
    rpm)
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

mongodb_wait_ready() {
  local attempt
  for attempt in $(seq 1 30); do
    mongosh --quiet --norc --eval 'db.runCommand({ ping: 1 }).ok' >/dev/null 2>&1 && return 0
    sleep 1
  done
  return 1
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
      systemctl start "$MONGODB_SERVICE"
      mongodb_wait_ready || panel_die "mongod did not start; check 'journalctl -u ${MONGODB_SERVICE}'."
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

mongodb_install() {
  # Default install: a MongoDB that can't run here is skipped, not fatal, so
  # the rest of the chain still installs.
  if ! mongodb_cpu_supported; then
    panel_warn_log "Skipping MongoDB: this CPU lacks AVX, which MongoDB ${MONGODB_SERIES} needs."
    return 0
  fi
  if ! mongodb_installed; then
    if ! mongodb_add_repo; then
      panel_warn_log "Skipping MongoDB on this server."
      return 0
    fi
    mongodb_install_packages
  fi
  systemctl daemon-reload || true
  mongodb_setup
  mongodb_off
  panel_info_log "mongodb installed and left OFF. Turn on from the panel or with: dpanel mongodb start"
}

mongodb_update() {
  if ! mongodb_installed; then
    panel_info_log "mongodb not installed; skipping update. Install with: dpanel mongodb install"
    return 0
  fi
  local was_active=false
  systemctl is-active --quiet "$MONGODB_SERVICE" && was_active=true
  mongodb_install_packages
  # Keep whatever on/off state the admin chose.
  [[ "$was_active" == true ]] && systemctl restart "$MONGODB_SERVICE"
  panel_info_log "mongodb updated."
}

mongodb_remove() {
  mongodb_off
  pkg_remove mongodb-org mongodb-org-server mongodb-org-mongos mongodb-org-tools mongodb-mongosh
  panel_info_log "mongodb removed. Data in /var/lib/mongodb (or /var/lib/mongo) was kept."
}

mongodb_status() {
  local active enabled
  active="$(systemctl is-active "$MONGODB_SERVICE" 2>/dev/null || true)"
  enabled="$(systemctl is-enabled "$MONGODB_SERVICE" 2>/dev/null || true)"
  printf '%-18s active=%-10s enabled=%s\n' "$MONGODB_SERVICE" "${active:-unknown}" "${enabled:-not-found}"
}

case "$action" in
  install) mongodb_install ;;
  update) mongodb_update ;;
  remove) mongodb_remove ;;
  start|enable|on)
    mongodb_installed || panel_die "MongoDB is not installed. Run: dpanel mongodb install"
    systemctl enable --now "$MONGODB_SERVICE"
    mongodb_status
    ;;
  stop|disable|off) mongodb_off; mongodb_status ;;
  restart)
    systemctl is-active --quiet "$MONGODB_SERVICE" && systemctl restart "$MONGODB_SERVICE"
    mongodb_status
    ;;
  status) mongodb_status ;;
  *) panel_die "Unsupported mongodb action: $action" ;;
esac
