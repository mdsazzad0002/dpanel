#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

action="${1:-install}"
shift || true

run_disable_root() {
  local user_script="${LIKESOFT_RUNTIME_DIR}/scripts/disable-root-login.sh"
  if [[ ! -x "$user_script" ]]; then
    panel_die "Runtime script not found: ${user_script}"
  fi

  bash "$user_script" "$@"
}

case "$action" in
  install)
    run_disable_root "$@"
    panel_info_log "ssh-root-login module installed."
    ;;
  update)
    run_disable_root "$@"
    panel_info_log "ssh-root-login module updated."
    ;;
  remove)
    panel_warn_log "SSH root-login restore is not automated."
    ;;
  *)
    panel_die "Unsupported ssh-root-login action: $action"
    ;;
esac
