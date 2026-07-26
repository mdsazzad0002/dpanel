#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEST_ROOT="$(mktemp -d)"
trap 'rm -rf -- "$TEST_ROOT"' EXIT

cat > "$TEST_ROOT/apt-get" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$TEST_ROOT/apt.log"
if [[ "$*" == *"php8.4-cli"* ]]; then
  exit 1
fi
exit 0
SH
chmod +x "$TEST_ROOT/apt-get"

cat > "$TEST_ROOT/apt-cache" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "$1" == "show" ]]; then
  if [[ "$2" == "php8.4-cli" || "$2" == "php8.4-common" || "$2" == "php8.4-fpm" ]]; then
    exit 1
  fi
  exit 0
fi
exit 0
SH
chmod +x "$TEST_ROOT/apt-cache"

cat > "$TEST_ROOT/add-apt-repository" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$TEST_ROOT/apt.log"
exit 0
SH
chmod +x "$TEST_ROOT/add-apt-repository"

cat > "$TEST_ROOT/id" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
if [[ "${1:-}" == "-u" ]]; then
  echo 0
  exit 0
fi
/usr/bin/id "$@"
SH
chmod +x "$TEST_ROOT/id"

export PATH="$TEST_ROOT:$PATH"
export DISTRO="ubuntu"
export TEST_ROOT

# shellcheck disable=SC1091
source "$ROOT/core/package-manager.sh"

pkg_ensure_php_repo 8.4
pkg_install_php_stack 8.4

if [[ ! -f "$TEST_ROOT/apt.log" ]]; then
  echo "Expected apt log file" >&2
  exit 1
fi

if ! grep -q 'ppa:ondrej/php' "$TEST_ROOT/apt.log"; then
  echo "Expected ondrej PHP repo setup entry" >&2
  exit 1
fi

if grep -q 'php8.4-cli' "$TEST_ROOT/apt.log"; then
  echo "Unsupported PHP package should have been skipped" >&2
  exit 1
fi

echo "php repo setup test passed."
