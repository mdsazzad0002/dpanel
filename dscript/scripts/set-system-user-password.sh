#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

usage() {
  cat <<'EOF'
Usage: set-system-user-password.sh <username> [password]

Preferred form, so the password never reaches a command line:
  DPANEL_USER_PASSWORD='secret' set-system-user-password.sh <username>
EOF
}

username="${1:-}"
# An argument password is visible in /proc to every local account while this
# script runs; the environment variable is the supported path.
password="${DPANEL_USER_PASSWORD:-${2:-}}"
if [[ -z "${DPANEL_USER_PASSWORD:-}" && -n "${2:-}" ]]; then
  echo "[WARN] Passing the password as an argument exposes it to other local users. Use DPANEL_USER_PASSWORD instead." >&2
fi
if [[ -z "$username" || "$username" == "-h" || "$username" == "--help" ]]; then
  usage
  [[ -n "$username" ]] && exit 0 || exit 64
fi
if [[ ! "$username" =~ ^[a-z_][a-z0-9_-]*$ ]]; then
  echo "Invalid username: $username" >&2
  exit 64
fi

if [[ -z "$password" ]]; then
  [[ -t 0 && -t 1 ]] || {
    echo "Password is required for non-interactive use." >&2
    exit 64
  }

  confirm=""
  while true; do
    read -rsp "New shell password for ${username}: " password
    printf '\n'
    read -rsp "Confirm shell password: " confirm
    printf '\n'

    if [[ -z "$password" ]]; then
      echo "Password cannot be empty." >&2
      continue
    fi
    if [[ "$password" != "$confirm" ]]; then
      echo "Passwords do not match." >&2
      continue
    fi
    break
  done
fi

drust_require_python
body="$(DPANEL_USER_PASSWORD="$password" python3 - "$username" <<'PY'
import json, os, sys
print(json.dumps({
    "action": "password",
    "username": sys.argv[1],
    "password": os.environ.get("DPANEL_USER_PASSWORD", ""),
}))
PY
)"
drust_api_post /api/v1/filemanager/user "$body"
