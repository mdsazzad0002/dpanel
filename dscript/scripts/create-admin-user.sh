#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || $# -lt 1 ]]; then
  echo "Usage: $0 <username> [password] [email] [ssh-key] [shell] [disable-root=true|false]"
  [[ $# -ge 1 ]] && exit 0 || exit 64
fi

drust_require_python
body="$(python3 - "$@" <<'PY'
import json, sys
a = sys.argv[1:]
def value(index):
    return a[index] if len(a) > index and a[index] else None
print(json.dumps({
    "username": a[0], "password": value(1), "email": value(2),
    "ssh_key": value(3), "shell": value(4),
    "disable_root": (value(5) or "true").lower() in {"1", "true", "yes"},
}))
PY
)"
drust_api_post /api/v1/create-admin-user "$body"
