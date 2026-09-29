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

# Print the highest version tag (v1.2.3 or 1.2.3) of DPANEL_REPO, or nothing.
latest_release_tag() {
  local tags_json="${TMP_DIR}/tags.json"
  download "https://api.github.com/repos/${DPANEL_REPO}/tags?per_page=100" "$tags_json" 2>/dev/null || return 0
  grep -o '"name": *"[^"]*"' "$tags_json" \
    | sed 's/^"name": *"//; s/"$//' \
    | grep -E '^v?[0-9]+(\.[0-9]+)*$' \
    | sort -V \
    | tail -n 1 || true
}

# Print the full commit SHA that a ref points to, or nothing.
commit_for_ref() {
  local sha_file="${TMP_DIR}/commit.sha"
  if command -v curl >/dev/null 2>&1; then
    curl -fsSL --retry 3 --connect-timeout 10 -H 'Accept: application/vnd.github.sha' \
      "https://api.github.com/repos/${DPANEL_REPO}/commits/$1" -o "$sha_file" 2>/dev/null || return 0
  else
    wget -q --tries=3 --timeout=10 --header='Accept: application/vnd.github.sha' \
      -O "$sha_file" "https://api.github.com/repos/${DPANEL_REPO}/commits/$1" 2>/dev/null || return 0
  fi
  grep -Eo '^[0-9a-f]{40}$' "$sha_file" || true
}

