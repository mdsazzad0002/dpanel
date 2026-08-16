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
    # Non-root CLI use (dpanel help, doctor) must not print a redirection error.
    if [[ -w "$logfile" ]]; then
      printf '%s\n' "$message" >> "$logfile" 2>/dev/null || true
    fi
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

  # Installer logs record host layout and module output; keep them root-only.
  chmod 0750 "$DPANEL_LOG_DIR" 2>/dev/null || true
  chmod 0640 \
    "${DPANEL_LOG_DIR}/install.log" \
    "${DPANEL_LOG_DIR}/update.log" \
    "${DPANEL_LOG_DIR}/agent.log" 2>/dev/null || true
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

  printf '%s' "https://installer.dengrweb.com/dscript"
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

panel_fix_runtime_exec_bits() {
  local root="${1:-}"
  [[ -n "$root" && -d "$root" ]] || return 0

  find "$root" -type d -exec chmod 0755 {} +
  find "$root" -type f \( -name '*.sh' -o -name dpanel -o -name launcher.sh \) -exec chmod 0755 {} +
}

panel_resolve_tempdir_for_user() {
  local username="${1:-default}"
  local tempdir="${PANEL_APP_DIR:-/var/www/dpanel}/storage/app/tmp/${username}"
  mkdir -p "$tempdir"
  chmod 0775 "$tempdir" 2>/dev/null || true
  printf '%s' "$tempdir"
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

  panel_fix_runtime_exec_bits "$DPANEL_RUNTIME_DIR"
  panel_fix_runtime_exec_bits "$DPANEL_REPOSITORY_DIR"

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
  local edge_gateway_env="/etc/drust/edge-gateway.env"

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
  "default_php_version": "$(panel_php_default_version)",
  "admin_username": "${PANEL_ADMIN_USERNAME:-}",
  "admin_email": "${PANEL_ADMIN_EMAIL:-}",
  "admin_password": "${PANEL_ADMIN_PASSWORD:-}",
  "edge_gateway_bind": "${DRUST_HTTP_BIND:-0.0.0.0:80}",
  "edge_gateway_https_bind": "${DRUST_HTTPS_BIND:-0.0.0.0:443}",
  "edge_gateway_root": "${DRUST_DEFAULT_SITE_ROOT:-/var/www/html}"
}
EOF

  install -d -m 0750 /etc/drust
  cat > "$edge_gateway_env" <<EOF
DRUST_HTTP_BIND=${DRUST_HTTP_BIND:-0.0.0.0:80}
DRUST_HTTPS_BIND=${DRUST_HTTPS_BIND:-0.0.0.0:443}
DRUST_PANEL_DOMAIN=${PANEL_DOMAIN:-}
DRUST_DEFAULT_SITE_ROOT=${DRUST_DEFAULT_SITE_ROOT:-/var/www/html}
EOF
  chmod 0600 "$edge_gateway_env"

  printf '%s\n' "$token" > "$DPANEL_TOKEN_FILE"
  chmod 0600 "$DPANEL_TOKEN_FILE"
  panel_info_log "Registered server: $server_uuid"
}

panel_is_interactive() {
  [[ -t 0 && -t 1 ]]
}

panel_prompt_required() {
  local variable_name="$1"
  local prompt="$2"
  local secret="${3:-false}"
  local value="${!variable_name:-}"
  local current_value=""
  local response=""

  if [[ -n "$value" ]]; then
    printf -v "$variable_name" '%s' "$value"
    return 0
  fi

  panel_is_interactive || panel_die "${variable_name} is required. Set it in the environment for non-interactive install."

  current_value=""
  if [[ -f "$DPANEL_SERVER_JSON" ]] && command -v python3 >/dev/null 2>&1; then
    current_value="$(python3 - "$DPANEL_SERVER_JSON" "$variable_name" <<'PY' 2>/dev/null
import json
import sys
path, variable_name = sys.argv[1], sys.argv[2]
try:
    with open(path, 'r', encoding='utf-8') as handle:
        data = json.load(handle)
    value = data.get(variable_name, '')
except (OSError, ValueError):
    value = ''
if variable_name == 'PANEL_ADMIN_PASSWORD':
    print('')
else:
    print(value)
PY
)"
  fi

  if [[ -n "$current_value" ]]; then
    while true; do
      if [[ "${secret,,}" == "true" ]]; then
        read -rp "Use previous value for ${prompt}? [Y/n]: " response
      else
        read -rp "Use previous value '${current_value}' for ${prompt}? [Y/n]: " response
      fi
      response="$(printf '%s' "$response" | tr '[:upper:]' '[:lower:]' | xargs)"
      if [[ -z "$response" || "$response" == "y" || "$response" == "yes" ]]; then
        printf -v "$variable_name" '%s' "$current_value"
        return 0
      fi
      if [[ "$response" == "n" || "$response" == "no" ]]; then
        break
      fi
    done
  fi

  while [[ -z "$value" ]]; do
    if [[ "${secret,,}" == "true" ]]; then
      read -rsp "${prompt}: " value
      printf '\n'
    else
      read -rp "${prompt}: " value
    fi
    value="$(printf '%s' "$value" | xargs)"
  done

  printf -v "$variable_name" '%s' "$value"
}

