#!/usr/bin/env bash
set -euo pipefail

DPANEL_CORE_SOURCE="${BASH_SOURCE[0]}"
DPANEL_BASE_DIR="${DPANEL_BASE_DIR:-/opt/dpanel}"
export DPANEL_BASE_DIR
DPANEL_RUNTIME_DIR="${DPANEL_RUNTIME_DIR:-${DPANEL_BASE_DIR}/runtime}"
DPANEL_CACHE_DIR="${DPANEL_CACHE_DIR:-${DPANEL_BASE_DIR}/cache}"
DPANEL_MODULE_DIR="${DPANEL_MODULE_DIR:-${DPANEL_BASE_DIR}/modules}"
DPANEL_LOG_DIR="${DPANEL_LOG_DIR:-${DPANEL_BASE_DIR}/logs}"
DPANEL_TEMPLATE_DIR="${DPANEL_TEMPLATE_DIR:-${DPANEL_BASE_DIR}/templates}"
DPANEL_MANIFEST_DIR="${DPANEL_MANIFEST_DIR:-${DPANEL_BASE_DIR}/manifests}"
DPANEL_REPOSITORY_DIR="${DPANEL_REPOSITORY_DIR:-${DPANEL_BASE_DIR}/repository}"
DPANEL_SERVER_JSON="${DPANEL_SERVER_JSON:-${DPANEL_BASE_DIR}/server.json}"
DPANEL_TOKEN_FILE="${DPANEL_TOKEN_FILE:-${DPANEL_BASE_DIR}/token}"
DPANEL_LOCAL_MANIFEST="${DPANEL_LOCAL_MANIFEST:-${DPANEL_CACHE_DIR}/modules.installed.json}"
DPANEL_LAUNCHER="${DPANEL_LAUNCHER:-/usr/local/bin/dpanel}"
DPANEL_DUAL_LAUNCHER="${DPANEL_DUAL_LAUNCHER:-}"
PANEL_APP_DIR="${PANEL_APP_DIR:-${SERVER_BASE_DIR:-/var/www/dpanel}}"
PANEL_APP_ENV_FILE="${PANEL_APP_ENV_FILE:-}"
PANEL_DB_NAME="${PANEL_DB_NAME:-dpanel}"
PANEL_DB_USER="${PANEL_DB_USER:-dpanel}"
PANEL_DB_HOST="${PANEL_DB_HOST:-127.0.0.1}"
PANEL_DB_PORT="${PANEL_DB_PORT:-3306}"
PANEL_DB_CHARSET="${PANEL_DB_CHARSET:-utf8mb4}"
PANEL_DB_COLLATION="${PANEL_DB_COLLATION:-utf8mb4_unicode_ci}"
PANEL_DB_PASSWORD="${PANEL_DB_PASSWORD:-}"

panel_log() {
  local level="$1"
  shift
  local message="[${level}] $*"
  local logfile="${DPANEL_LOG_DIR}/install.log"

  printf '%s\n' "$message"

  if mkdir -p "$DPANEL_LOG_DIR" 2>/dev/null && { [[ -w "$DPANEL_LOG_DIR" ]] || [[ -w "$logfile" ]] || [[ ! -e "$logfile" ]]; }; then
    touch "$logfile" 2>/dev/null || true
    printf '%s\n' "$message" >> "$logfile" 2>/dev/null || true
  fi
}

panel_info_log() {
  panel_log INFO "$@"
}

panel_warn_log() {
  panel_log WARN "$@"
}

panel_error_log() {
  panel_log ERROR "$@" >&2
}

panel_die() {
  panel_error_log "$@"
  exit 1
}

panel_require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    panel_die "Run this installer as root."
  fi
}

panel_ensure_dirs() {
  mkdir -p \
    "$DPANEL_BASE_DIR" \
    "$DPANEL_RUNTIME_DIR" \
    "$DPANEL_CACHE_DIR" \
    "$DPANEL_MODULE_DIR" \
    "$DPANEL_LOG_DIR" \
    "$DPANEL_TEMPLATE_DIR" \
    "$DPANEL_MANIFEST_DIR" \
    "$DPANEL_REPOSITORY_DIR"

  touch \
    "${DPANEL_LOG_DIR}/install.log" \
    "${DPANEL_LOG_DIR}/update.log" \
    "${DPANEL_LOG_DIR}/agent.log"
}

panel_detect_os() {
  if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
  else
    panel_die "Unable to detect OS: /etc/os-release is missing."
  fi

  DISTRO="${ID:-unknown}"
  VERSION="${VERSION_ID:-unknown}"

  case "$DISTRO" in
    ubuntu|debian|rocky|almalinux)
      ;;
    *)
      panel_warn_log "Unsupported distro '$DISTRO'; continuing with best effort."
      ;;
  esac
}

panel_generate_token() {
  if command -v openssl >/dev/null 2>&1; then
    openssl rand -hex 32
    return 0
  fi

  tr -dc 'a-f0-9' </dev/urandom | head -c 64
}

panel_generate_uuid() {
  if command -v uuidgen >/dev/null 2>&1; then
    uuidgen | tr '[:upper:]' '[:lower:]'
    return 0
  fi

  printf '%s-%s-%s-%s-%s\n' \
    "$(tr -dc 'a-f0-9' </dev/urandom | head -c 8)" \
    "$(tr -dc 'a-f0-9' </dev/urandom | head -c 4)" \
    "$(tr -dc 'a-f0-9' </dev/urandom | head -c 4)" \
    "$(tr -dc 'a-f0-9' </dev/urandom | head -c 4)" \
    "$(tr -dc 'a-f0-9' </dev/urandom | head -c 12)"
}

