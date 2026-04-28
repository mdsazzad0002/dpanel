#!/usr/bin/env bash
set -euo pipefail

INSTALL_CONTEXT_FILE="${DOCS_DIR}/INSTALL_CONTEXT.md"
INSTALL_STATE_FILE="${STATE_DIR}/install-state.env"

if [[ ! -f "$INSTALL_STATE_FILE" || ! -s "$INSTALL_STATE_FILE" ]]; then
  cat > "$INSTALL_STATE_FILE" <<EOF
PROJECT_PATH=/var/www/serverpanel
INSTALL_MODE=fresh
SERVER_TYPE=auto
EOF
fi

if [[ ! -f "$INSTALL_CONTEXT_FILE" || ! -s "$INSTALL_CONTEXT_FILE" ]]; then
  cat > "$INSTALL_CONTEXT_FILE" <<EOF
# INSTALL CONTEXT
- project path: /var/www/serverpanel
- install mode: fresh
- server type: auto
EOF
fi

