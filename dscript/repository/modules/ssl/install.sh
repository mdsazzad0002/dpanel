#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

SSL_RENEWAL_HOOK='/etc/letsencrypt/renewal-hooks/deploy/00-dpanel-reload-webstack.sh'

# certbot renews out of band. Without a deploy hook the web servers keep the old
# certificate in memory until something reloads them, so a renewed site still
# serves an expired certificate. A hook in renewal-hooks/deploy applies to every
# certificate on the server, including ones issued before this panel version.
ssl_install_renewal_hook() {
  mkdir -p "$(dirname "${SSL_RENEWAL_HOOK}")"
  cat > "${SSL_RENEWAL_HOOK}" <<'HOOK'
#!/usr/bin/env bash
# Managed by dpanel. Reloads the web stack after certbot deploys a certificate.
set -uo pipefail

if command -v nginx >/dev/null 2>&1 && nginx -t >/dev/null 2>&1; then
  systemctl reload nginx >/dev/null 2>&1 || true
fi

if command -v apache2ctl >/dev/null 2>&1 && apache2ctl -t >/dev/null 2>&1; then
  systemctl reload apache2 >/dev/null 2>&1 || true
elif command -v httpd >/dev/null 2>&1 && httpd -t >/dev/null 2>&1; then
  systemctl reload httpd >/dev/null 2>&1 || true
fi

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
  case "$(pkg_distro_family)" in
    debian) packages=(certbot python3-certbot-nginx python3-certbot-apache) ;;
    rpm) packages=(certbot python3-certbot-nginx) ;;
    *) packages=(certbot) ;;
  esac

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
  case "$(pkg_distro_family)" in
    debian)
      pkg_remove certbot python3-certbot-nginx python3-certbot-apache
      ;;
    rpm)
      pkg_remove certbot python3-certbot-nginx
      ;;
  esac
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