panel_prompt_password_pair() {
  local password="${PANEL_ADMIN_PASSWORD:-}"
  local confirm=""
  local current_password=""
  local response=""

  if [[ -n "$password" ]]; then
    PANEL_ADMIN_PASSWORD="$password"
    return 0
  fi

  panel_is_interactive || panel_die "PANEL_ADMIN_PASSWORD is required. Set it in the environment for non-interactive install."

  if [[ -f "$DPANEL_SERVER_JSON" ]] && command -v python3 >/dev/null 2>&1; then
    current_password="$(python3 - "$DPANEL_SERVER_JSON" <<'PY' 2>/dev/null
import json
import sys
try:
    with open(sys.argv[1], 'r', encoding='utf-8') as handle:
        data = json.load(handle)
    print(data.get('admin_password', ''))
except (OSError, ValueError):
    print('')
PY
)"
  fi

  if [[ -n "$current_password" ]]; then
    while true; do
      read -rp "Use previous admin password? [Y/n]: " response
      response="$(printf '%s' "$response" | tr '[:upper:]' '[:lower:]' | xargs)"
      if [[ -z "$response" || "$response" == "y" || "$response" == "yes" ]]; then
        PANEL_ADMIN_PASSWORD="$current_password"
        return 0
      fi
      if [[ "$response" == "n" || "$response" == "no" ]]; then
        break
      fi
    done
  fi

  while true; do
    read -rsp "Admin password: " password
    printf '\n'
    read -rsp "Confirm admin password: " confirm
    printf '\n'

    if [[ -z "$password" ]]; then
      panel_warn_log "Admin password cannot be empty."
      continue
    fi
    if [[ "$password" != "$confirm" ]]; then
      panel_warn_log "Passwords do not match."
      continue
    fi
    break
  done

  PANEL_ADMIN_PASSWORD="$password"
}

panel_is_public_ipv4() {
  local ip="$1" octet1 octet2
  [[ "$ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || return 1
  IFS=. read -r octet1 octet2 _ _ <<< "$ip"
  while IFS=. read -r -a octets <<< "$ip"; do
    local octet
    for octet in "${octets[@]}"; do
      [[ "$octet" =~ ^[0-9]+$ ]] && (( octet >= 0 && octet <= 255 )) || return 1
    done
    break
  done
  (( octet1 == 10 || octet1 == 127 || octet1 == 0 )) && return 1
  (( octet1 == 169 && octet2 == 254 )) && return 1
  (( octet1 == 172 && octet2 >= 16 && octet2 <= 31 )) && return 1
  (( octet1 == 192 && octet2 == 168 )) && return 1
  (( octet1 >= 224 )) && return 1
  return 0
}

panel_detect_public_ipv4() {
  local candidate=""
  if command -v ip >/dev/null 2>&1; then
    candidate="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i=1; i<=NF; i++) if ($i == "src") {print $(i+1); exit}}')"
  fi
  panel_is_public_ipv4 "$candidate" && printf '%s' "$candidate"
}

panel_collect_mail_server_ip() {
  local env_file existing="" detected="" entered=""
  PANEL_MAIL_SERVER_IP="${PANEL_MAIL_SERVER_IP:-${SERVERPANEL_MAIL_SERVER_IP:-}}"

  if [[ -z "$PANEL_MAIL_SERVER_IP" ]]; then
    env_file="$(panel_resolve_app_env_file)"
    if [[ -n "$env_file" && -f "$env_file" ]]; then
      existing="$(sed -n 's/^SERVERPANEL_MAIL_SERVER_IP=//p' "$env_file" | tail -n1 | tr -d '"' | xargs)"
      panel_is_public_ipv4 "$existing" && PANEL_MAIL_SERVER_IP="$existing"
    fi
  fi

  if [[ -n "$PANEL_MAIL_SERVER_IP" ]]; then
    panel_is_public_ipv4 "$PANEL_MAIL_SERVER_IP" || panel_die "PANEL_MAIL_SERVER_IP must be a public IPv4 address."
  fi

  if ! panel_is_interactive || [[ "${PANEL_SKIP_FIRST_INSTALL_PROMPTS:-false}" == "true" ]]; then
    export PANEL_MAIL_SERVER_IP
    return 0
  fi

  detected="$(panel_detect_public_ipv4)"
  [[ -z "$PANEL_MAIL_SERVER_IP" ]] && PANEL_MAIL_SERVER_IP="$detected"
  while true; do
    if [[ -n "$PANEL_MAIL_SERVER_IP" ]]; then
      read -rp "Mail server public IPv4 [${PANEL_MAIL_SERVER_IP}] (blank accepts, 'skip' leaves unset): " entered
      entered="$(printf '%s' "$entered" | xargs)"
      [[ -z "$entered" ]] && entered="$PANEL_MAIL_SERVER_IP"
    else
      read -rp "Mail server public IPv4 (blank to configure later): " entered
      entered="$(printf '%s' "$entered" | xargs)"
    fi
    if [[ -z "$entered" || "${entered,,}" == "skip" ]]; then
      PANEL_MAIL_SERVER_IP=""
      break
    fi
    if panel_is_public_ipv4 "$entered"; then
      PANEL_MAIL_SERVER_IP="$entered"
      break
    fi
    panel_warn_log "Enter a valid public IPv4 address; private addresses such as 10.x, 172.16-31.x and 192.168.x are not allowed."
  done
  export PANEL_MAIL_SERVER_IP
}

panel_prompt_panel_reconfigure() {
  local domain="${PANEL_DOMAIN:-}"
  local username="${PANEL_ADMIN_USERNAME:-}"
  local email="${PANEL_ADMIN_EMAIL:-}"
  local has_values="false"
  local answer=""

  [[ -n "$domain" || -n "$username" || -n "$email" || -n "${PANEL_ADMIN_PASSWORD:-}" ]] && has_values="true"
  [[ "$has_values" == "true" ]] || return 0

  while true; do
    printf 'Panel already configured:\n'
    [[ -n "$domain" ]] && printf '  Domain:   %s\n' "$domain"
    [[ -n "$username" ]] && printf '  User:     %s\n' "$username"
    [[ -n "$email" ]] && printf '  Email:    %s\n' "$email"
    printf 'Do you want panel reconfig? [y/N]: '
    read -r answer
    answer="$(printf '%s' "$answer" | tr '[:upper:]' '[:lower:]' | xargs)"
    case "$answer" in
      ''|n|no) return 1 ;;
      y|yes) return 0 ;;
      *) panel_warn_log "Please answer yes or no." ;;
    esac
  done
}

