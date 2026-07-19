#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/helpers.sh"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../shared/logs.sh"

dscript_info "Verifying system state..."

PASS=0
FAIL=0

check() {
  local name="$1"
  local result="$2"
  if [[ "$result" == "ok" ]]; then
    echo "  [PASS] ${name}"
    PASS=$((PASS + 1))
  else
    echo "  [FAIL] ${name}: ${result}"
    FAIL=$((FAIL + 1))
  fi
}

# Check services
for svc in apache2 httpd nginx mariadb mysql redis-server redis; do
  if systemctl list-unit-files "${svc}.service" >/dev/null 2>&1; then
    if systemctl is-active --quiet "$svc" 2>/dev/null; then
      check "$svc" "ok"
    else
      check "$svc" "not running"
    fi
  fi
done

# Check PHP
for ver in 7.4 8.0 8.2 8.3 8.4 8.5; do
  if command -v "php${ver}" >/dev/null 2>&1; then
    check "PHP ${ver}" "ok"
  fi
done

# Check panel directory
if [[ -d /var/www/dpanel ]]; then
  check "Panel directory" "ok"
else
  check "Panel directory" "missing"
fi

echo ""
echo "Verification: ${PASS} passed, ${FAIL} failed"
