#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_CORE_SOURCE="${BASH_SOURCE[0]}"
LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"
LIKESOFT_CACHE_DIR="${LIKESOFT_CACHE_DIR:-${LIKESOFT_BASE_DIR}/cache}"
LIKESOFT_MODULE_DIR="${LIKESOFT_MODULE_DIR:-${LIKESOFT_BASE_DIR}/modules}"
LIKESOFT_LOG_DIR="${LIKESOFT_LOG_DIR:-${LIKESOFT_BASE_DIR}/logs}"
LIKESOFT_TEMPLATE_DIR="${LIKESOFT_TEMPLATE_DIR:-${LIKESOFT_BASE_DIR}/templates}"
LIKESOFT_MANIFEST_DIR="${LIKESOFT_MANIFEST_DIR:-${LIKESOFT_BASE_DIR}/manifests}"
LIKESOFT_SERVER_JSON="${LIKESOFT_SERVER_JSON:-${LIKESOFT_BASE_DIR}/server.json}"
LIKESOFT_TOKEN_FILE="${LIKESOFT_TOKEN_FILE:-${LIKESOFT_BASE_DIR}/token}"
LIKESOFT_LOCAL_MANIFEST="${LIKESOFT_LOCAL_MANIFEST:-${LIKESOFT_CACHE_DIR}/modules.installed.json}"
LIKESOFT_LAUNCHER="${LIKESOFT_LAUNCHER:-/usr/local/bin/panel}"
PANEL_APP_DIR="${PANEL_APP_DIR:-${SERVER_BASE_DIR:-}}"
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
  printf '[%s] %s\n' "$level" "$*" | tee -a "${LIKESOFT_LOG_DIR}/install.log"
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
    "$LIKESOFT_BASE_DIR" \
    "$LIKESOFT_RUNTIME_DIR" \
    "$LIKESOFT_CACHE_DIR" \
    "$LIKESOFT_MODULE_DIR" \
    "$LIKESOFT_LOG_DIR" \
    "$LIKESOFT_TEMPLATE_DIR" \
    "$LIKESOFT_MANIFEST_DIR"

  touch \
    "${LIKESOFT_LOG_DIR}/install.log" \
    "${LIKESOFT_LOG_DIR}/update.log" \
    "${LIKESOFT_LOG_DIR}/agent.log"
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
  printf '%s' "${LIKESOFT_BASE_URL:-https://installer.likesoftbd.com}"
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
  install -m 0755 "$src" "$dest"
}

panel_install_runtime_assets() {
  panel_info_log "Installing runtime assets into ${LIKESOFT_RUNTIME_DIR}"

  local core_source="${LIKESOFT_DOWNLOADED_CORE:-$LIKESOFT_CORE_SOURCE}"
  local package_source="${LIKESOFT_DOWNLOADED_PACKAGE_MANAGER:-${LIKESOFT_PACKAGE_MANAGER_SOURCE:-}}"
  local source_scripts_dir=""
  local candidate_scripts_dir
  candidate_scripts_dir="$(cd "$(dirname "$LIKESOFT_CORE_SOURCE")/../scripts" 2>/dev/null && pwd || true)"
  if [[ -n "$candidate_scripts_dir" && -d "$candidate_scripts_dir" ]]; then
    source_scripts_dir="$candidate_scripts_dir"
  fi

  [[ -f "$core_source" ]] || panel_die "Missing core source file."
  [[ -n "$package_source" && -f "$package_source" ]] || panel_die "Missing package manager source file."

  panel_copy_runtime_asset "$core_source" "${LIKESOFT_RUNTIME_DIR}/core.sh"
  panel_copy_runtime_asset "$package_source" "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

  if [[ -n "$source_scripts_dir" ]]; then
    mkdir -p "${LIKESOFT_RUNTIME_DIR}/scripts"
    for script_name in create-admin-user.sh disable-root-login.sh database-request.sh; do
      if [[ -f "${source_scripts_dir}/${script_name}" ]]; then
        install -m 0755 "${source_scripts_dir}/${script_name}" "${LIKESOFT_RUNTIME_DIR}/scripts/${script_name}"
      fi
    done
  fi

  cat > "${LIKESOFT_RUNTIME_DIR}/launcher.sh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

if [[ ! -f "${LIKESOFT_RUNTIME_DIR}/core.sh" || ! -f "${LIKESOFT_RUNTIME_DIR}/package-manager.sh" ]]; then
  echo "Runtime core is missing. Re-run the installer." >&2
  exit 1
fi

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

panel_cli_dispatch "$@"
EOF
  chmod 0755 "${LIKESOFT_RUNTIME_DIR}/launcher.sh"

  cat > "$LIKESOFT_LAUNCHER" <<EOF
#!/usr/bin/env bash
exec "${LIKESOFT_RUNTIME_DIR}/launcher.sh" "\$@"
EOF
  chmod 0755 "$LIKESOFT_LAUNCHER"
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

  cat > "$LIKESOFT_SERVER_JSON" <<EOF
{
  "server_uuid": "$server_uuid",
  "installed_at": "$(date -u '+%Y-%m-%dT%H:%M:%SZ')",
  "base_url": "$(panel_default_base_url)",
  "server_ip": "$(panel_server_ip)",
  "distro": "${DISTRO:-unknown}",
  "version": "${VERSION:-unknown}",
  "panel_domain": "${PANEL_DOMAIN:-}",
  "panel_port": "${PANEL_PORT:-2083}",
  "default_php_version": "${PHP_VERSION:-8.3}"
}
EOF

  printf '%s\n' "$token" > "$LIKESOFT_TOKEN_FILE"
  chmod 0600 "$LIKESOFT_TOKEN_FILE"
  panel_info_log "Registered server: $server_uuid"
}

