#!/usr/bin/env bash
set -euo pipefail

SCRIPT_NAME="$(basename "$0")"
DEFAULT_BASE_URL="https://installer.likesoftbd.com"
BASE_URL="${PANEL_INSTALL_BASE_URL:-${LIKESOFT_BASE_URL:-$DEFAULT_BASE_URL}}"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

download_file() {
  local url="$1"
  local dest="$2"

  mkdir -p "$(dirname "$dest")"

  if command -v curl >/dev/null 2>&1; then
    curl -fsSL "$url" -o "$dest"
    return 0
  fi

  if command -v wget >/dev/null 2>&1; then
    wget -qO "$dest" "$url"
    return 0
  fi

  echo "curl or wget is required to run ${SCRIPT_NAME}" >&2
  exit 1
}

bootstrap_core="$TMP_DIR/core.sh"
package_manager="$TMP_DIR/package-manager.sh"

download_file "$BASE_URL/bootstrap/core.sh" "$bootstrap_core"
download_file "$BASE_URL/core/package-manager.sh" "$package_manager"

export LIKESOFT_BASE_URL="$BASE_URL"
export LIKESOFT_DOWNLOADED_CORE="$bootstrap_core"
export LIKESOFT_DOWNLOADED_PACKAGE_MANAGER="$package_manager"

source "$bootstrap_core"
source "$package_manager"

if [[ "${1:-}" == "panel" ]]; then
  shift || true
  export PANEL_BOOTSTRAP_MODE="${1:-info}"
  if [[ $# -gt 0 ]]; then
    shift || true
  fi
fi

panel_bootstrap "$@"
