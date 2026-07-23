#!/usr/bin/env bash
# Download dscript, prepare the runtime, then hand over all install work to chain.
set -Eeuo pipefail

die() {
  printf '[INSTALLER ERROR] %s\n' "$*" >&2
  exit 1
}

cleanup() { rm -rf "$TMP_DIR"; }

download() {
  local url="$1" destination="$2"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --retry 3 --connect-timeout 10 "$url" -o "$destination"
  elif command -v wget >/dev/null 2>&1; then
    wget -q --tries=3 --timeout=10 -O "$destination" "$url"
  else
    die "curl or wget is required. Install one and retry."
  fi
}

ensure_unzip() {
  command -v unzip >/dev/null 2>&1 && return 0
  printf '[INFO] unzip is missing; installing it...\n'
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update -qq && apt-get install -y -qq unzip
  elif command -v dnf >/dev/null 2>&1; then
    dnf install -y -q unzip
  elif command -v yum >/dev/null 2>&1; then
    yum install -y -q unzip
  else
    die "unzip is required and no supported package manager was found."
  fi
  command -v unzip >/dev/null 2>&1 || die "unzip installation failed."
}

find_dscript_root() {
  local candidate
  for candidate in "${EXTRACT_DIR}/dscript" "${EXTRACT_DIR}/package/dscript" "${EXTRACT_DIR}/bootstrap/dscript"; do
    if [[ -f "${candidate}/dpanel" ]]; then
      printf '%s' "$candidate"
      return 0
    fi
  done
  candidate="$(find "$EXTRACT_DIR" -type f -path '*/dscript/dpanel' -print -quit 2>/dev/null || true)"
  [[ -n "$candidate" ]] || return 1
  dirname "$candidate"
}

register_dpanel_command() {
  local command_name launcher_path temp_launcher
  install -d -m 0755 /usr/local/bin

  for command_name in dpanel; do
    launcher_path="/usr/local/bin/${command_name}"
    temp_launcher="$(mktemp /usr/local/bin/.${command_name}.XXXXXX)"
    cat > "$temp_launcher" <<EOF
#!/usr/bin/env bash
exec "${DSCRIPT_DIR}/dpanel" "\$@"
EOF
    install -m 0755 "$temp_launcher" "$launcher_path"
    rm -f "$temp_launcher"
  done
}




# ======================================work start===================================
#
# How to run this installer:
#
#   bash installer.sh
#   bash installer.sh apache nginx php mariadb
#   bash installer.sh update
#   PANEL_INSTALL_BASE_URL="https://installer.likesoftbd.com" bash installer.sh
#   DSCRIPT_SOURCE_DIR="/var/www/dscript" bash installer.sh
#
# Default call:
#   bash installer.sh
#
# After install, create a demo site with:
#   dpanel script run create-demo-site /home/example/public_html example.com 8.3
#

#
# Configure installer paths and download URLs.
#
DEFAULT_BASE_URL="https://installer.likesoftbd.com"
BASE_URL="${PANEL_INSTALL_BASE_URL:-${DPANEL_BASE_URL:-$DEFAULT_BASE_URL}}"
DSCRIPT_DIR="${DSCRIPT_DIR:-/var/www/dscript}"
TMP_DIR="$(mktemp -d)"
ARCHIVE_PATH="${TMP_DIR}/release.zip"
EXTRACT_DIR="${TMP_DIR}/extracted"
archive_url="${DSCRIPT_ARCHIVE_URL:-${BASE_URL%/}/dscript.zip}"
if [[ "${BASE_URL%/}" == */dscript ]]; then
  dscript_base_url="${BASE_URL%/}"
else
  dscript_base_url="${BASE_URL%/}/dscript"
fi


#
# Register cleanup and confirm the installer is running as root.
#
trap cleanup EXIT

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die "Run this installer as root."


#
# Create /var/www and allow installer/runtime files to be written and executed.
#
mkdir -p /var/www
chmod 0777 /var/www


#
# Prepare dscript from a local source, local release archive, or remote release archive.
#
if [[ -n "${DSCRIPT_SOURCE_DIR:-}" ]]; then
  [[ -d "$DSCRIPT_SOURCE_DIR" ]] || die "DSCRIPT_SOURCE_DIR does not exist: ${DSCRIPT_SOURCE_DIR}"
  [[ -f "${DSCRIPT_SOURCE_DIR}/dpanel" ]] || die "DSCRIPT_SOURCE_DIR is missing dpanel: ${DSCRIPT_SOURCE_DIR}"
  ARCHIVE_DSCRIPT_DIR="$(cd "$DSCRIPT_SOURCE_DIR" && pwd)"
  printf '[INFO] Using local dscript source directory %s\n' "$ARCHIVE_DSCRIPT_DIR"
