#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$TEST_ROOT"' EXIT

(
  export DPANEL_BASE_DIR="$TEST_ROOT/base"
  export DPANEL_RUNTIME_DIR="$TEST_ROOT/base/runtime"
  export DPANEL_LAUNCHER="$TEST_ROOT/bin/panel"
  export DPANEL_DUAL_LAUNCHER="$TEST_ROOT/bin/dpanel"

  # shellcheck disable=SC1091
  source "$ROOT/bootstrap/core.sh"
  # shellcheck disable=SC1091
  source "$ROOT/core/package-manager.sh"
  panel_install_runtime_assets
)

[[ -x "$TEST_ROOT/base/runtime/commands.sh" ]]
[[ -x "$TEST_ROOT/base/runtime/launcher.sh" ]]
[[ -x "$TEST_ROOT/bin/dpanel" ]]
[[ -x "$TEST_ROOT/base/repository/modules/nginx/install.sh" ]]
[[ -f "$TEST_ROOT/base/repository/manifests/modules.json" ]]
[[ "$(DPANEL_BASE_DIR="$TEST_ROOT/base" DPANEL_RUNTIME_DIR="$TEST_ROOT/base/runtime" "$TEST_ROOT/bin/dpanel" --version)" == "dscript 2.0.0" ]]

# A refresh launched from the installed runtime must reuse sibling assets and
# must continue resolving modules from the installed local repository.
(
  export DPANEL_BASE_DIR="$TEST_ROOT/base"
  export DPANEL_RUNTIME_DIR="$TEST_ROOT/base/runtime"
  export DPANEL_LAUNCHER="$TEST_ROOT/bin/panel"
  export DPANEL_DUAL_LAUNCHER="$TEST_ROOT/bin/dpanel"

  cd "$TEST_ROOT/base/runtime"
  # shellcheck disable=SC1091
  source core.sh
  # shellcheck disable=SC1091
  source package-manager.sh
  panel_install_runtime_assets
  [[ "$(panel_local_module_script nginx install)" == "$TEST_ROOT/base/repository/modules/nginx/install.sh" ]]
  panel_sync_manifest
  [[ -f "$TEST_ROOT/base/cache/modules.manifest.json" ]]
)

printf 'dscript runtime reinstall test passed.\n'