panel_load_existing_panel_env() {
  local env_file=""
  local panel_url=""
  local session_domain=""

  env_file="$(panel_resolve_app_env_file)"
  [[ -n "$env_file" && -f "$env_file" ]] || return 0

  if [[ -z "${PANEL_DOMAIN:-}" ]]; then
    if command -v python3 >/dev/null 2>&1; then
      panel_url="$(python3 - "$env_file" <<'PY'
import sys
path = sys.argv[1]
value = ""
session_domain = ""
try:
    with open(path, encoding="utf-8") as handle:
        for line in handle:
            if line.startswith("APP_URL="):
                value = line.split("=", 1)[1].strip().strip('"').strip("'")
            if line.startswith("SESSION_DOMAIN="):
                session_domain = line.split("=", 1)[1].strip().strip('"').strip("'")
except OSError:
    pass
if value:
    print(value)
elif session_domain:
    print(session_domain)
else:
    print("")
PY
)"
      if [[ -n "$panel_url" ]]; then
        panel_url="${panel_url#http://}"
        panel_url="${panel_url#https://}"
        panel_url="${panel_url%%/*}"
        panel_url="${panel_url%%:*}"
        PANEL_DOMAIN="$panel_url"
      fi
    fi
  fi
}

panel_collect_first_install_config() {
  [[ "${PANEL_BOOTSTRAP_MODE:-install}" == "install" ]] || return 0

  local skip_prompts="${PANEL_SKIP_FIRST_INSTALL_PROMPTS:-false}"
  local reconfig_checked="${PANEL_PANEL_RECONFIG_CHECKED:-false}"

  panel_load_existing_panel_env

  if [[ "$reconfig_checked" != "true" ]]; then
    export PANEL_PANEL_RECONFIG_CHECKED=true
    local existing_domain
    existing_domain=""

    if [[ -f "$DPANEL_SERVER_JSON" ]] && command -v python3 >/dev/null 2>&1; then
      read -r existing_domain < <(python3 - "$DPANEL_SERVER_JSON" <<'PY'
import json
import sys
try:
    with open(sys.argv[1], 'r', encoding='utf-8') as handle:
        data = json.load(handle)
    print(data.get('panel_domain', ''))
except (OSError, ValueError):
    print('')
PY
)
    fi

    if [[ -z "${PANEL_DOMAIN:-}" && -n "$existing_domain" ]]; then
      PANEL_DOMAIN="$existing_domain"
    fi

    if [[ "${skip_prompts}" != "true" ]] && [[ -n "${PANEL_DOMAIN:-}" ]]; then
      if ! panel_prompt_panel_reconfigure; then
        export PANEL_DOMAIN
        return 0
      fi
      PANEL_DOMAIN=""
    fi
  fi

  if [[ "${skip_prompts}" == "true" ]]; then
    export PANEL_DOMAIN
    return 0
  fi

  panel_prompt_required PANEL_DOMAIN "Panel domain"
  panel_collect_mail_server_ip

  export PANEL_DOMAIN PANEL_MAIL_SERVER_IP
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
      panel_info_log "php ${version} already installed; reconciling required extensions."
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

  root_path="${6:-/home/${username}/public_html}"
  site_name="${domain//./-}"

  mkdir -p "$DPANEL_TEMPLATE_DIR/generated/pools"

  panel_render_template \
    "${DPANEL_RUNTIME_DIR}/php-pool.conf.tpl" \
    "${DPANEL_TEMPLATE_DIR}/generated/pools/${username}.conf" \
    username "$username" \
    php_version "$php_version" \
    root "$root_path" \
    tmpdir "$(panel_resolve_tempdir_for_user "$username")"

  if [[ "${ssl,,}" == "yes" || "${ssl,,}" == "true" ]]; then
    panel_render_template \
      "${DPANEL_RUNTIME_DIR}/ssl-site.conf.tpl" \
      "${DPANEL_TEMPLATE_DIR}/generated/pools/${site_name}.ssl.conf" \
      domain "$domain" \
      root "$root_path" \
      username "$username" \
      php_version "$php_version"
  fi

  panel_info_log "Site scaffold created for ${domain}"
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
    "/opt/dengrweb/dpanel/.env"
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

panel_resolve_app_env_example_file() {
  local candidate=""
  local path
  local paths=()

  if [[ -n "${PANEL_APP_DIR:-}" ]]; then
    paths+=("${PANEL_APP_DIR}/.env.example" "${PANEL_APP_DIR}/dpanel/.env.example")
  fi

  paths+=(
    "${DPANEL_BASE_DIR}/dpanel/.env.example"
    "/var/www/dpanel/.env.example"
    "/opt/dpanel/dpanel/.env.example"
    "/opt/dengrweb/dpanel/.env.example"
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

panel_ensure_app_env_file() {
  local env_file="${1:-}"
  local panel_domain="${2:-${PANEL_DOMAIN:-localhost}}"
  local panel_port="${3:-${PANEL_PORT:-80}}"
  local app_url scheme env_example

  [[ -n "$env_file" ]] || return 1

  if [[ -f "$env_file" ]]; then
    printf '%s' "$env_file"
    return 0
  fi

  mkdir -p "$(dirname "$env_file")"

  env_example="$(panel_resolve_app_env_example_file)"
  if [[ -n "$env_example" ]]; then
    cp -f "$env_example" "$env_file"
  else
    cat > "$env_file" <<'EOF'
APP_NAME=dPanel
APP_ENV=production
APP_DEBUG=false
EOF
  fi

  scheme="http"
  [[ "$panel_port" == "443" ]] && scheme="https"
  app_url="${scheme}://${panel_domain}"
  if [[ "$panel_port" != "80" && "$panel_port" != "443" ]]; then
    app_url="${app_url}:${panel_port}"
  fi

  panel_env_set "$env_file" APP_URL "$app_url"
  panel_env_set "$env_file" SESSION_COOKIE_DOMAIN ""
  panel_env_set "$env_file" SESSION_SECURE_COOKIE "$([[ "$panel_port" == "443" ]] && printf true || printf false)"
  chmod 0644 "$env_file" 2>/dev/null || true
  printf '[INFO] Created missing application .env: %s\n' "$env_file" >&2
  printf '%s' "$env_file"
}

panel_env_set() {
  local file="$1"
  local key="$2"
  local value="$3"
  local tmp="${file}.tmp"

  [[ -f "$file" ]] || touch "$file"

  if ! : > "$tmp" 2>/dev/null; then
    tmp="$(mktemp "${TMPDIR:-/tmp}/panel-env.XXXXXX")"
  fi

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
  if ! mv "$tmp" "$file" 2>/dev/null; then
    cat "$tmp" > "$file"
    rm -f "$tmp"
  fi
}

panel_setup_application_database() {
  local env_file db_password db_name db_user db_host db_port db_charset db_collation

  env_file="$(panel_resolve_app_env_file)"
  if [[ -z "$env_file" ]]; then
    env_file="$(panel_ensure_app_env_file "${PANEL_APP_DIR:-/var/www/dpanel}/.env")"
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
  # Password travels in the environment, never as an argument other local users
  # could read from /proc while the installer runs.
  DPANEL_DB_PASSWORD="$db_password" panel_run_runtime_script "database-request.sh" create "$db_name" "$db_user" "" "$db_host" "$db_port" "$db_charset" "$db_collation"

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

panel_module_state_label() {
  local module="$1"
  local installed
  installed="$(panel_installed_manifest_value "$module" 2>/dev/null || true)"
  if [[ -n "$installed" && "$installed" != "no" ]]; then
    printf 'installed (%s)' "$installed"
  else
    printf 'not installed'
  fi
}

panel_prompt_module_action() {
  local module="$1"
  local desired_action="${2:-install}"
  local state_label answer default_action prompt
  local sensitive_modules="ssl ssh-root-login admin-user"

  state_label="$(panel_module_state_label "$module")"
  default_action="$desired_action"

  if [[ "$desired_action" == "install" ]]; then
    if [[ "$state_label" == installed* ]]; then
      default_action="skip"
    fi
  elif [[ "$desired_action" == "update" ]]; then
    if [[ "$state_label" == "not installed" ]]; then
      default_action="install"
    fi
  fi

  if [[ "$desired_action" == "install" ]] && [[ " ${sensitive_modules} " != *" ${module} "* ]]; then
    printf '%s' "$default_action"
    return 0
  fi

  if [[ "$default_action" == "skip" ]]; then
    prompt="Module ${module} is ${state_label}. Already OK. Continue with no action? [y/N/skip]"
  elif [[ "$default_action" == "install" ]]; then
    prompt="Module ${module} is ${state_label}. Install it now? [Y/n/skip]"
  else
    prompt="Module ${module} is ${state_label}. Update it now? [Y/n/skip]"
  fi

  while true; do
    read -rp "${prompt} " answer
    answer="$(printf '%s' "$answer" | tr '[:upper:]' '[:lower:]' | xargs)"
    case "$answer" in
      '')
        if [[ "$default_action" == "skip" ]]; then
          printf '%s' 'skip'
        else
          printf '%s' "$default_action"
        fi
        return 0
        ;;
      y|yes)
        if [[ "$default_action" == "skip" ]]; then
          printf '%s' 'skip'
        else
          printf '%s' "$default_action"
        fi
        return 0
        ;;
      n|no|skip|s) printf '%s' 'skip'; return 0 ;;
      install|update|remove|reinstall) printf '%s' "$answer"; return 0 ;;
      *) panel_warn_log "Please answer yes, no, skip, install, update, remove or reinstall." ;;
    esac
  done
}

