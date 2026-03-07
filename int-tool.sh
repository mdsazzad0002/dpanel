#!/usr/bin/env bash
set -euo pipefail

RED="\033[1;31m"
GREEN="\033[1;32m"
YELLOW="\033[1;33m"
BLUE="\033[1;34m"
CYAN="\033[1;36m"
NC="\033[0m"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR=""
PROJECT_HINT=""
PROJECT_URL=""
PROJECT_TARGET=""
PROJECT_BASE_URL=""
WEB_SERVER="apache"
PHP_VERSIONS_RAW="7.4,8.0,8.2,8.3,8.4,8.5"
PHP_DEFAULT_VERSION="8.3"
PHP_VERSIONS=()
FORCE_REPLACE_TARGET="true"
DB_NAME="serverinstaller"
DB_USER="serverpanel"
DB_PASSWORD=""
PANEL_PORT="8090"
DB_SERVICE=""
CURRENT_PID=""
RESET_DB_STACK="false"
LOGIN_CREDENTIALS_READY="false"
NODEJS_REQUIRED_MAJOR="22"

reset_runtime_defaults() {
    PROJECT_DIR=""
    PROJECT_HINT=""
    PROJECT_URL=""
    PROJECT_TARGET=""
    PROJECT_BASE_URL=""
    WEB_SERVER="apache"
    PHP_VERSIONS_RAW="7.4,8.0,8.2,8.3,8.4,8.5"
    PHP_DEFAULT_VERSION="8.3"
    PHP_VERSIONS=()
    FORCE_REPLACE_TARGET="true"
    DB_NAME="serverinstaller"
    DB_USER="serverpanel"
    DB_PASSWORD=""
    PANEL_PORT="8090"
    DB_SERVICE=""
    CURRENT_PID=""
    RESET_DB_STACK="false"
    LOGIN_CREDENTIALS_READY="false"
    NODEJS_REQUIRED_MAJOR="22"
}

banner() {
    clear || true
    echo -e "${CYAN}============================================================${NC}"
    echo -e "${CYAN}                 ServerPanel Installer                      ${NC}"
    echo -e "${CYAN}                 CyberPanel Style Setup                     ${NC}"
    echo -e "${CYAN}============================================================${NC}"
    echo
}

info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

ok() {
    echo -e "${GREEN}[OK]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARN]${NC} $1"
}

fail() {
    echo -e "${RED}[ERROR]${NC} $1"
    exit 1
}

handle_interrupt() {
    if [[ -n "${CURRENT_PID}" ]] && kill -0 "${CURRENT_PID}" 2>/dev/null; then
        kill "${CURRENT_PID}" 2>/dev/null || true
    fi
    jobs -pr | xargs -r kill 2>/dev/null || true
    echo
    echo -e "${YELLOW}[WARN]${NC} Keyboard interrupt received (Ctrl+C). Installer stopped by user."
    echo -e "${YELLOW}[WARN]${NC} Re-run int-tool.sh to continue from a safe state."
    exit 130
}

trap handle_interrupt INT TERM

usage() {
    cat <<EOF
Usage: bash int-tool.sh [--project-dir /absolute/path/to/ServerPanel] [--project-url http://host/path/archive.tar.gz|.tgz|.zip] [--project-target /path/to/ServerPanel]

Options:
  --project-dir PATH   Laravel project path containing artisan/composer.json
  --project-url URL    Download project archive (.tar.gz/.tgz/.zip) and auto-detect ServerPanel
  --base-url URL       Base URL to auto-discover archive (ServerInstaller/ServerPanel .tar.gz/.tgz/.zip)
  --project-target PATH Where downloaded project should be moved (default: SCRIPT_DIR/ServerPanel)
  --remote-project-dir PATH Alias for --project-target
  --web-server NAME    apache (default), nginx, or both
  --php-versions CSV   PHP versions list (default: 7.4,8.0,8.2,8.3,8.4,8.5)
  --php-default VER    Default PHP CLI version for Composer/Artisan (default: 8.3)
  --db-name NAME       Database name to create (default: serverinstaller)
  --db-user NAME       Database user to create (default: serverpanel)
  --db-password PASS   Database password (default: random generated)
  --panel-port PORT    Panel HTTP port for system startup service (default: 8090)
  --reset-db           Purge existing DB packages and data before install
  --fresh-install      Alias for --reset-db (fresh DB stack + full install flow)
  --node-major VER     Required Node.js major for Vite build (default: 22)
  -h, --help           Show this help message
EOF
}

run() {
    info "Running: $*"
    local status=0
    local heartbeat_pid=""
    local decision=""
    local can_fresh_db="false"

    if [[ "$*" == "systemctl restart mysql" || "$*" == "systemctl restart mariadb" || "$*" == "systemctl restart mysqld" || "$*" == *"install mariadb-server"* ]]; then
        can_fresh_db="true"
    fi

    while true; do
        (
            local elapsed=0
            local spinner='|/-\'
            local spin_idx=0
            while true; do
                sleep 1
                elapsed=$((elapsed + 1))
                printf "\r[INFO] Still running (%ss) [%s]: %s" "${elapsed}" "${spinner:spin_idx:1}" "$*"
                spin_idx=$(((spin_idx + 1) % 4))
            done
        ) &
        heartbeat_pid=$!

        set +e
        "$@"
        status=$?
        set -e

        if [[ -n "${heartbeat_pid}" ]] && kill -0 "${heartbeat_pid}" 2>/dev/null; then
            kill "${heartbeat_pid}" 2>/dev/null || true
            wait "${heartbeat_pid}" 2>/dev/null || true
        fi
        printf "\r\033[K\n"
        CURRENT_PID=""

        if [[ "${status}" -eq 0 ]]; then
            return 0
        fi

        warn "Command failed with exit code ${status}: $*"
        if [[ -t 0 ]]; then
            echo -e "${YELLOW}[WARN]${NC} Choose action:"
            echo "  1. Retry this command"
            echo "  2. Skip this command"
            echo "  3. Abort installer"
            if [[ "${can_fresh_db}" == "true" ]]; then
                echo "  4. Fresh reinstall MariaDB"
            fi
            read -r decision
            case "${decision,,}" in
                1|r|retry)
                    info "Retrying command..."
                    continue
                    ;;
                2|s|skip)
                    warn "Skipping failed command: $*"
                    return 0
                    ;;
                3|a|abort|"")
                    fail "Aborted after command failure: $*"
                    ;;
                4)
                    if [[ "${can_fresh_db}" == "true" ]]; then
                        warn "Starting fresh MariaDB reinstall..."
                        reinstall_mariadb_stack_fresh
                        info "MariaDB reinstall finished. Retrying failed command..."
                        continue
                    fi
                    warn "Option 4 is only available for database restart failures."
                    continue
                    ;;
                *)
                    if [[ "${can_fresh_db}" == "true" ]]; then
                        warn "Invalid choice. Please enter 1, 2, 3, or 4."
                    else
                        warn "Invalid choice. Please enter 1, 2, or 3."
                    fi
                    continue
                    ;;
            esac
        fi

        fail "Command failed in non-interactive mode: $* (exit ${status})"
    done
}

require_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        fail "Run as root (example: sudo bash int-tool.sh)."
    fi
}

check_ubuntu() {
    if [[ ! -f /etc/os-release ]]; then
        fail "/etc/os-release not found. Ubuntu check failed."
    fi

    # shellcheck disable=SC1091
    source /etc/os-release
    if [[ "${ID:-}" != "ubuntu" ]]; then
        warn "Detected ID=${ID:-unknown}. Script is designed for Ubuntu."
    else
        ok "Ubuntu detected (${PRETTY_NAME:-Ubuntu})."
    fi
}

parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --project-dir)
                [[ $# -lt 2 ]] && fail "--project-dir requires a path value."
                PROJECT_HINT="$2"
                shift 2
                ;;
            --project-url)
                [[ $# -lt 2 ]] && fail "--project-url requires a URL value."
                PROJECT_URL="$2"
                shift 2
                ;;
            --base-url)
                [[ $# -lt 2 ]] && fail "--base-url requires a URL value."
                PROJECT_BASE_URL="$2"
                shift 2
                ;;
            --project-target)
                [[ $# -lt 2 ]] && fail "--project-target requires a path value."
                PROJECT_TARGET="$2"
                shift 2
                ;;
            --remote-project-dir)
                [[ $# -lt 2 ]] && fail "--remote-project-dir requires a path value."
                PROJECT_TARGET="$2"
                shift 2
                ;;
            --web-server)
                [[ $# -lt 2 ]] && fail "--web-server requires a value: apache|nginx|both"
                WEB_SERVER="$(echo "$2" | tr '[:upper:]' '[:lower:]')"
                shift 2
                ;;
            --php-versions)
                [[ $# -lt 2 ]] && fail "--php-versions requires a CSV value."
                PHP_VERSIONS_RAW="$2"
                shift 2
                ;;
            --php-default)
                [[ $# -lt 2 ]] && fail "--php-default requires a value like 8.2."
                PHP_DEFAULT_VERSION="$2"
                shift 2
                ;;
            --db-name)
                [[ $# -lt 2 ]] && fail "--db-name requires a value."
                DB_NAME="$2"
                shift 2
                ;;
            --db-user)
                [[ $# -lt 2 ]] && fail "--db-user requires a value."
                DB_USER="$2"
                shift 2
                ;;
            --db-password)
                [[ $# -lt 2 ]] && fail "--db-password requires a value."
                DB_PASSWORD="$2"
                shift 2
                ;;
            --panel-port)
                [[ $# -lt 2 ]] && fail "--panel-port requires a value."
                PANEL_PORT="$2"
                shift 2
                ;;
            --reset-db)
                RESET_DB_STACK="true"
                shift 1
                ;;
            --fresh-install)
                RESET_DB_STACK="true"
                shift 1
                ;;
            --node-major)
                [[ $# -lt 2 ]] && fail "--node-major requires a value like 20 or 22."
                NODEJS_REQUIRED_MAJOR="$2"
                shift 2
                ;;
            -h|--help)
                usage
                exit 0
                ;;
            *)
                fail "Unknown argument: $1"
                ;;
        esac
    done

    if [[ -n "${PROJECT_HINT}" && -n "${PROJECT_URL}" ]]; then
        fail "Use either --project-dir or --project-url, not both."
    fi
    if [[ -n "${PROJECT_BASE_URL}" && -n "${PROJECT_URL}" ]]; then
        fail "Use either --project-url or --base-url, not both."
    fi
    if [[ -n "${PROJECT_HINT}" && -n "${PROJECT_BASE_URL}" ]]; then
        fail "Use either --project-dir or --base-url, not both."
    fi
    if [[ -n "${PROJECT_TARGET}" && -z "${PROJECT_URL}" ]]; then
        if [[ -z "${PROJECT_BASE_URL}" ]]; then
            fail "--project-target/--remote-project-dir can only be used with --project-url or --base-url."
        fi
    fi

    case "${WEB_SERVER}" in
        apache|nginx|both)
            ;;
        *)
            fail "Invalid --web-server value: ${WEB_SERVER}. Use apache, nginx, or both."
            ;;
    esac

    if [[ ! "${DB_NAME}" =~ ^[A-Za-z0-9_]+$ ]]; then
        fail "Invalid --db-name: ${DB_NAME}. Use only letters, numbers, underscore."
    fi
    if [[ ! "${DB_USER}" =~ ^[A-Za-z0-9_]+$ ]]; then
        fail "Invalid --db-user: ${DB_USER}. Use only letters, numbers, underscore."
    fi
    if [[ ! "${PHP_DEFAULT_VERSION}" =~ ^[0-9]+\.[0-9]+$ ]]; then
        fail "Invalid --php-default: ${PHP_DEFAULT_VERSION}. Expected format like 8.2"
    fi
    if [[ ! "${PANEL_PORT}" =~ ^[0-9]+$ ]]; then
        fail "Invalid --panel-port: ${PANEL_PORT}. Must be a number."
    fi
    if (( PANEL_PORT < 1 || PANEL_PORT > 65535 )); then
        fail "Invalid --panel-port: ${PANEL_PORT}. Must be between 1 and 65535."
    fi
    if [[ ! "${NODEJS_REQUIRED_MAJOR}" =~ ^[0-9]+$ ]]; then
        fail "Invalid --node-major: ${NODEJS_REQUIRED_MAJOR}. Must be numeric (20 or 22)."
    fi
    if (( NODEJS_REQUIRED_MAJOR < 20 )); then
        fail "Invalid --node-major: ${NODEJS_REQUIRED_MAJOR}. Vite 7 requires Node 20+."
    fi

    normalize_php_versions
}

normalize_php_versions() {
    local raw version cleaned
    IFS=',' read -r -a raw <<< "${PHP_VERSIONS_RAW}"
    PHP_VERSIONS=()

    for version in "${raw[@]}"; do
        cleaned="$(echo "${version}" | tr -d '[:space:]')"
        [[ -z "${cleaned}" ]] && continue
        if [[ ! "${cleaned}" =~ ^[0-9]+\.[0-9]+$ ]]; then
            fail "Invalid PHP version format: ${cleaned}. Expected format like 8.3"
        fi
        PHP_VERSIONS+=("${cleaned}")
    done

    if [[ "${#PHP_VERSIONS[@]}" -eq 0 ]]; then
        fail "No valid PHP versions provided via --php-versions."
    fi
}

is_valid_project_dir() {
    local dir="$1"
    [[ -f "${dir}/artisan" && -f "${dir}/composer.json" ]]
}

safe_remove_target_dir() {
    local target="$1"
    local resolved

    if ! resolved="$(readlink -f "${target}" 2>/dev/null)"; then
        resolved="${target}"
    fi

    case "${resolved}" in
        ""|"/"|"/root"|"/home"|"/var"|"/usr"|"/etc"|"/bin"|"/sbin"|"/opt"|"/srv"|"/tmp")
            fail "Refusing unsafe delete target: ${resolved}"
            ;;
    esac

    run rm -rf "${target}"
}

wants_apache() {
    [[ "${WEB_SERVER}" == "apache" || "${WEB_SERVER}" == "both" ]]
}

wants_nginx() {
    [[ "${WEB_SERVER}" == "nginx" || "${WEB_SERVER}" == "both" ]]
}

detect_db_service() {
    if systemctl cat "mariadb.service" >/dev/null 2>&1; then
        echo "mariadb"
        return
    fi
    if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx "mariadb.service"; then
        echo "mariadb"
        return
    fi
    echo ""
}

detect_db_cli() {
    if command -v mariadb >/dev/null 2>&1; then
        echo "mariadb"
        return
    fi
    if command -v mysql >/dev/null 2>&1; then
        echo "mysql"
        return
    fi
    echo ""
}

ensure_database_service_installed() {
    if [[ -n "$(detect_db_service)" ]]; then
        return
    fi

    warn "No MariaDB service unit detected. Attempting to install mariadb-server."
    if is_package_available mariadb-server; then
        ensure_package mariadb-server
        return
    fi
    fail "Package mariadb-server is not available on this host."
}

ensure_database_running() {
    local svc action
    ensure_database_service_installed
    svc="$(detect_db_service)"

    if [[ -z "${svc}" ]]; then
        fail "No database service unit found (mariadb.service)."
    fi

    DB_SERVICE="${svc}"

    if systemctl is-active --quiet "${svc}"; then
        ok "Database service already running: ${svc}"
        return
    fi

    while true; do
        warn "Database service is not active, attempting start: ${svc}"
        run systemctl enable "${svc}" || true
        run systemctl reset-failed "${svc}" || true
        run systemctl restart "${svc}" || true

        if systemctl is-active --quiet "${svc}"; then
            ok "Database service started: ${svc}"
            return
        fi

        systemctl status "${svc}.service" --no-pager || true
        journalctl -u "${svc}.service" -n 80 --no-pager || true
        if [[ -f /etc/mysql/FROZEN ]]; then
            warn "Detected /etc/mysql/FROZEN. Resolve database freeze condition, then retry."
        fi

        if [[ -t 0 ]]; then
            warn "Database restart failed."
            echo -e "${YELLOW}[WARN]${NC} Choose action:"
            echo "  1. Retry DB restart"
            echo "  2. Reset DB stack (purge + fresh reinstall), then retry"
            echo "  3. Abort installer"
            read -r action
            case "${action,,}" in
                1|"")
                    ;;
                2|reset)
                    warn "Starting fresh DB reinstall..."
                    reinstall_database_stack_fresh
                    svc="$(detect_db_service)"
                    if [[ -n "${svc}" ]]; then
                        DB_SERVICE="${svc}"
                    fi
                    ;;
                3|abort|a)
                    fail "Aborted by user after database restart failure."
                    ;;
                *)
                    warn "Invalid choice. Please enter 1, 2, or 3."
                    continue
                    ;;
            esac
            continue
        fi

        fail "Database service failed to start (${svc}). Review logs above."
    done
}

is_package_installed() {
    local pkg="$1"
    dpkg-query -W -f='${Status}' "${pkg}" 2>/dev/null | grep -q "install ok installed"
}

is_package_available() {
    local pkg="$1"
    apt-cache show "${pkg}" >/dev/null 2>&1
}

ensure_package() {
    local pkg="$1"
    local optional="${2:-false}"

    if is_package_installed "${pkg}"; then
        ok "Already installed: ${pkg}"
        return 0
    fi

    if ! is_package_available "${pkg}"; then
        if [[ "${optional}" == "true" ]]; then
            warn "Package not available, skipping: ${pkg}"
            return 0
        fi
        fail "Required package not available: ${pkg}"
    fi

    run env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew install "${pkg}" || true
    if is_package_installed "${pkg}"; then
        ok "Installed: ${pkg}"
        return 0
    fi

    warn "Initial install did not complete for ${pkg}. Attempting apt/dpkg recovery..."
    repair_apt_dpkg_state
    run env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew install "${pkg}" || true

    if is_package_installed "${pkg}"; then
        ok "Installed after recovery: ${pkg}"
        return 0
    fi

    if [[ "${optional}" == "true" ]]; then
        warn "Package installation still failed, skipping optional package: ${pkg}"
        return 0
    fi

    fail "Failed to install required package: ${pkg}"
}

repair_apt_dpkg_state() {
    warn "Repairing package manager state (dpkg/apt)..."
    run dpkg --configure -a || true
    run env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew --fix-broken install || true
}

disable_mysql_apt_repos() {
    local file
    local changed="false"

    if [[ -f /etc/apt/sources.list ]] && grep -q "repo.mysql.com" /etc/apt/sources.list; then
        warn "Disabling MySQL APT entries from /etc/apt/sources.list (MariaDB-only mode)."
        run sed -i '/repo\.mysql\.com/s/^/# disabled-by-serverpanel-installer: /' /etc/apt/sources.list || true
        changed="true"
    fi

    for file in /etc/apt/sources.list.d/*.list; do
        [[ -e "${file}" ]] || continue
        if grep -q "repo.mysql.com" "${file}"; then
            warn "Disabling MySQL APT source file: ${file}"
            run mv "${file}" "${file}.disabled" || true
            changed="true"
        fi
    done

    if [[ "${changed}" == "true" ]]; then
        ok "MySQL APT repositories disabled."
    fi
}

stop_db_services_safely() {
    local svc
    for svc in mysql mariadb mysqld; do
        if systemctl cat "${svc}.service" >/dev/null 2>&1; then
            run systemctl stop "${svc}" || true
        else
            info "Skipping ${svc}.service stop (unit not installed)."
        fi
    done
}

purge_db_packages_safely() {
    local pkg
    local purge_list=()
    local candidates=(
        mysql-server
        mysql-server-8.0
        mysql-client
        mysql-client-8.0
        mysql-common
        mariadb-server
        mariadb-server-10.11
        mariadb-client
        mariadb-common
    )

    for pkg in "${candidates[@]}"; do
        if dpkg-query -W -f='${Status}' "${pkg}" >/dev/null 2>&1; then
            purge_list+=("${pkg}")
        fi
    done

    if [[ "${#purge_list[@]}" -eq 0 ]]; then
        info "No installed MySQL/MariaDB packages found to purge."
        return
    fi

    run env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew purge "${purge_list[@]}" || true
}

reinstall_mariadb_stack_fresh() {
    warn "Fresh reinstall MariaDB requested by user."
    stop_db_services_safely
    purge_db_packages_safely
    run env DEBIAN_FRONTEND=noninteractive apt-get -y autoremove || true
    run rm -rf /etc/mysql /var/lib/mysql /var/lib/mysql-files /var/lib/mysql-keyring || true
    repair_apt_dpkg_state
    disable_mysql_apt_repos
    run apt update
    run env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew install mariadb-server
    ensure_package mariadb-client true
    DB_SERVICE="mariadb"
    ok "Fresh MariaDB reinstall completed."
}

cleanup_broken_db_packages() {
    warn "Cleaning broken database packages before retry..."
    stop_db_services_safely
    purge_db_packages_safely
    repair_apt_dpkg_state
}

reset_database_stack() {
    if [[ "${RESET_DB_STACK}" != "true" ]]; then
        return
    fi

    warn "--reset-db enabled: purging existing DB packages and data."
    stop_db_services_safely
    purge_db_packages_safely
    run env DEBIAN_FRONTEND=noninteractive apt-get -y autoremove || true
    run rm -rf /etc/mysql /var/lib/mysql /var/log/mysql || true
    run rm -f /etc/apt/sources.list.d/mariadb.list.old_1 /etc/apt/sources.list.d/mariadb.list.old_2 || true
    disable_mysql_apt_repos
    run apt update
    ok "Database stack reset completed."
}

reinstall_database_stack_fresh() {
    local previous_reset_flag
    previous_reset_flag="${RESET_DB_STACK}"
    RESET_DB_STACK="true"
    reset_database_stack
    RESET_DB_STACK="${previous_reset_flag}"
    install_mariadb_phpmyadmin
}

ensure_ondrej_repo() {
    if grep -Rqs "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
        ok "Repository already present: ondrej/php"
        return
    fi

    info "Adding ondrej/php repository for multi-version PHP packages"
    ensure_package software-properties-common
    run add-apt-repository -y ppa:ondrej/php
}

install_web_server() {
    if wants_apache; then
        ensure_package apache2
        ensure_package php
        ensure_package php-cli
        ensure_package php-fpm
        ensure_package libapache2-mod-php true
        if ! is_package_installed "libapache2-mod-php" && [[ -n "${PHP_DEFAULT_VERSION}" ]]; then
            ensure_package "libapache2-mod-php${PHP_DEFAULT_VERSION}" true
        fi

        local php_module=""
        if [[ -n "${PHP_DEFAULT_VERSION}" && -f "/etc/apache2/mods-available/php${PHP_DEFAULT_VERSION}.load" ]]; then
            php_module="php${PHP_DEFAULT_VERSION}"
        else
            php_module="$(ls -1 /etc/apache2/mods-available/php*.load 2>/dev/null | sed 's#.*/##' | sed 's#\.load$##' | sort -V | tail -n1 || true)"
        fi
        if [[ -n "${php_module}" ]]; then
            info "Enabling Apache PHP module: ${php_module}"
            run a2enmod "${php_module}" || true
        else
            warn "No Apache PHP module file found under /etc/apache2/mods-available/php*.load"
        fi
        run a2enmod rewrite || true

        run systemctl enable apache2 || true
        run systemctl restart apache2 || true

        if apache2ctl -M 2>/dev/null | grep -E -q 'php(_module|[0-9.]*_module)'; then
            ok "Apache PHP module is loaded."
        else
            warn "Apache PHP module not detected. Check: apache2ctl -M | grep php"
        fi

        if [[ -d /var/www/html ]]; then
            cat > /var/www/html/info.php <<'EOF'
<?php phpinfo();
EOF
            chmod 644 /var/www/html/info.php || true
            ok "PHP test file created: /var/www/html/info.php"
            warn "Remove /var/www/html/info.php after testing to avoid exposing server details."
        fi

        ok "Apache is ready."
    fi

    if wants_nginx; then
        ensure_package nginx
        ok "Nginx is ready."
    fi
}

install_php_versions() {
    local version

    for version in "${PHP_VERSIONS[@]}"; do
        if wants_apache; then
            ensure_package "php${version}" true
            ensure_package "php${version}-cli" true
            ensure_package "php${version}-fpm" true
            ensure_package "php${version}-common" true
            ensure_package "php${version}-mbstring" true
            ensure_package "php${version}-xml" true
            ensure_package "php${version}-curl" true
            ensure_package "php${version}-zip" true
            ensure_package "php${version}-sqlite3" true
            ensure_package "php${version}-mysql" true
            ensure_package "libapache2-mod-php${version}" true
        fi
    done

    ok "Requested PHP versions checked for ${WEB_SERVER}: ${PHP_VERSIONS_RAW}"
}

set_php_ini_kv() {
    local ini_file="$1"
    local key="$2"
    local value="$3"

    [[ -f "${ini_file}" ]] || return 0

    if grep -Eq "^[[:space:]]*;?[[:space:]]*${key}[[:space:]]*=" "${ini_file}"; then
        run sed -i -E "s|^[[:space:]]*;?[[:space:]]*${key}[[:space:]]*=.*|${key} = ${value}|g" "${ini_file}"
    else
        printf "\n%s = %s\n" "${key}" "${value}" >> "${ini_file}"
    fi
}

apply_php_runtime_defaults() {
    local version sapi ini_file
    info "Applying recommended PHP defaults for installer baseline"

    for version in "${PHP_VERSIONS[@]}"; do
        [[ -d "/etc/php/${version}" ]] || continue

        for sapi in apache2 fpm cli; do
            ini_file="/etc/php/${version}/${sapi}/php.ini"
            [[ -f "${ini_file}" ]] || continue

            set_php_ini_kv "${ini_file}" "memory_limit" "512M"
            set_php_ini_kv "${ini_file}" "upload_max_filesize" "256M"
            set_php_ini_kv "${ini_file}" "post_max_size" "256M"
            set_php_ini_kv "${ini_file}" "max_execution_time" "300"
            set_php_ini_kv "${ini_file}" "max_input_vars" "5000"
            set_php_ini_kv "${ini_file}" "display_errors" "Off"
            set_php_ini_kv "${ini_file}" "log_errors" "On"
            set_php_ini_kv "${ini_file}" "allow_url_fopen" "On"
        done

        if systemctl cat "php${version}-fpm.service" >/dev/null 2>&1; then
            run systemctl restart "php${version}-fpm" || true
        fi
    done

    if wants_apache && systemctl cat apache2.service >/dev/null 2>&1; then
        run systemctl restart apache2 || true
    fi
    if wants_nginx && systemctl cat nginx.service >/dev/null 2>&1; then
        run systemctl restart nginx || true
    fi

    ok "Recommended PHP runtime defaults applied."
}

ensure_default_php_binary() {
    local candidate current_php
    candidate="/usr/bin/php${PHP_DEFAULT_VERSION}"

    if [[ ! -x "${candidate}" ]]; then
        warn "Requested default PHP ${PHP_DEFAULT_VERSION} is not installed. Installing now..."
        ensure_package "php${PHP_DEFAULT_VERSION}"
        ensure_package "php${PHP_DEFAULT_VERSION}-cli"
    fi

    if [[ ! -x "${candidate}" ]]; then
        fail "Unable to force default PHP ${PHP_DEFAULT_VERSION}. Missing binary: ${candidate}"
    fi

    current_php="$(command -v php 2>/dev/null || true)"
    if [[ "${current_php}" == "${candidate}" ]]; then
        ok "Default PHP binary already set: ${candidate}"
        return
    fi

    run ln -sf "${candidate}" /usr/bin/php
    if command -v update-alternatives >/dev/null 2>&1; then
        run update-alternatives --install /usr/bin/php php "${candidate}" 100 || true
        run update-alternatives --set php "${candidate}" || true
    fi
    ok "Configured default PHP binary: ${candidate}"
}

generate_random_password() {
    local generated
    if command -v openssl >/dev/null 2>&1; then
        generated="$(openssl rand -base64 36 | tr -dc 'A-Za-z0-9' | head -c 24)"
    else
        generated="$(tr -dc 'A-Za-z0-9' </dev/urandom | head -c 24)"
    fi

    if [[ -z "${generated}" ]]; then
        fail "Unable to generate random database password."
    fi

    echo "${generated}"
}

escape_for_sed() {
    printf '%s' "$1" | sed -e 's/[\/&|]/\\&/g'
}

escape_sql_string() {
    printf '%s' "$1" | sed "s/'/''/g"
}

upsert_env_value() {
    local file="$1"
    local key="$2"
    local value="$3"
    local escaped
    escaped="$(escape_for_sed "${value}")"

    if grep -q "^${key}=" "${file}"; then
        run sed -i "s|^${key}=.*|${key}=${escaped}|" "${file}"
    else
        printf '\n%s=%s\n' "${key}" "${value}" >> "${file}"
    fi
}

setup_mariadb_database() {
    local sql_db sql_user sql_password db_cli
    info "Configuring MariaDB database and Laravel .env"

    if [[ -z "${DB_PASSWORD}" ]]; then
        DB_PASSWORD="$(generate_random_password)"
        ok "Generated random database password for ${DB_USER}."
    fi

    sql_db="$(escape_sql_string "${DB_NAME}")"
    sql_user="$(escape_sql_string "${DB_USER}")"
    sql_password="$(escape_sql_string "${DB_PASSWORD}")"

    ensure_database_running
    db_cli="$(detect_db_cli)"
    if [[ -z "${db_cli}" ]]; then
        fail "No database CLI found (mysql/mariadb)."
    fi
    run "${db_cli}" -e "CREATE DATABASE IF NOT EXISTS \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_user}'@'127.0.0.1' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_user}'@'localhost' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "ALTER USER '${sql_user}'@'127.0.0.1' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "ALTER USER '${sql_user}'@'localhost' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "GRANT ALL PRIVILEGES ON \`${sql_db}\`.* TO '${sql_user}'@'127.0.0.1';"
    run "${db_cli}" -e "GRANT ALL PRIVILEGES ON \`${sql_db}\`.* TO '${sql_user}'@'localhost';"
    run "${db_cli}" -e "FLUSH PRIVILEGES;"

    upsert_env_value ".env" "DB_CONNECTION" "mysql"
    upsert_env_value ".env" "DB_HOST" "127.0.0.1"
    upsert_env_value ".env" "DB_PORT" "3306"
    upsert_env_value ".env" "DB_DATABASE" "${DB_NAME}"
    upsert_env_value ".env" "DB_USERNAME" "${DB_USER}"
    upsert_env_value ".env" "DB_PASSWORD" "${DB_PASSWORD}"
    upsert_env_value ".env" "PDNS_DB_HOST" "127.0.0.1"
    upsert_env_value ".env" "PDNS_DB_PORT" "3306"
    upsert_env_value ".env" "PDNS_DB_DATABASE" "${DB_NAME}"
    upsert_env_value ".env" "PDNS_DB_USERNAME" "${DB_USER}"
    upsert_env_value ".env" "PDNS_DB_PASSWORD" "${DB_PASSWORD}"
    upsert_env_value ".env" "WEBMAIL_URL" "http://127.0.0.1:8080/"
    ok "MariaDB database/user created and .env updated."
}

ensure_composer_usable() {
    if command -v composer >/dev/null 2>&1 && COMPOSER_ALLOW_SUPERUSER=1 composer --no-interaction --version >/dev/null 2>&1; then
        ok "Composer is usable."
        return
    fi

    warn "Composer command is present but not runnable. Attempting to repair runtime."
    ensure_default_php_binary

    if command -v composer >/dev/null 2>&1 && COMPOSER_ALLOW_SUPERUSER=1 composer --no-interaction --version >/dev/null 2>&1; then
        ok "Composer repaired successfully."
        return
    fi

    warn "Installing standalone Composer to /usr/local/bin/composer."
    run curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    run php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    run chmod +x /usr/local/bin/composer
    run env COMPOSER_ALLOW_SUPERUSER=1 composer --no-interaction --version
}

extract_required_php_version_from_composer_error() {
    local log_file="$1"
    local version=""

    # Example: "requires php ^8.3 -> your php version (8.2.30) does not satisfy that requirement."
    version="$(grep -Eo 'requires php [^ ]+' "${log_file}" 2>/dev/null | tail -n1 | grep -Eo '[0-9]+\.[0-9]+' | head -n1 || true)"
    echo "${version}"
}

ensure_php_version_for_composer() {
    local version="$1"

    if [[ -z "${version}" ]]; then
        warn "Could not detect required PHP version from Composer error output."
        return 1
    fi

    warn "Composer requires PHP ${version}. Attempting to prepare and switch CLI PHP."
    ensure_package "php${version}" true
    ensure_package "php${version}-cli" true
    ensure_package "php${version}-common" true
    ensure_package "php${version}-mbstring" true
    ensure_package "php${version}-xml" true
    ensure_package "php${version}-curl" true
    ensure_package "php${version}-zip" true
    ensure_package "php${version}-sqlite3" true
    ensure_package "php${version}-mysql" true

    if [[ ! -x "/usr/bin/php${version}" ]]; then
        warn "PHP binary not found after install attempt: /usr/bin/php${version}"
        return 1
    fi

    PHP_DEFAULT_VERSION="${version}"
    ensure_default_php_binary
    ok "Composer PHP compatibility fix applied (default CLI PHP ${version})."
    return 0
}

install_composer_dependencies() {
    local status=0
    local decision=""
    local log_file=""
    local required_php=""

    while true; do
        log_file="$(mktemp /tmp/serverpanel-composer-install.XXXXXX.log)"
        info "Running: env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-dev --optimize-autoloader"
        set +e
        env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-dev --optimize-autoloader 2>&1 | tee "${log_file}"
        status=${PIPESTATUS[0]}
        set -e

        if [[ "${status}" -eq 0 ]]; then
            return 0
        fi

        warn "Command failed with exit code ${status}: composer install"
        required_php="$(extract_required_php_version_from_composer_error "${log_file}")"

        if [[ -t 0 ]]; then
            echo -e "${YELLOW}[WARN]${NC} Choose action:"
            echo "  1. Retry composer install"
            if [[ -n "${required_php}" ]]; then
                echo "  2. Auto-switch/install PHP ${required_php} and retry"
            else
                echo "  2. Auto-switch/install required PHP and retry (auto-detect)"
            fi
            echo "  3. Skip composer install"
            echo "  4. Abort installer"
            read -r decision

            case "${decision,,}" in
                1|r|retry|"")
                    info "Retrying composer install..."
                    ;;
                2|fix|php)
                    if ensure_php_version_for_composer "${required_php}"; then
                        info "Retrying composer install with updated PHP..."
                    else
                        warn "Auto PHP fix failed."
                    fi
                    ;;
                3|s|skip)
                    warn "Skipping composer install by user choice."
                    return 0
                    ;;
                4|a|abort)
                    fail "Aborted after composer install failure."
                    ;;
                *)
                    warn "Invalid choice. Please enter 1, 2, 3, or 4."
                    ;;
            esac
            continue
        fi

        fail "Composer install failed in non-interactive mode."
    done
}

verify_php_extensions() {
    local missing=()
    local ext

    ensure_default_php_binary

    # json is built-in on modern PHP; verify + ensure PDO drivers are active.
    for ext in json PDO pdo_mysql pdo_sqlite; do
        if ! php -m | grep -q -i "^${ext}$"; then
            missing+=("${ext}")
        fi
    done

    if [[ "${#missing[@]}" -eq 0 ]]; then
        ok "PHP runtime extensions available: json, PDO, pdo_mysql, pdo_sqlite"
        return
    fi

    warn "Missing PHP extensions in current CLI runtime: ${missing[*]}"
    warn "Ensure corresponding phpX.Y-mysql/phpX.Y-sqlite3 packages are installed for the active /usr/bin/php."
}

get_node_major_version() {
    local node_ver
    if ! command -v node >/dev/null 2>&1; then
        echo ""
        return
    fi
    node_ver="$(node -v 2>/dev/null | sed 's/^v//')"
    echo "${node_ver%%.*}"
}

ensure_nodejs_for_vite() {
    local current_major has_npm
    current_major="$(get_node_major_version)"
    has_npm="false"
    if command -v npm >/dev/null 2>&1; then
        has_npm="true"
    fi

    if [[ -n "${current_major}" ]] && (( current_major >= NODEJS_REQUIRED_MAJOR )) && [[ "${has_npm}" == "true" ]]; then
        ok "Node.js and npm are compatible for Vite build (node v${current_major}, required: ${NODEJS_REQUIRED_MAJOR}+)."
        return
    fi

    warn "Node.js/npm upgrade required for Vite build. Node: ${current_major:-missing}, npm: ${has_npm}, required node: ${NODEJS_REQUIRED_MAJOR}+"
    info "Installing Node.js ${NODEJS_REQUIRED_MAJOR}.x from NodeSource"
    run bash -lc "curl -fsSL https://deb.nodesource.com/setup_${NODEJS_REQUIRED_MAJOR}.x | bash -"
    run env DEBIAN_FRONTEND=noninteractive apt-get -y install nodejs
    if ! command -v npm >/dev/null 2>&1; then
        warn "npm not found after nodejs install. Trying corepack enable."
        run corepack enable || true
    fi
    if command -v npm >/dev/null 2>&1; then
        run npm install -g npm || true
    fi

    current_major="$(get_node_major_version)"
    has_npm="false"
    if command -v npm >/dev/null 2>&1; then
        has_npm="true"
    fi
    if [[ -z "${current_major}" ]] || (( current_major < NODEJS_REQUIRED_MAJOR )); then
        fail "Node.js upgrade failed. Detected: ${current_major:-missing}, required: ${NODEJS_REQUIRED_MAJOR}+."
    fi
    if [[ "${has_npm}" != "true" ]]; then
        fail "npm is still missing after Node.js setup. Check apt and NodeSource repository status."
    fi

    ok "Node.js/npm are ready for Vite build (node v${current_major})."
}

ensure_node_build_permissions() {
    # ZIP deployments can drop executable bits in node_modules/.bin scripts.
    if [[ -d "node_modules/.bin" ]]; then
        run chmod -R u+x node_modules/.bin || true
    fi
    if [[ -f "node_modules/vite/bin/vite.js" ]]; then
        run chmod u+x node_modules/vite/bin/vite.js || true
    fi
}

cleanup_vite_hot_file() {
    local project_dir
    project_dir="${1:-${PROJECT_DIR}}"
    if [[ -f "${project_dir}/public/hot" ]]; then
        info "Removing stale Vite hot file: ${project_dir}/public/hot"
        run rm -f "${project_dir}/public/hot"
    fi
}

write_install_credentials_log() {
    local server_ip login_url root_log project_log owner
    server_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    login_url="http://${server_ip:-127.0.0.1}:${PANEL_PORT}/login"
    root_log="/root/serverpanel_credentials.txt"
    project_log="${PROJECT_DIR}/storage/logs/serverpanel_credentials.txt"
    owner="$(web_owner_group)"

    cat > "${root_log}" <<EOF
============================================================
ServerPanel Installation Credentials
Generated: $(date '+%Y-%m-%d %H:%M:%S %Z')
============================================================

Panel URL
- ${login_url}

Database
- Service  : ${DB_SERVICE:-unknown}
- Name     : ${DB_NAME}
- User     : ${DB_USER}
- Password : ${DB_PASSWORD}

Seeded Panel Users
- Super Admin : test@example.com / password
- Reseller    : reseller@example.com / password
- General User: user@example.com / password

Important
- Change all default panel passwords immediately after first login.
- Keep this file secure; it contains database credentials.
EOF

    run mkdir -p "$(dirname "${project_log}")"
    run cp "${root_log}" "${project_log}"
    run chmod 600 "${root_log}"
    run chown "${owner}" "${project_log}" || true
    run chmod 640 "${project_log}" || true
    LOGIN_CREDENTIALS_READY="true"
    ok "Credential log created: ${root_log}"
}

install_mariadb_phpmyadmin() {
    local phpmyadmin_webserver
    if ! is_package_available mariadb-server; then
        fail "Package not available: mariadb-server"
    fi

    ensure_package mariadb-server true
    if ! is_package_installed mariadb-server; then
        cleanup_broken_db_packages
        warn "Retrying mariadb-server install after DB cleanup..."
        ensure_package mariadb-server true
    fi
    if ! is_package_installed mariadb-server; then
        fail "Unable to install mariadb-server."
    fi

    if is_package_available mariadb-client; then
        ensure_package mariadb-client true
    fi

    if is_package_installed phpmyadmin; then
        ok "Already installed: phpmyadmin"
        return
    fi

    if ! is_package_available phpmyadmin; then
        warn "Package not available, skipping: phpmyadmin"
        return
    fi

    phpmyadmin_webserver="none"
    if wants_apache; then
        phpmyadmin_webserver="apache2"
    fi

    echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect ${phpmyadmin_webserver}" | debconf-set-selections
    echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
    run env DEBIAN_FRONTEND=noninteractive apt install -y phpmyadmin
}

web_owner_group() {
    if wants_apache || wants_nginx; then
        echo "www-data:www-data"
    else
        echo "www-data:www-data"
    fi
}

download_file() {
    local url="$1"
    local output="$2"

    if command -v curl >/dev/null 2>&1; then
        run curl -fL --retry 3 --connect-timeout 10 -o "${output}" "${url}"
        return
    fi

    if command -v wget >/dev/null 2>&1; then
        run wget -O "${output}" "${url}"
        return
    fi

    fail "Neither curl nor wget is installed. Install one of them and re-run."
}

url_exists() {
    local url="$1"

    if command -v curl >/dev/null 2>&1; then
        curl -fsIL --connect-timeout 10 --retry 2 "${url}" >/dev/null 2>&1
        return
    fi

    if command -v wget >/dev/null 2>&1; then
        wget --spider -q "${url}" >/dev/null 2>&1
        return
    fi

    fail "Neither curl nor wget is installed. Install one of them and re-run."
}

resolve_project_url_from_base() {
    local base_url
    local candidate
    local candidates=(
        "ServerInstaller.tar.gz"
        "ServerInstaller.tgz"
        "ServerInstaller.zip"
        "ServerPanel.tar.gz"
        "ServerPanel.tgz"
        "ServerPanel.zip"
    )

    base_url="${PROJECT_BASE_URL%/}/"
    for archive_name in "${candidates[@]}"; do
        candidate="${base_url}${archive_name}"
        if url_exists "${candidate}"; then
            PROJECT_URL="${candidate}"
            ok "Archive found from base URL: ${PROJECT_URL}"
            return
        fi
    done

    fail "No project archive found at base URL: ${base_url}
Expected one of:
- ServerInstaller.tar.gz
- ServerInstaller.tgz
- ServerInstaller.zip
- ServerPanel.tar.gz
- ServerPanel.tgz
- ServerPanel.zip"
}

download_project_from_url() {
    local cleaned_url archive_name archive_path extract_dir detected_dir target_dir
    cleaned_url="${PROJECT_URL%%#*}"
    cleaned_url="${cleaned_url%%\?*}"
    archive_name="$(basename "${cleaned_url}")"

    case "${archive_name}" in
        *.tar.gz|*.tgz|*.zip)
            ;;
        *)
            fail "--project-url must point to a .tar.gz, .tgz, or .zip archive. Received: ${PROJECT_URL}"
            ;;
    esac

    if [[ -n "${PROJECT_TARGET}" ]]; then
        target_dir="${PROJECT_TARGET}"
    else
        target_dir="${SCRIPT_DIR}/ServerPanel"
    fi

    if [[ -e "${target_dir}" ]]; then
        if [[ "${FORCE_REPLACE_TARGET}" == "true" ]]; then
            warn "Target already exists and will be replaced: ${target_dir}"
            safe_remove_target_dir "${target_dir}"
        else
            if is_valid_project_dir "${target_dir}"; then
                PROJECT_DIR="$(cd "${target_dir}" && pwd)"
                warn "Target already exists, reusing existing project: ${PROJECT_DIR}"
                return
            fi
            fail "Target path already exists but is not a valid Laravel project: ${target_dir}. Remove it or pass --project-target /new/path"
        fi
    fi

    archive_path="/tmp/${archive_name}"
    extract_dir="$(mktemp -d /tmp/serverpanel_extract.XXXXXX)"

    info "Downloading project archive: ${PROJECT_URL}"
    download_file "${PROJECT_URL}" "${archive_path}"
    case "${archive_name}" in
        *.tar.gz|*.tgz)
            run tar -xzf "${archive_path}" -C "${extract_dir}"
            ;;
        *.zip)
            if command -v unzip >/dev/null 2>&1; then
                run unzip -q "${archive_path}" -d "${extract_dir}"
            else
                fail "unzip is required for .zip archives. Install unzip or use a .tar.gz archive."
            fi
            ;;
    esac

    detected_dir="$(find "${extract_dir}" -maxdepth 6 -type f -name artisan 2>/dev/null | sed 's#/artisan$##' | while IFS= read -r dir; do
        if is_valid_project_dir "${dir}"; then
            echo "${dir}"
            break
        fi
    done)"

    if [[ -z "${detected_dir}" ]]; then
        fail "Archive downloaded, but no valid Laravel project found (artisan/composer.json)."
    fi

    run mkdir -p "$(dirname "${target_dir}")"
    run mv "${detected_dir}" "${target_dir}"
    PROJECT_DIR="$(cd "${target_dir}" && pwd)"
    ok "Project downloaded, moved, and detected: ${PROJECT_DIR}"
}

detect_project_dir() {
    if [[ -n "${PROJECT_BASE_URL}" ]]; then
        resolve_project_url_from_base
    fi

    if [[ -n "${PROJECT_URL}" ]]; then
        download_project_from_url
        return
    fi

    if [[ -n "${PROJECT_HINT}" ]]; then
        if is_valid_project_dir "${PROJECT_HINT}"; then
            PROJECT_DIR="$(cd "${PROJECT_HINT}" && pwd)"
            ok "Using --project-dir: ${PROJECT_DIR}"
            return
        fi
        fail "--project-dir is invalid: ${PROJECT_HINT} (artisan/composer.json not found)"
    fi

    local candidates=(
        "${SCRIPT_DIR}"
        "${SCRIPT_DIR}/ServerPanel"
        "${SCRIPT_DIR}/../ServerPanel"
        "${PWD}/."
        "${PWD}/ServerPanel"
        "${PWD}/../ServerPanel"
    )

    for candidate in "${candidates[@]}"; do
        if is_valid_project_dir "${candidate}"; then
            PROJECT_DIR="$(cd "${candidate}" && pwd)"
            ok "File found: ${PROJECT_DIR}/artisan"
            return
        fi
    done

    local discovered
    discovered="$(find "${SCRIPT_DIR}" "${PWD}" -maxdepth 4 -type f -name artisan 2>/dev/null | sed 's#/artisan$##' | awk '!seen[$0]++')"
    if [[ -n "${discovered}" ]]; then
        while IFS= read -r dir; do
            if is_valid_project_dir "${dir}"; then
                PROJECT_DIR="$(cd "${dir}" && pwd)"
                ok "File found by scan: ${PROJECT_DIR}/artisan"
                return
            fi
        done <<< "${discovered}"
    fi

    fail "Project files not found.
Checked:
- ${SCRIPT_DIR}
- ${SCRIPT_DIR}/ServerPanel
- ${SCRIPT_DIR}/../ServerPanel
- ${PWD}
- ${PWD}/ServerPanel
- ${PWD}/../ServerPanel
Run with: bash int-tool.sh --project-dir /absolute/path/to/ServerPanel
Or:      bash int-tool.sh --project-url http://host/path/archive.tar.gz
Or:      bash int-tool.sh --project-url http://host/path/archive.zip
Or:      bash int-tool.sh --base-url http://host/path/ServerInstaller/"
}

install_packages() {
    info "Step 1/5: Installing required packages and validating existing stack"
    disable_mysql_apt_repos
    run apt update
    reset_database_stack

    ensure_package ca-certificates
    ensure_package gnupg
    ensure_package lsb-release
    ensure_package software-properties-common
    ensure_package curl
    ensure_package wget
    ensure_package git
    ensure_package unzip
    ensure_package sqlite3
    ensure_package ufw
    ensure_package openssh-server
    ensure_package composer
    ensure_package nodejs
    info "Skipping distro npm package install (NodeSource nodejs provides npm and avoids apt dependency conflicts)."
    ensure_package debconf-utils
    ensure_package php-cli
    ensure_package php-fpm
    ensure_package php-mbstring
    ensure_package php-xml
    ensure_package php-curl
    ensure_package php-zip
    ensure_package php-sqlite3
    ensure_package php-mysql

    ensure_ondrej_repo
    disable_mysql_apt_repos
    run apt update

    install_web_server
    install_php_versions
    apply_php_runtime_defaults
    install_mariadb_phpmyadmin
    ensure_default_php_binary
    info "Default PHP is ready. Starting Composer usability check..."
    ensure_composer_usable
    info "Composer check completed. Starting PHP extension verification..."
    verify_php_extensions

    ok "Package verification/installation completed."
}

setup_ssh() {
    info "Step 2/5: Enabling SSH service"
    run systemctl enable --now ssh
    run ufw allow OpenSSH || true
    run ufw allow 22/tcp || true
    run ufw allow "${PANEL_PORT}/tcp" || true
    ok "SSH enabled and firewall rules added."
}

setup_panel_startup_service() {
    local db_after
    info "Configuring ServerPanel startup service on port ${PANEL_PORT}"
    db_after="${DB_SERVICE:-mariadb}.service"
    cat > /etc/systemd/system/serverpanel.service <<EOF
[Unit]
Description=ServerPanel Laravel HTTP Service
After=network.target ${db_after}

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php artisan serve --host=0.0.0.0 --port=${PANEL_PORT}
Restart=always
RestartSec=5
Environment=APP_ENV=production

[Install]
WantedBy=multi-user.target
EOF
    run systemctl daemon-reload
    run systemctl enable --now serverpanel
    ok "ServerPanel startup service enabled."
}

setup_apache_proxy_default_site() {
    if ! wants_apache; then
        return
    fi

    info "Configuring Apache default site to proxy :80 -> :${PANEL_PORT}"
    run a2enmod proxy proxy_http headers rewrite
    cat > /etc/apache2/sites-available/serverpanel-proxy.conf <<EOF
<VirtualHost *:80>
    ServerName _

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:${PANEL_PORT}/
    ProxyPassReverse / http://127.0.0.1:${PANEL_PORT}/

    ErrorLog \${APACHE_LOG_DIR}/serverpanel_error.log
    CustomLog \${APACHE_LOG_DIR}/serverpanel_access.log combined
</VirtualHost>
EOF
    run a2dissite 000-default || true
    run a2ensite serverpanel-proxy
    if command -v apache2ctl >/dev/null 2>&1; then
        if ! apache2ctl configtest; then
            warn "Apache config test failed. Skipping Apache restart; panel stays available on port ${PANEL_PORT}."
            return
        fi
    fi

    # Apache reverse proxy is optional for panel access (panel still works on PANEL_PORT),
    # so do not trigger the generic retry prompt for apache2 restart failures.
    local apache_restart_status=0
    set +e
    systemctl restart apache2
    apache_restart_status=$?
    set -e

    if [[ "${apache_restart_status}" -eq 0 ]] && systemctl is-active --quiet apache2; then
        ok "Apache default IP route is ready."
    else
        warn "Apache restart failed (exit ${apache_restart_status}). Panel stays available on port ${PANEL_PORT}."
        warn "Quick checks:"
        echo "  systemctl status apache2 --no-pager -l"
        echo "  journalctl -u apache2 -n 80 --no-pager"
        echo "  ss -ltnp | grep ':80'"
    fi
}

setup_nginx_proxy_default_site() {
    if ! wants_nginx; then
        return
    fi

    info "Configuring Nginx default site to proxy :80 -> :${PANEL_PORT}"
    cat > /etc/nginx/sites-available/serverpanel-proxy <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;

    location / {
        proxy_pass http://127.0.0.1:${PANEL_PORT};
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
    }
}
EOF
    run rm -f /etc/nginx/sites-enabled/default || true
    run ln -sf /etc/nginx/sites-available/serverpanel-proxy /etc/nginx/sites-enabled/serverpanel-proxy
    if command -v nginx >/dev/null 2>&1; then
        if ! nginx -t; then
            warn "Nginx config test failed. Skipping nginx restart; panel stays available on port ${PANEL_PORT}."
            return
        fi
    fi

    local nginx_restart_status=0
    set +e
    systemctl restart nginx
    nginx_restart_status=$?
    set -e

    if [[ "${nginx_restart_status}" -eq 0 ]] && systemctl is-active --quiet nginx; then
        ok "Nginx default IP route is ready."
    else
        warn "Nginx restart failed (exit ${nginx_restart_status}). Panel stays available on port ${PANEL_PORT}."
        warn "Quick checks:"
        echo "  systemctl status nginx --no-pager -l"
        echo "  journalctl -u nginx -n 80 --no-pager"
        echo "  ss -ltnp | grep ':80'"
    fi
}

setup_application() {
    info "Step 3/5: Preparing Laravel application"
    cd "${PROJECT_DIR}"

    if [[ ! -f ".env" ]]; then
        run cp .env.example .env
        ok ".env created from .env.example"
    else
        info ".env already exists, keeping current values."
    fi

    upsert_env_value ".env" "APP_ENV" "production"
    upsert_env_value ".env" "APP_DEBUG" "false"

    setup_mariadb_database

    install_composer_dependencies
    ensure_nodejs_for_vite
    run npm install
    ensure_node_build_permissions
    run npm run build
    cleanup_vite_hot_file "${PROJECT_DIR}"
    run php artisan key:generate --force

    if grep -q "^DB_CONNECTION=sqlite$" ".env"; then
        if [[ ! -f "database/database.sqlite" ]]; then
            run mkdir -p database
            run touch database/database.sqlite
            ok "Created database/database.sqlite"
        else
            info "database/database.sqlite already exists."
        fi
    fi

    run php artisan config:clear
    run php artisan migrate --force
    run php artisan db:seed --force
    run php artisan optimize
    write_install_credentials_log
    ok "Application setup completed."
}

setup_permissions() {
    local owner
    info "Step 4/5: Setting permissions"
    owner="$(web_owner_group)"
    run chown -R "${owner}" "${PROJECT_DIR}"
    run chmod -R 775 "${PROJECT_DIR}/storage" "${PROJECT_DIR}/bootstrap/cache"
    ok "Permissions updated."
}

start_services() {
    info "Step 5/5: Starting web/database services"
    if wants_apache; then
        if systemctl cat apache2.service >/dev/null 2>&1; then
            run systemctl unmask apache2 || true
            run systemctl daemon-reload || true
            run systemctl enable apache2 || true
            if ! systemctl is-enabled apache2 >/dev/null 2>&1; then
                warn "Could not enable apache2 on startup. Continuing with runtime start."
            fi
        else
            warn "apache2.service not found. Skipping Apache startup."
        fi
    fi
    if wants_nginx; then
        if systemctl cat nginx.service >/dev/null 2>&1; then
            run systemctl unmask nginx || true
            run systemctl daemon-reload || true
            run systemctl enable nginx || true
            if ! systemctl is-enabled nginx >/dev/null 2>&1; then
                warn "Could not enable nginx on startup. Continuing with runtime start."
            fi
        else
            warn "nginx.service not found. Skipping nginx startup."
        fi
    fi
    ensure_database_running
    run systemctl restart ssh
    setup_panel_startup_service
    run systemctl restart serverpanel
    # apache + nginx cannot both listen on :80. If both selected, prefer nginx as frontend.
    if [[ "${WEB_SERVER}" == "both" ]]; then
        run systemctl stop apache2 || true
        setup_nginx_proxy_default_site
        warn "Both selected: nginx is active on :80, apache is installed but stopped to avoid conflict."
    elif [[ "${WEB_SERVER}" == "apache" ]]; then
        setup_apache_proxy_default_site
    elif [[ "${WEB_SERVER}" == "nginx" ]]; then
        setup_nginx_proxy_default_site
    fi
    ok "Services are running."
}

show_summary() {
    local server_ip
    server_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    echo
    echo -e "${GREEN}============================================================${NC}"
    echo -e "${GREEN} Installation Completed ${NC}"
    echo -e "${GREEN}============================================================${NC}"
    echo -e "Project Path : ${PROJECT_DIR}"
    echo -e "Web Server   : ${WEB_SERVER}"
    echo -e "PHP Versions : ${PHP_VERSIONS_RAW}"
    echo -e "PHP Default  : ${PHP_DEFAULT_VERSION}"
    echo -e "DB Name      : ${DB_NAME}"
    echo -e "DB User      : ${DB_USER}"
    echo -e "DB Password  : ${DB_PASSWORD}"
    echo -e "DB Service   : ${DB_SERVICE:-unknown}"
    echo -e "Panel Port   : ${PANEL_PORT}"
    echo -e "Panel URL    : http://${server_ip:-server_ip}:${PANEL_PORT}"
    if [[ "${LOGIN_CREDENTIALS_READY}" == "true" ]]; then
        echo -e "Creds Log    : /root/serverpanel_credentials.txt"
    fi
    echo -e "Server IP    : ${server_ip:-unknown}"
    echo -e "SSH Login    : ssh <username>@${server_ip:-server_ip}"
    echo -e "SSH Status   : systemctl status ssh"
    echo
}

ask_input() {
    local prompt="$1"
    local default="${2:-}"
    local value=""
    if [[ -n "${default}" ]]; then
        read -r -p "${prompt} [${default}]: " value
        echo "${value:-${default}}"
    else
        read -r -p "${prompt}: " value
        echo "${value}"
    fi
}

ask_yes_no() {
    local prompt="$1"
    local default="${2:-Y}"
    local answer=""
    if [[ "${default^^}" == "Y" ]]; then
        read -r -p "${prompt} (Y/n): " answer
        answer="${answer:-Y}"
    else
        read -r -p "${prompt} (y/N): " answer
        answer="${answer:-N}"
    fi
    [[ "${answer,,}" == "y" || "${answer,,}" == "yes" ]]
}

panel_port_from_service_file() {
    local line
    if [[ -f /etc/systemd/system/serverpanel.service ]]; then
        line="$(grep -E 'ExecStart=.*--port=' /etc/systemd/system/serverpanel.service | head -n1 || true)"
        if [[ -n "${line}" ]]; then
            echo "${line##*--port=}" | awk '{print $1}'
            return
        fi
    fi
    echo "8090"
}

show_service_dashboard() {
    local svc state ip port
    echo
    echo -e "${CYAN}================ Service Dashboard ================${NC}"
    for svc in serverpanel apache2 nginx mariadb ssh; do
        if systemctl cat "${svc}.service" >/dev/null 2>&1; then
            state="$(systemctl is-active "${svc}" 2>/dev/null || true)"
            printf "%-12s : %s\n" "${svc}" "${state:-unknown}"
        fi
    done
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    port="$(panel_port_from_service_file)"
    echo "Panel URL    : http://${ip:-127.0.0.1}:${port}"
}

manage_single_service() {
    local svc action
    svc="$(ask_input "Service name (serverpanel/apache2/nginx/mariadb/ssh)" "serverpanel")"
    action="$(ask_input "Action (status/start/stop/restart/enable/disable/logs)" "status")"

    case "${action}" in
        logs)
            run journalctl -u "${svc}" -n 80 --no-pager
            ;;
        status|start|stop|restart|enable|disable)
            run systemctl "${action}" "${svc}"
            ;;
        *)
            warn "Invalid action: ${action}"
            ;;
    esac
}

repair_apache_proxy_menu() {
    local port proxy_server
    port="$(ask_input "Panel port for web proxy" "$(panel_port_from_service_file)")"
    if [[ ! "${port}" =~ ^[0-9]+$ ]] || (( port < 1 || port > 65535 )); then
        warn "Invalid port: ${port}"
        return
    fi
    proxy_server="$(ask_input "Proxy server (apache/nginx)" "apache")"
    PANEL_PORT="${port}"
    case "${proxy_server}" in
        nginx)
            WEB_SERVER="nginx"
            setup_nginx_proxy_default_site
            ;;
        apache|*)
            WEB_SERVER="apache"
            setup_apache_proxy_default_site
            ;;
    esac
}

show_port_diagnostics() {
    run bash -lc "ss -ltnp | egrep ':22 |:80 |:443 |:8090 |:3306 ' || true"
}

restart_apache_safely() {
    if ! systemctl cat apache2.service >/dev/null 2>&1; then
        return 0
    fi

    local apache_restart_status=0
    set +e
    systemctl restart apache2
    apache_restart_status=$?
    set -e

    if [[ "${apache_restart_status}" -eq 0 ]] && systemctl is-active --quiet apache2; then
        ok "Apache restarted successfully."
    else
        warn "Apache restart failed (exit ${apache_restart_status}). Panel stays available on port ${PANEL_PORT}."
        warn "Quick checks:"
        echo "  systemctl status apache2 --no-pager -l"
        echo "  journalctl -u apache2 -n 80 --no-pager"
        echo "  ss -ltnp | grep ':80'"
    fi
}

restart_nginx_safely() {
    if ! systemctl cat nginx.service >/dev/null 2>&1; then
        return 0
    fi

    local nginx_restart_status=0
    set +e
    systemctl restart nginx
    nginx_restart_status=$?
    set -e

    if [[ "${nginx_restart_status}" -eq 0 ]] && systemctl is-active --quiet nginx; then
        ok "Nginx restarted successfully."
    else
        warn "Nginx restart failed (exit ${nginx_restart_status}). Panel stays available on port ${PANEL_PORT}."
        warn "Quick checks:"
        echo "  systemctl status nginx --no-pager -l"
        echo "  journalctl -u nginx -n 80 --no-pager"
        echo "  ss -ltnp | grep ':80'"
    fi
}

show_credentials_file() {
    if [[ -f /root/serverpanel_credentials.txt ]]; then
        run cat /root/serverpanel_credentials.txt
        return
    fi
    warn "/root/serverpanel_credentials.txt not found."
    run bash -lc "find /var/www -maxdepth 6 -type f -name serverpanel_credentials.txt 2>/dev/null | head -n 5"
}

panel_workdir_from_service_file() {
    local line
    if [[ -f /etc/systemd/system/serverpanel.service ]]; then
        line="$(grep -E '^WorkingDirectory=' /etc/systemd/system/serverpanel.service | head -n1 || true)"
        if [[ -n "${line}" ]]; then
            echo "${line#WorkingDirectory=}"
            return
        fi
    fi
    echo ""
}

default_panel_project_dir() {
    local wd
    wd="$(panel_workdir_from_service_file)"
    if [[ -n "${wd}" && -f "${wd}/artisan" ]]; then
        echo "${wd}"
        return
    fi

    local candidates=(
        "${SCRIPT_DIR}/ServerPanel"
        "/var/www/ServerPanel"
        "/var/www/serverpanel"
        "/root/ServerPanel"
    )
    local c
    for c in "${candidates[@]}"; do
        if [[ -f "${c}/artisan" ]]; then
            echo "${c}"
            return
        fi
    done

    echo "/root/ServerPanel"
}

detect_project_root_from_tree() {
    local root="$1"
    local detected

    if is_valid_project_dir "${root}"; then
        echo "${root}"
        return 0
    fi

    detected="$(find "${root}" -maxdepth 6 -type f -name artisan 2>/dev/null | sed 's#/artisan$##' | while IFS= read -r dir; do
        if is_valid_project_dir "${dir}"; then
            echo "${dir}"
            break
        fi
    done)"

    if [[ -n "${detected}" ]]; then
        echo "${detected}"
        return 0
    fi

    return 1
}

extract_archive_to_dir() {
    local archive_path="$1"
    local extract_dir="$2"

    case "${archive_path}" in
        *.tar.gz|*.tgz)
            run tar -xzf "${archive_path}" -C "${extract_dir}"
            ;;
        *.zip)
            if ! command -v unzip >/dev/null 2>&1; then
                fail "unzip is required for .zip archive updates."
            fi
            run unzip -q "${archive_path}" -d "${extract_dir}"
            ;;
        *)
            fail "Unsupported archive type: ${archive_path} (.tar.gz/.tgz/.zip only)"
            ;;
    esac
}

sync_panel_source_to_target() {
    local source_dir="$1"
    local target_dir="$2"
    local source_q target_q

    if command -v rsync >/dev/null 2>&1; then
        run rsync -a --delete \
            --exclude ".env" \
            --exclude "storage/" \
            --exclude "bootstrap/cache/" \
            --exclude ".git/" \
            "${source_dir}/" "${target_dir}/"
        return
    fi

    warn "rsync not found. Falling back to tar copy without delete sync."
    printf -v source_q "%q" "${source_dir}"
    printf -v target_q "%q" "${target_dir}"
    run bash -lc "tar -C ${source_q} --exclude='.env' --exclude='storage' --exclude='bootstrap/cache' --exclude='.git' -cf - . | tar -C ${target_q} -xf -"
}

update_panel_files_menu() {
    local target_dir update_mode source_dir archive_path archive_url
    local tmp_archive="" tmp_extract="" detected_source=""

    echo
    echo -e "${CYAN}================ Update Panel Files =================${NC}"
    target_dir="$(ask_input "Deployed panel path" "$(default_panel_project_dir)")"
    if [[ ! -f "${target_dir}/artisan" || ! -f "${target_dir}/composer.json" ]]; then
        warn "Invalid deployed path: ${target_dir} (artisan/composer.json not found)"
        return
    fi

    echo "Update source:"
    echo "  1) Local source folder"
    echo "  2) Local archive file (.tar.gz/.tgz/.zip)"
    echo "  3) Remote archive URL (.tar.gz/.tgz/.zip)"
    echo "  4) Git pull inside deployed folder"
    update_mode="$(ask_input "Select update source" "1")"

    case "${update_mode}" in
        1)
            source_dir="$(ask_input "Local source folder" "${SCRIPT_DIR}/ServerPanel")"
            if [[ ! -f "${source_dir}/artisan" || ! -f "${source_dir}/composer.json" ]]; then
                warn "Invalid source folder: ${source_dir} (artisan/composer.json not found)"
                return
            fi
            ;;
        2)
            archive_path="$(ask_input "Local archive path")"
            if [[ ! -f "${archive_path}" ]]; then
                warn "Archive not found: ${archive_path}"
                return
            fi
            tmp_extract="$(mktemp -d /tmp/serverpanel_update_extract.XXXXXX)"
            extract_archive_to_dir "${archive_path}" "${tmp_extract}"
            if ! detected_source="$(detect_project_root_from_tree "${tmp_extract}")"; then
                fail "No Laravel project found in archive."
            fi
            source_dir="${detected_source}"
            ;;
        3)
            local cleaned_url archive_name
            archive_url="$(ask_input "Remote archive URL")"
            if [[ -z "${archive_url}" ]]; then
                warn "Archive URL is required."
                return
            fi
            cleaned_url="${archive_url%%#*}"
            cleaned_url="${cleaned_url%%\?*}"
            archive_name="$(basename "${cleaned_url}")"
            case "${archive_name}" in
                *.tar.gz|*.tgz|*.zip)
                    ;;
                *)
                    fail "Remote archive URL must end with .tar.gz, .tgz, or .zip: ${archive_url}"
                    ;;
            esac
            tmp_archive="/tmp/serverpanel_update_$(date +%s)_${archive_name}"
            download_file "${archive_url}" "${tmp_archive}"
            tmp_extract="$(mktemp -d /tmp/serverpanel_update_extract.XXXXXX)"
            extract_archive_to_dir "${tmp_archive}" "${tmp_extract}"
            if ! detected_source="$(detect_project_root_from_tree "${tmp_extract}")"; then
                fail "No Laravel project found in downloaded archive."
            fi
            source_dir="${detected_source}"
            ;;
        4)
            if [[ ! -d "${target_dir}/.git" ]]; then
                warn "Target is not a git repository: ${target_dir}"
                return
            fi
            run git -C "${target_dir}" pull --ff-only
            source_dir=""
            ;;
        *)
            warn "Invalid option."
            return
            ;;
    esac

    if [[ -n "${source_dir}" ]]; then
        sync_panel_source_to_target "${source_dir}" "${target_dir}"
    fi

    if ask_yes_no "Copy ${target_dir}/extra/serverroot.php to /var/www/html/index.php?" "Y"; then
        copy_server_root_file_menu "${target_dir}"
    fi

    cd "${target_dir}"
    upsert_env_value ".env" "APP_ENV" "production"
    upsert_env_value ".env" "APP_DEBUG" "false"
    run php artisan optimize:clear || true
    cleanup_vite_hot_file "${target_dir}"

    if ask_yes_no "Run composer install (production flags)?" "Y"; then
        run env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-dev --optimize-autoloader
    fi

    if ask_yes_no "Run npm install + npm run build?" "Y"; then
        ensure_nodejs_for_vite
        run npm install
        ensure_node_build_permissions
        run npm run build
        cleanup_vite_hot_file "${target_dir}"
    fi

    local migrate_choice
    echo
    echo "Database migration mode:"
    echo "  1) Skip"
    echo "  2) Migrate only"
    echo "  3) Migrate + seed"
    migrate_choice="$(ask_input "Select migration mode" "1")"
    case "${migrate_choice}" in
        2)
            run php artisan migrate --force
            ;;
        3)
            run php artisan migrate --force
            run php artisan db:seed --force
            ;;
        *)
            info "Skipping database migration/seed."
            ;;
    esac

    run php artisan optimize || true
    run systemctl restart serverpanel || true
    if systemctl cat nginx.service >/dev/null 2>&1 && (systemctl is-active --quiet nginx || ! systemctl is-active --quiet apache2); then
        restart_nginx_safely
    else
        restart_apache_safely
    fi

    if [[ -n "${tmp_archive}" && -f "${tmp_archive}" ]]; then
        run rm -f "${tmp_archive}" || true
    fi
    if [[ -n "${tmp_extract}" && -d "${tmp_extract}" ]]; then
        run rm -rf "${tmp_extract}" || true
    fi

    ok "Panel update flow completed. Refresh browser with Ctrl+F5."
}

copy_server_root_file_menu() {
    local panel_dir source_file target_file owner_group source_has_php apache_proxy_enabled nginx_proxy_enabled
    panel_dir="${1:-$(default_panel_project_dir)}"
    source_file="$(ask_input "Source file" "${panel_dir}/extra/serverroot.php")"
    target_file="$(ask_input "Target file" "/var/www/html/index.php")"

    if [[ ! -f "${source_file}" ]]; then
        warn "Source file not found: ${source_file}"
        return
    fi
    if [[ ! -s "${source_file}" ]]; then
        warn "Source file is empty: ${source_file}"
    fi

    source_has_php="false"
    if grep -q "<?php" "${source_file}" 2>/dev/null; then
        source_has_php="true"
    fi
    if [[ "${source_has_php}" == "true" && "${target_file}" == *.html ]]; then
        warn "Source contains PHP code; .html will not execute PHP."
        target_file="${target_file%.*}.php"
        info "Auto-switched target to: ${target_file}"
    fi

    run mkdir -p "$(dirname "${target_file}")"
    run cp "${source_file}" "${target_file}"
    owner_group="$(web_owner_group)"
    run chown "${owner_group}" "${target_file}" || true
    run chmod 644 "${target_file}" || true
    ok "Copied: ${source_file} -> ${target_file}"

    apache_proxy_enabled="false"
    if [[ -f /etc/apache2/sites-enabled/serverpanel-proxy.conf ]] && grep -q "ProxyPass / http://127.0.0.1:" /etc/apache2/sites-enabled/serverpanel-proxy.conf 2>/dev/null; then
        apache_proxy_enabled="true"
    fi
    nginx_proxy_enabled="false"
    if [[ -f /etc/nginx/sites-enabled/serverpanel-proxy ]] && grep -q "proxy_pass http://127.0.0.1:" /etc/nginx/sites-enabled/serverpanel-proxy 2>/dev/null; then
        nginx_proxy_enabled="true"
    fi

    if [[ "${apache_proxy_enabled}" == "true" || "${nginx_proxy_enabled}" == "true" ]]; then
        warn "Web proxy mode is enabled (:80 -> panel port), so /var/www/html index file may not be visible at root URL."
        warn "Use menu option 5 (Repair web proxy) to switch behavior, or disable proxy site manually if you want static root index."
    fi
}

run_custom_install_prompt() {
    local source_mode project_dir project_url base_url project_target
    local web_choice web_server panel_port db_name db_user db_password
    local php_versions php_default node_major
    local -a args=()

    echo
    echo -e "${CYAN}================ Custom Install ====================${NC}"
    echo "Project source:"
    echo "  1) Auto detect"
    echo "  2) --project-dir"
    echo "  3) --project-url"
    echo "  4) --base-url"
    source_mode="$(ask_input "Select source mode" "1")"
    case "${source_mode}" in
        2)
            project_dir="$(ask_input "Absolute project path")"
            [[ -n "${project_dir}" ]] && args+=(--project-dir "${project_dir}")
            ;;
        3)
            project_url="$(ask_input "Project archive URL (.tar.gz/.tgz/.zip)")"
            [[ -n "${project_url}" ]] && args+=(--project-url "${project_url}")
            project_target="$(ask_input "Optional target path (--project-target)" "")"
            [[ -n "${project_target}" ]] && args+=(--project-target "${project_target}")
            ;;
        4)
            base_url="$(ask_input "Base URL containing ServerInstaller/ServerPanel archive")"
            [[ -n "${base_url}" ]] && args+=(--base-url "${base_url}")
            project_target="$(ask_input "Optional target path (--project-target)" "")"
            [[ -n "${project_target}" ]] && args+=(--project-target "${project_target}")
            ;;
        *)
            ;;
    esac

    echo
    echo "Web server:"
    echo "  1) apache"
    echo "  2) nginx"
    echo "  3) both"
    web_choice="$(ask_input "Select web server" "1")"
    case "${web_choice}" in
        2) web_server="nginx" ;;
        3) web_server="both" ;;
        *) web_server="apache" ;;
    esac
    args+=(--web-server "${web_server}")

    panel_port="$(ask_input "Panel port" "8090")"
    [[ -n "${panel_port}" ]] && args+=(--panel-port "${panel_port}")

    db_name="$(ask_input "DB name" "serverinstaller")"
    db_user="$(ask_input "DB user" "serverpanel")"
    db_password="$(ask_input "DB password (blank = auto-generate)" "")"
    [[ -n "${db_name}" ]] && args+=(--db-name "${db_name}")
    [[ -n "${db_user}" ]] && args+=(--db-user "${db_user}")
    [[ -n "${db_password}" ]] && args+=(--db-password "${db_password}")

    php_versions="$(ask_input "PHP versions CSV" "7.4,8.0,8.2,8.3,8.4,8.5")"
    php_default="$(ask_input "Default PHP version" "8.3")"
    node_major="$(ask_input "Required Node major" "22")"
    [[ -n "${php_versions}" ]] && args+=(--php-versions "${php_versions}")
    [[ -n "${php_default}" ]] && args+=(--php-default "${php_default}")
    [[ -n "${node_major}" ]] && args+=(--node-major "${node_major}")

    if ask_yes_no "Reset DB stack first? (--reset-db)" "N"; then
        args+=(--reset-db)
    fi

    echo
    info "Installer command preview:"
    printf "bash int-tool.sh"
    for arg in "${args[@]}"; do
        printf " %q" "${arg}"
    done
    printf "\n"

    if ask_yes_no "Run custom install now?" "Y"; then
        installer_main "${args[@]}"
    else
        warn "Custom install canceled."
    fi
}

show_control_menu() {
    echo
    echo -e "${CYAN}============================================================${NC}"
    echo -e "${CYAN}               Int Tool (Ubuntu Server)                    ${NC}"
    echo -e "${CYAN}============================================================${NC}"
    echo "1) Install ServerPanel (quick default)"
    echo "2) Install ServerPanel (custom options)"
    echo "3) Service status dashboard"
    echo "4) Manage service (start/stop/restart/logs)"
    echo "5) Repair web proxy (:80 -> panel port)"
    echo "6) Port diagnostics (22/80/443/8090/3306)"
    echo "7) Update panel files (sync + build + restart)"
    echo "8) Copy root file (extra/serverroot.php -> index.php)"
    echo "9) Show credentials file"
    echo "10) Fresh install (reset DB stack + full install)"
    echo "0) Exit"
}

run_control_center() {
    local choice
    while true; do
        show_control_menu
        choice="$(ask_input "Select option" "1")"
        case "${choice}" in
            1)
                if ask_yes_no "Start full install with defaults now?" "Y"; then
                    installer_main
                fi
                ;;
            2)
                run_custom_install_prompt
                ;;
            3)
                show_service_dashboard
                ;;
            4)
                manage_single_service
                ;;
            5)
                repair_apache_proxy_menu
                ;;
            6)
                show_port_diagnostics
                ;;
            7)
                update_panel_files_menu
                ;;
            8)
                copy_server_root_file_menu
                ;;
            9)
                show_credentials_file
                ;;
            10)
                if ask_yes_no "Start fresh install now? (equivalent: bash int-tool.sh --fresh-install)" "Y"; then
                    installer_main --fresh-install
                fi
                ;;
            0)
                info "Bye."
                return 0
                ;;
            *)
                warn "Invalid option. Choose 0-10."
                ;;
        esac
    done
}

installer_main() {
    reset_runtime_defaults
    parse_args "$@"
    banner
    require_root
    check_ubuntu
    detect_project_dir
    install_packages
    setup_ssh
    setup_application
    setup_permissions
    start_services
    show_summary
}

entrypoint() {
    if [[ "${1:-}" == "--control-center" ]]; then
        shift
        require_root
        check_ubuntu
        run_control_center
        return
    fi

    if [[ $# -gt 0 ]]; then
        installer_main "$@"
        return
    fi

    require_root
    check_ubuntu
    if [[ -t 0 ]]; then
        run_control_center
    else
        installer_main
    fi
}

entrypoint "$@"
