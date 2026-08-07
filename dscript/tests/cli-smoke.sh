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
expect_contains "$("$CLI" module list)" 'redis'
expect_contains "$("$CLI" --dry-run redis reinstall)" '[DRY-RUN] module redis reinstall'
expect_contains "$("$CLI" --dry-run chain install redis,php)" '[DRY-RUN] chain install: redis,php'
expect_contains "$("$CLI" filemanager exists "$ROOT")" 'folder exists'
"$CLI" doctor >/dev/null

printf 'dscript CLI smoke tests passed.\n'