# Turn a ref into the APP_VERSION shown in the panel: v1.2.3 -> 1.2.3,
# a branch or commit -> <ref>-<short sha>.
app_version_for() {
  local ref="$1" commit="$2"
  if [[ "$ref" =~ ^v?[0-9]+(\.[0-9]+)*$ ]]; then
    printf '%s' "${ref#v}"
  elif [[ -n "$commit" && "$ref" != "$commit" ]]; then
    printf '%s-%s' "$ref" "${commit:0:7}"
  else
    printf '%s' "${ref:0:12}"
  fi
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
#   bash installer.sh php mariadb redis
#   bash installer.sh update
#   DPANEL_VERSION="v1.2.0" bash installer.sh             # one release tag
#   DPANEL_VERSION="main" bash installer.sh               # a branch or commit
#   DPANEL_REPO="your-user/dpanel" bash installer.sh       # install from a fork
#   PANEL_INSTALL_BASE_URL="https://mirror.example.com" bash installer.sh
#   DSCRIPT_SOURCE_DIR="/var/www/dscript" bash installer.sh   # git checkout at /var/www
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
# By default everything comes straight from GitHub: the release is the source
# archive of the selected version, and dscript assets are served from
# raw.githubusercontent.com. DPANEL_VERSION picks the version:
#
#   latest (default)   highest version tag, such as v1.2.3; main when no tag exists
#   v1.2.3             that release tag
#   main / <commit>    a branch or commit
#
# A custom mirror can still be used with PANEL_INSTALL_BASE_URL, which must serve
# /dscript.zip (built by dscript/archive.sh) and the /dscript/ tree.
#
DPANEL_REPO="${DPANEL_REPO:-mdsazzad0002/dpanel}"
DPANEL_VERSION="${DPANEL_VERSION:-${DPANEL_REF:-latest}}"
DSCRIPT_DIR="${DSCRIPT_DIR:-/var/www/dscript}"
TMP_DIR="$(mktemp -d)"
trap cleanup EXIT

[[ "${EUID:-$(id -u)}" -eq 0 ]] || die "Run this installer as root."

DPANEL_RELEASE_REF=""
DPANEL_RELEASE_COMMIT=""
if [[ -n "${DSCRIPT_SOURCE_DIR:-}" ]]; then
  # Local checkout: describe the checked-out commit when git is available.
  if command -v git >/dev/null 2>&1 && git -C "$DSCRIPT_SOURCE_DIR" rev-parse HEAD >/dev/null 2>&1; then
    DPANEL_RELEASE_COMMIT="$(git -C "$DSCRIPT_SOURCE_DIR" rev-parse HEAD)"
    DPANEL_RELEASE_REF="$(git -C "$DSCRIPT_SOURCE_DIR" describe --tags --exact-match 2>/dev/null \
      || git -C "$DSCRIPT_SOURCE_DIR" rev-parse --abbrev-ref HEAD)"
  fi
elif [[ -z "${PANEL_INSTALL_BASE_URL:-${DPANEL_BASE_URL:-}}" && -z "${DSCRIPT_ARCHIVE_URL:-}" && -z "${DSCRIPT_ARCHIVE_PATH:-}" ]]; then
  if [[ "$DPANEL_VERSION" == "latest" ]]; then
    DPANEL_RELEASE_REF="$(latest_release_tag)"
    if [[ -z "$DPANEL_RELEASE_REF" ]]; then
      printf '[WARN] No release tag found in %s; installing the main branch.\n' "$DPANEL_REPO"
      DPANEL_RELEASE_REF="main"
    fi
  else
    DPANEL_RELEASE_REF="$DPANEL_VERSION"
  fi
  DPANEL_RELEASE_COMMIT="$(commit_for_ref "$DPANEL_RELEASE_REF")"
elif [[ "$DPANEL_VERSION" != "latest" ]]; then
  # Custom mirror or archive: trust an explicit version, there is nothing to resolve.
  DPANEL_RELEASE_REF="$DPANEL_VERSION"
fi

DPANEL_RELEASE_VERSION=""
if [[ -n "$DPANEL_RELEASE_REF" ]]; then
  DPANEL_RELEASE_VERSION="$(app_version_for "$DPANEL_RELEASE_REF" "$DPANEL_RELEASE_COMMIT")"
  printf '[INFO] Selected dPanel %s (%s%s)\n' "$DPANEL_RELEASE_VERSION" "$DPANEL_RELEASE_REF" \
    "${DPANEL_RELEASE_COMMIT:+ @ ${DPANEL_RELEASE_COMMIT:0:7}}"
fi

DEFAULT_BASE_URL="https://raw.githubusercontent.com/${DPANEL_REPO}/${DPANEL_RELEASE_REF:-main}"
BASE_URL="${PANEL_INSTALL_BASE_URL:-${DPANEL_BASE_URL:-$DEFAULT_BASE_URL}}"
ARCHIVE_PATH="${TMP_DIR}/release.zip"
EXTRACT_DIR="${TMP_DIR}/extracted"
if [[ "$BASE_URL" == "$DEFAULT_BASE_URL" ]]; then
  default_archive_url="https://github.com/${DPANEL_REPO}/archive/${DPANEL_RELEASE_REF:-main}.zip"
else
  default_archive_url="${BASE_URL%/}/dscript.zip"
fi
archive_url="${DSCRIPT_ARCHIVE_URL:-$default_archive_url}"
if [[ "${BASE_URL%/}" == */dscript ]]; then
  dscript_base_url="${BASE_URL%/}"
else
  dscript_base_url="${BASE_URL%/}/dscript"
fi



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
    download "$archive_url" "$ARCHIVE_PATH" || die "Unable to download release archive: ${archive_url}"
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
for component in drust dpanel; do
  [[ -d "${RELEASE_SOURCE_ROOT}/${component}" ]] || continue
  mkdir -p "/var/www/${component}"
  # A git checkout at /var/www is already in place; copying it onto itself fails.
  if [[ "$(readlink -f "${RELEASE_SOURCE_ROOT}/${component}")" == "$(readlink -f "/var/www/${component}")" ]]; then
    printf '[INFO] Local source already matches /var/www/%s; reusing existing files.\n' "$component"
    continue
  fi
  printf '[INFO] Installing %s into /var/www/%s\n' "$component" "$component"
  cp -a "${RELEASE_SOURCE_ROOT}/${component}/." "/var/www/${component}/"
done

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
#   installer.sh php redis        -> dpanel chain install php redis
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
# dscript records the version in the panel .env (APP_VERSION and DPANEL_RELEASE_*).
PANEL_INSTALL_BASE_URL="$BASE_URL" \
PANEL_DSCRIPT_BASE_URL="$dscript_base_url" \
DPANEL_REPO="$DPANEL_REPO" \
DPANEL_RELEASE_REF="$DPANEL_RELEASE_REF" \
DPANEL_RELEASE_COMMIT="$DPANEL_RELEASE_COMMIT" \
DPANEL_RELEASE_VERSION="$DPANEL_RELEASE_VERSION" \
bash "${DSCRIPT_DIR}/dpanel" "${dscript_args[@]}"


#
# Refresh the runtime command so it points at the latest installed code.
#
bash "${DSCRIPT_DIR}/dpanel" runtime refresh
printf '[INFO] dscript handover request completed successfully.\n'
