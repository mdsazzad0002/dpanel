#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
TEMPLATE_ROOT="${REPO_ROOT}/repository/templates"

ACTION="${1:-}"
DOMAIN_RAW="${2:-}"
ROOT_PATH="${3:-}"
PHP_VERSION_RAW="${4:-8.3}"
OLD_DOMAIN_RAW="${5:-}"
PANEL_PORT_RAW="${PANEL_PORT:-8090}"
APACHE_BACKEND_PORT_RAW="${APACHE_BACKEND_PORT:-8080}"
NGINX_PRIMARY_PORT_RAW="${NGINX_PRIMARY_PORT:-80}"
PHPMYADMIN_PORT_RAW="${PHPMYADMIN_PORT:-8090}"
REDIS_SERVICE_RAW="${REDIS_SERVICE:-auto}"
HAD_ERRORS=0

log() {
    printf '[sync-vhost] %s\n' "$*"
}

normalize_domain() {
    local value="$1"
    value="$(echo "${value}" | tr '[:upper:]' '[:lower:]' | xargs)"
    echo "${value}"
}

normalize_php_version() {
    local value="$1"
    if [[ "${value}" =~ ^[0-9]+\.[0-9]+$ ]]; then
        echo "${value}"
    else
        echo "8.3"
    fi
}