panel_total_memory_mb() {
  awk '/^MemTotal:/ {printf "%d", $2 / 1024; found = 1} END {if (!found) print 0}' /proc/meminfo 2>/dev/null || printf '0'
}

panel_total_swap_mb() {
  awk '/^SwapTotal:/ {printf "%d", $2 / 1024; found = 1} END {if (!found) print 0}' /proc/meminfo 2>/dev/null || printf '0'
}

# Composer, the Vite build and the Rust build are the memory peaks of an install.
# On a 1 GB VPS they get OOM-killed without swap, which is the most common reason
# a first install fails on cheap hosting.
panel_ensure_swap() {
  local swapfile="/swapfile" memory swap size_mb free_mb

  memory="$(panel_total_memory_mb)"
  swap="$(panel_total_swap_mb)"

  [[ "$memory" =~ ^[0-9]+$ && "$swap" =~ ^[0-9]+$ ]] || return 0
  (( memory > 0 )) || return 0
  (( memory >= 2048 )) && return 0
  (( swap >= 1024 )) && return 0

  if [[ -e "$swapfile" ]]; then
    panel_warn_log "${swapfile} already exists; leaving swap configuration unchanged."
    return 0
  fi

  size_mb=2048
  free_mb="$(df -Pm / 2>/dev/null | awk 'NR == 2 {print $4}')"
  if [[ "$free_mb" =~ ^[0-9]+$ ]] && (( free_mb < size_mb + 1024 )); then
    panel_warn_log "Not enough free disk space for a ${size_mb} MB swap file; continuing without swap."
    return 0
  fi

  panel_info_log "Detected ${memory} MB RAM and ${swap} MB swap; creating a ${size_mb} MB swap file."
  if ! fallocate -l "${size_mb}M" "$swapfile" 2>/dev/null; then
    if ! dd if=/dev/zero of="$swapfile" bs=1M count="$size_mb" status=none 2>/dev/null; then
      panel_warn_log "Unable to create ${swapfile}; continuing without swap."
      rm -f "$swapfile"
      return 0
    fi
  fi

  chmod 600 "$swapfile"
  if ! mkswap "$swapfile" >/dev/null 2>&1 || ! swapon "$swapfile" 2>/dev/null; then
    # Containers usually forbid swapon; that is not a reason to stop the install.
    panel_warn_log "Swap could not be enabled on this host; continuing without swap."
    swapoff "$swapfile" >/dev/null 2>&1 || true
    rm -f "$swapfile"
    return 0
  fi

  if ! grep -q "^${swapfile}[[:space:]]" /etc/fstab 2>/dev/null; then
    printf '%s none swap sw 0 0\n' "$swapfile" >> /etc/fstab
  fi
  panel_info_log "Swap enabled at ${swapfile}."
}

