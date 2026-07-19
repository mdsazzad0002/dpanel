#!/usr/bin/env bash
set -euo pipefail

VERSION=""

usage() {
    cat <<'EOF'
Usage:
  php-detect-config.sh --version 8.3
EOF
}

resolve_php_binary() {
    local version="$1"
    local digits="${version//./}"
    local candidate=""
    local current=""

    for candidate in \
        "/usr/bin/php${version}" \
        "/usr/local/bin/php${version}" \
        "/opt/cpanel/ea-php${digits}/root/usr/bin/php"
    do
        if [[ -x "${candidate}" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    for candidate in "php${version}" "php${digits}" "php"; do
        if ! command -v "${candidate}" >/dev/null 2>&1; then
            continue
        fi

        current="$("${candidate}" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
        if [[ "${current}" == "${version}" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    return 1
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --version)
            VERSION="${2:-}"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "[php-detect-config] Unknown argument: $1" >&2
            usage >&2
            exit 64
            ;;
    esac
done

if [[ -z "${VERSION}" || ! "${VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
    echo "[php-detect-config] Invalid or missing --version." >&2
    usage >&2
    exit 64
fi

PHP_BIN="$(resolve_php_binary "${VERSION}" || true)"
if [[ -z "${PHP_BIN}" ]]; then
    echo "[php-detect-config] PHP binary for version ${VERSION} not found." >&2
    exit 2
fi

"${PHP_BIN}" -r '
$toOnOff = static function ($value): string {
    $normalized = strtolower(trim((string) $value));
    if (in_array($normalized, ["", "0", "off", "false", "no"], true)) {
        return "Off";
    }

    return "On";
};

$payload = [
    "memory_limit" => (string) ini_get("memory_limit"),
    "upload_max_filesize" => (string) ini_get("upload_max_filesize"),
    "post_max_size" => (string) ini_get("post_max_size"),
    "max_execution_time" => (string) ini_get("max_execution_time"),
    "max_input_vars" => (string) ini_get("max_input_vars"),
    "display_errors" => $toOnOff((string) ini_get("display_errors")),
    "log_errors" => $toOnOff((string) ini_get("log_errors")),
    "allow_url_fopen" => $toOnOff((string) ini_get("allow_url_fopen")),
];

echo json_encode($payload, JSON_UNESCAPED_SLASHES);
' 2>/dev/null

