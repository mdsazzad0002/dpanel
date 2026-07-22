#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_drust-api.sh
source "${SCRIPT_DIR}/_drust-api.sh"

usage() {
  cat <<'EOF'
Usage:
  fix-permissions --all
  fix-permissions --user USERNAME [--path /home/USERNAME/public_html]
  fix-permissions --path /home/USERNAME/public_html
EOF
}

all=false
username=""
root_path=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --all)
      all=true
      shift
      ;;
    --user|--username)
      username="${2:-}"
      [[ -n "$username" ]] || { usage >&2; exit 1; }
      shift 2
      ;;
    --path|--root-path)
      root_path="${2:-}"
      [[ -n "$root_path" ]] || { usage >&2; exit 1; }
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf '[ERROR] Unknown option: %s\n' "$1" >&2
      usage >&2
      exit 1
      ;;
  esac
done

if [[ "$all" != true && -z "$username" && -z "$root_path" ]]; then
  usage >&2
  exit 1
fi

drust_require_python
body="$(python3 - "$all" "$username" "$root_path" <<'PY'
import json
import sys

all_value = sys.argv[1].lower() == "true"
username = sys.argv[2]
root_path = sys.argv[3]

payload = {"all": all_value}
if username:
    payload["username"] = username
if root_path:
    payload["root_path"] = root_path

print(json.dumps(payload))
PY
)"

drust_api_post /api/v1/filemanager/fix-permissions "$body"
