#!/usr/bin/env bash
set -euo pipefail
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
STATE_DIR="$BASE_DIR/state"

cp -f "$STATE_DIR/install-state.env" "$STATE_DIR/install-state.env.bak" 2>/dev/null || true
cp -f "$STATE_DIR/completed-steps.env" "$STATE_DIR/completed-steps.env.bak" 2>/dev/null || true
cp -f "$STATE_DIR/last-failed-step.env" "$STATE_DIR/last-failed-step.env.bak" 2>/dev/null || true

echo "Rollback backup snapshots written under $STATE_DIR"

