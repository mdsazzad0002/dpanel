#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck disable=SC1091
source "${ROOT}/core/package-manager.sh"

assert_family() {
  local distro="$1" expected="$2" actual
  DISTRO="$distro"
  actual="$(pkg_distro_family)"
  [[ "$actual" == "$expected" ]] || {
    printf 'expected %s => %s, got %s\n' "$distro" "$expected" "$actual" >&2
    return 1
  }
}

for distro in ubuntu debian; do
  assert_family "$distro" debian
done

for distro in rhel centos rocky almalinux fedora; do
  assert_family "$distro" rpm
done

assert_family alpine unknown
assert_family arch unknown
assert_family opensuse-leap unknown

printf 'supported distro mapping tests passed.\n'
