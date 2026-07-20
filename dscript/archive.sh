#!/usr/bin/env bash
# Build the archive consumed by /var/www/installer.sh.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTPUT="${1:-${SCRIPT_DIR}/../dscript.zip}"

command -v zip >/dev/null 2>&1 || {
  printf '[ERROR] zip is required to build the installer archive.\n' >&2
  exit 1
}

mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT"
(cd "$(dirname "$SCRIPT_DIR")" && zip -qr "$OUTPUT" "$(basename "$SCRIPT_DIR")" \
  -x 'dscript/.git/*' 'dscript/target/*' 'dscript/tests/*')
printf 'Created %s\n' "$OUTPUT"
