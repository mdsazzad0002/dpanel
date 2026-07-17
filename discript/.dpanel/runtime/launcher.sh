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
