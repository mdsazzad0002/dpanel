#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
[[ $# -gt 0 ]] && shift

# Incremental, encrypted backups (backlog W6). drust runs restic as root;
# the panel keeps the repository's location and password. Nothing to
# configure here, only the program.

restic_install() {
  case "$(pkg_distro_family)" in
    debian) pkg_install restic ;;
    rpm)
      pkg_install epel-release || true
      pkg_package_available restic || panel_die "restic is not in this distro's repositories (EPEL)."
      pkg_install restic
      ;;
    *) panel_die "Unsupported distro for restic: ${DISTRO:-unknown}" ;;
  esac
  command -v restic >/dev/null 2>&1 || panel_die 'restic was installed but is not on PATH.'
  # The distro build may be old; restic can update itself when it allows it.
  restic self-update >/dev/null 2>&1 || true
  panel_info_log "restic ready: $(restic version 2>/dev/null | head -n1). Set up the repository in Backups > Incremental."
}

restic_remove() {
  panel_info_log "restic is left installed: existing repositories still need it to restore."
}

case "$action" in
  install|update) restic_install ;;
  remove) restic_remove ;;
  *) panel_die "Unsupported restic action: $action" ;;
esac
