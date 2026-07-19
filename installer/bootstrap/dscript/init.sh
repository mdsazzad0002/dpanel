#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo "[INFO] dscript init starting..."

# Load environment
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/env.sh"

# Load shared helpers
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/shared/helpers.sh"
source "${SCRIPT_DIR}/shared/logs.sh"
source "${SCRIPT_DIR}/shared/paths.sh"

dscript_require_root
dscript_detect_os

# Run installation phases
echo "[INFO] Phase 1: Installing packages..."
bash "${SCRIPT_DIR}/install/packages.sh"

echo "[INFO] Phase 2: Installing PHP..."
bash "${SCRIPT_DIR}/install/php.sh"

echo "[INFO] Phase 3: Installing services..."
bash "${SCRIPT_DIR}/install/services.sh"

echo "[INFO] Phase 4: Configuring database..."
bash "${SCRIPT_DIR}/install/database.sh"

echo "[INFO] Phase 5: Applying configuration..."
bash "${SCRIPT_DIR}/config/main.sh"
bash "${SCRIPT_DIR}/config/php.sh"
bash "${SCRIPT_DIR}/config/security.sh"

echo "[INFO] dscript init completed successfully."