panel_default_base_url() {
  if [[ -n "${DPANEL_BASE_URL:-}" ]]; then
    printf '%s' "$DPANEL_BASE_URL"
    return 0
  fi

  if [[ -f "$DPANEL_SERVER_JSON" ]] && command -v python3 >/dev/null 2>&1; then
    local configured
    configured="$(python3 - "$DPANEL_SERVER_JSON" <<'PY'
import json, sys
try:
    with open(sys.argv[1], encoding='utf-8') as handle:
        print(json.load(handle).get('base_url', ''))
except (OSError, ValueError):
    print('')
PY
)"
    [[ -n "$configured" ]] && { printf '%s' "$configured"; return 0; }
  fi

  printf '%s' "https://installer.likesoftbd.com/dscript"
}

panel_fetch() {
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

  panel_die "curl or wget is required for remote module download."
}

panel_copy_runtime_asset() {
  local src="$1"
  local dest="$2"

  if [[ -e "$dest" && "$(readlink -f "$src")" == "$(readlink -f "$dest")" ]]; then
    return 0
  fi

  install -m 0755 "$src" "$dest"
}

panel_install_runtime_assets() {
  panel_info_log "Installing runtime assets into ${DPANEL_RUNTIME_DIR}"
  mkdir -p "$DPANEL_RUNTIME_DIR"

  local core_source="${DPANEL_DOWNLOADED_CORE:-$DPANEL_CORE_SOURCE}"
  local package_source="${DPANEL_DOWNLOADED_PACKAGE_MANAGER:-${DPANEL_PACKAGE_MANAGER_SOURCE:-}}"
  local commands_source="${DPANEL_DOWNLOADED_COMMANDS:-}"
  local source_scripts_dir=""
  local source_templates_dir="${DPANEL_DOWNLOADED_TEMPLATES_DIR:-}"
  local source_repository_dir="${DPANEL_DOWNLOADED_REPOSITORY_DIR:-}"
  local candidate_path
  if [[ -n "${DPANEL_DOWNLOADED_SCRIPTS_DIR:-}" && -d "$DPANEL_DOWNLOADED_SCRIPTS_DIR" ]]; then
    source_scripts_dir="$DPANEL_DOWNLOADED_SCRIPTS_DIR"
  else
    for candidate_path in \
      "$(dirname "$core_source")/scripts" \
      "$(dirname "$core_source")/../scripts"; do
      if [[ -d "$candidate_path" ]]; then
        source_scripts_dir="$(cd "$candidate_path" && pwd)"
        break
      fi
    done
  fi

  [[ -f "$core_source" ]] || panel_die "Missing core source file."
  [[ -n "$package_source" && -f "$package_source" ]] || panel_die "Missing package manager source file."

  if [[ -z "$commands_source" ]]; then
    for candidate_path in \
      "$(dirname "$core_source")/commands.sh" \
      "$(dirname "$core_source")/../core/commands.sh"; do
      if [[ -f "$candidate_path" ]]; then
        commands_source="$candidate_path"
        break
      fi
    done
  fi
  [[ -f "$commands_source" ]] || panel_die "Missing command router source file."

  panel_copy_runtime_asset "$core_source" "${DPANEL_RUNTIME_DIR}/core.sh"
  panel_copy_runtime_asset "$package_source" "${DPANEL_RUNTIME_DIR}/package-manager.sh"
  panel_copy_runtime_asset "$commands_source" "${DPANEL_RUNTIME_DIR}/commands.sh"

  if [[ -n "$source_scripts_dir" ]]; then
    mkdir -p "${DPANEL_RUNTIME_DIR}/scripts"
    for script_path in "${source_scripts_dir}"/*.sh; do
      [[ -f "$script_path" ]] || continue
      panel_copy_runtime_asset "$script_path" "${DPANEL_RUNTIME_DIR}/scripts/$(basename "$script_path")"
    done
  fi

  if [[ -z "$source_templates_dir" ]]; then
    for candidate_path in \
      "$(dirname "$core_source")/templates" \
      "$(dirname "$core_source")/../repository/templates"; do
      if [[ -d "$candidate_path" ]]; then
        source_templates_dir="$(cd "$candidate_path" && pwd)"
        break
      fi
    done
  fi
  if [[ -n "$source_templates_dir" && -d "$source_templates_dir" ]]; then
    mkdir -p "${DPANEL_RUNTIME_DIR}/templates"
    if [[ "$(readlink -f "$source_templates_dir")" != "$(readlink -f "${DPANEL_RUNTIME_DIR}/templates")" ]]; then
      cp -R "${source_templates_dir}/." "${DPANEL_RUNTIME_DIR}/templates/"
    fi
  fi

  if [[ -z "$source_repository_dir" ]]; then
    for candidate_path in \
      "$(dirname "$core_source")/repository" \
      "$(dirname "$core_source")/../repository"; do
      if [[ -d "$candidate_path" ]]; then
        source_repository_dir="$(cd "$candidate_path" && pwd)"
        break
      fi
    done
  fi
  if [[ -n "$source_repository_dir" && -d "$source_repository_dir" ]]; then
    mkdir -p "$DPANEL_REPOSITORY_DIR"
    if [[ "$(readlink -f "$source_repository_dir")" != "$(readlink -f "$DPANEL_REPOSITORY_DIR")" ]]; then
      cp -R "${source_repository_dir}/." "${DPANEL_REPOSITORY_DIR}/"
    fi
  fi

  panel_write_launcher() {
    local launcher_path="$1"
    local target_path="$2"
    local launcher_dir
    local tmp_file

    launcher_dir="$(dirname "$launcher_path")"
    mkdir -p "$launcher_dir"
    tmp_file="$(mktemp "${launcher_dir}/.launcher.XXXXXX")"
    cat > "$tmp_file" <<EOF
#!/usr/bin/env bash
exec "${target_path}" "\$@"
EOF
    chmod 0755 "$tmp_file"
    mv -f "$tmp_file" "$launcher_path"
  }

  cat > "${DPANEL_RUNTIME_DIR}/launcher.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

DPANEL_BASE_DIR="${DPANEL_BASE_DIR:-/opt/dpanel}"
DPANEL_RUNTIME_DIR="${DPANEL_RUNTIME_DIR:-${DPANEL_BASE_DIR}/runtime}"

if [[ ! -f "${DPANEL_RUNTIME_DIR}/core.sh" || ! -f "${DPANEL_RUNTIME_DIR}/package-manager.sh" || ! -f "${DPANEL_RUNTIME_DIR}/commands.sh" ]]; then
  echo "Runtime core is missing. Re-run the installer." >&2
  exit 1
fi

# shellcheck disable=SC1091
source "${DPANEL_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${DPANEL_RUNTIME_DIR}/package-manager.sh"
# shellcheck disable=SC1091
source "${DPANEL_RUNTIME_DIR}/commands.sh"

dscript_cli "$@"
EOF
  chmod 0755 "${DPANEL_RUNTIME_DIR}/launcher.sh"

  local launcher_target="${DPANEL_RUNTIME_DIR}/launcher.sh"
  if [[ -n "${DPANEL_DSCRIPT_ENTRYPOINT:-}" && -x "${DPANEL_DSCRIPT_ENTRYPOINT}" ]]; then
    launcher_target="$DPANEL_DSCRIPT_ENTRYPOINT"
  fi

  panel_write_launcher "$DPANEL_LAUNCHER" "$launcher_target"

  if [[ -n "$DPANEL_DUAL_LAUNCHER" && "$DPANEL_DUAL_LAUNCHER" != "$DPANEL_LAUNCHER" ]]; then
    panel_write_launcher "$DPANEL_DUAL_LAUNCHER" "$launcher_target"
  fi
}

panel_server_ip() {
  if [[ -n "${SERVER_IP:-}" ]]; then
    printf '%s' "$SERVER_IP"
    return 0
  fi

  local ip=""
  ip="$(hostname -I 2>/dev/null | awk '{print $1}' || true)"
  if [[ -n "$ip" ]]; then
    printf '%s' "$ip"
    return 0
  fi

  printf '127.0.0.1'
}

panel_register_server() {
  local server_uuid token
  server_uuid="$(panel_generate_uuid)"
  token="$(panel_generate_token)"

  cat > "$DPANEL_SERVER_JSON" <<EOF
{
  "server_uuid": "$server_uuid",
  "installed_at": "$(date -u '+%Y-%m-%dT%H:%M:%SZ')",
  "base_url": "$(panel_default_base_url)",
  "server_ip": "$(panel_server_ip)",
  "distro": "${DISTRO:-unknown}",
  "version": "${VERSION:-unknown}",
  "panel_domain": "${PANEL_DOMAIN:-}",
  "panel_port": "${PANEL_PORT:-80}",
  "default_php_version": "$(panel_php_default_version)"
}
EOF

  printf '%s\n' "$token" > "$DPANEL_TOKEN_FILE"
  chmod 0600 "$DPANEL_TOKEN_FILE"
  panel_info_log "Registered server: $server_uuid"
}

panel_server_json_valid() {
  [[ -s "$DPANEL_SERVER_JSON" ]] || return 1

  if command -v python3 >/dev/null 2>&1; then
    python3 - "$DPANEL_SERVER_JSON" <<'PY' >/dev/null 2>&1
import json
import sys

with open(sys.argv[1], 'r', encoding='utf-8') as handle:
    data = json.load(handle)

raise SystemExit(0 if isinstance(data, dict) else 1)
PY
    return $?
  fi

  return 0
}

panel_remote_manifest_url() {
  printf '%s/repository/manifests/modules.json' "$(panel_default_base_url)"
}

panel_manifest_version_for() {
  local module="$1"
  local manifest="${2:-$DPANEL_CACHE_DIR/modules.manifest.json}"

  if [[ ! -f "$manifest" ]]; then
    printf '%s' ''
    return 0
  fi

  if command -v python3 >/dev/null 2>&1; then
    python3 - "$manifest" "$module" <<'PY'
import json
import sys

with open(sys.argv[1], 'r', encoding='utf-8') as handle:
    data = json.load(handle)

print(data.get(sys.argv[2], ''))
PY
    return 0
  fi

  awk -v module="$module" '
    $0 ~ "\"" module "\"" {
      gsub(/.*: "/, "", $0)
      gsub(/".*/, "", $0)
      print
    }
  ' "$manifest" | head -n 1
}

