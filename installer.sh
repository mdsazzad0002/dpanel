#!/usr/bin/env bash
set -euo pipefail

SCRIPT_NAME="$(basename "$0")"
DEFAULT_BASE_URL="https://installer.likesoftbd.com"
BASE_URL="${PANEL_INSTALL_BASE_URL:-${LIKESOFT_BASE_URL:-$DEFAULT_BASE_URL}}"
INSTALLER_DIR="/var/www/installer"
BOOTSTRAP_DIR="${INSTALLER_DIR}/bootstrap"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

# --- Preflight checks ---

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  echo "[ERROR] This installer must run as root." >&2
  exit 1
fi

for cmd in curl wget unzip; do
  if ! command -v "$cmd" >/dev/null 2>&1; then
    echo "[INFO] Installing missing prerequisite: $cmd"
    if command -v apt-get >/dev/null 2>&1; then
      apt-get update -qq && apt-get install -y -qq "$cmd"
    elif command -v dnf >/dev/null 2>&1; then
      dnf install -y -q "$cmd"
    elif command -v yum >/dev/null 2>&1; then
      yum install -y -q "$cmd"
    else
      echo "[ERROR] Cannot install $cmd automatically. Please install it manually." >&2
      exit 1
    fi
  fi
done

# --- Download bootstrap archive ---

ARCHIVE_URL="${BASE_URL}/bootstrap.zip"
ARCHIVE_PATH="${TMP_DIR}/bootstrap.zip"

echo "[INFO] Downloading bootstrap package from ${ARCHIVE_URL} ..."
if command -v wget >/dev/null 2>&1; then
  wget -qO "$ARCHIVE_PATH" "$ARCHIVE_URL"
else
  curl -fsSL "$ARCHIVE_URL" -o "$ARCHIVE_PATH"
fi

# --- Extract archive ---

mkdir -p "$INSTALLER_DIR"
unzip -qo "$ARCHIVE_PATH" -d "$TMP_DIR/extracted"

# Move extracted bootstrap into place
if [[ -d "${TMP_DIR}/extracted/bootstrap" ]]; then
  rm -rf "$BOOTSTRAP_DIR"
  mv "${TMP_DIR}/extracted/bootstrap" "$BOOTSTRAP_DIR"
elif [[ -d "${TMP_DIR}/extracted/dscript" ]]; then
  mkdir -p "$BOOTSTRAP_DIR/dscript"
  rm -rf "$BOOTSTRAP_DIR/dscript"
  mv "${TMP_DIR}/extracted/dscript" "$BOOTSTRAP_DIR/dscript"
fi

# --- Run dscript init ---

DSCRIPT_INIT="${BOOTSTRAP_DIR}/dscript/init.sh"
if [[ -x "$DSCRIPT_INIT" ]] || [[ -f "$DSCRIPT_INIT" ]]; then
  echo "[INFO] Running dscript init..."
  bash "$DSCRIPT_INIT" "$@"
else
  echo "[WARN] dscript/init.sh not found, looking for install.sh..."
  DSCRIPT_INSTALL="${BOOTSTRAP_DIR}/dscript/install.sh"
  if [[ -f "$DSCRIPT_INSTALL" ]]; then
    bash "$DSCRIPT_INSTALL" "$@"
  else
    echo "[ERROR] No bootstrap entry point found in ${BOOTSTRAP_DIR}/dscript/" >&2
    exit 1
  fi
fi

echo "[INFO] Bootstrap installation completed."
