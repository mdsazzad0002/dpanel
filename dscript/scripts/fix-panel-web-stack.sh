#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/_drust-api.sh"

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" || $# -lt 1 ]]; then
  echo "Usage: $0 <domain> [backend-port] [frontend-port] [--app-dir PATH] [--conf-name NAME] [--alias DOMAIN] [--no-www] [--client-max-body-size SIZE]"
  [[ $# -ge 1 ]] && exit 0 || exit 64
fi
drust_require_python
body="$(python3 - "$@" <<'PY'
import json, sys
a = sys.argv[1:]
data = {"domain": a.pop(0), "backend_port": 8080, "frontend_port": 80, "aliases": [], "no_www": False}
if a and a[0].isdigit(): data["backend_port"] = int(a.pop(0))
if a and a[0].isdigit(): data["frontend_port"] = int(a.pop(0))
i = 0
while i < len(a):
    key = a[i]
    if key == "--no-www": data["no_www"] = True; i += 1; continue
    names = {"--app-dir":"app_dir", "--conf-name":"conf_name", "--client-max-body-size":"client_max_body_size"}
    if key == "--alias" and i + 1 < len(a): data["aliases"].append(a[i+1]); i += 2; continue
    if key in names and i + 1 < len(a): data[names[key]] = a[i+1]; i += 2; continue
    raise SystemExit(f"Unknown or incomplete option: {key}")
print(json.dumps(data))
PY
)"
drust_api_post /api/v1/fix-panel-web-stack "$body"
