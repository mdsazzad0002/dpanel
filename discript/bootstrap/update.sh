#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# shellcheck disable=SC1091
source "${SCRIPT_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_DOWNLOADED_PACKAGE_MANAGER:-${SCRIPT_DIR}/../core/package-manager.sh}"

PANEL_BOOTSTRAP_MODE="update" panel_bootstrap "$@"
