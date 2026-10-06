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

cat > "$TEST_ROOT/curl" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
url="${*: -1}"
out=""
while [[ $# -gt 0 ]]; do
  if [[ "$1" == "-o" ]]; then out="$2"; shift; fi
  shift
done
if [[ "$url" == *launchpadcontent.net* && "${PPA_AVAILABLE:-1}" != "1" ]]; then
  exit 22
fi
if [[ -n "$out" && "$out" != "/dev/null" ]]; then
  printf 'key' > "$out"
fi
exit 0
SH
chmod +x "$TEST_ROOT/curl"

mkdir -p "$TEST_ROOT/sources" "$TEST_ROOT/keyrings"

export PATH="$TEST_ROOT:$PATH"
export DPANEL_APT_SOURCES_DIR="$TEST_ROOT/sources"
export DPANEL_APT_KEYRING_DIR="$TEST_ROOT/keyrings"
export DISTRO="ubuntu"
export TEST_ROOT

# shellcheck disable=SC1091
source "$ROOT/core/package-manager.sh"
pkg_require_root() { :; }
pkg_os_codename() { printf 'noble'; }

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

# Ubuntu release the PPA does not publish (26.04 resolute): use Sury and drop
# the stale PPA entry that would otherwise 404 on every apt update.
pkg_os_codename() { printf 'resolute'; }
: > "$TEST_ROOT/apt.log"
touch "$TEST_ROOT/sources/ondrej-ubuntu-php-resolute.sources"
PPA_AVAILABLE=0 pkg_ensure_php_repo 8.4

if grep -q 'ppa:ondrej/php' "$TEST_ROOT/apt.log"; then
  echo "PPA must not be added for a release it does not publish" >&2
  exit 1
fi
if [[ -e "$TEST_ROOT/sources/ondrej-ubuntu-php-resolute.sources" ]]; then
  echo "Stale ondrej PPA entry should have been removed" >&2
  exit 1
fi
if ! grep -q 'packages.sury.org/php/ resolute main' "$TEST_ROOT/sources/php.list"; then
  echo "Expected Sury PHP repo for resolute" >&2
  exit 1
fi
if [[ ! -s "$TEST_ROOT/keyrings/php-sury.gpg" ]]; then
  echo "Expected Sury keyring" >&2
  exit 1
fi

echo "php repo setup test passed."
