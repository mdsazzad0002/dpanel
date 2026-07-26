#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

usage() {
  echo "Usage: $0 <username> [password]"
}

username="${1:-}"
password="${2:-}"
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
body="$(python3 - "$username" "$password" <<'PY'
import json, sys
print(json.dumps({
    "action": "password",
    "username": sys.argv[1],
    "password": sys.argv[2],
}))
PY
)"
drust_api_post /api/v1/filemanager/user "$body"
