#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

SSL_RENEWAL_HOOK='/etc/letsencrypt/renewal-hooks/deploy/00-dpanel-reload-edge.sh'

# Restart the Rust edge gateway after certbot deploys a renewed certificate.
ssl_install_renewal_hook() {
  mkdir -p "$(dirname "${SSL_RENEWAL_HOOK}")"
  cat > "${SSL_RENEWAL_HOOK}" <<'HOOK'
#!/usr/bin/env bash
# Managed by dpanel. Restarts the Rust edge gateway after certificate renewal.
set -uo pipefail

systemctl restart edge-gateway.service >/dev/null 2>&1 || true

exit 0
HOOK
  chmod 0755 "${SSL_RENEWAL_HOOK}"
  panel_info_log "certbot deploy hook installed at ${SSL_RENEWAL_HOOK}."
}

# The packages ship the renewal job themselves, but it is disabled often enough
# on minimal images that an unattended server silently runs into expiry.
ssl_enable_renewal_schedule() {
  if systemctl list-unit-files 2>/dev/null | grep -q '^certbot\.timer'; then
    if systemctl enable --now certbot.timer >/dev/null 2>&1; then
      panel_info_log "certbot.timer enabled for automatic renewal."
    else
      panel_warn_log "certbot.timer could not be enabled; verify automatic renewal manually."
    fi
  elif [[ -f /etc/cron.d/certbot ]]; then
    panel_info_log "certbot renewal cron job detected."
  else
    panel_warn_log "No certbot renewal timer or cron job found; certificates will not renew automatically."
  fi
}

# This module is part of the default chain, so a missing certbot package on a
# trimmed-down mirror must not abort the whole install. The panel works without
# certbot; only SSL issuing waits until it is available.
ssl_install() {
  local packages=()
  packages=(certbot)

  if ! pkg_install "${packages[@]}"; then
    panel_warn_log "Full certbot package set unavailable; retrying with certbot alone."
    if ! pkg_install certbot; then
      panel_warn_log "certbot could not be installed; website SSL stays unavailable until it is installed manually."
      return 0
    fi
  fi

  ssl_install_renewal_hook
  ssl_enable_renewal_schedule
  panel_info_log "ssl tooling installed."
}

ssl_remove() {
  rm -f "${SSL_RENEWAL_HOOK}"
  pkg_remove certbot
  panel_info_log "ssl tooling removed."
}

ssl_update() {
  ssl_install
  panel_info_log "ssl tooling updated."
}

case "$action" in
  install)
    ssl_install
    ;;
  remove)
    ssl_remove
    ;;
  update)
    ssl_update
    ;;
  *)
    panel_die "Unsupported ssl action: $action"
    ;;
esac