panel_remote_module_url() {
  local module="$1"
  local action="${2:-install}"
  local version="${3:-}"

  if [[ "$module" == "php" && "$action" == "install" && -n "$version" ]]; then
    printf '%s/repository/modules/%s/%s.sh' "$(panel_default_base_url)" "$module" "$version"
    return 0
  fi

  printf '%s/repository/modules/%s/install.sh' "$(panel_default_base_url)" "$module"
}

panel_repository_root() {
  local core_dir
  core_dir="$(cd "$(dirname "${DPANEL_CORE_SOURCE}")" && pwd)"
  printf '%s' "$(cd "${core_dir}/.." && pwd)"
}

panel_local_module_script() {
  local module="$1"
  local action="${2:-install}"
  local version="${3:-}"
  local root

  root="$(panel_repository_root)"

  if [[ "$module" == "php" && "$action" == "install" && -n "$version" ]]; then
    if [[ -f "${root}/repository/modules/${module}/${version}.sh" ]]; then
      printf '%s' "${root}/repository/modules/${module}/${version}.sh"
      return 0
    fi
  fi

  if [[ -f "${root}/repository/modules/${module}/${action}.sh" ]]; then
    printf '%s' "${root}/repository/modules/${module}/${action}.sh"
    return 0
  fi

  if [[ -f "${root}/repository/modules/${module}/install.sh" ]]; then
    printf '%s' "${root}/repository/modules/${module}/install.sh"
    return 0
  fi

  printf '%s' ''
}