panel_remote_manifest_url() {
  printf '%s/repository/manifests/modules.json' "$(panel_default_base_url)"
}

panel_manifest_version_for() {
  local module="$1"
  local manifest="${2:-$LIKESOFT_CACHE_DIR/modules.manifest.json}"

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

panel_module_cache_path() {
  local module="$1"
  local action="${2:-install}"
  local version="${3:-}"

  if [[ "$module" == "php" && "$action" == "install" && -n "$version" ]]; then
    printf '%s/%s-%s.sh' "$LIKESOFT_MODULE_DIR" "$module" "$version"
    return 0
  fi

  printf '%s/%s.sh' "$LIKESOFT_MODULE_DIR" "$module"
}

panel_sync_manifest() {
  local dest="$LIKESOFT_CACHE_DIR/modules.manifest.json"
  panel_fetch "$(panel_remote_manifest_url)" "$dest"
  panel_info_log "Synced remote manifest."
}

panel_installed_manifest_value() {
  local key="$1"
  if [[ ! -f "$LIKESOFT_LOCAL_MANIFEST" ]]; then
    return 0
  fi

  awk -v key="$key" -F= '
    $1 == key { print $2 }
  ' "$LIKESOFT_LOCAL_MANIFEST" | tail -n 1
}

panel_store_installed_manifest_value() {
  local key="$1"
  local value="$2"

  touch "$LIKESOFT_LOCAL_MANIFEST"
  grep -v "^${key}=" "$LIKESOFT_LOCAL_MANIFEST" > "${LIKESOFT_LOCAL_MANIFEST}.tmp" || true
  mv "${LIKESOFT_LOCAL_MANIFEST}.tmp" "$LIKESOFT_LOCAL_MANIFEST"
  printf '%s=%s\n' "$key" "$value" >> "$LIKESOFT_LOCAL_MANIFEST"
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
  local action="${2:-install}"
  local version="${3:-}"
  shift 3 || true

  local script
  script="$(panel_download_module "$module" "$action" "$version")"
  LIKESOFT_RUNTIME_DIR="$LIKESOFT_RUNTIME_DIR" LIKESOFT_BASE_DIR="$LIKESOFT_BASE_DIR" bash "$script" "$action" "$@"
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
    panel_run_module "$module" update "${PHP_VERSION:-8.3}"
  else
    panel_run_module "$module" update
  fi
  panel_store_installed_manifest_value "$module" "$remote_version"
}

panel_update_from_manifest() {
  local manifest="$LIKESOFT_CACHE_DIR/modules.manifest.json"

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
    read -rp "Web server (nginx/apache): " web_server
  fi

  root_path="${6:-/home/${username}/public_html}"
  site_name="${domain//./-}"

  mkdir -p "$LIKESOFT_TEMPLATE_DIR/generated/sites" "$LIKESOFT_TEMPLATE_DIR/generated/pools"

  if [[ "$web_server" == "apache" ]]; then
    panel_render_template \
      "${LIKESOFT_RUNTIME_DIR}/apache-site.conf.tpl" \
      "${LIKESOFT_TEMPLATE_DIR}/generated/sites/${site_name}.conf" \
      domain "$domain" \
      root "$root_path" \
      username "$username" \
      php_version "$php_version"
  else
    panel_render_template \
      "${LIKESOFT_RUNTIME_DIR}/nginx-site.conf.tpl" \
      "${LIKESOFT_TEMPLATE_DIR}/generated/sites/${site_name}.conf" \
      domain "$domain" \
      root "$root_path" \
      username "$username" \
      php_version "$php_version"
  fi

  panel_render_template \
    "${LIKESOFT_RUNTIME_DIR}/php-pool.conf.tpl" \
    "${LIKESOFT_TEMPLATE_DIR}/generated/pools/${username}.conf" \
    username "$username" \
    php_version "$php_version" \
    root "$root_path"

  if [[ "${ssl,,}" == "yes" || "${ssl,,}" == "true" ]]; then
    panel_render_template \
      "${LIKESOFT_RUNTIME_DIR}/ssl-site.conf.tpl" \
      "${LIKESOFT_TEMPLATE_DIR}/generated/sites/${site_name}.ssl.conf" \
      domain "$domain" \
      root "$root_path" \
      username "$username" \
      php_version "$php_version"
  fi

  panel_info_log "Site scaffold created for ${domain}"
  panel_info_log "Config cached at ${LIKESOFT_TEMPLATE_DIR}/generated/sites/${site_name}.conf"
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
    "${LIKESOFT_BASE_DIR}/dpanel/.env"
    "${LIKESOFT_BASE_DIR}/.env"
    "/var/www/ServerPanel/.env"
    "/var/www/dpanel/.env"
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
  panel_run_runtime_script "database-request.sh" create "$db_name" "$db_user" "$db_password" "$db_host" "$db_charset" "$db_collation"

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

  local script_path="${LIKESOFT_RUNTIME_DIR}/scripts/${script_name}"
  [[ -x "$script_path" ]] || panel_die "Missing runtime script: ${script_name}"

  LIKESOFT_RUNTIME_DIR="$LIKESOFT_RUNTIME_DIR" LIKESOFT_BASE_DIR="$LIKESOFT_BASE_DIR" bash "$script_path" "$@"
}

panel_write_runtime_templates() {
  cat > "${LIKESOFT_RUNTIME_DIR}/nginx-site.conf.tpl" <<'EOF'
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

  cat > "${LIKESOFT_RUNTIME_DIR}/apache-site.conf.tpl" <<'EOF'
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

  cat > "${LIKESOFT_RUNTIME_DIR}/php-pool.conf.tpl" <<'EOF'
[{{username}}]
user = {{username}}
group = {{username}}
listen = /run/php/panel-{{username}}.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 10
EOF

  cat > "${LIKESOFT_RUNTIME_DIR}/ssl-site.conf.tpl" <<'EOF'
# SSL placeholder for {{domain}}
# Place the certbot or ACME generated directives here after issuance.
EOF
}

panel_install_cli_launcher() {
  panel_write_runtime_templates
  panel_install_runtime_assets
}

panel_bootstrap() {
  local requested_modules="${PANEL_MODULES:-nginx,php,mariadb,supervisor,firewall,fail2ban}"
  local skip_firewall="${SKIP_FIREWALL:-false}"
  local skip_ssl="${SKIP_SSL:-false}"
  local skip_test="${SKIP_TEST:-false}"
  local bootstrap_mode="${PANEL_BOOTSTRAP_MODE:-install}"
  local php_version="${PHP_VERSION:-8.3}"
  local panel_domain="${PANEL_DOMAIN:-installer.likesoftbd.com}"
  local panel_port="${PANEL_PORT:-2083}"

  panel_require_root
  panel_ensure_dirs
  panel_detect_os
  panel_install_cli_launcher
  panel_register_server

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
          panel_run_module "$module" install "$php_version"
        else
          panel_run_module "$module" install
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
  if [[ -f "$LIKESOFT_SERVER_JSON" ]]; then
    cat "$LIKESOFT_SERVER_JSON"
  else
    panel_warn_log "No server metadata found at ${LIKESOFT_SERVER_JSON}"
  fi

  if [[ -f "$LIKESOFT_LOCAL_MANIFEST" ]]; then
    echo
    echo "[installed-modules]"
    cat "$LIKESOFT_LOCAL_MANIFEST"
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
          panel_run_module "$module" install "${PHP_VERSION:-8.3}" "$@"
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
        panel_run_module "$module" remove "${1:-${PHP_VERSION:-8.3}}" "${@:2}"
      else
        panel_run_module "$module" remove "$@"
      fi
      ;;
    update)
      if [[ $# -gt 0 ]]; then
        local module="$1"
        shift || true
        if [[ "$module" == "php" ]]; then
          panel_run_module "$module" update "${1:-${PHP_VERSION:-8.3}}" "${@:2}"
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
    user:create)
      panel_run_runtime_script "create-admin-user.sh" "$@"
      ;;
    ssh:disable-root)
      panel_run_runtime_script "disable-root-login.sh" "$@"
      ;;
    *)
      panel_die "Unknown command: ${command}"
      ;;
  esac
}
