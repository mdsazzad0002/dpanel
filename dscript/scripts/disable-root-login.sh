#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

if ! drust_api_post /api/v1/disable-root-login '{}'; then
  echo "[WARN] SSH hardening step skipped because the drust API returned an error." >&2
  exit 0
fi