panel_install_app_dependencies() {
  local app_dir="${PANEL_APP_DIR:-/var/www/dpanel}"
  local vendor_dir="${app_dir}/vendor"

  [[ -d "$app_dir" ]] || { panel_warn_log "Application directory not found; skipping app dependency install."; return 0; }

  if [[ -f "${vendor_dir}/autoload.php" ]]; then
    panel_info_log "Application dependencies already present."
  else
    panel_info_log "Installing PHP application dependencies with composer."
    if ! command -v composer >/dev/null 2>&1; then
      panel_warn_log "composer is missing; attempting package install."
      if command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get update -qq
        DEBIAN_FRONTEND=noninteractive apt-get install -y -qq composer
      elif command -v dnf >/dev/null 2>&1; then
        dnf install -y composer
      elif command -v yum >/dev/null 2>&1; then
        yum install -y composer
      else
        panel_die "composer is required but no supported package manager was found."
      fi
    fi

    (cd "$app_dir" && composer install --no-interaction --prefer-dist --optimize-autoloader)
  fi
}

panel_fix_app_permissions() {
  local app_dir="${PANEL_APP_DIR:-/var/www/dpanel}"
  local storage_dir="${app_dir}/storage"
  local cache_dir="${app_dir}/bootstrap/cache"
  local env_file="${app_dir}/.env"

  [[ -d "$app_dir" ]] || { panel_warn_log "Application directory not found; skipping app permission repair."; return 0; }

  panel_info_log "Repairing application permissions."

  # Application code stays root-owned: www-data only needs to read it, so a
  # compromised PHP process cannot rewrite the panel itself. Capital X keeps the
  # execute bit on directories and on binaries such as vendor/bin and node_modules/.bin.
  #
  # The restrictive mode is only applied when the ownership change worked. On a
  # host without a www-data group, stripping other-read from files still owned by
  # someone else would lock the web server out of the panel.
  if chown -R root:www-data "$app_dir" 2>/dev/null; then
    chmod -R u=rwX,g=rX,o= "$app_dir" 2>/dev/null || true
  else
    panel_warn_log "Could not apply root:www-data ownership to ${app_dir}; leaving its permissions unchanged."
    return 0
  fi

  # Only these two trees are written at runtime.
  if [[ -d "$storage_dir" || -d "$cache_dir" ]]; then
    if chown -R www-data:www-data "$storage_dir" "$cache_dir" 2>/dev/null; then
      chmod -R u=rwX,g=rwX,o= "$storage_dir" "$cache_dir" 2>/dev/null || true
      find "$storage_dir" "$cache_dir" -type d -exec chmod g+s {} + 2>/dev/null || true
    else
      panel_warn_log "Could not apply www-data ownership to the runtime directories."
    fi
  fi

  # .env holds the database password and the drust API token, and that token is a
  # root-level capability on this host. It must never be readable by other users.
  if [[ -f "$env_file" ]]; then
    chown root:www-data "$env_file" 2>/dev/null || true
    chmod 640 "$env_file" 2>/dev/null || true
  fi
}

