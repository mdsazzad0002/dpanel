#!/usr/bin/env bash
set -euo pipefail

# Install an isolated phpMyAdmin sign-on configuration without modifying an
# existing phpMyAdmin config.  This is safe to run repeatedly.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TEMPLATE_ROOT="${REPO_ROOT}/repository/templates/phpmyadmin"
if [[ ! -f "${TEMPLATE_ROOT}/config.inc.php" && -f "${REPO_ROOT}/templates/phpmyadmin/config.inc.php" ]]; then
    TEMPLATE_ROOT="${REPO_ROOT}/templates/phpmyadmin"
fi
if [[ ! -f "${TEMPLATE_ROOT}/config.inc.php" && -n "${DPANEL_DOWNLOADED_TEMPLATES_DIR:-}" ]]; then
    TEMPLATE_ROOT="${DPANEL_DOWNLOADED_TEMPLATES_DIR}/phpmyadmin"
fi
TARGET_ROOT="${PHPMYADMIN_SIGNON_ROOT:-/var/www/phpmyadmin}"
SOURCE_ROOT="${PHPMYADMIN_ROOT:-}"
PANEL_DOMAIN="${PANEL_DOMAIN:-localhost}"
PANEL_PORT="${PANEL_PORT:-80}"
PANEL_APP_DIR="${PANEL_APP_DIR:-/var/www/dpanel}"
PUBLIC_PATH="/${PHPMYADMIN_URL_PATH:-phpmyadmin}"
# Used when no phpMyAdmin is installed on the host (Ubuntu/Debian without the
# phpmyadmin package). Bump both together; the .sha256 is also checked.
PHPMYADMIN_VERSION="${PHPMYADMIN_VERSION:-5.2.3}"
PHPMYADMIN_SHA256="${PHPMYADMIN_SHA256:-12ba1c425fa4071abbd4e7668c9ebdeac0b0755a467a6d6d5026122bb47c102b}"

log() { printf '[phpmyadmin-signon] %s\n' "$*"; }
die() { log "$*" >&2; exit 1; }
generate_secret() {
    if command -v openssl >/dev/null 2>&1; then openssl rand -hex 32; else date +%s%N | sha256sum | awk '{print $1}'; fi
}

download() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL --retry 3 --connect-timeout 10 "$1" -o "$2"
    elif command -v wget >/dev/null 2>&1; then
        wget -q --tries=3 --timeout=10 -O "$2" "$1"
    else
        die "curl or wget is required to download phpMyAdmin."
    fi
}

# Fetches the official release into TARGET_ROOT. The setup wizard and examples
# are left out: the instance is configured only through config.inc.php.
install_phpmyadmin_release() {
    local name="phpMyAdmin-${PHPMYADMIN_VERSION}-all-languages"
    local url="https://files.phpmyadmin.net/phpMyAdmin/${PHPMYADMIN_VERSION}/${name}.tar.gz"
    local tmp
    tmp="$(mktemp -d)"
    log "phpMyAdmin is not installed; downloading ${PHPMYADMIN_VERSION}."
    download "$url" "${tmp}/pma.tar.gz" || { rm -rf "$tmp"; die "Unable to download ${url}"; }
    if ! printf '%s  %s\n' "$PHPMYADMIN_SHA256" "${tmp}/pma.tar.gz" | sha256sum -c --status; then
        rm -rf "$tmp"
        die "phpMyAdmin ${PHPMYADMIN_VERSION} checksum mismatch."
    fi
    tar -xzf "${tmp}/pma.tar.gz" -C "$tmp"
    rm -rf "${tmp}/${name}/setup" "${tmp}/${name}/examples"
    mkdir -p "$TARGET_ROOT"
    cp -a "${tmp}/${name}/." "${TARGET_ROOT}/"
    chown -R root:root "$TARGET_ROOT"
    rm -rf "$tmp"
}

upsert_env() {
    local file="$1" key="$2" value="$3" tmp
    [[ -f "$file" ]] || return 0
    tmp="$(mktemp)"
    awk -v key="$key" -v value="$value" '
        BEGIN { found = 0 }
        $0 ~ "^" key "=" { print key "=" value; found = 1; next }
        { print }
        END { if (!found) print key "=" value }
    ' "$file" > "$tmp"
    chmod --reference="$file" "$tmp" 2>/dev/null || true
    chown --reference="$file" "$tmp" 2>/dev/null || true
    mv "$tmp" "$file"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --root) [[ $# -ge 2 ]] || die '--root requires a path'; TARGET_ROOT="$2"; shift 2 ;;
        -h|--help) printf 'Usage: %s [--root PATH]\n' "$(basename "$0")"; exit 0 ;;
        *) die "Unknown option: $1" ;;
    esac
done

[[ -f "${TEMPLATE_ROOT}/config.inc.php" ]] || die "phpMyAdmin templates are missing."
if [[ -z "$SOURCE_ROOT" ]]; then
    # Only a directory with the app counts; a previous run may have left just
    # the config files in TARGET_ROOT.
    for candidate in /usr/share/phpmyadmin /var/www/phpmyadmin /var/www/html/phpmyadmin; do
        if [[ -f "${candidate}/index.php" ]]; then SOURCE_ROOT="$candidate"; break; fi
    done
fi

APP_URL="${PANEL_URL:-}"
if [[ -z "$APP_URL" && -f "${PANEL_APP_DIR}/.env" ]]; then
    APP_URL="$(sed -n 's/^APP_URL=//p' "${PANEL_APP_DIR}/.env" | tail -n 1 | tr -d '\r\"')"
