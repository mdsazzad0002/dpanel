#!/usr/bin/env bash
# Load dscript libraries from a source checkout or an installed runtime.

DSCRIPT_MODULES_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DSCRIPT_REPOSITORY_ROOT="$(cd "${DSCRIPT_MODULES_DIR}/../.." && pwd)"

if [[ -f "${DSCRIPT_REPOSITORY_ROOT}/bootstrap/core.sh" ]]; then
  DPANEL_RUNTIME_DIR="$DSCRIPT_REPOSITORY_ROOT"
  export DPANEL_RUNTIME_DIR
  # shellcheck disable=SC1091
  source "${DSCRIPT_REPOSITORY_ROOT}/bootstrap/core.sh"
  # shellcheck disable=SC1091
  source "${DSCRIPT_REPOSITORY_ROOT}/core/package-manager.sh"
else
  DPANEL_BASE_DIR="${DPANEL_BASE_DIR:-/opt/dpanel}"
  DPANEL_RUNTIME_DIR="${DPANEL_RUNTIME_DIR:-${DPANEL_BASE_DIR}/runtime}"
  # shellcheck disable=SC1091
  source "${DPANEL_RUNTIME_DIR}/core.sh"
  # shellcheck disable=SC1091
  source "${DPANEL_RUNTIME_DIR}/package-manager.sh"
fi