panel_install_frontend_assets() {
  local app_dir="${PANEL_APP_DIR:-/var/www/dpanel}"

  [[ -d "$app_dir" ]] || { panel_warn_log "Application directory not found; skipping frontend build."; return 0; }
  [[ -f "${app_dir}/package.json" ]] || { panel_warn_log "package.json not found; skipping frontend build."; return 0; }

  panel_info_log "Installing frontend dependencies and building assets."
  if ! command -v node >/dev/null 2>&1 || ! command -v npm >/dev/null 2>&1; then
    panel_warn_log "npm is missing; attempting package install."
  fi

  panel_ensure_node20

  # Node grows its heap until the kernel kills it. Capping the heap below the
  # machine size makes the build spill to swap instead of dying on a small VPS.
  local memory node_heap
  memory="$(panel_total_memory_mb)"
  if [[ "$memory" =~ ^[0-9]+$ ]] && (( memory > 0 && memory < 4096 )); then
    node_heap=$(( memory / 2 ))
    (( node_heap < 512 )) && node_heap=512
    export NODE_OPTIONS="--max-old-space-size=${node_heap}"
    panel_info_log "Limiting the frontend build heap to ${node_heap} MB for a ${memory} MB server."
  fi

  (cd "$app_dir" && npm install --no-audit --no-fund)
  (cd "$app_dir" && npm run build)
}

panel_ensure_node20() {
  local major version
  local arch node_version node_url node_dir tarball

  if ! command -v node >/dev/null 2>&1; then
    major=0
  else
    version="$(node -v 2>/dev/null | sed 's/^v//')"
    major="${version%%.*}"
  fi

  if [[ "$major" =~ ^[0-9]+$ ]] && (( major >= 20 )); then
    return 0
  fi

  panel_warn_log "Node.js ${version:-missing} is too old for Vite; installing Node.js 20+."

  arch="$(uname -m)"
  case "$arch" in
    x86_64|amd64) node_arch="linux-x64" ;;
    aarch64|arm64) node_arch="linux-arm64" ;;
    armv7l) node_arch="linux-armv7l" ;;
    *)
      panel_die "Unsupported CPU architecture for bundled Node.js download: ${arch}"
      ;;
  esac

  node_version="v20.19.0"
  node_url="https://nodejs.org/dist/${node_version}/node-${node_version}-${node_arch}.tar.xz"
  node_dir="${DPANEL_BASE_DIR:-/opt/dpanel}/node-${node_version}"
  tarball="${node_dir}.tar.xz"

  mkdir -p "$DPANEL_BASE_DIR"
  if [[ ! -x "${node_dir}/bin/node" ]]; then
    rm -rf "$node_dir" "$tarball"
    panel_info_log "Downloading Node.js ${node_version} (${node_arch})."
    if command -v curl >/dev/null 2>&1; then
      curl -fsSL "$node_url" -o "$tarball"
    elif command -v wget >/dev/null 2>&1; then
      wget -qO "$tarball" "$node_url"
    else
      panel_die "curl or wget is required to download Node.js ${node_version}."
    fi
    tar -xJf "$tarball" -C "$DPANEL_BASE_DIR"
    mv -f "${DPANEL_BASE_DIR}/node-${node_version}-${node_arch}" "$node_dir"
  fi

  export PATH="${node_dir}/bin:${PATH}"
  hash -r 2>/dev/null || true
}

panel_run_app_migrations() {
  local app_dir="${PANEL_APP_DIR:-/var/www/dpanel}"

  [[ -x "${app_dir}/artisan" ]] || { panel_warn_log "artisan not found; skipping database migration."; return 0; }

  panel_info_log "Running Laravel migrations."
  (cd "$app_dir" && php artisan migrate --force)
}