panel_module_cache_path() {
  local module="$1"
  local action="${2:-install}"
  local version="${3:-}"

  if [[ "$module" == "php" && "$action" == "install" && -n "$version" ]]; then
    printf '%s/%s-%s.sh' "$DPANEL_MODULE_DIR" "$module" "$version"
    return 0
  fi

  printf '%s/%s.sh' "$DPANEL_MODULE_DIR" "$module"
}

panel_sync_manifest() {
  local dest="$DPANEL_CACHE_DIR/modules.manifest.json"
  mkdir -p "$DPANEL_CACHE_DIR"

  if [[ "${DSCRIPT_REFRESH_REMOTE:-false}" != "true" && "${PANEL_BOOTSTRAP_MODE:-install}" != "update" \
    && -f "${DPANEL_REPOSITORY_DIR}/manifests/modules.json" ]]; then
    cp -f "${DPANEL_REPOSITORY_DIR}/manifests/modules.json" "$dest"
    panel_info_log "Loaded local module manifest."
    return 0
  fi

  panel_fetch "$(panel_remote_manifest_url)" "$dest"
  panel_info_log "Synced remote manifest."
}

panel_installed_manifest_value() {
  local key="$1"
  if [[ ! -f "$DPANEL_LOCAL_MANIFEST" ]]; then
    return 0
  fi

  awk -v key="$key" -F= '
    $1 == key { print $2 }
  ' "$DPANEL_LOCAL_MANIFEST" | tail -n 1
}

panel_store_installed_manifest_value() {
  local key="$1"
  local value="$2"

  touch "$DPANEL_LOCAL_MANIFEST"
  grep -v "^${key}=" "$DPANEL_LOCAL_MANIFEST" > "${DPANEL_LOCAL_MANIFEST}.tmp" || true
  mv "${DPANEL_LOCAL_MANIFEST}.tmp" "$DPANEL_LOCAL_MANIFEST"
  printf '%s=%s\n' "$key" "$value" >> "$DPANEL_LOCAL_MANIFEST"
}

panel_download_module() {
  local module="$1"
  local action="${2:-install}"
  local version="${3:-}"
  local cache_path
  local remote_url

  cache_path="$(panel_module_cache_path "$module" "$action" "$version")"
  remote_url="$(panel_remote_module_url "$module" "$action" "$version")"

  if [[ ! -f "$cache_path" ]]; then
    panel_info_log "Downloading ${module} ${action}${version:+ (${version})}"
    panel_fetch "$remote_url" "$cache_path"
    chmod 0755 "$cache_path"
  fi

  printf '%s' "$cache_path"
}

