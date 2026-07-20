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

log() { printf '[phpmyadmin-signon] %s\n' "$*"; }
die() { log "$*" >&2; exit 1; }
generate_secret() {
    if command -v openssl >/dev/null 2>&1; then openssl rand -hex 32; else date +%s%N | sha256sum | awk '{print $1}'; fi
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
    for candidate in /usr/share/phpmyadmin /var/www/phpmyadmin /var/www/html/phpmyadmin; do
        if [[ -d "$candidate" ]]; then SOURCE_ROOT="$candidate"; break; fi
    done
fi

APP_URL="${PANEL_URL:-}"
if [[ -z "$APP_URL" && -f "${PANEL_APP_DIR}/.env" ]]; then
    APP_URL="$(sed -n 's/^APP_URL=//p' "${PANEL_APP_DIR}/.env" | tail -n 1 | tr -d '\r\"')"
fi
if [[ -z "$APP_URL" ]]; then
    scheme=http
    [[ "$PANEL_PORT" == 443 || "$PANEL_PORT" == 2083 ]] && scheme=https
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
        $updated = str_replace($needle, $replacement, $content);
        $updated = preg_replace("/\\s*{%\\s*else\\s*%}\\s*<span class=\"text-danger\">\\{\\{ get_icon\\('s_error', 'No privileges to create databases'\\|trans\\) \\}\\}<\\/span>/", "", (string) $updated, 1);

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
install -m 644 "${TEMPLATE_ROOT}/phpmyadminsignin.php" "${TARGET_ROOT}/phpmyadminsignin.php"

if [[ "${PHPMYADMIN_CONFIGURE_WEB_SERVER:-true}" == true ]] && command -v a2enconf >/dev/null 2>&1 && [[ -d /etc/apache2/conf-available ]]; then
    APACHE_CONF=/etc/apache2/conf-available/dpanel-phpmyadmin.conf
    cat > "$APACHE_CONF" <<EOF
Alias ${PUBLIC_PATH} ${TARGET_ROOT}
<Directory ${TARGET_ROOT}>
    Options FollowSymLinks
    DirectoryIndex index.php
    AllowOverride All
    Require all granted
</Directory>
EOF
    a2enconf dpanel-phpmyadmin >/dev/null
    apache2ctl configtest >/dev/null
    systemctl reload apache2
fi

upsert_env "${PANEL_APP_DIR}/.env" PHPMYADMIN_URL "${PUBLIC_URL}/"
if [[ -f "${PANEL_APP_DIR}/artisan" ]] && command -v php >/dev/null 2>&1; then
    (cd "$PANEL_APP_DIR" && php artisan config:clear >/dev/null) || true
fi
log "Configured isolated sign-on instance at ${TARGET_ROOT} (existing config untouched)."
log "phpMyAdmin URL: ${PUBLIC_URL}/"