# drust owns the vhost templates and the certificate handling, so an update that
# does not rebuild the daemon leaves the server running the old generator.
panel_refresh_drust_service() {
  if [[ "$(id -u)" -ne 0 ]]; then
    panel_warn_log "Skipping drust service installation because this session is not running as root."
    return 0
  fi

  if [[ ! -x "/var/www/drust/deploy/install-service.sh" ]]; then
    panel_warn_log "drust installer not found; admin user creation may fail until drust is installed."
    return 0
  fi

  bash "/var/www/drust/deploy/install-service.sh"
}

# A site only moves to its own PHP-FPM pool safely once its files already belong
# to that account, so ownership is repaired before the vhosts are regenerated.
panel_fix_website_permissions() {
  local script_path="${DPANEL_RUNTIME_DIR}/scripts/fix-permissions.sh"

  [[ -x "$script_path" ]] || {
    panel_warn_log "fix-permissions script not found; skipping website ownership repair."
    return 0
  }

  panel_info_log "Repairing website ownership under /home."
  if ! bash "$script_path" --all; then
    panel_warn_log "Website ownership repair reported errors; run 'dpanel script run fix-permissions --all' after fixing them."
  fi
}

# Website vhosts are written once at create time, so template fixes shipped with an
# update stay dormant until each site is synced again. Doing it here means every
# existing website on the server picks the new template up during the update.
panel_resync_website_vhosts() {
  local app_dir="${PANEL_APP_DIR:-/var/www/dpanel}"

  [[ -x "${app_dir}/artisan" ]] || return 0

  panel_info_log "Regenerating website vhosts from the current templates."
  if ! (cd "$app_dir" && php artisan serverpanel:vhost-resync); then
    panel_warn_log "Some website vhosts could not be regenerated; run 'php artisan serverpanel:vhost-resync' after fixing them."
  fi
}

panel_refresh_app_config_cache() {
  local app_dir="${PANEL_APP_DIR:-/var/www/dpanel}"

  [[ -x "${app_dir}/artisan" ]] || return 0
  (cd "$app_dir" && php artisan optimize:clear >/dev/null 2>&1 || true)
  (cd "$app_dir" && php artisan config:cache >/dev/null 2>&1 || true)
}

panel_reconcile_system_records() {
  local args=()
  local app_dir="${PANEL_APP_DIR:-/var/www/dpanel}"

  [[ -x "${DPANEL_RUNTIME_DIR}/scripts/reconcile-system-records.sh" ]] || {
    panel_warn_log "System record reconciler not found; reserved user/domain records were not checked."
    return 0
  }

  [[ -n "${PANEL_DOMAIN:-}" ]] && args+=(--domain "$PANEL_DOMAIN")
  args+=(--root "$app_dir")
  if [[ "${PANEL_SKIP_FIRST_INSTALL_PROMPTS:-false}" == "true" || ! -t 0 ]]; then
    args+=(--non-interactive)
  fi

  panel_info_log "Reviewing existing websites, aliases, and reserved system user/domain records."
  panel_run_runtime_script "reconcile-system-records.sh" "${args[@]}"
}

panel_refresh_phpmyadmin_sso() {
  if [[ -x "${DPANEL_RUNTIME_DIR}/scripts/configure-phpmyadmin-signon.sh" ]]; then
    panel_info_log "Refreshing phpMyAdmin sign-on for the active panel domain."
    panel_run_runtime_script "configure-phpmyadmin-signon.sh"
  else
    panel_warn_log "phpMyAdmin signon helper not found; phpMyAdmin routing was not refreshed."
  fi
}

panel_finalize_default_install() {
  panel_collect_first_install_config

  local env_file="${PANEL_APP_ENV_FILE:-}"
  if [[ -z "$env_file" ]]; then
    env_file="$(panel_resolve_app_env_file)"
  fi
  if [[ -z "$env_file" ]]; then
    env_file="$(panel_ensure_app_env_file "${PANEL_APP_DIR:-/var/www/dpanel}/.env")"
  else
    env_file="$(panel_ensure_app_env_file "$env_file")"
  fi

  local panel_domain="${PANEL_DOMAIN:-}"
  local panel_port="${PANEL_PORT:-80}"
  local drust_token="${DRUST_API_TOKEN:-}"

  if [[ -n "$drust_token" ]]; then
    panel_env_set "$env_file" SERVERPANEL_EXECUTION_API_BASE_URL "http://127.0.0.1:9500"
    panel_env_set "$env_file" SERVERPANEL_EXECUTION_API_TOKEN "$drust_token"
  fi

  if [[ -n "$panel_domain" ]]; then
    panel_env_set "$env_file" APP_URL "http://${panel_domain}"
    panel_env_set "$env_file" SESSION_COOKIE_DOMAIN ""
    panel_env_set "$env_file" SESSION_SECURE_COOKIE "$([[ "$panel_port" == "443" ]] && printf true || printf false)"
    panel_env_set "$env_file" PHPMYADMIN_URL "http://${panel_domain}/phpmyadmin/"
  fi

  if [[ -n "${PANEL_MAIL_SERVER_IP:-}" ]]; then
    panel_env_set "$env_file" SERVERPANEL_MAIL_SERVER_IP "$PANEL_MAIL_SERVER_IP"
  fi

  panel_ensure_swap
  panel_install_app_dependencies
  panel_run_app_migrations
  panel_install_frontend_assets

  panel_reconcile_system_records
  panel_refresh_phpmyadmin_sso

  panel_refresh_drust_service

  local admin_username="${PANEL_ADMIN_USERNAME:-}"
  local admin_password="${PANEL_ADMIN_PASSWORD:-}"
  local admin_email="${PANEL_ADMIN_EMAIL:-}"

  if [[ -n "$admin_username" || -n "$admin_password" || -n "$admin_email" ]]; then
    panel_warn_log "Admin user env vars are ignored during bootstrap. Create the first user after install from the menu."
  fi

  panel_refresh_app_config_cache
  # Runs last: composer, migrations, npm and the config cache all create files as
  # root, so repairing before them leaves root-owned files under storage/.
  panel_fix_app_permissions

}

