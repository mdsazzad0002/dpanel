#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CLI="${ROOT}/dpanel"

expect_contains() {
  local output="$1" expected="$2"
  [[ "$output" == *"$expected"* ]] || {
    printf 'Expected output to contain: %s\nActual output:\n%s\n' "$expected" "$output" >&2
    exit 1
  }
}

expect_contains "$("$CLI" --version)" 'dscript 2.0.0'
expect_contains "$("$CLI" help)" 'chain <install|update|verify|repair>'
expect_contains "$("$CLI" module list)" 'nginx'
expect_contains "$("$CLI" script list)" 'reset-web-stack'
expect_contains "$("$CLI" --dry-run nginx reinstall)" '[DRY-RUN] module nginx reinstall'
expect_contains "$("$CLI" --dry-run chain install nginx,php)" '[DRY-RUN] chain install: nginx,php'
expect_contains "$("$CLI" --dry-run script run reset-web-stack --yes)" '[DRY-RUN] bash'
expect_contains "$("$CLI" filemanager exists "$ROOT")" 'folder exists'
"$CLI" doctor >/dev/null

printf 'dscript CLI smoke tests passed.\n'