else
  if [[ -n "${DSCRIPT_ARCHIVE_PATH:-}" ]]; then
    [[ -f "$DSCRIPT_ARCHIVE_PATH" ]] || die "DSCRIPT_ARCHIVE_PATH does not exist: ${DSCRIPT_ARCHIVE_PATH}"
    printf '[INFO] Using local release archive %s\n' "$DSCRIPT_ARCHIVE_PATH"
    cp -f "$DSCRIPT_ARCHIVE_PATH" "$ARCHIVE_PATH"
  else
    printf '[INFO] Downloading release archive from %s\n' "$archive_url"
    if ! download "$archive_url" "$ARCHIVE_PATH"; then
      legacy_url="${BASE_URL%/}/bootstrap.zip"
      printf '[WARN] dscript.zip was unavailable; trying legacy %s\n' "$legacy_url" >&2
      download "$legacy_url" "$ARCHIVE_PATH" || die "Unable to download a release archive."
    fi
  fi

  ensure_unzip
  mkdir -p "$EXTRACT_DIR"
  unzip -q -o "$ARCHIVE_PATH" -d "$EXTRACT_DIR" || die "Archive extraction failed."
  ARCHIVE_DSCRIPT_DIR="$(find_dscript_root || true)"
  [[ -n "$ARCHIVE_DSCRIPT_DIR" ]] || die "The archive does not contain dscript/dpanel."
fi


#
# Install extracted dscript files into the target runtime directory.
#
mkdir -p "$(dirname "$DSCRIPT_DIR")" "$DSCRIPT_DIR"
printf '[INFO] Installing dscript into %s\n' "$DSCRIPT_DIR"
if [[ "$(readlink -f "$ARCHIVE_DSCRIPT_DIR")" != "$(readlink -f "$DSCRIPT_DIR")" ]]; then
  cp -a "${ARCHIVE_DSCRIPT_DIR}/." "$DSCRIPT_DIR/"
else
  printf '[INFO] Local source already matches DSCRIPT_DIR; reusing existing files.\n'
fi

#
# Install release sibling services when the archive contains them.
#
RELEASE_SOURCE_ROOT="$(dirname "$ARCHIVE_DSCRIPT_DIR")"
if [[ -d "${RELEASE_SOURCE_ROOT}/drust" ]]; then
  mkdir -p /var/www/drust
  printf '[INFO] Installing drust into /var/www/drust\n'
  cp -a "${RELEASE_SOURCE_ROOT}/drust/." /var/www/drust/
fi
if [[ -d "${RELEASE_SOURCE_ROOT}/dpanel" ]]; then
  mkdir -p /var/www/dpanel
  printf '[INFO] Installing dpanel into /var/www/dpanel\n'
  cp -a "${RELEASE_SOURCE_ROOT}/dpanel/." /var/www/dpanel/
fi

# ZIP mode bits are not reliable across mirrors or ZIP creation tools.
find "$DSCRIPT_DIR" -type d -exec chmod 0755 {} +
find "$DSCRIPT_DIR" -type f \( -name '*.sh' -o -name dpanel \) -exec chmod 0755 {} +
[[ -f "${DSCRIPT_DIR}/dpanel" ]] || die "Installed dscript is missing dpanel."


#
# Register global command launcher for dpanel.
#
printf '[INFO] Registering dpanel command.\n'
register_dpanel_command


#
# Build the dpanel handover arguments.
#
# This installer never owns module install/update logic. It only prepares the
# release files, then transfers the request to dscript/dpanel.
#
# Supported handovers:
#   installer.sh                  -> dpanel default-install
#   installer.sh apache nginx     -> dpanel chain install apache nginx
#   installer.sh update           -> dpanel chain update
#   installer.sh chain update     -> dpanel chain update
#
printf '[INFO] Handing over request to dscript/dpanel.\n'
if [[ $# -eq 0 ]]; then
  dscript_args=(default-install)
elif [[ "${1:-}" == "update" ]]; then
  shift || true
  dscript_args=(chain update "$@")
elif [[ "${1:-}" == "install" ]]; then
  shift || true
  dscript_args=(chain install "$@")
elif [[ "${1:-}" == "chain" ]]; then
  dscript_args=("$@")
else
  dscript_args=(chain install "$@")
fi
PANEL_INSTALL_BASE_URL="$BASE_URL" \
PANEL_DSCRIPT_BASE_URL="$dscript_base_url" \
bash "${DSCRIPT_DIR}/dpanel" "${dscript_args[@]}"


#
# Refresh the runtime command so it points at the latest installed code.
#
bash "${DSCRIPT_DIR}/dpanel" runtime refresh
printf '[INFO] dscript handover request completed successfully.\n'