panel_write_runtime_templates() {
  cat > "${DPANEL_RUNTIME_DIR}/php-pool.conf.tpl" <<'EOF'
[{{username}}]
user = {{username}}
group = {{username}}
listen = /run/php/panel-{{username}}.sock
listen.owner = www-data
listen.group = www-data
env[TMPDIR] = {{tmpdir}}
env[TEMP] = {{tmpdir}}
env[TMP] = {{tmpdir}}
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
  local requested_modules="${PANEL_MODULES:-php,mariadb,redis,ssl,supervisor,queue,firewall,fail2ban}"
  local skip_firewall="${SKIP_FIREWALL:-false}"
  local skip_ssl="${SKIP_SSL:-false}"
  local skip_test="${SKIP_TEST:-false}"
  local bootstrap_mode="${PANEL_BOOTSTRAP_MODE:-install}"
  local panel_domain="${PANEL_DOMAIN:-}"
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
        local module_action
        module_action="$(panel_prompt_module_action "$module" install)"
        if [[ "$module_action" == "skip" ]]; then
          panel_info_log "Skipping module ${module}."
          continue
        fi
        if [[ "$module" == "firewall" && "${skip_firewall,,}" == "true" ]]; then
          panel_info_log "Skipping firewall module."
          continue
        fi
        if [[ "$module" == "ssl" && "${skip_ssl,,}" == "true" ]]; then
          panel_info_log "Skipping ssl module."
          continue
        fi
        if [[ "$module" == "php" ]]; then
          if ! panel_php_manage_versions "$module_action" all; then
            panel_error_log "Module failed; chain stopped: ${module}"
            return 1
          fi
        elif ! panel_run_module "$module" "$module_action"; then
          panel_error_log "Module failed; chain stopped: ${module}"
          return 1
        fi
        panel_store_installed_manifest_value "$module" "$(panel_manifest_version_for "$module")"
        if [[ "$module" == "mariadb" ]]; then
          panel_setup_application_database
        fi
      done
      if [[ "${PANEL_DEFER_FINALIZE:-false}" != "true" ]]; then
        panel_finalize_default_install
      fi
      ;;
    update)
      panel_sync_manifest
      local module
      for module in $(dscript_manifest_modules); do
        local module_action
        module_action="$(panel_prompt_module_action "$module" update)"
        [[ "$module_action" == "skip" ]] && { panel_info_log "Skipping module ${module}."; continue; }
        if [[ "$module" == "php" ]]; then
          if ! panel_php_manage_versions "$module_action" all; then
            panel_error_log "Module failed; chain stopped: ${module}"
            return 1
          fi
        else
          if ! panel_run_module "$module" "$module_action"; then
            panel_error_log "Module failed; chain stopped: ${module}"
            return 1
          fi
        fi
      done
      panel_ensure_swap
      # An update must not stop halfway: a failed daemon rebuild still leaves the
      # previous drust running, and the remaining repair steps are worth doing.
      panel_refresh_drust_service || panel_warn_log "drust rebuild failed; keeping the running binary."
      panel_load_existing_panel_env
      panel_reconcile_system_records
      panel_refresh_phpmyadmin_sso
      panel_fix_website_permissions
      panel_resync_website_vhosts
      panel_fix_app_permissions
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
    ssh)
      if [[ $# -lt 1 ]]; then
        panel_die "Usage: dpanel ssh <install|status|enable|disable|port|allow-ip|remove-ip|deny-global|allow-global|list-access|root-login|password-auth|list-users|remove-user|sessions|diagnose> [value]"
      fi
      local ssh_command="$1"
      shift || true
      panel_run_module_exact "ssh-manager" install "" "$ssh_command" "$@"
      ;;
    firewall)
      if [[ $# -lt 1 ]]; then
        panel_die "Usage: dpanel firewall <status|rules|logs|diagnose|allow-port|remove-port|allow-ip|remove-ip|limit-ssh|delete-rule|logging|enable|disable> [value]"
      fi
      local firewall_command="$1"
      shift || true
      panel_run_module_exact "firewall-manager" install "" "$firewall_command" "$@"
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