normalize_port() {
    local value="$1"
    local fallback="$2"
    if [[ "${value}" =~ ^[0-9]+$ ]] && (( 10#${value} >= 1 && 10#${value} <= 65535 )); then
        echo "$((10#${value}))"
    else
        echo "${fallback}"
    fi
}

short_hash() {
    local value="$1"
    local hash

    if command -v sha1sum >/dev/null 2>&1; then
        hash="$(printf '%s' "${value}" | sha1sum | awk '{print $1}')"
    elif command -v shasum >/dev/null 2>&1; then
        hash="$(printf '%s' "${value}" | shasum | awk '{print $1}')"
    else
        hash="$(printf '%s' "${value}" | cksum | awk '{print $1}')"
    fi

    echo "${hash:0:12}"
}

domain_token() {
    local domain="$1"
    local normalized
    normalized="$(echo "${domain}" | tr '[:upper:]' '[:lower:]')"
    normalized="$(echo "${normalized}" | sed -E 's/[^a-z0-9.-]+/-/g; s/^-+//; s/-+$//')"
    if [[ -z "${normalized}" ]]; then
        normalized="site"
    fi
    echo "${normalized}"
}

domain_conf_basename() {
    local domain="$1"
    local token hash
    token="$(domain_token "${domain}")"
    hash="$(short_hash "${domain}")"
    if (( ${#token} > 110 )); then
        token="${token:0:110}"
    fi
    echo "${token}-${hash}"
}

domain_log_basename() {
    local domain="$1"
    local token hash
    token="$(domain_token "${domain}")"
    hash="$(short_hash "${domain}")"
    if (( ${#token} > 52 )); then
        token="${token:0:52}"
    fi
    echo "${token}-${hash}"
}

apache_conf_path() {
    local domain="$1"
    echo "/etc/apache2/sites-available/$(domain_conf_basename "${domain}").conf"
}

apache_legacy_conf_path() {
    local domain="$1"
    echo "/etc/apache2/sites-available/${domain}.conf"
}

nginx_conf_path() {
    local domain="$1"
    echo "/etc/nginx/sites-available/$(domain_conf_basename "${domain}").conf"
}

nginx_enabled_path() {
    local domain="$1"
    echo "/etc/nginx/sites-enabled/$(domain_conf_basename "${domain}").conf"
}

nginx_legacy_conf_path() {
    local domain="$1"
    echo "/etc/nginx/sites-available/${domain}.conf"
}

nginx_legacy_enabled_path() {
    local domain="$1"
    echo "/etc/nginx/sites-enabled/${domain}.conf"
}

should_add_www_alias() {
    local domain="$1"
    local dots
    dots="$(awk -F'.' '{print NF-1}' <<< "${domain}")"
    if [[ "${dots}" -lt 1 ]]; then
        return 1
    fi

    if [[ "${domain}" == www.* ]]; then
        return 1
    fi

    return 0
}

reload_apache_if_available() {
    if command -v apache2ctl >/dev/null 2>&1 && systemctl cat apache2.service >/dev/null 2>&1; then
        if ! apache2ctl -t >/dev/null 2>&1; then
            log "Apache config test failed; skipping Apache reload/start."
            HAD_ERRORS=1
            return 0
        fi

        systemctl enable apache2 >/dev/null 2>&1 || true

        if systemctl is-active --quiet apache2; then
            systemctl reload apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1 || true
        else
            systemctl start apache2 >/dev/null 2>&1 || systemctl restart apache2 >/dev/null 2>&1 || true
        fi
    fi
}

reload_nginx_if_available() {
    if command -v nginx >/dev/null 2>&1 && systemctl cat nginx.service >/dev/null 2>&1; then
        if ! nginx -t >/dev/null 2>&1; then
            log "Nginx config test failed; skipping Nginx reload/start."
            HAD_ERRORS=1
            return 0
        fi

        systemctl enable nginx >/dev/null 2>&1 || true

        if systemctl is-active --quiet nginx; then
            systemctl reload nginx >/dev/null 2>&1 || systemctl restart nginx >/dev/null 2>&1 || true
        else
            systemctl start nginx >/dev/null 2>&1 || systemctl restart nginx >/dev/null 2>&1 || true
        fi
    fi
}

ensure_apache_backend_ports() {
    local ports_conf="/etc/apache2/ports.conf"

    if [[ ! -f "${ports_conf}" ]]; then
        return 0
    fi

    if ! grep -qE "^[[:space:]]*Listen[[:space:]]+${APACHE_BACKEND_PORT}[[:space:]]*$" "${ports_conf}"; then
        echo "Listen ${APACHE_BACKEND_PORT}" >> "${ports_conf}"
    fi

    if [[ "${PHPMYADMIN_PORT}" == "${PANEL_PORT}" ]]; then
        sed -i -E "/^[[:space:]]*Listen[[:space:]]+${PHPMYADMIN_PORT}([[:space:]]*)$/d" "${ports_conf}" || true
    elif ! grep -qE "^[[:space:]]*Listen[[:space:]]+${PHPMYADMIN_PORT}[[:space:]]*$" "${ports_conf}"; then
        echo "Listen ${PHPMYADMIN_PORT}" >> "${ports_conf}"
    fi

    # In reverse-proxy mode, keep 80/443 for Nginx front-end.
    sed -i -E 's/^[[:space:]]*Listen[[:space:]]+80([[:space:]]*)$/# Listen 80/g' "${ports_conf}" || true
    sed -i -E 's/^[[:space:]]*Listen[[:space:]]+443([[:space:]]*)$/# Listen 443/g' "${ports_conf}" || true
}

cleanup_phpmyadmin_apache_sites() {
    local conf_path
    local site_name

    [[ -d /etc/apache2/sites-available ]] || return 0

    for conf_path in /etc/apache2/sites-available/serverpanel-phpmyadmin-*.conf; do
        [[ -e "${conf_path}" ]] || continue
        site_name="$(basename "${conf_path}")"
        a2dissite "${site_name}" >/dev/null 2>&1 || true
        rm -f "/etc/apache2/sites-enabled/${site_name}" || true
        rm -f "${conf_path}" || true
    done
}

ensure_apache_php_fpm_mode() {
    local php_module php_fpm_service php_fpm_conf

    if [[ ! -d /etc/apache2/mods-enabled ]]; then
        return 0
    fi

    for module_load in /etc/apache2/mods-enabled/php*.load; do
        if [[ -f "${module_load}" ]]; then
            php_module="$(basename "${module_load}" .load)"
            a2dismod "${php_module}" >/dev/null 2>&1 || true
        fi
    done

    a2dismod mpm_prefork >/dev/null 2>&1 || true
    a2enmod mpm_event proxy proxy_fcgi setenvif rewrite headers >/dev/null 2>&1 || true

    php_fpm_service="php${PHP_VERSION}-fpm"
    php_fpm_conf="php${PHP_VERSION}-fpm"
    if systemctl cat "${php_fpm_service}.service" >/dev/null 2>&1; then
        systemctl enable "${php_fpm_service}" >/dev/null 2>&1 || true
        systemctl start "${php_fpm_service}" >/dev/null 2>&1 || true
    fi
    if [[ -f "/etc/apache2/conf-available/${php_fpm_conf}.conf" ]]; then
        a2enconf "${php_fpm_conf}" >/dev/null 2>&1 || true
    fi
}

write_phpmyadmin_helper_file() {
    local target="$1"
    local template="${TEMPLATE_ROOT}/phpmyadmin/phpmyadminsignin.php"

    if [[ -f "${template}" ]]; then
        mkdir -p "$(dirname "${target}")"
        cp "${template}" "${target}"
        chmod 644 "${target}" >/dev/null 2>&1 || true
        return 0
    fi

    cat > "${target}" <<'PHP'
<?php
declare(strict_types=1);

function serverpanelRequestIsSecure(): bool
{
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($forwardedProto !== '') {
        return $forwardedProto === 'https';
    }

    return !empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off';
}

session_name('SignonSession');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => serverpanelRequestIsSecure(),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');

/**
 * Allow panel origins from same host (any port), plus optional explicit list.
 * Set PMA_ALLOWED_ORIGINS as comma-separated full origins if needed.
 */
$allowedOrigins = [];
$configuredOrigins = trim((string) getenv('PMA_ALLOWED_ORIGINS'));
if ($configuredOrigins !== '') {
    $allowedOrigins = array_values(array_filter(array_map('trim', explode(',', $configuredOrigins)), static fn ($item) => $item !== ''));
}
$allowedOrigins = array_values(array_unique(array_merge($allowedOrigins, [
    'http://127.0.0.1:8000',
    'http://localhost:8000',
])));

$origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
$isAllowedOrigin = in_array($origin, $allowedOrigins, true);
if (!$isAllowedOrigin && $origin !== '') {
    $originHost = (string) parse_url($origin, PHP_URL_HOST);
    $serverHostRaw = (string) ($_SERVER['HTTP_HOST'] ?? '');
    $serverHost = strtolower(trim((string) preg_replace('/:\d+$/', '', $serverHostRaw)));
    $normalizedOriginHost = strtolower(trim($originHost));
    if ($normalizedOriginHost !== '' && $serverHost !== '' && $normalizedOriginHost === $serverHost) {
        $isAllowedOrigin = true;
    }
}

if ($isAllowedOrigin) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Vary: Origin');
}

header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

function clearSignonSession(): void
{
    unset(
        $_SESSION['PMA_single_signon_user'],
        $_SESSION['PMA_single_signon_password'],
        $_SESSION['PMA_single_signon_host'],
        $_SESSION['PMA_single_signon_db']
    );

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

$action = (string) ($_GET['action'] ?? '');
$selfUrl = strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
$target = rtrim(dirname($selfUrl), '/') . '/index.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action !== 'redirect') {
    clearSignonSession();

    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => serverpanelRequestIsSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    if (str_contains($accept, 'application/json')) {
        jsonResponse([
            'success' => true,
            'message' => 'Logged out from phpMyAdmin.',
        ]);
    }

    http_response_code(200);
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>phpMyAdmin Logged Out</title>
    </head>
    <body>
        <p>Logged out from phpMyAdmin.</p>
        <p>Start login again from ServerPanel.</p>
    </body>
    </html>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
    $isJson = str_contains($contentType, 'application/json');
    $wantsJson = $isJson || str_contains($accept, 'application/json');

    $input = [];

    if ($isJson) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }

    if (!is_array($input) || $input === []) {
        $input = $_POST;
    }

    $username = trim((string) ($input['pma_username'] ?? ''));
    $password = (string) ($input['pma_password'] ?? '');
    $host = trim((string) ($input['pma_host'] ?? '127.0.0.1'));
    $database = trim((string) ($input['db'] ?? ''));

    if (strcasecmp($host, 'localhost') === 0) {
        $host = '127.0.0.1';
    }

    if ($username === '' || $password === '') {
        if ($wantsJson) {
            jsonResponse([
                'success' => false,
                'message' => 'Missing phpMyAdmin username or password.',
            ], 422);
        }

        echo 'Missing phpMyAdmin credentials.';
        exit;
    }

    if (strcasecmp($username, 'root') === 0) {
        if ($wantsJson) {
            jsonResponse([
                'success' => false,
                'message' => 'Root login is disabled for phpMyAdmin auto-login.',
            ], 403);
        }

        echo 'Root login is disabled for phpMyAdmin auto-login.';
        exit;
    }

    $_SESSION['PMA_single_signon_user'] = $username;
    $_SESSION['PMA_single_signon_password'] = $password;
    $_SESSION['PMA_single_signon_host'] = $host !== '' ? $host : '127.0.0.1';
    if ($database !== '') {
        $_SESSION['PMA_single_signon_db'] = $database;
    } else {
        unset($_SESSION['PMA_single_signon_db']);
    }

    $redirect = $target;
    if ($database !== '') {
        $redirect .= '?db=' . rawurlencode($database);
    }
    session_write_close();

    if ($wantsJson) {
        jsonResponse([
            'success' => true,
            'message' => 'Session created successfully.',
            'redirect' => $redirect,
        ]);
    }

    header('Location: ' . $redirect);
    exit;
}

if ($action === 'redirect') {
    $username = (string) ($_SESSION['PMA_single_signon_user'] ?? '');
    if ($username === '') {
        echo 'Auto login session not found. Please start from panel again.';
        exit;
    }

    $redirect = $target;
    $database = (string) ($_SESSION['PMA_single_signon_db'] ?? '');
    if ($database !== '') {
        $redirect .= '?db=' . rawurlencode($database);
    }
    header('Location: ' . $redirect);
    exit;
}

http_response_code(400);
echo 'Start phpMyAdmin from ServerPanel to continue.';
PHP

    chmod 644 "${target}" >/dev/null 2>&1 || true
}

sync_phpmyadmin_apache_site() {
    local pma_root conf_path helper_target

    [[ -d /etc/apache2/sites-available ]] || return 0

    if [[ "${PHPMYADMIN_PORT}" == "${PANEL_PORT}" ]]; then
        cleanup_phpmyadmin_apache_sites
        log "phpMyAdmin uses the panel port :${PANEL_PORT}; skipping dedicated Apache site sync."
        return 0
    fi

    pma_root=""
    for candidate in /usr/share/phpmyadmin /var/www/phpmyadmin /var/www/html/phpmyadmin; do
        if [[ -d "${candidate}" ]]; then
            pma_root="${candidate}"
            break
        fi
    done

    if [[ -z "${pma_root}" ]]; then
        log "phpMyAdmin directory not found; skipping phpMyAdmin :${PHPMYADMIN_PORT} site sync."
        return 0
    fi

    conf_path="/etc/apache2/sites-available/serverpanel-phpmyadmin-${PHPMYADMIN_PORT}.conf"
    cat > "${conf_path}" <<EOF
<VirtualHost *:${PHPMYADMIN_PORT}>
    ServerName localhost
    DocumentRoot ${pma_root}

    Alias /phpmyadmin ${pma_root}
    RedirectMatch 302 ^/$ /phpmyadmin/index.php

    <Directory ${pma_root}>
        Options FollowSymLinks
        AllowOverride All
        DirectoryIndex index.php
        Require all granted
    </Directory>

    ErrorLog \${APACHE_LOG_DIR}/phpmyadmin_${PHPMYADMIN_PORT}_error.log
    CustomLog \${APACHE_LOG_DIR}/phpmyadmin_${PHPMYADMIN_PORT}_access.log combined
</VirtualHost>
EOF

    chmod 644 "${conf_path}" || true
    a2ensite "$(basename "${conf_path}")" >/dev/null 2>&1 || true

    helper_target="${pma_root}/phpmyadminsignin.php"
    write_phpmyadmin_helper_file "${helper_target}"
}

auto_correct_web_stack() {
    ensure_apache_backend_ports
    ensure_apache_php_fpm_mode
    sync_phpmyadmin_apache_site
    ensure_redis_service
}

detect_redis_service() {
    local preferred="$1"

    if [[ -n "${preferred}" && "${preferred}" != "auto" ]]; then
        if systemctl cat "${preferred}.service" >/dev/null 2>&1; then
            echo "${preferred}"
            return 0
        fi
        return 1
    fi

    if systemctl cat redis-server.service >/dev/null 2>&1; then
        echo "redis-server"
        return 0
    fi
    if systemctl cat redis.service >/dev/null 2>&1; then
        echo "redis"
        return 0
    fi

    return 1
}

ensure_redis_service() {
    local redis_service

    if ! redis_service="$(detect_redis_service "${REDIS_SERVICE_RAW}")"; then
        log "Redis service not found; skipping Redis auto-start."
        return 0
    fi

    systemctl enable "${redis_service}" >/dev/null 2>&1 || true

    if systemctl is-active --quiet "${redis_service}"; then
        return 0
    fi

    if ! systemctl start "${redis_service}" >/dev/null 2>&1; then
        systemctl restart "${redis_service}" >/dev/null 2>&1 || true
    fi

    if ! systemctl is-active --quiet "${redis_service}"; then
        log "Redis service ${redis_service} is still down after auto-start attempt."
        HAD_ERRORS=1
    fi
}

sync_apache_vhost() {
    local domain="$1"
    local root_path="$2"
    local php_version="$3"
    local conf_path legacy_conf_path socket_path server_alias log_basename

    [[ -d /etc/apache2/sites-available ]] || return 1
    conf_path="$(apache_conf_path "${domain}")"
    legacy_conf_path="$(apache_legacy_conf_path "${domain}")"
    socket_path="/run/php/php${php_version}-fpm.sock"
    log_basename="$(domain_log_basename "${domain}")"
    server_alias=""
    if should_add_www_alias "${domain}"; then
        server_alias=$'\n'"    ServerAlias www.${domain}"
    fi

    if [[ "${legacy_conf_path}" != "${conf_path}" ]]; then
        a2dissite "$(basename "${legacy_conf_path}")" >/dev/null 2>&1 || true
        rm -f "${legacy_conf_path}" || true
    fi

    cat > "${conf_path}" <<EOF
<VirtualHost *:${APACHE_BACKEND_PORT}>
    ServerName ${domain}${server_alias}
    DocumentRoot ${root_path}

    <Directory ${root_path}>
        AllowOverride All
        Options FollowSymLinks
        Require all granted

        <IfModule mod_dir.c>
            FallbackResource /index.php
        </IfModule>
    </Directory>

    DirectoryIndex index.php index.html index.htm

    <FilesMatch \\.php$>
        SetHandler "proxy:unix:${socket_path}|fcgi://localhost/"
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/${log_basename}_error.log
    CustomLog \${APACHE_LOG_DIR}/${log_basename}_access.log combined
</VirtualHost>
EOF

    chmod 644 "${conf_path}" || true
    a2ensite "$(basename "${conf_path}")" >/dev/null 2>&1 || true
    reload_apache_if_available
    return 0
}

remove_apache_vhost() {
    local domain="$1"
    local conf_path legacy_conf_path

    [[ -d /etc/apache2/sites-available ]] || return 1
    conf_path="$(apache_conf_path "${domain}")"
    legacy_conf_path="$(apache_legacy_conf_path "${domain}")"
    a2dissite "$(basename "${conf_path}")" >/dev/null 2>&1 || true
    rm -f "${conf_path}" || true
    if [[ "${legacy_conf_path}" != "${conf_path}" ]]; then
        a2dissite "$(basename "${legacy_conf_path}")" >/dev/null 2>&1 || true
        rm -f "${legacy_conf_path}" || true
    fi
    reload_apache_if_available
    return 0
}

sync_nginx_vhost() {
    local domain="$1"
    local root_path="$2"
    local php_version="$3"
    local conf_path enabled_path legacy_conf_path legacy_enabled_path server_names log_basename

    [[ -d /etc/nginx/sites-available && -d /etc/nginx/sites-enabled ]] || return 1
    conf_path="$(nginx_conf_path "${domain}")"
    enabled_path="$(nginx_enabled_path "${domain}")"
    legacy_conf_path="$(nginx_legacy_conf_path "${domain}")"
    legacy_enabled_path="$(nginx_legacy_enabled_path "${domain}")"
    log_basename="$(domain_log_basename "${domain}")"
    server_names="${domain}"
    if should_add_www_alias "${domain}"; then
        server_names="${server_names} www.${domain}"
    fi

    if [[ "${legacy_conf_path}" != "${conf_path}" ]]; then
        rm -f "${legacy_enabled_path}" || true
        rm -f "${legacy_conf_path}" || true
    fi

    cat > "${conf_path}" <<EOF
server {
    listen ${NGINX_PRIMARY_PORT};
    listen [::]:${NGINX_PRIMARY_PORT};
    server_name ${server_names};
    root ${root_path};
    index index.php index.html index.htm;

    access_log /var/log/nginx/${log_basename}_access.log;
    error_log /var/log/nginx/${log_basename}_error.log;

    location ^~ /.well-known/acme-challenge/ {
        allow all;
        try_files \$uri @apache;
    }

    location ~ /\\. {
        deny all;
    }

    location ~* \\.(?:css|js|mjs|map|jpg|jpeg|gif|png|webp|svg|ico|ttf|otf|woff|woff2|eot|mp4|webm|ogg|mp3|wav|pdf|txt|xml|json|webmanifest)\$ {
        expires 30d;
        access_log off;
        add_header Cache-Control "public, max-age=2592000, immutable";
        try_files \$uri @apache;
    }

    location ~ \\.php(?:\$|/) {
        proxy_pass http://127.0.0.1:${APACHE_BACKEND_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        proxy_next_upstream error timeout http_502 http_503 http_504;
    }

    location / {
        proxy_pass http://127.0.0.1:${APACHE_BACKEND_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        proxy_next_upstream error timeout http_502 http_503 http_504;
    }

    location @apache {
        proxy_pass http://127.0.0.1:${APACHE_BACKEND_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_connect_timeout 30s;
        proxy_send_timeout 60s;
        proxy_read_timeout 60s;
        proxy_next_upstream error timeout http_502 http_503 http_504;
    }
}
EOF

    chmod 644 "${conf_path}" || true
    ln -sfn "${conf_path}" "${enabled_path}"
    reload_nginx_if_available
    return 0
}

remove_nginx_vhost() {
    local domain="$1"
    local conf_path enabled_path legacy_conf_path legacy_enabled_path

    [[ -d /etc/nginx/sites-available && -d /etc/nginx/sites-enabled ]] || return 1
    conf_path="$(nginx_conf_path "${domain}")"
    enabled_path="$(nginx_enabled_path "${domain}")"
    legacy_conf_path="$(nginx_legacy_conf_path "${domain}")"
    legacy_enabled_path="$(nginx_legacy_enabled_path "${domain}")"
    rm -f "${enabled_path}" || true
    rm -f "${conf_path}" || true
    if [[ "${legacy_conf_path}" != "${conf_path}" ]]; then
        rm -f "${legacy_enabled_path}" || true
        rm -f "${legacy_conf_path}" || true
    fi
    reload_nginx_if_available
    return 0
}

DOMAIN="$(normalize_domain "${DOMAIN_RAW}")"
OLD_DOMAIN="$(normalize_domain "${OLD_DOMAIN_RAW}")"
PHP_VERSION="$(normalize_php_version "${PHP_VERSION_RAW}")"
PANEL_PORT="$(normalize_port "${PANEL_PORT_RAW}" "8090")"
APACHE_BACKEND_PORT="$(normalize_port "${APACHE_BACKEND_PORT_RAW}" "8080")"
NGINX_PRIMARY_PORT="$(normalize_port "${NGINX_PRIMARY_PORT_RAW}" "80")"
PHPMYADMIN_PORT="$(normalize_port "${PHPMYADMIN_PORT_RAW}" "8090")"

if [[ "${ACTION}" != "sync" && "${ACTION}" != "remove" ]]; then
    log "Usage: $0 <sync|remove> <domain> [root_path] [php_version] [old_domain]"
    exit 64
fi

if [[ -z "${DOMAIN}" ]]; then
    log "Domain is required."
    exit 64
fi

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    log "This script must run as root."
    exit 77
fi

did_anything=0

if [[ "${ACTION}" == "sync" ]]; then
    if [[ -z "${ROOT_PATH}" ]]; then
        log "root_path is required for sync action."
        exit 64
    fi

    auto_correct_web_stack || true

    if [[ -n "${OLD_DOMAIN}" && "${OLD_DOMAIN}" != "${DOMAIN}" ]]; then
        remove_apache_vhost "${OLD_DOMAIN}" && did_anything=1 || true
        remove_nginx_vhost "${OLD_DOMAIN}" && did_anything=1 || true
    fi

    sync_apache_vhost "${DOMAIN}" "${ROOT_PATH}" "${PHP_VERSION}" && did_anything=1 || true
    sync_nginx_vhost "${DOMAIN}" "${ROOT_PATH}" "${PHP_VERSION}" && did_anything=1 || true
else
    remove_apache_vhost "${DOMAIN}" && did_anything=1 || true
    remove_nginx_vhost "${DOMAIN}" && did_anything=1 || true
fi

if [[ "${did_anything}" -eq 0 ]]; then
    log "No Apache/Nginx target found on this server."
    exit 2
fi

if [[ "${HAD_ERRORS}" -ne 0 ]]; then
    log "Completed with recoverable errors. Check apache2ctl -t and nginx -t outputs."
    exit 3
fi

log "Completed: action=${ACTION}, domain=${DOMAIN}, nginx_port=${NGINX_PRIMARY_PORT}, apache_backend_port=${APACHE_BACKEND_PORT}"
exit 0
