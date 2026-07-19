#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/logs.sh"

dscript_info "Configuring PHP..."

# Common PHP settings for panel operation
apply_php_config() {
  local version="$1"
  local conf_dir="/etc/php/${version}/fpm/conf.d"
  local cli_dir="/etc/php/${version}/cli/conf.d"

  if [[ -d "$conf_dir" ]]; then
    # Increase memory and execution limits
    cat > "${conf_dir}/99-panel.ini" <<'INI'
memory_limit = 256M
max_execution_time = 300
upload_max_filesize = 128M
post_max_size = 128M
max_file_uploads = 50
max_input_vars = 5000
date.timezone = UTC
INI
  fi
}

for ver in 7.4 8.0 8.2 8.3 8.4 8.5; do
  if [[ -d "/etc/php/${ver}/fpm" ]]; then
    apply_php_config "$ver"
    dscript_service_restart "php${ver}-fpm"
  fi
done

dscript_info "PHP configuration applied."