fi
if [[ -z "$APP_URL" ]]; then
    scheme=http
    [[ "$PANEL_PORT" == 443 ]] && scheme=https
    APP_URL="${scheme}://${PANEL_DOMAIN}"
    [[ "$PANEL_PORT" != 80 && "$PANEL_PORT" != 443 ]] && APP_URL="${APP_URL}:${PANEL_PORT}"
fi
PUBLIC_URL="${APP_URL%/}${PUBLIC_PATH}"
mkdir -p "${TARGET_ROOT}" "${TARGET_ROOT}/config.d"

# Reuse the installed phpMyAdmin application as an additional instance. The
# source tree (including its config) is never changed.
if [[ -n "$SOURCE_ROOT" && -d "$SOURCE_ROOT" && "$SOURCE_ROOT" != "$TARGET_ROOT" ]]; then
    # Debian/Ubuntu phpMyAdmin packages use relative symlinks into
    # /usr/share/javascript. An isolated copy changes their resolution base,
    # so dereference them while copying to keep every CSS/JS asset usable.
    # Remove only symlinks from the generated target first, allowing upgrades
    # from older runs where a link now needs to become a real file/directory.
    find "$TARGET_ROOT" -type l -delete
    cp -aL "${SOURCE_ROOT}/." "${TARGET_ROOT}/"
elif [[ ! -f "${TARGET_ROOT}/index.php" ]]; then
    install_phpmyadmin_release
fi

if [[ -f "${TARGET_ROOT}/libraries/vendor_config.php" ]]; then
    php -r '
        $path = $argv[1];
        $config = $argv[2];
        $content = file_get_contents($path);
        if ($content === false) {
            fwrite(STDERR, "Unable to read vendor_config.php\n");
            exit(1);
        }
        $replacement = "'"'"'configFile'"'"' => " . var_export($config, true) . ",";
        $updated = preg_replace("/'"'"'configFile'"'"'\\s*=>\\s*[^,]+,/", $replacement, $content, 1);
        if (is_string($updated) && $updated === $content && str_contains($content, $replacement)) {
            exit(0);
        }
        if (! is_string($updated) || $updated === $content) {
            fwrite(STDERR, "Unable to update phpMyAdmin configFile path\n");
            exit(1);
        }
        if (file_put_contents($path, $updated) === false) {
            fwrite(STDERR, "Unable to write vendor_config.php\n");
            exit(1);
        }
    ' "${TARGET_ROOT}/libraries/vendor_config.php" "${TARGET_ROOT}/config.inc.php"
fi

if [[ -f "${TARGET_ROOT}/templates/server/databases/index.twig" ]]; then
    php -r '
        $path = $argv[1];
        $content = file_get_contents($path);
        if ($content === false) {
            fwrite(STDERR, "Unable to read server databases template\n");
            exit(1);
        }

        $needle = "{% if is_create_database_shown %}";
        $replacement = "{% if is_create_database_shown and has_create_database_privileges %}";
        if (! str_contains($content, $needle) && str_contains($content, $replacement)) {
            exit(0);
        }
        $updated = str_replace($needle, $replacement, $content);
        if (! is_string($updated) || $updated === $content) {
            fwrite(STDERR, "Unable to update create database visibility\n");
            exit(1);
        }
        if (file_put_contents($path, $updated) === false) {
            fwrite(STDERR, "Unable to write server databases template\n");
            exit(1);
        }
    ' "${TARGET_ROOT}/templates/server/databases/index.twig"
fi

# The standalone config is intentionally a new file. Never write to
# /etc/phpmyadmin/config.inc.php or to an existing application installation.
sed -e "s#{{blowfish_secret}}#${PHPMYADMIN_BLOWFISH_SECRET:-$(generate_secret)}#" \
    -e "s#{{phpmyadmin_signon_url}}#${PUBLIC_URL}/phpmyadminsignin.php#g" \
    "${TEMPLATE_ROOT}/config.inc.php" > "${TARGET_ROOT}/config.inc.php"
if chown root:www-data "${TARGET_ROOT}/config.inc.php" 2>/dev/null; then
    chmod 640 "${TARGET_ROOT}/config.inc.php"
else
    chmod 644 "${TARGET_ROOT}/config.inc.php"
fi
install -o root -g www-data -m 640 "${TEMPLATE_ROOT}/phpmyadminsignin.php" "${TARGET_ROOT}/phpmyadminsignin.php"

# Reassert sensitive-file permissions on every idempotent run. phpMyAdmin
# deliberately refuses world-writable configuration files.
chown root:www-data "${TARGET_ROOT}/config.inc.php" "${TARGET_ROOT}/phpmyadminsignin.php"
chmod 640 "${TARGET_ROOT}/config.inc.php" "${TARGET_ROOT}/phpmyadminsignin.php"

upsert_env "${PANEL_APP_DIR}/.env" PHPMYADMIN_URL "${PUBLIC_URL}/"
if [[ -f "${PANEL_APP_DIR}/artisan" ]] && command -v php >/dev/null 2>&1; then
    (cd "$PANEL_APP_DIR" && php artisan config:clear >/dev/null) || true
fi
log "Configured isolated sign-on instance at ${TARGET_ROOT} (existing config untouched)."
log "phpMyAdmin URL: ${PUBLIC_URL}/"
