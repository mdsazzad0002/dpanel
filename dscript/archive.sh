#!/usr/bin/env bash
# Build the release archive consumed by /var/www/installer.sh.
# The remote release keeps dpanel, drust and dscript together in one archive.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RELEASE_ROOT="$(dirname "$SCRIPT_DIR")"
OUTPUT="${1:-${RELEASE_ROOT}/dscript.zip}"
TMP_DIR="$(mktemp -d)"

cleanup() {
  rm -rf "$TMP_DIR"
}
trap cleanup EXIT

command -v zip >/dev/null 2>&1 || {
  printf '[ERROR] zip is required to build the release archive.\n' >&2
  exit 1
}

mkdir -p "$(dirname "$OUTPUT")"
rm -f "$OUTPUT"

DSCRIPT_VERSION="$(awk -F\" '/^DSCRIPT_VERSION=/ { print $2; exit }' "${RELEASE_ROOT}/dscript/core/commands.sh")"
DRUST_VERSION="$(awk -F\" '/^version = / { print $2; exit }' "${RELEASE_ROOT}/drust/Cargo.toml")"
BUILD_DATE="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
GIT_COMMIT="$(git -C "$RELEASE_ROOT" rev-parse --short HEAD 2>/dev/null || true)"

cat > "${TMP_DIR}/release.json" <<EOF
{
  "name": "dpanel-release",
  "build_date": "${BUILD_DATE}",
  "git_commit": "${GIT_COMMIT}",
  "components": {
    "dpanel": {
      "path": "dpanel"
    },
    "drust": {
      "path": "drust",
      "version": "${DRUST_VERSION}"
    },
    "dscript": {
      "path": "dscript",
      "version": "${DSCRIPT_VERSION}"
    }
  }
}
EOF

#
# Package all release components side by side:
#   dpanel/
#   drust/
#   dscript/
#
(cd "$RELEASE_ROOT" && zip -qr "$OUTPUT" dpanel drust dscript \
    README.md CHANGELOG.md LICENSE LICENSE.md SECURITY.md \
  -x \
    '*/.git/*' \
    '*/.mimocode/*' \
    'dpanel/.env' \
    'dpanel/.phpunit.result.cache' \
    'dpanel/node_modules/*' \
    'dpanel/storage/*' \
    'dpanel/vendor/*' \
    'drust/target/*' \
    'dscript/tests/*')

(cd "$TMP_DIR" && zip -q "$OUTPUT" release.json)

printf 'Created %s\n' "$OUTPUT"
