#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || $# -lt 3 ]]; then
  echo "Usage: $0 <action> <domain> <root-path> [php-version] [old-domain] [--alias DOMAIN] [--no-www] [--client-max-body-size SIZE]"
  [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]] && exit 0 || exit 64
fi
drust_require_python
body="$(python3 - "$@" <<'PY'
import json, sys
a = sys.argv[1:]
data = {"action": a.pop(0), "domain": a.pop(0), "root_path": a.pop(0), "php_version": "8.3", "aliases": [], "no_www": False}
if a and not a[0].startswith("--"): data["php_version"] = a.pop(0)
if a and not a[0].startswith("--"): data["old_domain"] = a.pop(0)
i = 0
while i < len(a):
    if a[i] == "--no-www": data["no_www"] = True; i += 1; continue
    if a[i] == "--alias" and i + 1 < len(a): data["aliases"].append(a[i+1]); i += 2; continue
    if a[i] == "--client-max-body-size" and i + 1 < len(a): data["client_max_body_size"] = a[i+1]; i += 2; continue
    raise SystemExit(f"Unknown or incomplete option: {a[i]}")
print(json.dumps(data))
PY
)"
drust_api_post /api/v1/sync-vhost "$body"
