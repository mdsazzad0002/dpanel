#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || $# -lt 1 ]]; then
  cat <<'EOF'
Usage: create-admin-user.sh <username> [password] [email] [ssh-key] [shell] [disable-root=true|false]

Preferred form, so the password never reaches a command line:
  DPANEL_ADMIN_PASSWORD='secret' create-admin-user.sh <username> '' <email>
EOF
  [[ $# -ge 1 ]] && exit 0 || exit 64
fi

# A password given as an argument is readable through /proc by every local
# account for as long as this script runs. The environment variable is the
# supported path; the positional form stays for older callers.
password="${DPANEL_ADMIN_PASSWORD:-${2:-}}"
if [[ -z "${DPANEL_ADMIN_PASSWORD:-}" && -n "${2:-}" ]]; then
  echo "[WARN] Passing the password as an argument exposes it to other local users. Use DPANEL_ADMIN_PASSWORD instead." >&2
fi

drust_require_python
body="$(DPANEL_ADMIN_PASSWORD="$password" python3 - "$1" "${3:-}" "${4:-}" "${5:-}" "${6:-}" <<'PY'
import json, os, sys

a = sys.argv[1:]


def value(index):
    return a[index] if len(a) > index and a[index] else None


print(json.dumps({
    "username": a[0],
    "password": os.environ.get("DPANEL_ADMIN_PASSWORD") or None,
    "email": value(1),
    "ssh_key": value(2),
    "shell": value(3),
    "disable_root": (value(4) or "true").lower() in {"1", "true", "yes"},
}))
PY
)"
if ! drust_api_post /api/v1/create-admin-user "$body"; then
  echo "[WARN] Admin user creation skipped because the drust API returned an error." >&2
  exit 0
fi
