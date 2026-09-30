#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
[[ $# -gt 0 ]] && shift

# ClamAV is installed for the Security Center malware scan, but its daemons
# stay off: clamd keeps ~1 GB of signatures in RAM. The drust scanner runs
# clamscan on demand and refreshes signatures with freshclam when they are
# older than a day. `dpanel clamav start` turns the daemons on if wanted.

clamav_units() {
  case "$(pkg_distro_family)" in
    rpm) printf '%s\n' clamav-freshclam clamd@scan ;;
    *) printf '%s\n' clamav-freshclam clamav-daemon ;;
  esac
}

clamav_services_off() {
  local unit
  while IFS= read -r unit; do
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
  done < <(clamav_units)
}

clamav_install_packages() {
  case "$(pkg_distro_family)" in
    debian) pkg_install clamav clamav-daemon clamav-freshclam ;;
    rpm)
      pkg_install epel-release || true
      pkg_install clamav clamav-update clamd
      # Fedora/EPEL ship freshclam.conf with an "Example" guard line.
      [[ -f /etc/freshclam.conf ]] && sed -i 's/^Example/#Example/' /etc/freshclam.conf
      ;;
    *) panel_die "Unsupported distro for ClamAV: ${DISTRO:-unknown}" ;;
  esac
}

clamav_refresh_signatures() {
  # The freshclam service may hold the database lock right after install.
  systemctl stop clamav-freshclam >/dev/null 2>&1 || true
  systemctl stop dpanel-freshclam.service >/dev/null 2>&1 || true

  # Downloading ~300 MB of signatures and test-loading them (~1 GB RAM) can
  # take many minutes on a small VPS and looked like a frozen install. It runs
  # in the background at low priority instead; nothing in the install needs
  # the signatures, and a malware scan refreshes them anyway.
  if command -v systemd-run >/dev/null 2>&1 \
    && systemd-run --quiet --collect --unit=dpanel-freshclam \
      --property=Nice=19 --property=IOSchedulingClass=idle --property=RuntimeMaxSec=1800 \
      "$(command -v freshclam)" --quiet; then
    panel_info_log "ClamAV signatures are downloading in the background (journalctl -u dpanel-freshclam)."
  elif ! panel_run_with_progress "Downloading ClamAV signatures" timeout 600 freshclam --quiet; then
    panel_warn_log "freshclam could not download signatures now; the first malware scan will retry."
  fi
}

clamav_install() {
  clamav_install_packages
  clamav_refresh_signatures
  clamav_services_off
  panel_info_log "ClamAV installed; daemons left OFF. Malware scans run clamscan on demand."
}

clamav_update() {
  if ! command -v clamscan >/dev/null 2>&1; then
    panel_info_log "clamav not installed; skipping update. Install with: dpanel clamav install"
    return 0
  fi
  clamav_install_packages
  clamav_refresh_signatures
  panel_info_log "clamav updated."
}

clamav_remove() {
  clamav_services_off
  case "$(pkg_distro_family)" in
    debian) pkg_remove clamav clamav-daemon clamav-freshclam ;;
    rpm) pkg_remove clamav clamav-update clamd ;;
  esac
  panel_info_log "clamav removed."
}

clamav_status() {
  local unit
  printf '%-18s %s\n' clamscan "$(command -v clamscan || printf 'not installed')"
  while IFS= read -r unit; do
    printf '%-18s active=%-10s enabled=%s\n' "$unit" \
      "$(systemctl is-active "$unit" 2>/dev/null || true)" \
      "$(systemctl is-enabled "$unit" 2>/dev/null || true)"
  done < <(clamav_units)
}

case "$action" in
  install) clamav_install ;;
  update) clamav_update ;;
  remove) clamav_remove ;;
  start)
    while IFS= read -r unit; do systemctl enable --now "$unit"; done < <(clamav_units)
    clamav_status
    ;;
  stop) clamav_services_off; clamav_status ;;
  restart)
    while IFS= read -r unit; do
      systemctl is-active --quiet "$unit" && systemctl restart "$unit"
    done < <(clamav_units)
    clamav_status
    ;;
  status) clamav_status ;;
  *) panel_die "Unsupported clamav action: $action" ;;
esac
