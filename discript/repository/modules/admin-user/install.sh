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

run_create_user() {
  local user_script="${LIKESOFT_RUNTIME_DIR}/scripts/create-admin-user.sh"
  if [[ ! -x "$user_script" ]]; then
    panel_die "Runtime script not found: ${user_script}"
  fi

  bash "$user_script" "$@"
}

case "$action" in
  install)
    forward_args=()
    disable_root="true"

    for arg in "$@"; do
      case "$arg" in
        --keep-root|--no-disable-root)
          disable_root="false"
          ;;
        --disable-root)
          disable_root="true"
          forward_args+=("$arg")
          ;;
        *)
          forward_args+=("$arg")
          ;;
      esac
    done

    if [[ "$disable_root" == "true" ]]; then
      forward_args+=(--disable-root)
    fi

    run_create_user "${forward_args[@]}"
    panel_info_log "admin-user module installed."
    ;;
  update)
    if [[ $# -gt 0 ]]; then
      run_create_user "$@"
      panel_info_log "admin-user module updated."
    else
      panel_warn_log "Admin user update is not automated."
    fi
    ;;
  remove)
    panel_warn_log "Admin user removal is not automated."
    ;;
  *)
    panel_die "Unsupported admin-user action: $action"
    ;;
esac
