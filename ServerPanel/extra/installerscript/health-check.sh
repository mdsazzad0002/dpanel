#!/usr/bin/env bash
set -euo pipefail
BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOG="$BASE_DIR/logs/latest-health-check.md"

{
  echo "# Health Check"
  echo "- Time: $(date -u '+%Y-%m-%d %H:%M:%S UTC')"
  echo "- Host: $(hostname)"
  echo "- User: $(whoami)"
  echo "- Kernel: $(uname -sr)"
  echo "## Disk"
  df -h || true
  echo "## Memory"
  free -m || true
} > "$LOG"

echo "Health check written to: $LOG"