panel_run_module() {
  local module="$1"
  shift || true
  local action="${1:-install}"
  [[ $# -gt 0 ]] && shift
  local version=""

  if [[ "$module" == "php" && $# -gt 0 ]]; then
    version="$1"
    shift
  fi

  panel_run_module_exact "$module" "$action" "$version" "$@"
}

panel_run_module_exact() {
  local module="$1"
  local action="${2:-install}"
  local version="${3:-}"
  [[ $# -gt 0 ]] && shift
  [[ $# -gt 0 ]] && shift
  [[ $# -gt 0 ]] && shift

  local script
  script="$(panel_local_module_script "$module" "$action" "$version")"
  if [[ -z "$script" ]]; then
    script="$(panel_download_module "$module" "$action" "$version")"
  fi

  if [[ "$module" == "php" && -n "$version" ]]; then
    DPANEL_RUNTIME_DIR="$DPANEL_RUNTIME_DIR" DPANEL_BASE_DIR="$DPANEL_BASE_DIR" PHP_VERSION="$version" bash "$script" "$action" "$version" "$@"
    return 0
  fi

  if [[ -n "$version" ]]; then
    DPANEL_RUNTIME_DIR="$DPANEL_RUNTIME_DIR" DPANEL_BASE_DIR="$DPANEL_BASE_DIR" bash "$script" "$action" "$version" "$@"
  else
    DPANEL_RUNTIME_DIR="$DPANEL_RUNTIME_DIR" DPANEL_BASE_DIR="$DPANEL_BASE_DIR" bash "$script" "$action" "$@"
  fi
}

panel_php_versions() {
  local root
  local module_json

  root="$(panel_repository_root)"
  module_json="${root}/repository/modules/php/php.json"

  if [[ -f "$module_json" && -x "$(command -v python3 2>/dev/null || true)" ]]; then
    python3 - "$module_json" <<'PY'
import json
import sys

with open(sys.argv[1], 'r', encoding='utf-8') as handle:
    data = json.load(handle)

for version in data.get('versions', []):
    print(version)
PY
    return 0
  fi

  printf '%s\n' 7.4 8.0 8.1 8.2 8.3 8.4 8.5
}

panel_php_version_supported() {
  local version="$1"

  while IFS= read -r available; do
    [[ -z "$available" ]] && continue
    if [[ "$available" == "$version" ]]; then
      return 0
    fi
  done < <(panel_php_versions)

  return 1
}

panel_php_version_installed() {
  local version="$1"

  if command -v "php${version}" >/dev/null 2>&1; then
    return 0
  fi

  if [[ -x "/usr/bin/php${version}" || -x "/usr/local/bin/php${version}" ]]; then
    return 0
  fi

  case "$(pkg_distro_family)" in
    debian)
      if pkg_package_installed "php${version}-cli" || pkg_package_installed "php${version}-fpm"; then
        return 0
      fi
      ;;
    rpm)
      if pkg_package_installed php-cli || pkg_package_installed php-fpm || pkg_package_installed php; then
        return 0
      fi
      ;;
  esac

  return 1
}

panel_php_versions_status() {
  local default_version
  local version
  local status

  default_version="$(panel_php_default_version)"

  printf '%s\n' "PHP versions on current server:"
  while IFS= read -r version; do
    [[ -z "$version" ]] && continue
    status="available"
    if panel_php_version_installed "$version"; then
      status="installed"
    fi
    if [[ "$version" == "$default_version" ]]; then
      status="${status}, default"
    fi
    printf '%s - %s\n' "$version" "$status"
  done < <(panel_php_versions)
}

panel_php_default_version() {
  local configured=""
  local candidate=""

  if [[ -n "${PHP_VERSION:-}" ]] && panel_php_version_supported "${PHP_VERSION:-}"; then
    printf '%s' "$PHP_VERSION"
    return 0
  fi

  if [[ -f "$DPANEL_SERVER_JSON" && -x "$(command -v python3 2>/dev/null || true)" ]]; then
    configured="$(python3 - "$DPANEL_SERVER_JSON" <<'PY'
import json
import sys

try:
    with open(sys.argv[1], 'r', encoding='utf-8') as handle:
        data = json.load(handle)
    print(data.get('default_php_version', ''))
except (OSError, ValueError):
    print('')
PY
)"
    if [[ -n "$configured" ]] && panel_php_version_supported "$configured"; then
      printf '%s' "$configured"
      return 0
    fi
  fi

  if panel_php_version_supported "8.3"; then
    printf '%s' 8.3
    return 0
  fi

  while IFS= read -r candidate; do
    [[ -n "$candidate" ]] && { printf '%s' "$candidate"; return 0; }
  done < <(panel_php_versions)

  printf '%s' 8.3
}

panel_php_binary_for_version() {
  local version="$1"
  local candidate

  for candidate in \
    "$(command -v "php${version}" 2>/dev/null || true)" \
    "/usr/bin/php${version}" \
    "/usr/local/bin/php${version}"; do
    if [[ -n "$candidate" && -x "$candidate" ]]; then
      printf '%s' "$candidate"
      return 0
    fi
  done

  printf '%s' ''
}

panel_set_php_default_version() {
  local version="$1"
  local binary=""

  [[ -n "$version" ]] || panel_die "PHP default version is required."
  panel_php_version_supported "$version" || panel_die "Unsupported PHP version: ${version}"

  binary="$(panel_php_binary_for_version "$version")"
  if [[ -z "$binary" ]]; then
    panel_warn_log "PHP binary for ${version} is not installed yet; recording default only."
  else
    if command -v update-alternatives >/dev/null 2>&1; then
      update-alternatives --set php "$binary" >/dev/null 2>&1 || true
    elif command -v alternatives >/dev/null 2>&1; then
      alternatives --set php "$binary" >/dev/null 2>&1 || true
    else
      panel_warn_log "No alternatives manager found; skipping system php CLI switch."
    fi
  fi

  if [[ -x "$(command -v python3 2>/dev/null || true)" ]]; then
    python3 - "$DPANEL_SERVER_JSON" "$version" <<'PY'
import json
import os
import sys

path, version = sys.argv[1], sys.argv[2]
data = {}

if os.path.exists(path):
    with open(path, 'r', encoding='utf-8') as handle:
        try:
            data = json.load(handle)
        except json.JSONDecodeError:
            data = {}

data['default_php_version'] = version

with open(path, 'w', encoding='utf-8') as handle:
    json.dump(data, handle, indent=2)
    handle.write('\n')
PY
  fi

  export PHP_VERSION="$version"
  panel_info_log "Default PHP version set to ${version}"
}

panel_php_install_versions() {
  local version
  local force="${1:-false}"
  local selected_versions=()

  if [[ $# -gt 0 ]]; then
    shift || true
    selected_versions=("$@")
  fi

  if [[ ${#selected_versions[@]} -eq 0 ]]; then
    while IFS= read -r version; do
      [[ -n "$version" ]] && selected_versions+=("$version")
    done < <(panel_php_versions)
  fi

  [[ ${#selected_versions[@]} -gt 0 ]] || panel_die "No PHP versions available."

  for version in "${selected_versions[@]}"; do
    panel_php_version_supported "$version" || panel_die "Unsupported PHP version: ${version}"
    if [[ "$force" != "true" ]] && panel_php_version_installed "$version"; then
      panel_info_log "php ${version} already installed; skipping."
      continue
    fi
    panel_run_module php install "$version"
  done
}

panel_php_update_versions() {
  local version
  local selected_versions=("$@")

  if [[ ${#selected_versions[@]} -eq 0 ]]; then
    while IFS= read -r version; do
      [[ -n "$version" ]] && selected_versions+=("$version")
    done < <(panel_php_versions)
  fi

  [[ ${#selected_versions[@]} -gt 0 ]] || panel_die "No PHP versions available."

  for version in "${selected_versions[@]}"; do
    panel_php_version_supported "$version" || panel_die "Unsupported PHP version: ${version}"
    panel_run_module php update "$version"
  done
}

panel_php_manage_versions() {
  local action="$1"
  shift || true

  case "$action" in
    install)
      if [[ $# -eq 0 || "${1:-}" == "all" ]]; then
        panel_php_install_versions false
      else
        panel_php_install_versions false "$@"
      fi
      ;;
    update)
      if [[ $# -eq 0 || "${1:-}" == "all" ]]; then
        panel_php_update_versions
      else
        panel_php_update_versions "$@"
      fi
      ;;
    reinstall)
      if [[ $# -eq 0 || "${1:-}" == "all" ]]; then
        panel_php_install_versions true
      else
        panel_php_install_versions true "$@"
      fi
      ;;
    default)
      [[ $# -ge 1 ]] || panel_die "Usage: panel php default <version>"
      panel_set_php_default_version "$1"
      ;;
    versions|list)
      panel_php_versions_status
      ;;
    remove)
      [[ $# -ge 1 ]] || panel_die "Usage: panel php remove <version>"
      panel_run_module php remove "$1"
      ;;
    *)
      panel_die "Unsupported php action: ${action}"
      ;;
  esac
}

panel_update_module_if_changed() {
  local module="$1"
  local remote_version="$2"
  local current_version

  current_version="$(panel_installed_manifest_value "$module")"
  if [[ "$current_version" == "$remote_version" ]]; then
    panel_info_log "Module unchanged: ${module} (${remote_version})"
    return 0
  fi

  panel_info_log "Module changed: ${module} ${current_version:-none} -> ${remote_version}"
  if [[ "$module" == "php" ]]; then
    panel_php_manage_versions update all
  else
    panel_run_module "$module" update
  fi
  panel_store_installed_manifest_value "$module" "$remote_version"
}

panel_update_from_manifest() {
  local manifest="$DPANEL_CACHE_DIR/modules.manifest.json"

  if [[ ! -f "$manifest" ]]; then
    panel_sync_manifest
  fi

  if command -v python3 >/dev/null 2>&1; then
    while IFS='=' read -r module version; do
      [[ -z "$module" || -z "$version" ]] && continue
      panel_update_module_if_changed "$module" "$version"
    done < <(
      python3 - "$manifest" <<'PY'
import json
import sys

with open(sys.argv[1], 'r', encoding='utf-8') as handle:
    data = json.load(handle)

for key, value in data.items():
    print(f"{key}={value}")
PY
    )
    return 0
  fi

  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    if [[ "$line" =~ \"([a-z0-9_-]+)\"\:[[:space:]]*\"([^\"]+)\" ]]; then
      panel_update_module_if_changed "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}"
    fi
  done < "$manifest"
}

panel_render_template() {
  local template="$1"
  local dest="$2"
  shift 2

  local content
  content="$(cat "$template")"

  while (($#)); do
    local key="$1"
    local value="$2"
    shift 2

    value="${value//\\/\\\\}"
    value="${value//&/\\&}"
    value="${value//|/\\|}"
    content="$(printf '%s' "$content" | sed "s|{{${key}}}|${value}|g")"
  done

  mkdir -p "$(dirname "$dest")"
  printf '%s\n' "$content" > "$dest"
}

panel_site_create() {
  local domain="${1:-}"
  local username="${2:-}"
  local php_version="${3:-${PHP_VERSION:-8.3}}"
  local ssl="${4:-}"
  local web_server="${5:-nginx}"
  local root_path=""
  local site_name=""

  if [[ -z "$domain" ]]; then
    read -rp "Domain: " domain
  fi

  if [[ -z "$username" ]]; then
    read -rp "System user: " username
  fi

  if [[ -z "$ssl" ]]; then
    read -rp "Enable SSL? (yes/no): " ssl
  fi

  if [[ -z "$web_server" ]]; then
    read -rp "Web server (apache/nginx): " web_server
  fi

  root_path="${6:-/home/${username}/public_html}"
  site_name="${domain//./-}"

  mkdir -p "$DPANEL_TEMPLATE_DIR/generated/sites" "$DPANEL_TEMPLATE_DIR/generated/pools"

  if [[ "$web_server" == "apache" ]]; then
    panel_render_template \
      "${DPANEL_RUNTIME_DIR}/apache-site.conf.tpl" \
      "${DPANEL_TEMPLATE_DIR}/generated/sites/${site_name}.conf" \
      domain "$domain" \
      root "$root_path" \
      username "$username" \
      php_version "$php_version"
  else
    panel_render_template \
      "${DPANEL_RUNTIME_DIR}/nginx-site.conf.tpl" \
      "${DPANEL_TEMPLATE_DIR}/generated/sites/${site_name}.conf" \
      domain "$domain" \
      root "$root_path" \
      username "$username" \
      php_version "$php_version"
  fi

  panel_render_template \
    "${DPANEL_RUNTIME_DIR}/php-pool.conf.tpl" \
    "${DPANEL_TEMPLATE_DIR}/generated/pools/${username}.conf" \
    username "$username" \
    php_version "$php_version" \
    root "$root_path"

  if [[ "${ssl,,}" == "yes" || "${ssl,,}" == "true" ]]; then
    panel_render_template \
      "${DPANEL_RUNTIME_DIR}/ssl-site.conf.tpl" \
      "${DPANEL_TEMPLATE_DIR}/generated/sites/${site_name}.ssl.conf" \
      domain "$domain" \
      root "$root_path" \
      username "$username" \
      php_version "$php_version"
  fi

  panel_info_log "Site scaffold created for ${domain}"
  panel_info_log "Config cached at ${DPANEL_TEMPLATE_DIR}/generated/sites/${site_name}.conf"
}

panel_resolve_app_env_file() {
  local candidate=""
  local path
  local paths=()

  if [[ -n "${PANEL_APP_ENV_FILE:-}" && -f "${PANEL_APP_ENV_FILE}" ]]; then
    printf '%s' "${PANEL_APP_ENV_FILE}"
    return 0
  fi

  if [[ -n "${PANEL_APP_DIR:-}" ]]; then
    paths+=("${PANEL_APP_DIR}/.env" "${PANEL_APP_DIR}/dpanel/.env")
  fi

  paths+=(
    "${DPANEL_BASE_DIR}/dpanel/.env"
    "${DPANEL_BASE_DIR}/.env"
    "/var/www/ServerPanel/.env"
    "/var/www/dpanel/.env"
    "/opt/dpanel/dpanel/.env"
    "/opt/likesoft/dpanel/.env"
  )

  for path in "${paths[@]}"; do
    candidate="$(printf '%s' "$path" | xargs)"
    if [[ -n "$candidate" && -f "$candidate" ]]; then
      printf '%s' "$candidate"
      return 0
    fi
  done

  printf '%s' ''
}

panel_env_set() {
  local file="$1"
  local key="$2"
  local value="$3"
  local tmp="${file}.tmp"

  [[ -f "$file" ]] || touch "$file"

  awk -v key="$key" -v value="$value" '
    BEGIN { found = 0 }
    $0 ~ "^" key "=" {
      print key "=" value
      found = 1
      next
    }
    { print }
    END {
      if (!found) print key "=" value
    }
  ' "$file" > "$tmp"
  mv "$tmp" "$file"
}

panel_setup_application_database() {
  local env_file db_password db_name db_user db_host db_port db_charset db_collation

  env_file="$(panel_resolve_app_env_file)"
  if [[ -z "$env_file" ]]; then
    panel_warn_log "Application .env not found; skipping database provisioning."
    return 0
  fi

  db_name="${PANEL_DB_NAME:-dpanel}"
  db_user="${PANEL_DB_USER:-dpanel}"
  db_host="${PANEL_DB_HOST:-127.0.0.1}"
  db_port="${PANEL_DB_PORT:-3306}"
  db_charset="${PANEL_DB_CHARSET:-utf8mb4}"
  db_collation="${PANEL_DB_COLLATION:-utf8mb4_unicode_ci}"
  db_password="${PANEL_DB_PASSWORD:-}"

  if [[ -z "$db_password" ]]; then
    db_password="$(panel_generate_token | cut -c1-24)"
  fi

  panel_info_log "Provisioning application database ${db_name} and user ${db_user}."
  panel_run_runtime_script "database-request.sh" create "$db_name" "$db_user" "$db_password" "$db_host" "$db_port" "$db_charset" "$db_collation"

  panel_env_set "$env_file" DB_CONNECTION mysql
  panel_env_set "$env_file" DB_HOST "$db_host"
  panel_env_set "$env_file" DB_PORT "$db_port"
  panel_env_set "$env_file" DB_DATABASE "$db_name"
  panel_env_set "$env_file" DB_USERNAME "$db_user"
  panel_env_set "$env_file" DB_PASSWORD "$db_password"
  panel_env_set "$env_file" PDNS_DB_HOST "$db_host"
  panel_env_set "$env_file" PDNS_DB_PORT "$db_port"
  panel_env_set "$env_file" PDNS_DB_DATABASE "$db_name"
  panel_env_set "$env_file" PDNS_DB_USERNAME "$db_user"
  panel_env_set "$env_file" PDNS_DB_PASSWORD "$db_password"

  panel_info_log "Application .env updated: ${env_file}"
  printf 'Generated database credentials for %s:\n' "$db_name"
  printf '  DB_USERNAME=%s\n' "$db_user"
  printf '  DB_PASSWORD=%s\n' "$db_password"
}

panel_run_runtime_script() {
  local script_name="$1"
  shift || true

  local script_path="${DPANEL_RUNTIME_DIR}/scripts/${script_name}"
  [[ -x "$script_path" ]] || panel_die "Missing runtime script: ${script_name}"

  DPANEL_RUNTIME_DIR="$DPANEL_RUNTIME_DIR" DPANEL_BASE_DIR="$DPANEL_BASE_DIR" bash "$script_path" "$@"
}

panel_write_runtime_templates() {
  cat > "${DPANEL_RUNTIME_DIR}/nginx-site.conf.tpl" <<'EOF'
server {
    listen 80;
    server_name {{domain}} www.{{domain}};

    root {{root}};
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php{{php_version}}-fpm.sock;
    }
}
EOF

  cat > "${DPANEL_RUNTIME_DIR}/apache-site.conf.tpl" <<'EOF'
<VirtualHost *:80>
    ServerName {{domain}}
    ServerAlias www.{{domain}}
    DocumentRoot {{root}}

    <Directory {{root}}>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
EOF

  cat > "${DPANEL_RUNTIME_DIR}/php-pool.conf.tpl" <<'EOF'
[{{username}}]
user = {{username}}
group = {{username}}
listen = /run/php/panel-{{username}}.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 10
EOF

  cat > "${DPANEL_RUNTIME_DIR}/ssl-site.conf.tpl" <<'EOF'
# SSL placeholder for {{domain}}
# Place the certbot or ACME generated directives here after issuance.
EOF
}

panel_install_cli_launcher() {
  panel_write_runtime_templates
  panel_install_runtime_assets
}

panel_bootstrap() {
  local requested_modules="${PANEL_MODULES:-apache,nginx,php,mariadb,supervisor,firewall,fail2ban}"
  local skip_firewall="${SKIP_FIREWALL:-false}"
  local skip_ssl="${SKIP_SSL:-false}"
  local skip_test="${SKIP_TEST:-false}"
  local bootstrap_mode="${PANEL_BOOTSTRAP_MODE:-install}"
  local panel_domain="${PANEL_DOMAIN:-installer.likesoftbd.com}"
  local panel_port="${PANEL_PORT:-80}"

  panel_require_root
  panel_ensure_dirs
  panel_detect_os
  panel_install_cli_launcher
  if ! panel_server_json_valid; then
    panel_warn_log "Server metadata missing or invalid; regenerating ${DPANEL_SERVER_JSON}."
    panel_register_server
  fi

  case "${bootstrap_mode}" in
    install)
      panel_sync_manifest
      IFS=',' read -r -a module_list <<< "$requested_modules"
      for module in "${module_list[@]}"; do
        module="$(printf '%s' "$module" | xargs)"
        [[ -z "$module" ]] && continue
        if [[ "$module" == "firewall" && "${skip_firewall,,}" == "true" ]]; then
          panel_info_log "Skipping firewall module."
          continue
        fi
        if [[ "$module" == "ssl" && "${skip_ssl,,}" == "true" ]]; then
          panel_info_log "Skipping ssl module."
          continue
        fi
        if [[ "$module" == "php" ]]; then
          if ! panel_php_manage_versions install all; then
            panel_error_log "Module failed; chain stopped: ${module}"
            return 1
          fi
        elif ! panel_run_module "$module" install; then
          panel_error_log "Module failed; chain stopped: ${module}"
          return 1
        fi
        panel_store_installed_manifest_value "$module" "$(panel_manifest_version_for "$module")"
        if [[ "$module" == "mariadb" ]]; then
          panel_setup_application_database
        fi
      done
      ;;
    update)
      panel_sync_manifest
      panel_update_from_manifest
      ;;
    info)
      panel_info
      ;;
    site:create)
      panel_site_create "$@"
      ;;
    *)
      panel_die "Unknown bootstrap mode: ${bootstrap_mode}"
      ;;
  esac

  if [[ "${skip_test,,}" != "true" ]]; then
    panel_info_log "Bootstrap finished for https://${panel_domain}:${panel_port}"
  else
    panel_warn_log "Skipped post-install test execution."
  fi
}

panel_info() {
  if [[ -f "$DPANEL_SERVER_JSON" ]]; then
    cat "$DPANEL_SERVER_JSON"
  else
    panel_warn_log "No server metadata found at ${DPANEL_SERVER_JSON}"
  fi

  if [[ -f "$DPANEL_LOCAL_MANIFEST" ]]; then
    echo
    echo "[installed-modules]"
    cat "$DPANEL_LOCAL_MANIFEST"
  fi
}

panel_cli_dispatch() {
  local command="${1:-install}"
  shift || true

  case "$command" in
    install)
      if [[ $# -gt 0 ]]; then
        local module="$1"
        shift || true
        if [[ "$module" == "site:create" ]]; then
          panel_site_create "$@"
        elif [[ "$module" == "php" ]]; then
          panel_php_manage_versions install "${1:-all}" "${@:2}"
        else
          panel_run_module "$module" install "$@"
        fi
      else
        panel_bootstrap
      fi
      ;;
    remove)
      if [[ $# -lt 1 ]]; then
        panel_die "Usage: panel remove <module> [version]"
      fi
      local module="$1"
      shift || true
      if [[ "$module" == "php" ]]; then
        panel_php_manage_versions remove "${1:-$(panel_php_default_version)}"
      else
        panel_run_module "$module" remove "$@"
      fi
      ;;
    update)
      if [[ $# -gt 0 ]]; then
        local module="$1"
        shift || true
        if [[ "$module" == "php" ]]; then
          panel_php_manage_versions update "${1:-all}" "${@:2}"
        else
          panel_run_module "$module" update "$@"
        fi
      else
        panel_bootstrap_mode="update"
        PANEL_BOOTSTRAP_MODE="update" panel_bootstrap
      fi
      ;;
    info)
      panel_info
      ;;
    site:create)
      panel_site_create "$@"
      ;;
    php)
      if [[ $# -lt 1 ]]; then
        panel_die "Usage: panel php <install|update|reinstall|default|list|remove> [version|all]"
      fi
      local php_command="$1"
      shift || true
      panel_php_manage_versions "$php_command" "$@"
      ;;
    user:create)
      panel_run_runtime_script "create-admin-user.sh" "$@"
      ;;
    ssh:disable-root)
      panel_run_runtime_script "disable-root-login.sh" "$@"
      ;;
    filemanager)
      if [[ $# -lt 1 ]]; then
        panel_die "Usage: panel filemanager <create|remove|exists|file-exists|user> <path>..."
      fi
      local filemanager_command="$1"
      shift || true
      case "$filemanager_command" in
        user)
          if [[ $# -lt 1 ]]; then
            panel_die "Usage: panel filemanager user <create|ensure> <username> [options]"
          fi
          local filemanager_user_command="$1"
          shift || true
          panel_run_module_exact "filemanager" user "" "$filemanager_user_command" "$@"
          ;;
        remove)
          panel_run_module_exact "filemanager" remove "$@"
          ;;
        create|exists|file-exists)
          panel_run_module_exact "filemanager" install "$filemanager_command" "$@"
          ;;
        *)
          panel_run_module_exact "filemanager" install "$filemanager_command" "$@"
          ;;
      esac
      ;;
    *)
      panel_die "Unknown command: ${command}"
      ;;
  esac
}
