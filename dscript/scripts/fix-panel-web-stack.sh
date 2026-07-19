#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -x "${SCRIPT_DIR}/../target/debug/dscript" ]]; then
  exec "${SCRIPT_DIR}/../target/debug/dscript" fix-panel-web-stack "$@"
fi
exec cargo run --quiet --manifest-path "${SCRIPT_DIR}/../Cargo.toml" -- fix-panel-web-stack "$@"
