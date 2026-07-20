#!/usr/bin/env bash
set -euo pipefail

SCRIPT_NAME="$(basename "$0")"
DEFAULT_BASE_URL="https://installer.likesoftbd.com"
BASE_URL="${PANEL_INSTALL_BASE_URL:-${DPANEL_BASE_URL:-$DEFAULT_BASE_URL}}"
if [[ -n "${PANEL_DSCRIPT_BASE_URL:-}" ]]; then
  DSCRIPT_BASE_URL="${PANEL_DSCRIPT_BASE_URL%/}"
elif [[ "${BASE_URL%/}" == */dscript ]]; then
  DSCRIPT_BASE_URL="${BASE_URL%/}"
else
  DSCRIPT_BASE_URL="${BASE_URL%/}/dscript"
fi
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
commands="$TMP_DIR/commands.sh"
downloaded_scripts="$TMP_DIR/scripts"
downloaded_templates="$TMP_DIR/templates"
downloaded_repository=""

SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "${SOURCE_DIR}/bootstrap/core.sh" && -f "${SOURCE_DIR}/core/package-manager.sh" && -f "${SOURCE_DIR}/core/commands.sh" ]]; then
  # A checkout can install directly without a network round-trip.
  bootstrap_core="${SOURCE_DIR}/bootstrap/core.sh"
  package_manager="${SOURCE_DIR}/core/package-manager.sh"
  commands="${SOURCE_DIR}/core/commands.sh"
  downloaded_scripts="${SOURCE_DIR}/scripts"
  downloaded_templates="${SOURCE_DIR}/repository/templates"
  downloaded_repository="${SOURCE_DIR}/repository"
else
  download_file "$DSCRIPT_BASE_URL/bootstrap/core.sh" "$bootstrap_core"
  download_file "$DSCRIPT_BASE_URL/core/package-manager.sh" "$package_manager"
  download_file "$DSCRIPT_BASE_URL/core/commands.sh" "$commands"

  mkdir -p "$downloaded_scripts" "$downloaded_templates"
  for script_name in \
    _drust-api create-admin-user create-demo-site database-request disable-root-login \
    fix-dpanel-root fix-panel-web-stack fix-web-stack install-roundcube-dovecot-mysql \
    issue-ssl php-config-apply php-detect-config php-detect-extensions php-detect-versions \
    reset-web-stack sync-vhost; do
    download_file "$DSCRIPT_BASE_URL/scripts/${script_name}.sh" "$downloaded_scripts/${script_name}.sh"
  done

  for template_name in \
    apache/site.conf fail2ban/jail.local nginx/laravel.conf php/pool.conf \
    phpmyadmin/autologin.blade.php phpmyadmin/config.inc.php phpmyadmin/phpmyadminsignin.php \
    roundcube/config.inc.php supervisor/panel-agent.conf webmail/autologin.blade.php; do
    download_file "$DSCRIPT_BASE_URL/repository/templates/${template_name}" "$downloaded_templates/${template_name}"
  done
fi

export DPANEL_BASE_URL="$DSCRIPT_BASE_URL"
export DPANEL_DOWNLOADED_CORE="$bootstrap_core"
export DPANEL_DOWNLOADED_PACKAGE_MANAGER="$package_manager"
export DPANEL_DOWNLOADED_COMMANDS="$commands"
export DPANEL_DOWNLOADED_SCRIPTS_DIR="$downloaded_scripts"
export DPANEL_DOWNLOADED_TEMPLATES_DIR="$downloaded_templates"
export DPANEL_DOWNLOADED_REPOSITORY_DIR="$downloaded_repository"

source "$bootstrap_core"
source "$package_manager"
source "$commands"

if [[ "${1:-}" == "panel" ]]; then
  shift || true
  export PANEL_BOOTSTRAP_MODE="${1:-info}"
  if [[ $# -gt 0 ]]; then
    shift || true
  fi
fi

if [[ "${PANEL_BOOTSTRAP_MODE:-}" != "" ]]; then
  dscript_cli chain "$PANEL_BOOTSTRAP_MODE" "$@"
elif [[ $# -eq 0 ]]; then
  dscript_cli chain install
else
  dscript_cli "$@"
fi
