#!/usr/bin/env bash
# Optional Docker add-on for dPanel. The main installer (installer.sh) never
# installs Docker; run this only on a server that needs containers.
#
#   curl -fsSL https://raw.githubusercontent.com/mdsazzad0002/dpanel/main/docker-installer.sh -o docker-installer.sh
#   sudo bash docker-installer.sh             # check the server, show the cost, ask, install
#   sudo bash docker-installer.sh status
#   sudo bash docker-installer.sh remove [--purge]
#
# On a dPanel server this is the same as `sudo dpanel docker`. See docs/docker.md.
set -euo pipefail

DSCRIPT_DIR="${DSCRIPT_DIR:-/var/www/dscript}"
script="${DSCRIPT_DIR}/scripts/docker-installer.sh"

if [[ ! -f "$script" ]]; then
  printf '[ERROR] dPanel is not installed here, or it is older than the Docker add-on.\n' >&2
  printf '        Install dPanel with installer.sh first, or update it: sudo dpanel chain update\n' >&2
  exit 1
fi
exec bash "$script" "$@"
