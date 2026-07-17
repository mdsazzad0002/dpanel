#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"

action="${1:-install}"
shift || true

filemanager_usage() {
  cat <<'EOF'
Usage:
  filemanager install [create|remove|exists|file-exists] <path>...
  filemanager remove <path>...
  filemanager update [create|remove|exists|file-exists] <path>...
  filemanager user create <username> [--home <path>] [--shell <shell>]
  filemanager user ensure <username> [--home <path>] [--shell <shell>]

Commands:
  create       Create one or more folders
  remove       Remove one or more folders
  exists       Check whether one or more folders exist
  file-exists  Check whether one or more files exist
  user create  Create a system user and prepare its home directory
  user ensure  Repair an existing user home directory
EOF
}

filemanager_require_paths() {
  if [[ $# -lt 1 ]]; then
    filemanager_usage
    panel_die "Missing path argument."
  fi
}

filemanager_validate_target() {
  local target="$1"

  [[ -n "$target" ]] || panel_die "Missing path argument."

  case "$target" in
    /*)
      ;;
    *)
      panel_die "Path must be absolute: $target"
      ;;
  esac
}

filemanager_invoking_user_home() {
  if [[ -n "${SUDO_USER:-}" ]]; then
    getent passwd "$SUDO_USER" | awk -F: '{print $6}' | tail -n 1
    return 0
  fi

  printf '%s' "${HOME:-}"
}

filemanager_is_protected_target() {
  local target="$1"
  local home

  home="$(filemanager_invoking_user_home)"
  [[ -n "$home" ]] || return 1

  [[ "$target" == "$home" ]]
}

filemanager_apply_owner() {
  local path="$1"

  if [[ "${EUID:-$(id -u)}" -eq 0 && -n "${SUDO_USER:-}" && -n "${SUDO_UID:-}" && -n "${SUDO_GID:-}" ]]; then
    chown "${SUDO_UID}:${SUDO_GID}" -- "$path"
  fi
}

filemanager_validate_username() {
  local username="$1"

  [[ "$username" =~ ^[a-z_][a-z0-9_-]*$ ]] || panel_die "Invalid username: $username"
}

filemanager_user_home() {
  local username="$1"
  printf '%s' "/home/${username}"
}

filemanager_user_group() {
  local username="$1"

  if id -gn "$username" >/dev/null 2>&1; then
    id -gn "$username"
  else
    printf '%s' "$username"
  fi
}

filemanager_prepare_home() {
  local username="$1"
  local home="$2"
  local group

  filemanager_validate_target "$home"
  group="$(filemanager_user_group "$username")"

  install -d -m 0750 -o "$username" -g "$group" -- "$home"

  if [[ -d "$home" ]]; then
    chown -R "$username:$group" -- "$home"
    chmod 0750 -- "$home"
  fi
}

filemanager_user_create() {
  local username="${1:-}"
  shift || true

  local home=""
  local shell="/bin/bash"
  local user_exists="false"
  local home_dir

  [[ -n "$username" ]] || panel_die "Missing username argument."
  filemanager_validate_username "$username"

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --home)
        home="${2:-}"
        shift 2 || true
        ;;
      --shell)
        shell="${2:-/bin/bash}"
        shift 2 || true
        ;;
      *)
        panel_die "Unsupported user option: $1"
        ;;
    esac
  done

  home="${home:-$(filemanager_user_home "$username")}"
  filemanager_validate_target "$home"

  if id -u "$username" >/dev/null 2>&1; then
    user_exists="true"
  fi

  if [[ "$user_exists" == "false" ]]; then
    useradd -m -d "$home" -s "$shell" -U "$username"
    panel_info_log "user created: $username"
  else
    panel_info_log "user exists: $username"
  fi

  home_dir="$home"
  filemanager_prepare_home "$username" "$home_dir"
  panel_info_log "home prepared: $home_dir"
}

filemanager_user_ensure() {
  filemanager_user_create "$@"
}

filemanager_create() {
  local path

  filemanager_require_paths "$@"

  for path in "$@"; do
    filemanager_validate_target "$path"
    if [[ -d "$path" ]]; then
      panel_info_log "folder exists: $path"
    else
      mkdir -p -- "$path"
      panel_info_log "folder created: $path"
    fi
    filemanager_apply_owner "$path"
  done
}

filemanager_remove() {
  local path

  filemanager_require_paths "$@"

  for path in "$@"; do
    filemanager_validate_target "$path"
    if filemanager_is_protected_target "$path"; then
      panel_die "Refusing to remove your home directory: $path"
    fi
    if [[ -e "$path" ]]; then
      rm -rf -- "$path"
      panel_info_log "removed: $path"
    else
      panel_warn_log "nothing to remove: $path"
    fi
  done
}

filemanager_exists() {
  local path
  local missing=0

  filemanager_require_paths "$@"

  for path in "$@"; do
    filemanager_validate_target "$path"
    if [[ -d "$path" ]]; then
      panel_info_log "folder exists: $path"
    else
      panel_warn_log "folder missing: $path"
      missing=1
    fi
  done

  return "$missing"
}

filemanager_file_exists() {
  local path
  local missing=0

  filemanager_require_paths "$@"

  for path in "$@"; do
    filemanager_validate_target "$path"
    if [[ -f "$path" ]]; then
      panel_info_log "file exists: $path"
    else
      panel_warn_log "file missing: $path"
      missing=1
    fi
  done

  return "$missing"
}

filemanager_dispatch() {
  local command="${1:-install}"
  shift || true

  case "$command" in
    install)
      case "${1:-}" in
        create|remove|exists|file-exists)
          command="$1"
          shift || true
          ;;
      esac
      ;;
    update)
      case "${1:-}" in
        create|remove|exists|file-exists)
          command="$1"
          shift || true
          ;;
        *)
          command="exists"
          ;;
      esac
      ;;
    remove)
      command="remove"
      ;;
    user)
      command="user"
      ;;
  esac

  case "$command" in
    install|create)
      filemanager_create "$@"
      ;;
    remove)
      filemanager_remove "$@"
      ;;
    exists)
      filemanager_exists "$@"
      ;;
    file-exists)
      filemanager_file_exists "$@"
      ;;
    user)
      case "${1:-}" in
        create)
          shift || true
          filemanager_user_create "$@"
          ;;
        ensure)
          shift || true
          filemanager_user_ensure "$@"
          ;;
        *)
          filemanager_usage
          panel_die "Unsupported filemanager user action: ${1:-missing}"
          ;;
      esac
      ;;
    *)
      filemanager_usage
      panel_die "Unsupported filemanager action: $command"
      ;;
  esac
}

filemanager_dispatch "$action" "$@"
