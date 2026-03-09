#!/usr/bin/env bash
set -euo pipefail

RED="\033[1;31m"
GREEN="\033[1;32m"
YELLOW="\033[1;33m"
BLUE="\033[1;34m"
CYAN="\033[1;36m"
NC="\033[0m"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
EXTRA_DIR=""
APACHE_PROXY_TEMPLATE_FILE=""
PROJECT_DIR=""
PROJECT_HINT=""
PROJECT_URL=""
PROJECT_TARGET=""
PROJECT_BASE_URL=""
REMOTE_PANEL_ARCHIVE_URL="http://192.168.0.50/a_final_storing/ServerInstaller/ServerPanel.zip"
WEB_SERVER="apache"
PHP_VERSIONS_RAW="7.4,8.0,8.2,8.3,8.4,8.5"
PHP_DEFAULT_VERSION="8.3"
PHP_VERSIONS=()
FORCE_REPLACE_TARGET="true"
DB_NAME="serverinstaller"
DB_USER="serverpanel"
DB_PASSWORD=""
PANEL_PORT="8090"
WEBTOOLS_SEPARATE_PORTS="false"
PHPMYADMIN_PORT="8091"
ROUNDCUBE_PORT="8092"
APACHE_BACKEND_PORT="8080"
NGINX_PRIMARY_PORT="80"
DB_SERVICE=""
REDIS_SERVICE=""
PHPMYADMIN_SERVICE="serverpanel-phpmyadmin"
ROUNDCUBE_SERVICE="serverpanel-roundcube"
PHPMYADMIN_ROOT="/usr/share/phpmyadmin"
ROUNDCUBE_ROOT="/root/roundcube"
PHPMYADMIN_CONTROL_DB="phpmyadmin"
PHPMYADMIN_CONTROL_USER="pma"
PHPMYADMIN_CONTROL_PASSWORD=""
PHPMYADMIN_ADMIN_USER="dbadmin"
PHPMYADMIN_ADMIN_PASSWORD=""
ROUNDCUBE_DB_NAME="roundcube"
ROUNDCUBE_DB_USER="roundcube"
ROUNDCUBE_DB_PASSWORD=""
CURRENT_PID=""
RESET_DB_STACK="false"
LOGIN_CREDENTIALS_READY="false"
NODEJS_REQUIRED_MAJOR="22"
OS_ID=""
OS_PRETTY_NAME=""
FIREWALL_BACKEND=""
PHP_CLI_SERVER_WORKERS="8"
PKG_MANAGER=""

reset_runtime_defaults() {
    PROJECT_DIR=""
    PROJECT_HINT=""
    PROJECT_URL=""
    PROJECT_TARGET=""
    PROJECT_BASE_URL=""
    REMOTE_PANEL_ARCHIVE_URL="http://192.168.0.50/a_final_storing/ServerInstaller/ServerPanel.zip"
    WEB_SERVER="apache"
    PHP_VERSIONS_RAW="7.4,8.0,8.2,8.3,8.4,8.5"
    PHP_DEFAULT_VERSION="8.3"
    PHP_VERSIONS=()
    FORCE_REPLACE_TARGET="true"
    DB_NAME="serverinstaller"
    DB_USER="serverpanel"
    DB_PASSWORD=""
    PANEL_PORT="8090"
    WEBTOOLS_SEPARATE_PORTS="false"
    PHPMYADMIN_PORT="8091"
    ROUNDCUBE_PORT="8092"
    APACHE_BACKEND_PORT="8080"
    NGINX_PRIMARY_PORT="80"
    DB_SERVICE=""
    REDIS_SERVICE=""
    PHPMYADMIN_SERVICE="serverpanel-phpmyadmin"
    ROUNDCUBE_SERVICE="serverpanel-roundcube"
    PHPMYADMIN_ROOT="/usr/share/phpmyadmin"
    ROUNDCUBE_ROOT="/root/roundcube"
    PHPMYADMIN_CONTROL_DB="phpmyadmin"
    PHPMYADMIN_CONTROL_USER="pma"
    PHPMYADMIN_CONTROL_PASSWORD=""
    PHPMYADMIN_ADMIN_USER="dbadmin"
    PHPMYADMIN_ADMIN_PASSWORD=""
    ROUNDCUBE_DB_NAME="roundcube"
    ROUNDCUBE_DB_USER="roundcube"
    ROUNDCUBE_DB_PASSWORD=""
    CURRENT_PID=""
    RESET_DB_STACK="false"
    LOGIN_CREDENTIALS_READY="false"
    NODEJS_REQUIRED_MAJOR="22"
    OS_ID=""
    OS_PRETTY_NAME=""
    FIREWALL_BACKEND=""
    PHP_CLI_SERVER_WORKERS="8"
    PKG_MANAGER=""
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

# --- Configuration Patches ---
export SP_SCRIPTS="/root/ServerPanel/scripts"
export INSTALL_DIR="${SP_SCRIPTS}/install"

# --- Reusable File Loader ---
fileload() {
    local target="$1"
    if [[ -f "${target}" ]]; then
        # shellcheck source=/dev/null
        source "${target}"
        return 0
    else
        echo -e "\e[31m[ERROR]\e[0m File not found: ${target}"
        return 1
    fi
}

# --- Decision Runner ---
# This runs a function and returns 0 (Success) or 1 (Failure)
execute_task() {
    local task_name="$1"
    
    echo -e "\e[34m[TASK]\e[0m Running ${task_name}..."
    
    # Execute the function
    if "${task_name}"; then
        echo -e "\e[32m[SUCCESS]\e[0m ${task_name} completed."
        return 0
    else
        echo -e "\e[31m[FAILED]\e[0m ${task_name} encountered an error."
        return 1
    fi
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
  --remote-panel-url URL Fallback remote ServerPanel archive URL when local project is not found
  --project-target PATH Where downloaded project should be moved (default: SCRIPT_DIR/ServerPanel)
  --remote-project-dir PATH Alias for --project-target
  --web-server NAME    apache (default), nginx, or both
  --php-versions CSV   PHP versions list (default: 7.4,8.0,8.2,8.3,8.4,8.5)
  --php-default VER    Default PHP CLI version for Composer/Artisan (default: 8.3)
  --db-name NAME       Database name to create (default: serverinstaller)
  --db-user NAME       Database user to create (default: serverpanel)
  --db-password PASS   Database password (default: random generated)
  --panel-port PORT    Panel HTTP port for system startup service (default: 8090)
  --separate-webtools  Run phpMyAdmin/Roundcube on dedicated ports/services
  --no-separate-webtools Keep phpMyAdmin/Roundcube behind panel port paths
  --phpmyadmin-port PORT Dedicated phpMyAdmin port (default: 8091)
  --roundcube-port PORT  Dedicated Roundcube port (default: 8092)
  --apache-backend-port PORT Apache backend port when --web-server both (default: 8080)
  --nginx-primary-port PORT  Nginx frontend port when --web-server both/nginx (default: 80)
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

disable_apache_site_if_enabled() {
    local site="${1:-}"
    local resolved_site=""

    if [[ -z "${site}" ]]; then
        return 0
    fi

    if [[ -e "/etc/apache2/sites-enabled/${site}" ]]; then
        resolved_site="${site}"
    elif [[ -e "/etc/apache2/sites-enabled/${site}.conf" ]]; then
        resolved_site="${site}.conf"
    elif [[ -e "/etc/apache2/sites-available/${site}" || -e "/etc/apache2/sites-available/${site}.conf" ]]; then
        info "Apache site ${site} is already disabled."
        return 0
    else
        info "Apache site ${site} does not exist; skipping."
        return 0
    fi

    run a2dissite "${resolved_site}"
}

sanitize_apache_legacy_panel_port_bindings() {
    local legacy_site_conf="/etc/apache2/sites-available/serverpanel-panel-port.conf"
    local legacy_enabled_conf="/etc/apache2/sites-enabled/serverpanel-panel-port.conf"
    local ports_conf="/etc/apache2/ports.conf"
    local backup_path=""

    if [[ -e "${legacy_enabled_conf}" ]]; then
        warn "Removing legacy Apache panel-port site link: ${legacy_enabled_conf}"
        run rm -f "${legacy_enabled_conf}"
    fi

    if [[ -f "${legacy_site_conf}" ]]; then
        backup_path="${legacy_site_conf}.disabled.$(date +%Y%m%d%H%M%S)"
        warn "Disabling legacy Apache panel-port site file: ${legacy_site_conf}"
        run mv "${legacy_site_conf}" "${backup_path}"
    fi

    if [[ -f "${ports_conf}" && "${PANEL_PORT}" != "80" && "${PANEL_PORT}" != "443" ]]; then
        if grep -qE "^[[:space:]]*Listen[[:space:]]+${PANEL_PORT}([[:space:]]*)$" "${ports_conf}" 2>/dev/null; then
            warn "Removing Apache Listen ${PANEL_PORT} to keep panel port reserved for serverpanel.service"
            run sed -i -E "s/^[[:space:]]*Listen[[:space:]]+${PANEL_PORT}([[:space:]]*)$/# Listen ${PANEL_PORT} # disabled-by-serverpanel-installer/" "${ports_conf}"
        fi
    fi
}

write_serverpanel_apache_proxy_site_conf() {
    local output_conf="/etc/apache2/sites-available/serverpanel-proxy.conf"
    local template_conf=""

    template_conf="$(resolve_apache_proxy_template_file)"
    if [[ -n "${template_conf}" && -f "${template_conf}" ]]; then
        sed "s/__PANEL_PORT__/${PANEL_PORT}/g" "${template_conf}" > "${output_conf}"
        ok "Apache proxy config generated from template: ${template_conf}"
        return 0
    fi

    cat > "${output_conf}" <<EOF
<VirtualHost *:80>
    ServerName _

    ProxyPreserveHost On
    ProxyPass / http://127.0.0.1:${PANEL_PORT}/
    ProxyPassReverse / http://127.0.0.1:${PANEL_PORT}/

    ErrorLog \${APACHE_LOG_DIR}/serverpanel_error.log
    CustomLog \${APACHE_LOG_DIR}/serverpanel_access.log combined
</VirtualHost>
EOF
}

apache_configtest_with_self_heal() {
    local max_attempts="${1:-2}"
    local attempt=0
    local bad_conf=""
    local bad_site=""

    if ! command -v apache2ctl >/dev/null 2>&1; then
        return 0
    fi

    while (( attempt <= max_attempts )); do
        if apache2ctl configtest >/tmp/serverpanel-apache-configtest.log 2>&1; then
            return 0
        fi

        bad_conf="$(sed -n -E 's/.*Syntax error on line [0-9]+ of ([^:]+):.*/\1/p' /tmp/serverpanel-apache-configtest.log | head -n1 || true)"
        if [[ -z "${bad_conf}" ]]; then
            return 1
        fi

        if [[ "${bad_conf}" == /etc/apache2/sites-enabled/* ]]; then
            bad_site="$(basename "${bad_conf}")"
            warn "Apache syntax error in ${bad_conf}; disabling ${bad_site} and retrying."
            run rm -f "${bad_conf}" || true
            if [[ -e "/etc/apache2/sites-available/${bad_site}" ]]; then
                run a2dissite "${bad_site}" || true
            elif [[ "${bad_site}" == *.conf && -e "/etc/apache2/sites-available/${bad_site%.conf}" ]]; then
                run a2dissite "${bad_site%.conf}" || true
            fi
            attempt=$((attempt + 1))
            continue
        fi

        return 1
    done

    return 1
}

require_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        fail "Run as root (example: sudo bash int-tool.sh)."
    fi
}

resolve_extra_dir() {
    local candidate
    local candidates=()

    if [[ -n "${PROJECT_DIR:-}" ]]; then
        candidates+=("${PROJECT_DIR%/}/extra")
    fi
    candidates+=(
        "${SCRIPT_DIR}/ServerPanel/extra"
        "${SCRIPT_DIR}/extra"
    )

    for candidate in "${candidates[@]}"; do
        [[ -z "${candidate}" ]] && continue
        if [[ -d "${candidate}" ]]; then
            EXTRA_DIR="${candidate}"
            echo "${candidate}"
            return 0
        fi
    done

    echo ""
}

resolve_extra_file() {
    local relative_path="$1"
    local candidate
    local extra_dir
    local candidates=()

    extra_dir="$(resolve_extra_dir)"
    if [[ -n "${extra_dir}" ]]; then
        candidates+=("${extra_dir%/}/${relative_path}")
    fi

    candidates+=(
        "${SCRIPT_DIR}/ServerPanel/extra/${relative_path}"
        "${SCRIPT_DIR}/extra/${relative_path}"
    )

    for candidate in "${candidates[@]}"; do
        if [[ -f "${candidate}" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    echo ""
}

resolve_apache_proxy_template_file() {
    local template_path

    template_path="$(resolve_extra_file "apache/serverpanel-proxy.conf.template")"
    if [[ -n "${template_path}" ]]; then
        APACHE_PROXY_TEMPLATE_FILE="${template_path}"
        echo "${template_path}"
        return 0
    fi

    if [[ -n "${APACHE_PROXY_TEMPLATE_FILE:-}" && -f "${APACHE_PROXY_TEMPLATE_FILE}" ]]; then
        echo "${APACHE_PROXY_TEMPLATE_FILE}"
        return 0
    fi

    echo ""
}

ensure_parent_dir_writable() {
    local target_path="$1"
    local parent_dir
    local ancestor_dir

    parent_dir="$(dirname "${target_path}")"
    if [[ -d "${parent_dir}" ]]; then
        if [[ ! -w "${parent_dir}" ]]; then
            warn "Directory not writable: ${parent_dir}. Attempting permission fix."
            run chmod u+rwx "${parent_dir}" || true
        fi
        return 0
    fi

    run mkdir -p "${parent_dir}" || true
    if [[ -d "${parent_dir}" ]]; then
        return 0
    fi

    warn "Unable to create directory: ${parent_dir}. Trying parent permission fix."
    ancestor_dir="$(dirname "${parent_dir}")"
    run mkdir -p "${ancestor_dir}" || true
    run chmod u+rwx "${ancestor_dir}" || true
    run mkdir -p "${parent_dir}"
}

detect_firewall_backend() {
    if command -v ufw >/dev/null 2>&1; then
        echo "ufw"
        return 0
    fi

    if command -v firewall-cmd >/dev/null 2>&1; then
        echo "firewalld"
        return 0
    fi

    echo "none"
}

prepare_firewall_backend() {
    FIREWALL_BACKEND="$(detect_firewall_backend)"

    case "${FIREWALL_BACKEND}" in
        ufw)
            if systemctl cat ufw.service >/dev/null 2>&1; then
                run systemctl enable --now ufw || true
            fi
            ;;
        firewalld)
            if systemctl cat firewalld.service >/dev/null 2>&1; then
                run systemctl enable --now firewalld || true
            fi
            ;;
        *)
            warn "No supported firewall backend detected (ufw/firewalld). Skipping automatic firewall rule updates."
            ;;
    esac
}

allow_firewall_service() {
    local service_name="$1"
    local backend="${FIREWALL_BACKEND:-$(detect_firewall_backend)}"

    case "${backend}" in
        ufw)
            if [[ "${service_name}" == "ssh" ]]; then
                run ufw allow OpenSSH || true
            else
                run ufw allow "${service_name}" || true
            fi
            ;;
        firewalld)
            run firewall-cmd --permanent --add-service="${service_name}" || true
            run firewall-cmd --reload || true
            ;;
        *)
            warn "Firewall service rule skipped (${service_name}). No supported firewall backend found."
            ;;
    esac
}

allow_firewall_port() {
    local port="$1"
    local proto="${2:-tcp}"
    local backend="${FIREWALL_BACKEND:-$(detect_firewall_backend)}"

    case "${backend}" in
        ufw)
            run ufw allow "${port}/${proto}" || true
            ;;
        firewalld)
            run firewall-cmd --permanent --add-port="${port}/${proto}" || true
            run firewall-cmd --reload || true
            ;;
        *)
            warn "Firewall port rule skipped (${port}/${proto}). No supported firewall backend found."
            ;;
    esac
}

detect_package_manager() {
    if command -v apt-get >/dev/null 2>&1; then
        echo "apt"
        return 0
    fi
    if command -v dnf >/dev/null 2>&1; then
        echo "dnf"
        return 0
    fi
    if command -v yum >/dev/null 2>&1; then
        echo "dnf"
        return 0
    fi
    echo ""
}

map_package_name() {
    local pkg="$1"
    local mapped="${pkg}"
    local versioned_ext=""

    if [[ "${PKG_MANAGER}" != "dnf" ]]; then
        echo "${mapped}"
        return 0
    fi

    case "${pkg}" in
        apache2) mapped="nginx" ;; # AlmaLinux path uses nginx-only mode in this installer
        libapache2-mod-php|libapache2-mod-php*) mapped="php" ;;
        lsb-release) mapped="redhat-lsb-core" ;;
        gnupg) mapped="gnupg2" ;;
        software-properties-common) mapped="dnf-plugins-core" ;;
        redis-server) mapped="redis" ;;
        mariadb-client) mapped="mariadb" ;;
        phpmyadmin) mapped="phpMyAdmin" ;;
        roundcube|roundcube-core) mapped="roundcubemail" ;;
        roundcube-mysql) mapped="roundcubemail-mysql" ;;
        dovecot-core|dovecot-imapd|dovecot-pop3d) mapped="dovecot" ;;
        php-mysql) mapped="php-mysqlnd" ;;
    esac

    if [[ "${pkg}" =~ ^php[0-9]+\.[0-9]+$ ]]; then
        mapped="php"
    elif [[ "${pkg}" =~ ^php[0-9]+\.[0-9]+-(.+)$ ]]; then
        versioned_ext="${BASH_REMATCH[1]}"
        case "${versioned_ext}" in
            mysql) mapped="php-mysqlnd" ;;
            *) mapped="php-${versioned_ext}" ;;
        esac
    fi

    echo "${mapped}"
}

pkg_update_cache() {
    case "${PKG_MANAGER}" in
        apt)
            run apt update
            ;;
        dnf)
            run dnf -y makecache
            ;;
        *)
            fail "Unsupported package manager: ${PKG_MANAGER}"
            ;;
    esac
}

pkg_install() {
    local pkg="$1"

    case "${PKG_MANAGER}" in
        apt)
            run env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew install "${pkg}" || true
            ;;
        dnf)
            run dnf -y install "${pkg}" || true
            ;;
        *)
            fail "Unsupported package manager: ${PKG_MANAGER}"
            ;;
    esac
}

pkg_remove() {
    local pkg="$1"
    case "${PKG_MANAGER}" in
        apt)
            run env DEBIAN_FRONTEND=noninteractive apt-get -y purge "${pkg}" || true
            ;;
        dnf)
            run dnf -y remove "${pkg}" || true
            ;;
        *)
            warn "Unsupported package manager for remove: ${PKG_MANAGER}"
            ;;
    esac
}

pkg_autoremove() {
    case "${PKG_MANAGER}" in
        apt)
            run env DEBIAN_FRONTEND=noninteractive apt-get -y autoremove || true
            ;;
        dnf)
            run dnf -y autoremove || true
            ;;
        *)
            warn "Unsupported package manager for autoremove: ${PKG_MANAGER}"
            ;;
    esac
}

check_ubuntu() {
    if [[ ! -f /etc/os-release ]]; then
        fail "/etc/os-release not found. OS check failed."
    fi

    # shellcheck disable=SC1091
    source /etc/os-release
    OS_ID="${ID:-unknown}"
    OS_PRETTY_NAME="${PRETTY_NAME:-${ID:-unknown}}"
    PKG_MANAGER="$(detect_package_manager)"

    if [[ -z "${PKG_MANAGER}" ]]; then
        fail "No supported package manager found (apt-get/dnf)."
    fi

    case "${OS_ID}" in
        ubuntu|debian)
            ok "${OS_PRETTY_NAME} detected (package manager: ${PKG_MANAGER})."
            ;;
        almalinux|almalinux*)
            ok "AlmaLinux detected (${OS_PRETTY_NAME}) (package manager: ${PKG_MANAGER})."
            ;;
        amzn|amazon)
            warn "Amazon Linux detected (${OS_PRETTY_NAME}). Compatibility mode enabled (package manager: ${PKG_MANAGER})."
            ;;
        *)
            warn "Detected ID=${OS_ID} (${OS_PRETTY_NAME}). Compatibility mode enabled with package manager: ${PKG_MANAGER}."
            ;;
    esac

    if [[ "${PKG_MANAGER}" == "dnf" && ( "${WEB_SERVER}" == "apache" || "${WEB_SERVER}" == "both" ) ]]; then
        warn "Apache mode in this installer is Debian/Ubuntu specific. Switching web-server mode to nginx for ${OS_PRETTY_NAME}."
        WEB_SERVER="nginx"
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
            --remote-panel-url)
                [[ $# -lt 2 ]] && fail "--remote-panel-url requires a URL value."
                REMOTE_PANEL_ARCHIVE_URL="$2"
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
            --separate-webtools)
                WEBTOOLS_SEPARATE_PORTS="true"
                shift 1
                ;;
            --no-separate-webtools)
                WEBTOOLS_SEPARATE_PORTS="false"
                shift 1
                ;;
            --phpmyadmin-port)
                [[ $# -lt 2 ]] && fail "--phpmyadmin-port requires a value."
                PHPMYADMIN_PORT="$2"
                shift 2
                ;;
            --roundcube-port)
                [[ $# -lt 2 ]] && fail "--roundcube-port requires a value."
                ROUNDCUBE_PORT="$2"
                shift 2
                ;;
            --apache-backend-port)
                [[ $# -lt 2 ]] && fail "--apache-backend-port requires a value."
                APACHE_BACKEND_PORT="$2"
                shift 2
                ;;
            --nginx-primary-port)
                [[ $# -lt 2 ]] && fail "--nginx-primary-port requires a value."
                NGINX_PRIMARY_PORT="$2"
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
    if [[ ! "${PHPMYADMIN_PORT}" =~ ^[0-9]+$ ]]; then
        fail "Invalid --phpmyadmin-port: ${PHPMYADMIN_PORT}. Must be a number."
    fi
    if (( PHPMYADMIN_PORT < 1 || PHPMYADMIN_PORT > 65535 )); then
        fail "Invalid --phpmyadmin-port: ${PHPMYADMIN_PORT}. Must be between 1 and 65535."
    fi
    if [[ ! "${ROUNDCUBE_PORT}" =~ ^[0-9]+$ ]]; then
        fail "Invalid --roundcube-port: ${ROUNDCUBE_PORT}. Must be a number."
    fi
    if (( ROUNDCUBE_PORT < 1 || ROUNDCUBE_PORT > 65535 )); then
        fail "Invalid --roundcube-port: ${ROUNDCUBE_PORT}. Must be between 1 and 65535."
    fi
    if [[ ! "${APACHE_BACKEND_PORT}" =~ ^[0-9]+$ ]]; then
        fail "Invalid --apache-backend-port: ${APACHE_BACKEND_PORT}. Must be a number."
    fi
    if (( APACHE_BACKEND_PORT < 1 || APACHE_BACKEND_PORT > 65535 )); then
        fail "Invalid --apache-backend-port: ${APACHE_BACKEND_PORT}. Must be between 1 and 65535."
    fi
    if [[ ! "${NGINX_PRIMARY_PORT}" =~ ^[0-9]+$ ]]; then
        fail "Invalid --nginx-primary-port: ${NGINX_PRIMARY_PORT}. Must be a number."
    fi
    if (( NGINX_PRIMARY_PORT < 1 || NGINX_PRIMARY_PORT > 65535 )); then
        fail "Invalid --nginx-primary-port: ${NGINX_PRIMARY_PORT}. Must be between 1 and 65535."
    fi
    if [[ "${WEB_SERVER}" == "both" && "${APACHE_BACKEND_PORT}" == "${NGINX_PRIMARY_PORT}" ]]; then
        fail "--apache-backend-port and --nginx-primary-port cannot be the same when --web-server both."
    fi
    if [[ "${WEB_SERVER}" == "both" && "${APACHE_BACKEND_PORT}" == "${PANEL_PORT}" ]]; then
        fail "--apache-backend-port cannot match --panel-port when --web-server both."
    fi
    if [[ "${WEB_SERVER}" == "both" && "${NGINX_PRIMARY_PORT}" == "${PANEL_PORT}" ]]; then
        fail "--nginx-primary-port cannot match --panel-port when --web-server both."
    fi
    if [[ "${WEB_SERVER}" == "nginx" && "${NGINX_PRIMARY_PORT}" == "${PANEL_PORT}" ]]; then
        fail "--nginx-primary-port cannot match --panel-port when --web-server nginx."
    fi
    if [[ "${WEBTOOLS_SEPARATE_PORTS}" != "true" && "${WEBTOOLS_SEPARATE_PORTS}" != "false" ]]; then
        fail "Invalid webtools mode flag: ${WEBTOOLS_SEPARATE_PORTS}. Use --separate-webtools or --no-separate-webtools."
    fi
    if [[ "${WEBTOOLS_SEPARATE_PORTS}" == "true" ]]; then
        if [[ "${PHPMYADMIN_PORT}" == "${PANEL_PORT}" ]]; then
            fail "--phpmyadmin-port cannot match --panel-port when --separate-webtools is enabled."
        fi
        if [[ "${ROUNDCUBE_PORT}" == "${PANEL_PORT}" ]]; then
            fail "--roundcube-port cannot match --panel-port when --separate-webtools is enabled."
        fi
        if [[ "${PHPMYADMIN_PORT}" == "${ROUNDCUBE_PORT}" ]]; then
            fail "--phpmyadmin-port and --roundcube-port must be different."
        fi
        if [[ "${PHPMYADMIN_PORT}" == "${NGINX_PRIMARY_PORT}" || "${PHPMYADMIN_PORT}" == "${APACHE_BACKEND_PORT}" ]]; then
            fail "--phpmyadmin-port conflicts with web server listener port."
        fi
        if [[ "${ROUNDCUBE_PORT}" == "${NGINX_PRIMARY_PORT}" || "${ROUNDCUBE_PORT}" == "${APACHE_BACKEND_PORT}" ]]; then
            fail "--roundcube-port conflicts with web server listener port."
        fi
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

detect_redis_service() {
    local svc
    for svc in redis-server redis; do
        if systemctl cat "${svc}.service" >/dev/null 2>&1; then
            echo "${svc}"
            return
        fi
        if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx "${svc}.service"; then
            echo "${svc}"
            return
        fi
    done
    echo ""
}

detect_dovecot_service() {
    local svc
    for svc in dovecot; do
        if systemctl cat "${svc}.service" >/dev/null 2>&1; then
            echo "${svc}"
            return
        fi
        if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx "${svc}.service"; then
            echo "${svc}"
            return
        fi
    done
    echo ""
}

detect_ssh_service() {
    local svc
    for svc in ssh sshd; do
        if systemctl cat "${svc}.service" >/dev/null 2>&1; then
            echo "${svc}"
            return
        fi
        if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx "${svc}.service"; then
            echo "${svc}"
            return
        fi
    done
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

ensure_redis_service_installed() {
    if [[ -n "$(detect_redis_service)" ]]; then
        return
    fi

    warn "No Redis service unit detected. Attempting to install redis-server."
    if is_package_available redis-server; then
        ensure_package redis-server true
        return
    fi

    warn "Package redis-server is not available on this host. Skipping Redis setup."
}

ensure_dovecot_service_installed() {
    if [[ -n "$(detect_dovecot_service)" ]]; then
        return
    fi

    warn "No Dovecot service unit detected. Attempting to install Dovecot packages."
    ensure_package dovecot-core true
    ensure_package dovecot-imapd true
    ensure_package dovecot-pop3d true
    ensure_package dovecot-mysql true
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

ensure_dovecot_running() {
    local svc

    ensure_dovecot_service_installed
    svc="$(detect_dovecot_service)"

    if [[ -z "${svc}" ]]; then
        warn "No Dovecot service unit found. Mail storage server may not work."
        return 0
    fi

    run systemctl enable "${svc}" || true
    run systemctl reset-failed "${svc}" || true
    run systemctl restart "${svc}" || true

    if systemctl is-active --quiet "${svc}"; then
        ok "Dovecot service running: ${svc}"
        return 0
    fi

    warn "Dovecot service is not active after restart. Check: systemctl status ${svc}"
    return 0
}

ensure_redis_running() {
    local svc action was_active
    ensure_redis_service_installed
    svc="$(detect_redis_service)"

    if [[ -z "${svc}" ]]; then
        REDIS_SERVICE=""
        warn "No Redis service unit found (redis-server/redis). Continuing without Redis."
        return 0
    fi

    REDIS_SERVICE="${svc}"

    while true; do
        if systemctl is-active --quiet "${svc}"; then
            was_active="true"
            info "Redis service is already running, attempting restart: ${svc}"
        else
            was_active="false"
            warn "Redis service is not active, attempting start: ${svc}"
        fi

        run systemctl enable "${svc}" || true
        run systemctl reset-failed "${svc}" || true
        run systemctl restart "${svc}" || true

        if systemctl is-active --quiet "${svc}"; then
            if [[ "${was_active}" == "true" ]]; then
                ok "Redis service restarted and running: ${svc}"
            else
                ok "Redis service started: ${svc}"
            fi
            return 0
        fi

        systemctl status "${svc}.service" --no-pager || true
        journalctl -u "${svc}.service" -n 80 --no-pager || true

        if [[ -t 0 ]]; then
            warn "Redis restart failed."
            echo -e "${YELLOW}[WARN]${NC} Choose action:"
            echo "  1. Retry Redis restart"
            echo "  2. Skip Redis startup and continue"
            echo "  3. Abort installer"
            read -r action
            case "${action,,}" in
                1|"")
                    ;;
                2|skip|s)
                    warn "Skipping Redis startup as requested."
                    REDIS_SERVICE=""
                    return 0
                    ;;
                3|abort|a)
                    fail "Aborted by user after Redis restart failure."
                    ;;
                *)
                    warn "Invalid choice. Please enter 1, 2, or 3."
                    continue
                    ;;
            esac
            continue
        fi

        warn "Redis service failed to start (${svc}) in non-interactive mode. Continuing without Redis."
        REDIS_SERVICE=""
        return 0
    done
}

is_package_installed() {
    local pkg="$1"
    local mapped_pkg
    mapped_pkg="$(map_package_name "${pkg}")"

    case "${PKG_MANAGER}" in
        apt)
            dpkg-query -W -f='${Status}' "${mapped_pkg}" 2>/dev/null | grep -q "install ok installed"
            ;;
        dnf)
            rpm -q "${mapped_pkg}" >/dev/null 2>&1
            ;;
        *)
            return 1
            ;;
    esac
}

is_package_available() {
    local pkg="$1"
    local mapped_pkg
    mapped_pkg="$(map_package_name "${pkg}")"

    case "${PKG_MANAGER}" in
        apt)
            apt-cache show "${mapped_pkg}" >/dev/null 2>&1
            ;;
        dnf)
            dnf -q list "${mapped_pkg}" >/dev/null 2>&1
            ;;
        *)
            return 1
            ;;
    esac
}

ensure_package() {
    local pkg="$1"
    local optional="${2:-false}"
    local mapped_pkg=""

    mapped_pkg="$(map_package_name "${pkg}")"

    if is_package_installed "${pkg}"; then
        ok "Already installed: ${mapped_pkg}"
        return 0
    fi

    if ! is_package_available "${pkg}"; then
        if [[ "${optional}" == "true" ]]; then
            warn "Package not available, skipping: ${mapped_pkg}"
            return 0
        fi
        fail "Required package not available: ${mapped_pkg}"
    fi

    pkg_install "${mapped_pkg}"
    if is_package_installed "${pkg}"; then
        ok "Installed: ${mapped_pkg}"
        return 0
    fi

    warn "Initial install did not complete for ${mapped_pkg}. Attempting package-manager recovery..."
    repair_apt_dpkg_state
    pkg_install "${mapped_pkg}"

    if is_package_installed "${pkg}"; then
        ok "Installed after recovery: ${mapped_pkg}"
        return 0
    fi

    if [[ "${optional}" == "true" ]]; then
        warn "Package installation still failed, skipping optional package: ${mapped_pkg}"
        return 0
    fi

    fail "Failed to install required package: ${mapped_pkg}"
}

repair_apt_dpkg_state() {
    if [[ "${PKG_MANAGER}" == "apt" ]]; then
        warn "Repairing package manager state (dpkg/apt)..."
        run dpkg --configure -a || true
        run env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew --fix-broken install || true
        return 0
    fi

    if [[ "${PKG_MANAGER}" == "dnf" ]]; then
        warn "Repairing package manager state (dnf makecache)..."
        run dnf -y makecache || true
    fi
}

disable_mysql_apt_repos() {
    if [[ "${PKG_MANAGER}" != "apt" ]]; then
        return 0
    fi

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

    if [[ "${PKG_MANAGER}" == "apt" ]]; then
        for pkg in "${candidates[@]}"; do
            if dpkg-query -W -f='${Status}' "${pkg}" >/dev/null 2>&1; then
                purge_list+=("${pkg}")
            fi
        done
    else
        for pkg in "${candidates[@]}"; do
            if rpm -q "$(map_package_name "${pkg}")" >/dev/null 2>&1; then
                purge_list+=("$(map_package_name "${pkg}")")
            fi
        done
    fi

    if [[ "${#purge_list[@]}" -eq 0 ]]; then
        info "No installed MySQL/MariaDB packages found to purge."
        return
    fi

    if [[ "${PKG_MANAGER}" == "apt" ]]; then
        run env DEBIAN_FRONTEND=noninteractive apt-get -y -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confnew purge "${purge_list[@]}" || true
    else
        run dnf -y remove "${purge_list[@]}" || true
    fi
}

reinstall_mariadb_stack_fresh() {
    warn "Fresh reinstall MariaDB requested by user."
    stop_db_services_safely
    purge_db_packages_safely
    pkg_autoremove
    run rm -rf /etc/mysql /var/lib/mysql /var/lib/mysql-files /var/lib/mysql-keyring || true
    repair_apt_dpkg_state
    disable_mysql_apt_repos
    pkg_update_cache
    pkg_install "$(map_package_name "mariadb-server")"
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
    pkg_autoremove
    run rm -rf /etc/mysql /var/lib/mysql /var/log/mysql || true
    if [[ "${PKG_MANAGER}" == "apt" ]]; then
        run rm -f /etc/apt/sources.list.d/mariadb.list.old_1 /etc/apt/sources.list.d/mariadb.list.old_2 || true
    fi
    disable_mysql_apt_repos
    pkg_update_cache
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
    if [[ "${PKG_MANAGER}" != "apt" ]]; then
        info "Skipping ondrej/php repository setup (not applicable for ${PKG_MANAGER})."
        return 0
    fi

    if grep -Rqs "ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
        ok "Repository already present: ondrej/php"
        return
    fi

    info "Adding ondrej/php repository for multi-version PHP packages"
    ensure_package software-properties-common
    run add-apt-repository -y ppa:ondrej/php
}

configure_apache_php_fpm_mode() {
    local php_version="$1"
    local php_module=""
    local fpm_service=""
    local fpm_conf=""

    if [[ -z "${php_version}" ]]; then
        php_version="8.3"
    fi

    info "Configuring Apache PHP runtime: PHP-FPM (${php_version}) with mpm_event"

    for module_load in /etc/apache2/mods-enabled/php*.load; do
        [[ -f "${module_load}" ]] || continue
        php_module="$(basename "${module_load}" .load)"
        run a2dismod "${php_module}" || true
    done

    run a2dismod mpm_prefork || true
    run a2enmod mpm_event proxy proxy_fcgi setenvif rewrite headers || true

    fpm_service="php${php_version}-fpm"
    fpm_conf="php${php_version}-fpm"
    if systemctl cat "${fpm_service}.service" >/dev/null 2>&1; then
        run systemctl enable "${fpm_service}" || true
        run systemctl restart "${fpm_service}" || true
    else
        warn "${fpm_service}.service not found. PHP-FPM may not be active for Apache."
    fi

    if [[ -f "/etc/apache2/conf-available/${fpm_conf}.conf" ]]; then
        run a2enconf "${fpm_conf}" || true
    else
        warn "Apache FPM config not found: /etc/apache2/conf-available/${fpm_conf}.conf"
    fi
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

        configure_apache_php_fpm_mode "${PHP_DEFAULT_VERSION}"

        run systemctl enable apache2 || true
        restart_apache_safely

        if apache2ctl -M 2>/dev/null | grep -q 'proxy_fcgi_module'; then
            ok "Apache PHP-FPM mode is active (proxy_fcgi_module loaded)."
        else
            warn "Apache PHP-FPM module not detected. Check: apache2ctl -M | grep proxy_fcgi"
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
            ensure_package "php${version}-mysql" true
            ensure_package "libapache2-mod-php${version}" true
        fi

        # Composer dependencies commonly require these modules across SAPIs.
        enable_php_modules_for_version "${version}"
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
            set_php_ini_kv "${ini_file}" "upload_max_filesize" "2G"
            set_php_ini_kv "${ini_file}" "post_max_size" "2G"
            set_php_ini_kv "${ini_file}" "max_execution_time" "300"
            set_php_ini_kv "${ini_file}" "max_input_vars" "5000"
            set_php_ini_kv "${ini_file}" "display_errors" "On"
            set_php_ini_kv "${ini_file}" "log_errors" "On"
            set_php_ini_kv "${ini_file}" "allow_url_fopen" "On"
        done

        enable_php_modules_for_version "${version}"

        if systemctl cat "php${version}-fpm.service" >/dev/null 2>&1; then
            run systemctl restart "php${version}-fpm" || true
        fi
    done

    if wants_apache && systemctl cat apache2.service >/dev/null 2>&1; then
        restart_apache_safely
    fi
    if wants_nginx && systemctl cat nginx.service >/dev/null 2>&1; then
        restart_nginx_safely
    fi

    ok "Recommended PHP runtime defaults applied."
}

ensure_default_php_binary() {
    local candidate current_php
    if [[ "${PKG_MANAGER}" == "dnf" ]]; then
        candidate="/usr/bin/php"
    else
        candidate="/usr/bin/php${PHP_DEFAULT_VERSION}"
    fi

    if [[ ! -x "${candidate}" ]]; then
        if [[ "${PKG_MANAGER}" == "dnf" ]]; then
            warn "PHP CLI binary not found. Installing php/php-cli..."
            ensure_package php
            ensure_package php-cli
        else
            warn "Requested default PHP ${PHP_DEFAULT_VERSION} is not installed. Installing now..."
            ensure_package "php${PHP_DEFAULT_VERSION}"
            ensure_package "php${PHP_DEFAULT_VERSION}-cli"
        fi
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

escape_for_sed_replacement() {
    printf '%s' "$1" | sed -e 's/[\\&|]/\\&/g'
}

normalize_compact_line() {
    printf '%s' "$1" | tr -d '[:space:]'
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

read_env_value() {
    local file="$1"
    local key="$2"
    local line=""

    if [[ ! -f "${file}" ]]; then
        echo ""
        return 0
    fi

    line="$(grep -E "^${key}=" "${file}" | tail -n1 || true)"
    if [[ -z "${line}" ]]; then
        echo ""
        return 0
    fi

    echo "${line#*=}"
}

is_loopback_url() {
    local value="$1"
    [[ "${value}" =~ ^https?://(127\.0\.0\.1|localhost)(:[0-9]+)?(/|$) ]]
}

upsert_php_array_setting() {
    local file="$1"
    local var_name="$2"
    local key="$3"
    local value_expr="$4"
    local escaped_expr=""
    local current_line=""
    local desired_line=""

    if [[ ! -f "${file}" ]]; then
        return 0
    fi

    escaped_expr="$(escape_for_sed_replacement "${value_expr}")"
    desired_line="\$${var_name}['${key}'] = ${value_expr};"
    current_line="$(grep -E "^[[:space:]]*\\\$${var_name}\\['${key}'\\][[:space:]]*=" "${file}" | tail -n1 || true)"

    if [[ -n "${current_line}" ]]; then
        if [[ "$(normalize_compact_line "${current_line}")" == "$(normalize_compact_line "${desired_line}")" ]]; then
            return 0
        fi
    fi

    if grep -Eq "^[[:space:]]*\\\$${var_name}\\['${key}'\\][[:space:]]*=" "${file}"; then
        run sed -i -E "s|^[[:space:]]*\\\$${var_name}\\['${key}'\\][[:space:]]*=.*|\\\$${var_name}['${key}'] = ${escaped_expr};|g" "${file}"
    else
        printf "\n\$%s['%s'] = %s;\n" "${var_name}" "${key}" "${value_expr}" >> "${file}"
    fi
}

upsert_php_cfg_server_setting() {
    local file="$1"
    local key="$2"
    local value_expr="$3"
    local escaped_expr=""
    local current_line=""
    local desired_line=""

    if [[ ! -f "${file}" ]]; then
        return 0
    fi

    escaped_expr="$(escape_for_sed_replacement "${value_expr}")"
    desired_line="\$cfg['Servers'][\$i]['${key}'] = ${value_expr};"
    current_line="$(grep -E "^[[:space:]]*\\\$cfg\\['Servers'\\]\\[\\\$i\\]\\['${key}'\\][[:space:]]*=" "${file}" | tail -n1 || true)"

    if [[ -n "${current_line}" ]]; then
        if [[ "$(normalize_compact_line "${current_line}")" == "$(normalize_compact_line "${desired_line}")" ]]; then
            return 0
        fi
    fi

    if grep -Eq "^[[:space:]]*\\\$cfg\\['Servers'\\]\\[\\\$i\\]\\['${key}'\\][[:space:]]*=" "${file}"; then
        run sed -i -E "s|^[[:space:]]*\\\$cfg\\['Servers'\\]\\[\\\$i\\]\\['${key}'\\][[:space:]]*=.*|\\\$cfg['Servers'][\\\$i]['${key}'] = ${escaped_expr};|g" "${file}"
    else
        printf "\n\$cfg['Servers'][\$i]['%s'] = %s;\n" "${key}" "${value_expr}" >> "${file}"
    fi
}

upsert_php_cfg_server_index_setting() {
    local file="$1"
    local index="$2"
    local key="$3"
    local value_expr="$4"
    local escaped_expr=""
    local current_line=""
    local desired_line=""

    if [[ ! -f "${file}" ]]; then
        return 0
    fi

    escaped_expr="$(escape_for_sed_replacement "${value_expr}")"
    desired_line="\$cfg['Servers'][${index}]['${key}'] = ${value_expr};"
    current_line="$(grep -E "^[[:space:]]*\\\$cfg\\['Servers'\\]\\[${index}\\]\\['${key}'\\][[:space:]]*=" "${file}" | tail -n1 || true)"

    if [[ -n "${current_line}" ]]; then
        if [[ "$(normalize_compact_line "${current_line}")" == "$(normalize_compact_line "${desired_line}")" ]]; then
            return 0
        fi
    fi

    if grep -Eq "^[[:space:]]*\\\$cfg\\['Servers'\\]\\[${index}\\]\\['${key}'\\][[:space:]]*=" "${file}"; then
        run sed -i -E "s|^[[:space:]]*\\\$cfg\\['Servers'\\]\\[${index}\\]\\['${key}'\\][[:space:]]*=.*|\\\$cfg['Servers'][${index}]['${key}'] = ${escaped_expr};|g" "${file}"
    else
        printf "\n\$cfg['Servers'][%s]['%s'] = %s;\n" "${index}" "${key}" "${value_expr}" >> "${file}"
    fi
}

ensure_default_php_extension() {
    local extension="$1"
    local allow_generic="${2:-true}"
    local versioned_pkg="php${PHP_DEFAULT_VERSION}-${extension}"
    local generic_pkg="php-${extension}"

    if is_package_available "${versioned_pkg}"; then
        ensure_package "${versioned_pkg}" true
        return 0
    fi

    if [[ "${allow_generic}" == "true" ]]; then
        ensure_package "${generic_pkg}" true
    else
        warn "Package not available, skipping to avoid conflicts: ${versioned_pkg}"
    fi
}

enable_default_php_module() {
    local module="$1"
    local version="${2:-${PHP_DEFAULT_VERSION}}"
    local sapi

    if ! command -v phpenmod >/dev/null 2>&1; then
        return 0
    fi

    if [[ ! -f "/etc/php/${version}/mods-available/${module}.ini" ]]; then
        return 0
    fi

    for sapi in cli fpm apache2; do
        if [[ -d "/etc/php/${version}/${sapi}" ]]; then
            run phpenmod -v "${version}" -s "${sapi}" "${module}" || true
        fi
    done
}

enable_php_modules_for_version() {
    local version="$1"
    local module

    [[ -n "${version}" ]] || return 0

    # Keep core Composer/runtime modules enabled for CLI + web SAPIs.
    for module in ctype curl dom simplexml xml xmlwriter; do
        enable_default_php_module "${module}" "${version}"
    done
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

    if [[ "${PKG_MANAGER}" == "dnf" ]]; then
        warn "Exact PHP version switch is limited on ${PKG_MANAGER}. Ensuring system php/php-cli and retrying Composer."
        ensure_package php true
        ensure_package php-cli true
        ensure_default_php_binary
        return 0
    fi

    warn "Composer requires PHP ${version}. Attempting to prepare and switch CLI PHP."
    ensure_package "php${version}" true
    ensure_package "php${version}-cli" true
    ensure_package "php${version}-common" true
    ensure_package "php${version}-mbstring" true
    ensure_package "php${version}-xml" true
    ensure_package "php${version}-curl" true
    ensure_package "php${version}-zip" true
    ensure_package "php${version}-mysql" true

    if [[ ! -x "/usr/bin/php${version}" ]]; then
        warn "PHP binary not found after install attempt: /usr/bin/php${version}"
        return 1
    fi

    PHP_DEFAULT_VERSION="${version}"
    ensure_default_php_binary
    enable_php_modules_for_version "${version}"
    ok "Composer PHP compatibility fix applied (default CLI PHP ${version})."
    return 0
}

install_composer_dependencies() {
    local status=0
    local decision=""
    local log_file=""
    local required_php=""
    local vendor_rebuild_attempted="false"

    is_composer_vendor_scan_error() {
        local log_path="$1"
        [[ -f "${log_path}" ]] || return 1
        grep -q "Could not scan for classes inside" "${log_path}" 2>/dev/null \
            && grep -q "does not appear to be a file nor a folder" "${log_path}" 2>/dev/null
    }

    rebuild_composer_vendor_tree() {
        warn "Detected broken Composer vendor tree. Rebuilding vendor directory and retrying."
        if [[ -d vendor ]]; then
            rm -rf vendor
        fi
        mkdir -p vendor || true
        env COMPOSER_ALLOW_SUPERUSER=1 composer clear-cache >/dev/null 2>&1 || true
    }

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

        if [[ "${vendor_rebuild_attempted}" != "true" ]] && is_composer_vendor_scan_error "${log_file}"; then
            rebuild_composer_vendor_tree
            vendor_rebuild_attempted="true"
            continue
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
            echo "  3. Auto-clean vendor cache and retry"
            echo "  4. Skip composer install"
            echo "  5. Abort installer"
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
                3|clean|vendor)
                    rebuild_composer_vendor_tree
                    vendor_rebuild_attempted="true"
                    ;;
                4|s|skip)
                    warn "Skipping composer install by user choice."
                    return 0
                    ;;
                5|a|abort)
                    fail "Aborted after composer install failure."
                    ;;
                *)
                    warn "Invalid choice. Please enter 1, 2, 3, 4, or 5."
                    ;;
            esac
            continue
        fi

        if [[ "${vendor_rebuild_attempted}" != "true" ]] && is_composer_vendor_scan_error "${log_file}"; then
            rebuild_composer_vendor_tree
            vendor_rebuild_attempted="true"
            continue
        fi

        fail "Composer install failed in non-interactive mode."
    done
}

verify_php_extensions() {
    local missing=()
    local ext

    ensure_default_php_binary

    # json is built-in on modern PHP; verify common runtime/composer extensions too.
    for ext in curl dom json PDO pdo_mysql SimpleXML xmlwriter; do
        if ! php -m | grep -q -i "^${ext}$"; then
            missing+=("${ext}")
        fi
    done

    if [[ "${#missing[@]}" -eq 0 ]]; then
        ok "PHP runtime extensions available: curl, dom, json, PDO, pdo_mysql, SimpleXML, xmlwriter"
        return
    fi

    warn "Missing PHP extensions in current CLI runtime: ${missing[*]}"
    warn "Ensure phpX.Y-curl/phpX.Y-xml/phpX.Y-mysql are installed and modules are enabled for active /usr/bin/php."
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
    if [[ "${PKG_MANAGER}" == "dnf" ]]; then
        run bash -lc "curl -fsSL https://rpm.nodesource.com/setup_${NODEJS_REQUIRED_MAJOR}.x | bash -"
        run dnf -y install nodejs
    else
        run bash -lc "curl -fsSL https://deb.nodesource.com/setup_${NODEJS_REQUIRED_MAJOR}.x | bash -"
        run env DEBIAN_FRONTEND=noninteractive apt-get -y install nodejs
    fi
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
        fail "npm is still missing after Node.js setup. Check package manager and NodeSource repository status."
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

service_http_port_from_unit() {
    local unit_file="$1"
    local line
    if [[ ! -f "${unit_file}" ]]; then
        echo ""
        return 0
    fi
    line="$(grep -E '^ExecStart=' "${unit_file}" | head -n1 || true)"
    if [[ "${line}" =~ -S[[:space:]]+0\.0\.0\.0:([0-9]+) ]]; then
        echo "${BASH_REMATCH[1]}"
        return 0
    fi
    echo ""
}

phpmyadmin_port_from_service_file() {
    service_http_port_from_unit "/etc/systemd/system/${PHPMYADMIN_SERVICE}.service"
}

roundcube_port_from_service_file() {
    service_http_port_from_unit "/etc/systemd/system/${ROUNDCUBE_SERVICE}.service"
}

is_webtools_separate_mode_active() {
    [[ "${WEBTOOLS_SEPARATE_PORTS}" == "true" ]]
}

sync_webtools_mode_from_installed_services() {
    local pma_unit="/etc/systemd/system/${PHPMYADMIN_SERVICE}.service"
    local rc_unit="/etc/systemd/system/${ROUNDCUBE_SERVICE}.service"
    local detected_pma_port=""
    local detected_rc_port=""

    if [[ -f "${pma_unit}" || -f "${rc_unit}" ]]; then
        WEBTOOLS_SEPARATE_PORTS="true"
        detected_pma_port="$(phpmyadmin_port_from_service_file)"
        detected_rc_port="$(roundcube_port_from_service_file)"
        if [[ -n "${detected_pma_port}" ]]; then
            PHPMYADMIN_PORT="${detected_pma_port}"
        fi
        if [[ -n "${detected_rc_port}" ]]; then
            ROUNDCUBE_PORT="${detected_rc_port}"
        fi
    else
        WEBTOOLS_SEPARATE_PORTS="false"
    fi
}

phpmyadmin_access_url() {
    local host="${1:-127.0.0.1}"
    local detected_port=""
    if is_webtools_separate_mode_active; then
        detected_port="$(phpmyadmin_port_from_service_file)"
        echo "http://${host}:${detected_port:-${PHPMYADMIN_PORT}}/"
        return 0
    fi
    echo "http://${host}:${PANEL_PORT}/phpmyadmin/"
}

roundcube_access_url() {
    local host="${1:-127.0.0.1}"
    local detected_port=""
    if is_webtools_separate_mode_active; then
        detected_port="$(roundcube_port_from_service_file)"
        echo "http://${host}:${detected_port:-${ROUNDCUBE_PORT}}/"
        return 0
    fi
    echo "http://${host}:${PANEL_PORT}/roundcube/"
}

sync_panel_webtools_env() {
    local env_file="${1:-.env}"
    local existing_webmail existing_pma_url existing_pma_helper

    if [[ ! -f "${env_file}" ]]; then
        warn "Cannot sync panel webtool env (missing file): ${env_file}"
        return 0
    fi

    upsert_env_value "${env_file}" "WEBTOOLS_SEPARATE_PORTS" "${WEBTOOLS_SEPARATE_PORTS}"
    upsert_env_value "${env_file}" "PHPMYADMIN_PORT" "${PHPMYADMIN_PORT}"
    upsert_env_value "${env_file}" "ROUNDCUBE_PORT" "${ROUNDCUBE_PORT}"

    existing_webmail="$(read_env_value "${env_file}" "WEBMAIL_URL")"
    if [[ -z "${existing_webmail}" || "${existing_webmail,,}" == "auto" ]] || is_loopback_url "${existing_webmail}"; then
        upsert_env_value "${env_file}" "WEBMAIL_URL" "auto"
    fi

    existing_pma_url="$(read_env_value "${env_file}" "PHPMYADMIN_URL")"
    if [[ -z "${existing_pma_url}" ]] || is_loopback_url "${existing_pma_url}"; then
        upsert_env_value "${env_file}" "PHPMYADMIN_URL" ""
    fi

    existing_pma_helper="$(read_env_value "${env_file}" "PHPMYADMIN_HELPER_URL")"
    if [[ -z "${existing_pma_helper}" ]] || is_loopback_url "${existing_pma_helper}"; then
        upsert_env_value "${env_file}" "PHPMYADMIN_HELPER_URL" ""
    fi

    ok "Panel .env webtool settings synchronized."
}

write_install_credentials_log() {
    local server_ip login_url phpmyadmin_url roundcube_url root_log project_log owner
    server_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    login_url="http://${server_ip:-127.0.0.1}:${PANEL_PORT}/login"
    phpmyadmin_url="$(phpmyadmin_access_url "${server_ip:-127.0.0.1}")"
    roundcube_url="$(roundcube_access_url "${server_ip:-127.0.0.1}")"
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

Web Tools
- phpMyAdmin : ${phpmyadmin_url}
- Roundcube  : ${roundcube_url}

Database
- Service  : ${DB_SERVICE:-unknown}
- Name     : ${DB_NAME}
- User     : ${DB_USER}
- Password : ${DB_PASSWORD}

Roundcube Database
- Name     : ${ROUNDCUBE_DB_NAME:-roundcube}
- User     : ${ROUNDCUBE_DB_USER:-roundcube}
- Password : ${ROUNDCUBE_DB_PASSWORD:-not-generated}

phpMyAdmin Control User
- DB Name  : ${PHPMYADMIN_CONTROL_DB:-phpmyadmin}
- User     : ${PHPMYADMIN_CONTROL_USER:-pma}
- Password : ${PHPMYADMIN_CONTROL_PASSWORD:-not-generated}

phpMyAdmin Admin User (Full Access)
- Host     : 127.0.0.1
- User     : ${PHPMYADMIN_ADMIN_USER:-dbadmin}
- Password : ${PHPMYADMIN_ADMIN_PASSWORD:-not-generated}

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

detect_roundcube_web_root() {
    local candidate
    local candidates=(
        "/var/lib/roundcube/public_html"
        "/usr/share/roundcube"
        "/usr/share/roundcube/public_html"
        "/usr/share/roundcubemail"
        "/usr/share/roundcubemail/public_html"
        "/var/lib/roundcube"
    )

    for candidate in "${candidates[@]}"; do
        if [[ -f "${candidate}/index.php" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    echo ""
}

# Backward-compatible alias kept because multiple flows still call detect_phpmyadmin_web_root().
# Canonical resolver is inttool_resolve_phpmyadmin_web_root() in the phpMyAdmin section.
detect_phpmyadmin_web_root() {
    inttool_resolve_phpmyadmin_web_root
}






publish_panel_public_symlink() {
    local panel_dir="$1"
    local source_dir="$2"
    local slug="$3"
    local target_dir resolved_source resolved_target owner_group

    if [[ -z "${panel_dir}" || ! -d "${panel_dir}/public" ]]; then
        warn "Panel public directory not found. Skipping publish for /${slug}."
        return 0
    fi

    if [[ -z "${source_dir}" || ! -d "${source_dir}" ]]; then
        warn "Source directory not found for /${slug}: ${source_dir}"
        return 0
    fi

    target_dir="${panel_dir}/public/${slug}"
    resolved_source="$(readlink -f "${source_dir}" 2>/dev/null || echo "${source_dir}")"

    if [[ -L "${target_dir}" ]]; then
        resolved_target="$(readlink -f "${target_dir}" 2>/dev/null || true)"
        if [[ "${resolved_target}" == "${resolved_source}" ]]; then
            ok "Already published on panel port: /${slug}"
            return 0
        fi
    fi

    if [[ -e "${target_dir}" || -L "${target_dir}" ]]; then
        warn "Replacing existing path for /${slug}: ${target_dir}"
        run rm -rf "${target_dir}"
    fi

    run ln -s "${source_dir}" "${target_dir}"
    owner_group="$(web_owner_group)"
    run chown -h "${owner_group}" "${target_dir}" || true
    ok "Published on panel port: /${slug}"
}

expose_panel_tools_on_port() {
    local panel_dir="${1:-${PROJECT_DIR}}"
    local phpmyadmin_root=""
    local roundcube_root=""

    phpmyadmin_root="$(detect_phpmyadmin_web_root)"
    if [[ -n "${phpmyadmin_root}" ]]; then
        PHPMYADMIN_ROOT="${phpmyadmin_root}"
        publish_panel_public_symlink "${panel_dir}" "${phpmyadmin_root}" "phpmyadmin"
    else
        warn "phpMyAdmin web root not found."
    fi

    roundcube_root="$(detect_roundcube_web_root)"
    if [[ -n "${roundcube_root}" ]]; then
        publish_panel_public_symlink "${panel_dir}" "${roundcube_root}" "roundcube"
    else
        warn "Roundcube web root not found after package install."
    fi
}

cleanup_panel_embedded_webtools_links() {
    local panel_dir="${1:-${PROJECT_DIR}}"
    local slug
    for slug in phpmyadmin roundcube; do
        if [[ -L "${panel_dir}/public/${slug}" ]]; then
            run rm -f "${panel_dir}/public/${slug}" || true
        fi
    done
}

ensure_webtool_root_target() {
    local source_dir="$1"
    local target_dir="$2"
    local label="$3"
    local resolved_source resolved_target backup_dir

    if [[ ! -d "${source_dir}" ]]; then
        warn "${label} source path not found: ${source_dir}"
        return 1
    fi

    run mkdir -p "$(dirname "${target_dir}")"
    resolved_source="$(readlink -f "${source_dir}" 2>/dev/null || echo "${source_dir}")"

    if [[ -L "${target_dir}" ]]; then
        resolved_target="$(readlink -f "${target_dir}" 2>/dev/null || true)"
        if [[ "${resolved_target}" == "${resolved_source}" ]]; then
            ok "${label} root already linked: ${target_dir}"
            return 0
        fi
        run rm -f "${target_dir}" || true
    elif [[ -d "${target_dir}" ]]; then
        if [[ -f "${target_dir}/index.php" ]]; then
            info "Keeping existing ${label} root directory: ${target_dir}"
            return 0
        fi
        backup_dir="${target_dir}.backup.$(date +%s)"
        run mv "${target_dir}" "${backup_dir}" || true
        warn "Moved unexpected ${label} path to backup: ${backup_dir}"
    elif [[ -e "${target_dir}" ]]; then
        backup_dir="${target_dir}.backup.$(date +%s)"
        run mv "${target_dir}" "${backup_dir}" || true
        warn "Moved unexpected ${label} file to backup: ${backup_dir}"
    fi

    run ln -s "${source_dir}" "${target_dir}"
    ok "${label} root prepared: ${target_dir} -> ${source_dir}"
    return 0
}

write_php_builtin_webtool_service() {
    local service_name="$1"
    local description="$2"
    local working_dir="$3"
    local listen_port="$4"

    cat > "/etc/systemd/system/${service_name}.service" <<EOF
[Unit]
Description=${description}
After=network.target mariadb.service
Wants=network.target

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=${working_dir}
ExecStart=/usr/bin/php -S 0.0.0.0:${listen_port} -t ${working_dir}
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
EOF
}

disable_separate_webtools_services() {
    local svc
    local changed="false"
    for svc in "${PHPMYADMIN_SERVICE}" "${ROUNDCUBE_SERVICE}"; do
        if systemctl cat "${svc}.service" >/dev/null 2>&1 || [[ -f "/etc/systemd/system/${svc}.service" ]]; then
            run systemctl disable --now "${svc}" || true
            run rm -f "/etc/systemd/system/${svc}.service" || true
            changed="true"
        fi
    done
    if [[ "${changed}" == "true" ]]; then
        run systemctl daemon-reload || true
        info "Removed dedicated webtool services."
    fi
}

setup_separate_webtools_services() {
    local phpmyadmin_source=""
    local roundcube_source=""
    local pma_ready="false"
    local rc_ready="false"

    info "Configuring dedicated webtool services (phpMyAdmin:${PHPMYADMIN_PORT}, Roundcube:${ROUNDCUBE_PORT})"
    prepare_firewall_backend

    phpmyadmin_source="$(detect_phpmyadmin_web_root)"
    if [[ -n "${phpmyadmin_source}" ]]; then
        if ensure_webtool_root_target "${phpmyadmin_source}" "${PHPMYADMIN_ROOT}" "phpMyAdmin"; then
            write_php_builtin_webtool_service "${PHPMYADMIN_SERVICE}" "ServerPanel phpMyAdmin HTTP Service" "${PHPMYADMIN_ROOT}" "${PHPMYADMIN_PORT}"
            pma_ready="true"
        fi
    else
        warn "phpMyAdmin source path could not be detected."
    fi

    roundcube_source="$(detect_roundcube_web_root)"
    if [[ -n "${roundcube_source}" ]]; then
        if ensure_webtool_root_target "${roundcube_source}" "${ROUNDCUBE_ROOT}" "Roundcube"; then
            write_php_builtin_webtool_service "${ROUNDCUBE_SERVICE}" "ServerPanel Roundcube HTTP Service" "${ROUNDCUBE_ROOT}" "${ROUNDCUBE_PORT}"
            rc_ready="true"
        fi
    else
        warn "Roundcube web root could not be detected."
    fi

    run systemctl daemon-reload || true
    if [[ "${pma_ready}" == "true" ]]; then
        run systemctl enable --now "${PHPMYADMIN_SERVICE}" || true
        allow_firewall_port "${PHPMYADMIN_PORT}" "tcp"
    fi
    if [[ "${rc_ready}" == "true" ]]; then
        run systemctl enable --now "${ROUNDCUBE_SERVICE}" || true
        allow_firewall_port "${ROUNDCUBE_PORT}" "tcp"
    fi
}

detect_roundcube_mysql_schema() {
    local candidate
    local candidates=(
        "/usr/share/dbconfig-common/data/roundcube/install/mysql"
        "/usr/share/roundcube/SQL/mysql.initial.sql"
        "/usr/share/roundcube/SQL/mysql5.initial.sql"
        "/usr/share/roundcube/SQL/mysql.initial.sql.gz"
        "/usr/share/roundcubemail/SQL/mysql.initial.sql"
        "/usr/share/roundcubemail/SQL/mysql.initial.sql.gz"
    )

    for candidate in "${candidates[@]}"; do
        if [[ -f "${candidate}" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    echo ""
}

detect_roundcube_config_file() {
    local candidate
    local candidates=(
        "/etc/roundcube/config.inc.php"
        "/etc/roundcubemail/config.inc.php"
        "/usr/share/roundcube/config/config.inc.php"
        "/usr/share/roundcubemail/config/config.inc.php"
    )

    for candidate in "${candidates[@]}"; do
        if [[ -f "${candidate}" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    if [[ "${PKG_MANAGER}" == "dnf" ]]; then
        echo "/etc/roundcubemail/config.inc.php"
    else
        echo "/etc/roundcube/config.inc.php"
    fi
}

detect_phpmyadmin_schema() {
    local candidate
    local candidates=(
        "/usr/share/phpmyadmin/sql/create_tables.sql"
        "/usr/share/phpMyAdmin/sql/create_tables.sql"
        "/usr/share/doc/phpmyadmin/examples/create_tables.sql"
        "/usr/share/doc/phpmyadmin/examples/create_tables.sql.gz"
    )

    for candidate in "${candidates[@]}"; do
        if [[ -f "${candidate}" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    echo ""
}

detect_phpmyadmin_config_file() {
    local candidate
    local candidates=()

    if [[ -n "${PHPMYADMIN_ROOT:-}" ]]; then
        candidates+=("${PHPMYADMIN_ROOT%/}/config.inc.php")
    fi
    candidates+=(
        "/etc/phpmyadmin/config.inc.php"
        "/etc/phpMyAdmin/config.inc.php"
        "/usr/share/phpmyadmin/config.inc.php"
        "/usr/share/phpMyAdmin/config.inc.php"
        "/root/phpmyadmin/config.inc.php"
    )

    for candidate in "${candidates[@]}"; do
        if [[ -f "${candidate}" ]]; then
            echo "${candidate}"
            return 0
        fi
    done

    echo ""
}



#  ===================================== PhpMyAdmin + Maria DB ====================================
inttool_resolve_phpmyadmin_web_root() {
    local candidate
    local candidates=(
        "${PHPMYADMIN_ROOT:-}"
        "/usr/share/phpmyadmin"
        "/usr/share/phpMyAdmin"
        "/var/www/phpmyadmin"
        "/var/www/html/phpmyadmin"
    )

    for candidate in "${candidates[@]}"; do
        [[ -z "${candidate}" ]] && continue
        if [[ -f "${candidate%/}/index.php" ]]; then
            echo "${candidate%/}"
            return 0
        fi
    done

    echo ""
}

inttool_copy_with_retry_continue() {
    local source_file="$1"
    local target_file="$2"
    local label="$3"
    local max_attempts="${4:-2}"
    local attempt=1

    if [[ ! -f "${source_file}" ]]; then
        fail "${label} source file not found: ${source_file}"
    fi

    while (( attempt <= max_attempts )); do
        if run cp "${source_file}" "${target_file}"; then
            if (( attempt > 1 )); then
                ok "${label} copy succeeded on retry (${attempt}/${max_attempts})."
            fi
            return 0
        fi
        warn "${label} copy failed (${attempt}/${max_attempts})."
        attempt=$((attempt + 1))
    done

    fail "${label} copy failed after ${max_attempts} attempts."
}

inttool_deploy_phpmyadmin_helper() {
    local pma_root helper_template target_bridge owner_group

    pma_root="$(inttool_resolve_phpmyadmin_web_root)"
    if [[ -z "${pma_root}" ]]; then
        fail "phpMyAdmin root not found. Cannot deploy helper in copy-only mode."
    fi

    helper_template="$(resolve_extra_file "phpmyadminsignin.php")"
    if [[ -z "${helper_template}" || ! -f "${helper_template}" ]]; then
        fail "phpMyAdmin helper template not found: ServerPanel/extra/phpmyadminsignin.php"
    fi

    target_bridge="${pma_root}/phpmyadminsignin.php"
    inttool_copy_with_retry_continue "${helper_template}" "${target_bridge}" "phpMyAdmin helper" 2

    owner_group="$(web_owner_group)"
    run chmod 644 "${target_bridge}"
    run chown "${owner_group}" "${target_bridge}"
    ok "phpMyAdmin helper deployed: ${target_bridge}"
    return 0
}

inttool_deploy_phpmyadmin_suite() {
    local pma_root template_config target_config owner_group
    local blowfish_secret pma_pass escaped_blowfish escaped_pma_pass

    pma_root="$(inttool_resolve_phpmyadmin_web_root)"
    if [[ -z "${pma_root}" ]]; then
        fail "phpMyAdmin root not found. Cannot deploy suite in copy-only mode."
    fi

    template_config="$(resolve_extra_file "phpmyadmin.config.inc.php")"
    if [[ -z "${template_config}" || ! -f "${template_config}" ]]; then
        fail "phpMyAdmin config template not found: ServerPanel/extra/phpmyadmin.config.inc.php"
    fi

    target_config="${pma_root}/config.inc.php"
    inttool_copy_with_retry_continue "${template_config}" "${target_config}" "phpMyAdmin config" 2

    if command -v openssl >/dev/null 2>&1; then
        blowfish_secret="$(openssl rand -hex 32)"
    else
        blowfish_secret="$(generate_random_password)$(generate_random_password)"
    fi

    pma_pass="${PHPMYADMIN_CONTROL_PASSWORD:-${DB_PMA_PASSWORD:-}}"
    if [[ -z "${pma_pass}" ]]; then
        pma_pass="$(generate_random_password)"
    fi
    PHPMYADMIN_CONTROL_PASSWORD="${pma_pass}"

    escaped_blowfish="$(escape_for_sed_replacement "${blowfish_secret}")"
    escaped_pma_pass="$(escape_for_sed_replacement "${pma_pass}")"
    if ! sed -i "s|___blowfish_secret___|${escaped_blowfish}|g" "${target_config}"; then
        fail "Failed to patch blowfish secret in ${target_config}"
    fi
    if ! sed -i "s|___pma_password___|${escaped_pma_pass}|g" "${target_config}"; then
        fail "Failed to patch pma password in ${target_config}"
    fi

    if [[ -f "/etc/ssl/certs/serverpanel.crt" || "${FORCE_SSL:-false}" == "true" ]]; then
        sed -i "s/'secure' => false/'secure' => true/g" "${target_config}" || true
    fi

    owner_group="$(web_owner_group)"
    run chmod 640 "${target_config}"
    run chown "${owner_group}" "${target_config}"
    ok "phpMyAdmin suite config deployed: ${target_config}"

    inttool_deploy_phpmyadmin_helper
    return 0
}


configure_phpmyadmin_runtime() {
    local config_file=""
    local temp_dir="/var/lib/phpmyadmin/tmp"
    local creds_file="/etc/phpmyadmin/serverpanel-control-user.conf"
    local admin_creds_file="/etc/phpmyadmin/serverpanel-admin-user.conf"
    local blowfish_secret_file="/etc/phpmyadmin/serverpanel-blowfish-secret"
    local control_db="${PHPMYADMIN_CONTROL_DB:-phpmyadmin}"
    local control_user="${PHPMYADMIN_CONTROL_USER:-pma}"
    local control_password="${PHPMYADMIN_CONTROL_PASSWORD:-}"
    local admin_user="${PHPMYADMIN_ADMIN_USER:-dbadmin}"
    local admin_password="${PHPMYADMIN_ADMIN_PASSWORD:-}"
    local owner_group secret="" db_cli sql_db sql_user sql_password sql_admin_user sql_admin_password
    local schema_file pma_table_exists db_cli_q db_name_q schema_q
    local config_candidate=""
    local signon_url="/phpmyadmin/phpmyadminsignin.php"
    local -a config_files=()
    local -A config_seen=()

    if ! is_package_installed phpmyadmin; then
        warn "phpMyAdmin package is not installed. Trying custom phpMyAdmin config paths."
    fi

    ensure_default_php_binary
    ensure_package "php${PHP_DEFAULT_VERSION}-common" true
    enable_php_modules_for_version "${PHP_DEFAULT_VERSION}"
    ensure_default_php_extension "mbstring" true
    ensure_default_php_extension "mysql" true
    ensure_default_php_extension "xml" true
    ensure_default_php_extension "zip" true

    run mkdir -p "${temp_dir}"
    owner_group="$(web_owner_group)"
    run chown -R "${owner_group}" "${temp_dir}" || true
    run chmod 1770 "${temp_dir}" || true
    run mkdir -p /etc/phpmyadmin

    config_candidate="$(detect_phpmyadmin_config_file)"
    for config_file in "${config_candidate}" \
        "/etc/phpmyadmin/config.inc.php" \
        "/etc/phpMyAdmin/config.inc.php" \
        "/usr/share/phpmyadmin/config.inc.php" \
        "/usr/share/phpMyAdmin/config.inc.php" \
        "${PHPMYADMIN_ROOT%/}/config.inc.php"; do
        [[ -n "${config_file}" ]] || continue
        [[ -f "${config_file}" ]] || continue
        if [[ -z "${config_seen[${config_file}]:-}" ]]; then
            config_files+=("${config_file}")
            config_seen["${config_file}"]="1"
        fi
    done

    if [[ "${#config_files[@]}" -eq 0 ]]; then
        fail "phpMyAdmin config.inc.php not found. Copy-only mode requires template copy from ServerPanel/extra/phpmyadmin.config.inc.php."
    fi
    config_file="${config_files[0]}"
    info "phpMyAdmin config detected: ${config_file}"

    # Keep SignonURL aligned with selected mode so panel auto-login always lands on
    # the correct helper endpoint.
    if is_webtools_separate_mode_active; then
        signon_url="/phpmyadminsignin.php"
    fi

    if [[ -f "${creds_file}" ]]; then
        control_db="$(grep -E '^DB_NAME=' "${creds_file}" | head -n1 | cut -d= -f2- || true)"
        control_user="$(grep -E '^DB_USER=' "${creds_file}" | head -n1 | cut -d= -f2- || true)"
        control_password="$(grep -E '^DB_PASSWORD=' "${creds_file}" | head -n1 | cut -d= -f2- || true)"
    fi

    if [[ -z "${control_db}" ]]; then
        control_db="phpmyadmin"
    fi
    if [[ -z "${control_user}" || ! "${control_user}" =~ ^[A-Za-z0-9_]+$ ]]; then
        control_user="pma"
    fi
    if [[ -z "${control_password}" ]]; then
        control_password="$(generate_random_password)"
    fi
    if [[ -f "${admin_creds_file}" ]]; then
        admin_user="$(grep -E '^DB_USER=' "${admin_creds_file}" | head -n1 | cut -d= -f2- || true)"
        admin_password="$(grep -E '^DB_PASSWORD=' "${admin_creds_file}" | head -n1 | cut -d= -f2- || true)"
    fi
    if [[ -z "${admin_user}" || ! "${admin_user}" =~ ^[A-Za-z0-9_]+$ ]]; then
        admin_user="dbadmin"
    fi
    if [[ -z "${admin_password}" ]]; then
        admin_password="$(generate_random_password)"
    fi

    PHPMYADMIN_CONTROL_DB="${control_db}"
    PHPMYADMIN_CONTROL_USER="${control_user}"
    PHPMYADMIN_CONTROL_PASSWORD="${control_password}"
    PHPMYADMIN_ADMIN_USER="${admin_user}"
    PHPMYADMIN_ADMIN_PASSWORD="${admin_password}"

    cat > "${creds_file}" <<EOF
DB_NAME=${control_db}
DB_USER=${control_user}
DB_PASSWORD=${control_password}
EOF
    run chmod 600 "${creds_file}"
    cat > "${admin_creds_file}" <<EOF
DB_USER=${admin_user}
DB_PASSWORD=${admin_password}
EOF
    run chmod 600 "${admin_creds_file}"

    ensure_database_running
    db_cli="$(detect_db_cli)"
    if [[ -z "${db_cli}" ]]; then
        warn "No database CLI found. Skipping phpMyAdmin control-user DB setup."
    else
        sql_db="$(escape_sql_string "${control_db}")"
        sql_user="$(escape_sql_string "${control_user}")"
        sql_password="$(escape_sql_string "${control_password}")"
        sql_admin_user="$(escape_sql_string "${admin_user}")"
        sql_admin_password="$(escape_sql_string "${admin_password}")"

        run "${db_cli}" -e "CREATE DATABASE IF NOT EXISTS \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
        run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_user}'@'127.0.0.1' IDENTIFIED BY '${sql_password}';"
        run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_user}'@'localhost' IDENTIFIED BY '${sql_password}';"
        run "${db_cli}" -e "ALTER USER '${sql_user}'@'127.0.0.1' IDENTIFIED BY '${sql_password}';"
        run "${db_cli}" -e "ALTER USER '${sql_user}'@'localhost' IDENTIFIED BY '${sql_password}';"
        run "${db_cli}" -e "GRANT ALL PRIVILEGES ON \`${sql_db}\`.* TO '${sql_user}'@'127.0.0.1';"
        run "${db_cli}" -e "GRANT ALL PRIVILEGES ON \`${sql_db}\`.* TO '${sql_user}'@'localhost';"
        run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_admin_user}'@'127.0.0.1' IDENTIFIED BY '${sql_admin_password}';"
        run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_admin_user}'@'localhost' IDENTIFIED BY '${sql_admin_password}';"
        run "${db_cli}" -e "ALTER USER '${sql_admin_user}'@'127.0.0.1' IDENTIFIED BY '${sql_admin_password}';"
        run "${db_cli}" -e "ALTER USER '${sql_admin_user}'@'localhost' IDENTIFIED BY '${sql_admin_password}';"
        run "${db_cli}" -e "GRANT ALL PRIVILEGES ON *.* TO '${sql_admin_user}'@'127.0.0.1' WITH GRANT OPTION;"
        run "${db_cli}" -e "GRANT ALL PRIVILEGES ON *.* TO '${sql_admin_user}'@'localhost' WITH GRANT OPTION;"
        run "${db_cli}" -e "FLUSH PRIVILEGES;"

        pma_table_exists="$("${db_cli}" -N -s -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${sql_db}' AND table_name='pma__bookmark' LIMIT 1;" 2>/dev/null || true)"
        if [[ "${pma_table_exists}" != "1" ]]; then
            schema_file="$(detect_phpmyadmin_schema)"
            if [[ -n "${schema_file}" ]]; then
                printf -v db_cli_q "%q" "${db_cli}"
                printf -v db_name_q "%q" "${control_db}"
                printf -v schema_q "%q" "${schema_file}"
                if [[ "${schema_file}" == *.gz ]]; then
                    run bash -lc "gzip -dc ${schema_q} | ${db_cli_q} ${db_name_q}"
                else
                    run bash -lc "${db_cli_q} ${db_name_q} < ${schema_q}"
                fi
            else
                warn "phpMyAdmin create_tables SQL not found. Advanced relation features may stay disabled."
            fi
        fi
    fi

    if [[ -s "${blowfish_secret_file}" ]]; then
        secret="$(head -n1 "${blowfish_secret_file}" 2>/dev/null | tr -d '\r\n' || true)"
    fi
    if [[ -z "${secret}" || "${#secret}" -lt 32 ]]; then
        secret="$(generate_random_password)$(generate_random_password)"
        run mkdir -p "$(dirname "${blowfish_secret_file}")"
        cat > "${blowfish_secret_file}" <<EOF
${secret}
EOF
        run chmod 600 "${blowfish_secret_file}" || true
    fi
    for config_file in "${config_files[@]}"; do
        local has_dynamic_server_index="false"
        [[ -f "${config_file}" ]] || continue

        if grep -Eq "\\\$cfg\\['Servers'\\]\\[\\\$i\\]" "${config_file}"; then
            has_dynamic_server_index="true"
        fi

        upsert_php_array_setting "${config_file}" "cfg" "blowfish_secret" "'${secret}'"
        upsert_php_cfg_server_setting "${config_file}" "auth_type" "'signon'"
        upsert_php_cfg_server_setting "${config_file}" "SignonSession" "'SignonSession'"
        upsert_php_cfg_server_setting "${config_file}" "SignonURL" "'${signon_url}'"
        upsert_php_cfg_server_setting "${config_file}" "pmadb" "'${control_db}'"
        upsert_php_cfg_server_setting "${config_file}" "controluser" "'${control_user}'"
        upsert_php_cfg_server_setting "${config_file}" "controlpass" "'${control_password}'"

        # Fallback only for configs that do not use $i.
        if [[ "${has_dynamic_server_index}" != "true" ]]; then
            upsert_php_cfg_server_index_setting "${config_file}" "1" "auth_type" "'signon'"
            upsert_php_cfg_server_index_setting "${config_file}" "1" "SignonSession" "'SignonSession'"
            upsert_php_cfg_server_index_setting "${config_file}" "1" "SignonURL" "'${signon_url}'"
            upsert_php_cfg_server_index_setting "${config_file}" "1" "pmadb" "'${control_db}'"
            upsert_php_cfg_server_index_setting "${config_file}" "1" "controluser" "'${control_user}'"
            upsert_php_cfg_server_index_setting "${config_file}" "1" "controlpass" "'${control_password}'"
        fi
    done
    info "phpMyAdmin credentials updated in config file(s) by int-tool.sh"
    inttool_deploy_phpmyadmin_helper

    if ! php -m 2>/dev/null | grep -qi "^ctype$"; then
        warn "PHP ctype extension is still not active in CLI. phpMyAdmin may fail until ctype is enabled."
    fi

    ok "phpMyAdmin runtime configured."
}

configure_roundcube_runtime() {
    local config_file=""
    local template_config=""
    local creds_file="/etc/roundcube/serverpanel-db.conf"
    local debian_db_file="/etc/roundcube/debian-db.php"
    local roundcube_db="${ROUNDCUBE_DB_NAME:-roundcube}"
    local roundcube_user="${ROUNDCUBE_DB_USER:-roundcube}"
    local roundcube_password="${ROUNDCUBE_DB_PASSWORD:-}"
    local sql_db sql_user sql_password db_cli users_table_exists schema_file
    local db_cli_q db_name_q schema_q
    local owner_group web_group des_key=""

    if ! is_package_installed roundcube && ! is_package_installed roundcube-core; then
        warn "Roundcube package is not installed. Skipping Roundcube runtime configuration."
        return 0
    fi

    ensure_default_php_binary
    ensure_package "php${PHP_DEFAULT_VERSION}-common" true
    enable_php_modules_for_version "${PHP_DEFAULT_VERSION}"
    ensure_default_php_extension "mbstring" true
    ensure_default_php_extension "xml" true
    ensure_default_php_extension "mysql" true
    ensure_default_php_extension "intl" true
    ensure_default_php_extension "gd" true
    ensure_default_php_extension "imap" false

    config_file="$(detect_roundcube_config_file)"
    run mkdir -p /etc/roundcube
    run mkdir -p "$(dirname "${config_file}")" || true
    if [[ "${PKG_MANAGER}" == "dnf" ]]; then
        debian_db_file="/etc/roundcubemail/debian-db.php"
        run mkdir -p /etc/roundcubemail || true
    fi
    if [[ -f "${creds_file}" ]]; then
        roundcube_user="$(grep -E '^DB_USER=' "${creds_file}" | head -n1 | cut -d= -f2- || true)"
        roundcube_password="$(grep -E '^DB_PASSWORD=' "${creds_file}" | head -n1 | cut -d= -f2- || true)"
    fi

    if [[ -z "${roundcube_user}" ]]; then
        roundcube_user="roundcube"
    fi
    if [[ -z "${roundcube_password}" ]]; then
        roundcube_password="$(generate_random_password)"
        cat > "${creds_file}" <<EOF
DB_NAME=${roundcube_db}
DB_USER=${roundcube_user}
DB_PASSWORD=${roundcube_password}
EOF
        run chmod 600 "${creds_file}"
    fi

    ROUNDCUBE_DB_NAME="${roundcube_db}"
    ROUNDCUBE_DB_USER="${roundcube_user}"
    ROUNDCUBE_DB_PASSWORD="${roundcube_password}"

    ensure_database_running
    db_cli="$(detect_db_cli)"
    if [[ -z "${db_cli}" ]]; then
        warn "No database CLI found. Skipping Roundcube DB setup."
        return 0
    fi

    sql_db="$(escape_sql_string "${roundcube_db}")"
    sql_user="$(escape_sql_string "${roundcube_user}")"
    sql_password="$(escape_sql_string "${roundcube_password}")"

    run "${db_cli}" -e "CREATE DATABASE IF NOT EXISTS \`${sql_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_user}'@'127.0.0.1' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_user}'@'localhost' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "ALTER USER '${sql_user}'@'127.0.0.1' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "ALTER USER '${sql_user}'@'localhost' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "GRANT ALL PRIVILEGES ON \`${sql_db}\`.* TO '${sql_user}'@'127.0.0.1';"
    run "${db_cli}" -e "GRANT ALL PRIVILEGES ON \`${sql_db}\`.* TO '${sql_user}'@'localhost';"
    run "${db_cli}" -e "FLUSH PRIVILEGES;"

    users_table_exists="$("${db_cli}" -N -s -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${sql_db}' AND table_name='users' LIMIT 1;" 2>/dev/null || true)"
    if [[ "${users_table_exists}" != "1" ]]; then
        schema_file="$(detect_roundcube_mysql_schema)"
        if [[ -n "${schema_file}" ]]; then
            printf -v db_cli_q "%q" "${db_cli}"
            printf -v db_name_q "%q" "${roundcube_db}"
            printf -v schema_q "%q" "${schema_file}"
            if [[ "${schema_file}" == *.gz ]]; then
                run bash -lc "gzip -dc ${schema_q} | ${db_cli_q} ${db_name_q}"
            else
                run bash -lc "${db_cli_q} ${db_name_q} < ${schema_q}"
            fi
        else
            warn "Roundcube SQL schema file not found. Roundcube may fail until schema is imported."
        fi
    fi

    template_config="${SCRIPT_DIR}/ServerPanel/extra/roundcube.config.inc.php"
    if [[ ! -s "${template_config}" ]]; then
        fail "Required Roundcube template not found: ${template_config}"
    fi
    ensure_parent_dir_writable "${config_file}"
    run cp "${template_config}" "${config_file}"
    run chmod 640 "${config_file}" || true
    info "Roundcube template applied: ${template_config}"

    if [[ "${PKG_MANAGER}" == "apt" || "$(dirname "${debian_db_file}")" == "/etc/roundcube" ]]; then
        cat > "${debian_db_file}" <<EOF
<?php
\$dbuser='${roundcube_user}';
\$dbpass='${roundcube_password}';
\$basepath='';
\$dbname='${roundcube_db}';
\$dbserver='127.0.0.1';
\$dbport='';
\$dbtype='mysql';
\$dbsocket='';
EOF
        web_group="$(web_owner_group)"
        web_group="${web_group#*:}"
        run chown "root:${web_group}" "${debian_db_file}" || true
        run chmod 640 "${debian_db_file}" || true
    fi

    run mkdir -p /var/lib/roundcube/temp /var/log/roundcube
    owner_group="$(web_owner_group)"
    run chown -R "${owner_group}" /var/lib/roundcube/temp /var/log/roundcube || true
    run chmod 770 /var/lib/roundcube/temp /var/log/roundcube || true

    upsert_php_array_setting "${config_file}" "config" "db_dsnw" "'mysql://${roundcube_user}:${roundcube_password}@127.0.0.1/${roundcube_db}'"
    upsert_php_array_setting "${config_file}" "config" "temp_dir" "'/var/lib/roundcube/temp'"
    upsert_php_array_setting "${config_file}" "config" "log_dir" "'/var/log/roundcube'"
    upsert_php_array_setting "${config_file}" "config" "default_host" "'127.0.0.1'"
    upsert_php_array_setting "${config_file}" "config" "default_port" "143"
    upsert_php_array_setting "${config_file}" "config" "smtp_server" "'127.0.0.1'"
    upsert_php_array_setting "${config_file}" "config" "smtp_port" "587"
    upsert_php_array_setting "${config_file}" "config" "smtp_user" "'%u'"
    upsert_php_array_setting "${config_file}" "config" "smtp_pass" "'%p'"
    upsert_php_array_setting "${config_file}" "config" "auto_create_user" "true"
    upsert_php_array_setting "${config_file}" "config" "login_autocomplete" "2"
    upsert_php_array_setting "${config_file}" "config" "session_lifetime" "3600"

    if ! grep -Eq "^[[:space:]]*\\\$config\\['des_key'\\][[:space:]]*=" "${config_file}" \
        || grep -Eq "^[[:space:]]*\\\$config\\['des_key'\\][[:space:]]*=[[:space:]]*'';" "${config_file}"; then
        des_key="$(generate_random_password)$(generate_random_password)"
        upsert_php_array_setting "${config_file}" "config" "des_key" "'${des_key}'"
    fi

    ok "Roundcube runtime configured."
}

install_roundcube_webmail() {
    ensure_default_php_extension "intl" true
    ensure_default_php_extension "gd" true
    ensure_default_php_extension "imap" false

    if [[ "${PKG_MANAGER}" == "dnf" ]]; then
        if is_package_installed roundcube || is_package_installed roundcube-core; then
            ok "Already installed: roundcubemail"
            return 0
        fi

        ensure_package roundcube true
        ensure_package roundcube-mysql true
        if is_package_installed roundcube || is_package_installed roundcube-core; then
            ok "Roundcube package install completed."
        else
            warn "Roundcube install did not complete."
        fi
        return 0
    fi

    if is_package_installed roundcube || is_package_installed roundcube-core; then
        ok "Already installed: roundcube"
        return 0
    fi

    if is_package_available roundcube; then
        echo "roundcube-core roundcube-core/dbconfig-install boolean false" | debconf-set-selections || true
        echo "roundcube-core roundcube-core/reconfigure-webserver multiselect none" | debconf-set-selections || true
        ensure_package roundcube true
    elif is_package_available roundcube-core; then
        echo "roundcube-core roundcube-core/dbconfig-install boolean false" | debconf-set-selections || true
        echo "roundcube-core roundcube-core/reconfigure-webserver multiselect none" | debconf-set-selections || true
        ensure_package roundcube-core true
    else
        warn "Package not available, skipping: roundcube"
        return 0
    fi

    if is_package_available roundcube-mysql; then
        ensure_package roundcube-mysql true
    fi

    if is_package_installed roundcube || is_package_installed roundcube-core; then
        ok "Roundcube package install completed."
    else
        warn "Roundcube install did not complete."
    fi
}

install_dovecot_storage_stack() {
    local installer_script=""

    ensure_package dovecot-core true
    ensure_package dovecot-imapd true
    ensure_package dovecot-pop3d true

    if [[ "${PKG_MANAGER}" == "apt" ]]; then
        installer_script="${PROJECT_DIR%/}/scripts/install-roundcube-dovecot-mysql.sh"
        if [[ ! -f "${installer_script}" ]]; then
            installer_script="${SCRIPT_DIR}/ServerPanel/scripts/install-roundcube-dovecot-mysql.sh"
        fi
        if [[ ! -f "${installer_script}" ]]; then
            installer_script="${SCRIPT_DIR}/scripts/install-roundcube-dovecot-mysql.sh"
        fi

        if [[ -f "${installer_script}" ]]; then
            run bash "${installer_script}" --skip-update || true
        else
            ensure_package dovecot-mysql true
        fi
    else
        ensure_package dovecot-mysql true
    fi

    if systemctl cat dovecot.service >/dev/null 2>&1; then
        run systemctl enable dovecot || true
        run systemctl restart dovecot || true
        if systemctl is-active --quiet dovecot; then
            ok "Dovecot service is running."
        else
            warn "Dovecot service is not active after restart. Check: systemctl status dovecot"
        fi
    else
        warn "dovecot.service not found after package install."
    fi
}


# ======================== Complete phpMyAdmin installation and configuration ===================
complete_phpmyadmin_install_and_configuration() {
    local phpmyadmin_webserver

    info "Installing and configuring phpMyAdmin (single function)"

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
    else
        if ! is_package_available phpmyadmin; then
            fail "Package not available: phpmyadmin"
        else
            if [[ "${PKG_MANAGER}" == "apt" ]]; then
                phpmyadmin_webserver="none"
                if wants_apache; then
                    phpmyadmin_webserver="apache2"
                fi

                echo "phpmyadmin phpmyadmin/reconfigure-webserver multiselect ${phpmyadmin_webserver}" | debconf-set-selections
                echo "phpmyadmin phpmyadmin/dbconfig-install boolean false" | debconf-set-selections
                run env DEBIAN_FRONTEND=noninteractive apt install -y phpmyadmin
            else
                ensure_package phpmyadmin true
            fi
        fi
    fi

    inttool_deploy_phpmyadmin_suite
    configure_phpmyadmin_runtime
    ok "phpMyAdmin install and configuration completed."
}
# ======================== Complete phpMyAdmin installation and configuration ===================

install_mariadb_phpmyadmin() {
    complete_phpmyadmin_install_and_configuration
    install_roundcube_webmail
    install_dovecot_storage_stack
}

web_owner_group() {
    if id -u www-data >/dev/null 2>&1; then
        echo "www-data:www-data"
        return 0
    fi
    if id -u apache >/dev/null 2>&1; then
        echo "apache:apache"
        return 0
    fi
    if id -u nginx >/dev/null 2>&1; then
        echo "nginx:nginx"
        return 0
    fi
    echo "root:root"
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

    if [[ -n "${REMOTE_PANEL_ARCHIVE_URL}" ]]; then
        info "Project not found locally. Trying remote archive URL: ${REMOTE_PANEL_ARCHIVE_URL}"
        PROJECT_URL="${REMOTE_PANEL_ARCHIVE_URL}"
        download_project_from_url
        return
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
    pkg_update_cache
    if [[ "${PKG_MANAGER}" == "dnf" ]]; then
        ensure_package epel-release true
        pkg_update_cache
    fi
    reset_database_stack

    ensure_package ca-certificates
    ensure_package gnupg
    ensure_package lsb-release true
    ensure_package software-properties-common true
    ensure_package curl
    ensure_package wget
    ensure_package git
    ensure_package unzip
    ensure_package ufw true
    ensure_package openssh-server
    ensure_package redis-server true
    info "Skipping mandatory Composer/Node.js package install (not required)."
    if [[ "${PKG_MANAGER}" == "apt" ]]; then
        ensure_package debconf-utils
    fi
    ensure_package php-cli
    ensure_package php-fpm
    ensure_package php-mbstring
    ensure_package php-xml
    ensure_package php-curl
    ensure_package php-zip
    ensure_package php-mysql

    if [[ "${PKG_MANAGER}" == "apt" ]]; then
        ensure_ondrej_repo
        disable_mysql_apt_repos
        pkg_update_cache
    fi

    install_web_server
    install_php_versions
    apply_php_runtime_defaults
    install_mariadb_phpmyadmin
    ensure_default_php_binary
    ensure_default_php_extension "curl" true
    ensure_default_php_extension "xml" true
    enable_php_modules_for_version "${PHP_DEFAULT_VERSION}"
    info "Default PHP is ready. Starting PHP extension verification..."
    verify_php_extensions

    ok "Package verification/installation completed."
}

setup_ssh() {
    local ssh_service=""
    info "Step 2/5: Enabling SSH service"
    ssh_service="$(detect_ssh_service)"
    if [[ -n "${ssh_service}" ]]; then
        run systemctl enable --now "${ssh_service}"
    else
        warn "SSH service unit not found (ssh/sshd)."
    fi

    prepare_firewall_backend
    allow_firewall_service "ssh"
    allow_firewall_port "22" "tcp"
    allow_firewall_port "${PANEL_PORT}" "tcp"
    if [[ "${WEBTOOLS_SEPARATE_PORTS}" == "true" ]]; then
        allow_firewall_port "${PHPMYADMIN_PORT}" "tcp"
        allow_firewall_port "${ROUNDCUBE_PORT}" "tcp"
    fi
    ok "SSH enabled and firewall rules added."
}

setup_panel_startup_service() {
    local db_after redis_after
    info "Configuring ServerPanel startup service on port ${PANEL_PORT}"
    db_after="${DB_SERVICE:-mariadb}.service"
    redis_after=""
    if [[ -n "${REDIS_SERVICE}" ]]; then
        redis_after="${REDIS_SERVICE}.service"
    fi
    cat > /etc/systemd/system/serverpanel.service <<EOF
[Unit]
Description=ServerPanel Laravel HTTP Service
After=network.target ${db_after} ${redis_after}

[Service]
Type=simple
User=root
Group=root
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php artisan serve --host=0.0.0.0 --port=${PANEL_PORT}
Restart=always
RestartSec=5
Environment=APP_ENV=production
Environment=PHP_CLI_SERVER_WORKERS=${PHP_CLI_SERVER_WORKERS}

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

    sanitize_apache_legacy_panel_port_bindings
    info "Configuring Apache default site to proxy :80 -> :${PANEL_PORT}"
    cat > /etc/apache2/ports.conf <<EOF
Listen 80

<IfModule ssl_module>
    Listen 443
</IfModule>

<IfModule mod_gnutls.c>
    Listen 443
</IfModule>
EOF
    run a2enmod proxy proxy_http headers rewrite
    write_serverpanel_apache_proxy_site_conf
    disable_apache_site_if_enabled "000-default"
    run a2ensite serverpanel-proxy
    if ! apache_configtest_with_self_heal 2; then
        warn "Apache config test failed. Skipping Apache restart; panel stays available on port ${PANEL_PORT}."
        warn "Quick checks:"
        echo "  apache2ctl configtest"
        echo "  tail -n 80 /tmp/serverpanel-apache-configtest.log"
        return
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

    info "Configuring Nginx default site to proxy :${NGINX_PRIMARY_PORT} -> :${PANEL_PORT}"
    cat > /etc/nginx/sites-available/serverpanel-proxy <<EOF
server {
    listen ${NGINX_PRIMARY_PORT} default_server;
    listen [::]:${NGINX_PRIMARY_PORT} default_server;
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
        echo "  ss -ltnp | grep ':${NGINX_PRIMARY_PORT}'"
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
    upsert_env_value ".env" "APACHE_BACKEND_PORT" "${APACHE_BACKEND_PORT}"
    upsert_env_value ".env" "NGINX_PRIMARY_PORT" "${NGINX_PRIMARY_PORT}"
    sync_panel_webtools_env ".env"

    setup_mariadb_database

    info "Skipping Composer install and frontend build (not required)."
    cleanup_vite_hot_file "${PROJECT_DIR}"
    run php artisan key:generate --force

    run php artisan config:clear
    run php artisan migrate --force
    run php artisan db:seed --force
    run php artisan optimize
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

configure_apache_backend_for_dual_stack() {
    local ports_conf="/etc/apache2/ports.conf"
    local ports_backup="/etc/apache2/ports.conf.bak"
    local default_conf="/etc/apache2/sites-available/000-default.conf"
    local default_backup="/etc/apache2/sites-available/000-default.conf.bak"

    if [[ "${WEB_SERVER}" != "both" ]]; then
        return
    fi
    if ! systemctl cat apache2.service >/dev/null 2>&1; then
        warn "apache2.service not found. Cannot configure Apache backend listener."
        return
    fi

    sanitize_apache_legacy_panel_port_bindings
    info "Configuring Apache backend listener on :${APACHE_BACKEND_PORT} (Nginx frontend :${NGINX_PRIMARY_PORT})"
    run a2enmod proxy_fcgi setenvif rewrite headers || true

    if [[ -f "${ports_conf}" ]]; then
        run cp "${ports_conf}" "${ports_backup}" || true
    fi
    if [[ -f "${default_conf}" ]]; then
        run cp "${default_conf}" "${default_backup}" || true
    fi

    # Safe migration from :80 to backend Apache port.
    run sed -i "s/^Listen 80\$/Listen ${APACHE_BACKEND_PORT}/" "${ports_conf}" || true

    # If a broken value appears (example: 808080), reset to clean backend listen.
    if grep -q "${APACHE_BACKEND_PORT}${APACHE_BACKEND_PORT}" "${ports_conf}" 2>/dev/null; then
        echo "Listen ${APACHE_BACKEND_PORT}" > "${ports_conf}"
    fi

    if [[ -f "${default_conf}" ]]; then
        run sed -i "s/<VirtualHost \\*:80>/<VirtualHost *:${APACHE_BACKEND_PORT}>/" "${default_conf}" || true
    fi

    disable_apache_site_if_enabled "serverpanel-proxy"
    run a2ensite 000-default.conf || true

    if ! apache_configtest_with_self_heal 2; then
        warn "Apache config test failed after backend-listener setup."
        warn "Restoring Apache backup configs."
        if [[ -f "${ports_backup}" ]]; then
            cp "${ports_backup}" "${ports_conf}" || true
        fi
        if [[ -f "${default_backup}" ]]; then
            cp "${default_backup}" "${default_conf}" || true
        fi
        restart_apache_safely
        return
    fi

    restart_apache_safely
}

start_services() {
    local ssh_service=""
    info "Step 5/5: Starting web/database services"
    check_and_prepare_web_ports_before_service_ops
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
    ensure_redis_running
    ensure_dovecot_running
    ssh_service="$(detect_ssh_service)"
    if [[ -n "${ssh_service}" ]]; then
        run systemctl restart "${ssh_service}" || true
    else
        warn "SSH service unit not found (ssh/sshd); skipping restart."
    fi
    setup_panel_startup_service
    if [[ "${WEBTOOLS_SEPARATE_PORTS}" == "true" ]]; then
        cleanup_panel_embedded_webtools_links "${PROJECT_DIR}"
        setup_separate_webtools_services
    else
        disable_separate_webtools_services
        expose_panel_tools_on_port "${PROJECT_DIR}"
    fi
    # Apply runtime config after mode/link setup so helper paths and URLs match the
    # final selected exposure mode (panel path vs separate ports).
    configure_phpmyadmin_runtime
    configure_roundcube_runtime
    sync_panel_webtools_env "${PROJECT_DIR}/.env"
    run systemctl restart serverpanel

    if is_dual_stack_mode && [[ "${WEB_SERVER}" != "both" ]]; then
        info "Detected existing dual-stack routing (Nginx frontend -> Apache backend). Preserving mode."
        WEB_SERVER="both"
    fi

    if [[ "${WEB_SERVER}" == "both" ]]; then
        configure_apache_backend_for_dual_stack
        disable_panel_direct_ip_proxy "false" "true"
        ok "Both selected: Nginx frontend :${NGINX_PRIMARY_PORT}, Apache backend :${APACHE_BACKEND_PORT}. Panel kept on :${PANEL_PORT}."
    elif [[ "${WEB_SERVER}" == "apache" ]]; then
        disable_panel_direct_ip_proxy "true" "false"
    elif [[ "${WEB_SERVER}" == "nginx" ]]; then
        disable_panel_direct_ip_proxy "false" "true"
    fi
    write_install_credentials_log
    ok "Services are running."
}

is_dual_stack_mode() {
    if [[ "${WEB_SERVER}" == "both" ]]; then
        return 0
    fi

    if [[ -f /etc/apache2/ports.conf ]] && grep -qE "^[[:space:]]*Listen[[:space:]]+${APACHE_BACKEND_PORT}([[:space:]]*)$" /etc/apache2/ports.conf; then
        if systemctl cat nginx.service >/dev/null 2>&1; then
            return 0
        fi
    fi

    return 1
}

check_and_prepare_web_ports_before_service_ops() {
    local ports_conf="/etc/apache2/ports.conf"
    local ports_backup="/etc/apache2/ports.conf.precheck.bak"
    local default_conf="/etc/apache2/sites-available/000-default.conf"
    local default_backup="/etc/apache2/sites-available/000-default.conf.precheck.bak"

    if ! is_dual_stack_mode; then
        return 0
    fi

    info "Checking Apache/Nginx port mapping before service operations"

    if [[ -f "${ports_conf}" ]]; then
        cp "${ports_conf}" "${ports_backup}" 2>/dev/null || true

        if ! grep -qE "^[[:space:]]*Listen[[:space:]]+${APACHE_BACKEND_PORT}([[:space:]]*)$" "${ports_conf}"; then
            echo "Listen ${APACHE_BACKEND_PORT}" >> "${ports_conf}"
        fi

        sed -i -E 's/^[[:space:]]*Listen[[:space:]]+80([[:space:]]*)$/# Listen 80/g' "${ports_conf}" || true
        sed -i -E 's/^[[:space:]]*Listen[[:space:]]+443([[:space:]]*)$/# Listen 443/g' "${ports_conf}" || true

        if grep -q "${APACHE_BACKEND_PORT}${APACHE_BACKEND_PORT}" "${ports_conf}" 2>/dev/null; then
            echo "Listen ${APACHE_BACKEND_PORT}" > "${ports_conf}"
        fi
    fi

    if [[ -f "${default_conf}" ]]; then
        cp "${default_conf}" "${default_backup}" 2>/dev/null || true
        sed -i "s/<VirtualHost \\*:80>/<VirtualHost *:${APACHE_BACKEND_PORT}>/" "${default_conf}" || true
    fi

    if command -v apache2ctl >/dev/null 2>&1; then
        if ! apache2ctl configtest >/tmp/serverpanel-apache-precheck.log 2>&1; then
            warn "Apache precheck configtest failed. Restoring Apache backups."
            [[ -f "${ports_backup}" ]] && cp "${ports_backup}" "${ports_conf}" || true
            [[ -f "${default_backup}" ]] && cp "${default_backup}" "${default_conf}" || true
        fi
    fi

    if command -v nginx >/dev/null 2>&1; then
        if ! nginx -t >/tmp/serverpanel-nginx-precheck.log 2>&1; then
            warn "Nginx precheck failed. Check: tail -n 80 /tmp/serverpanel-nginx-precheck.log"
        fi
    fi
}

show_summary() {
    local server_ip
    local phpmyadmin_url roundcube_url
    server_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    phpmyadmin_url="$(phpmyadmin_access_url "${server_ip:-server_ip}")"
    roundcube_url="$(roundcube_access_url "${server_ip:-server_ip}")"
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
    echo -e "Redis Service: ${REDIS_SERVICE:-not-running-or-not-installed}"
    echo -e "Panel Port   : ${PANEL_PORT}"
    if is_webtools_separate_mode_active; then
        echo -e "Tools Mode   : separate ports/services"
        echo -e "PMA Root     : ${PHPMYADMIN_ROOT}"
        echo -e "RC Root      : ${ROUNDCUBE_ROOT}"
        echo -e "PMA Port     : ${PHPMYADMIN_PORT}"
        echo -e "RC Port      : ${ROUNDCUBE_PORT}"
    else
        echo -e "Tools Mode   : via panel port paths"
    fi
    echo -e "Nginx Port   : ${NGINX_PRIMARY_PORT}"
    echo -e "Apache Port  : ${APACHE_BACKEND_PORT}"
    echo -e "Panel URL    : http://${server_ip:-server_ip}:${PANEL_PORT}"
    echo -e "phpMyAdmin   : ${phpmyadmin_url}"
    echo -e "Roundcube    : ${roundcube_url}"
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

ask_secret_input() {
    local prompt="$1"
    local value=""
    read -r -s -p "${prompt}: " value
    echo
    echo "${value}"
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
    local svc state ip port redis_svc
    echo
    echo -e "${CYAN}================ Service Dashboard ================${NC}"
    for svc in serverpanel apache2 nginx mariadb ssh; do
        if systemctl cat "${svc}.service" >/dev/null 2>&1; then
            state="$(systemctl is-active "${svc}" 2>/dev/null || true)"
            printf "%-12s : %s\n" "${svc}" "${state:-unknown}"
        fi
    done
    redis_svc="${REDIS_SERVICE:-$(detect_redis_service)}"
    if [[ -n "${redis_svc}" ]] && systemctl cat "${redis_svc}.service" >/dev/null 2>&1; then
        state="$(systemctl is-active "${redis_svc}" 2>/dev/null || true)"
        printf "%-12s : %s\n" "${redis_svc}" "${state:-unknown}"
    fi
    for svc in "${PHPMYADMIN_SERVICE}" "${ROUNDCUBE_SERVICE}"; do
        if systemctl cat "${svc}.service" >/dev/null 2>&1; then
            state="$(systemctl is-active "${svc}" 2>/dev/null || true)"
            printf "%-20s : %s\n" "${svc}" "${state:-unknown}"
        fi
    done
    ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    port="$(panel_port_from_service_file)"
    echo "Panel URL    : http://${ip:-127.0.0.1}:${port}"
    echo "phpMyAdmin   : $(phpmyadmin_access_url "${ip:-127.0.0.1}")"
    echo "Roundcube    : $(roundcube_access_url "${ip:-127.0.0.1}")"
}

manage_single_service() {
    local svc action
    svc="$(ask_input "Service name (serverpanel/apache2/nginx/mariadb/redis-server/ssh/${PHPMYADMIN_SERVICE}/${ROUNDCUBE_SERVICE})" "serverpanel")"
    action="$(ask_input "Action (status/start/stop/restart/enable/disable/logs)" "status")"

    case "${action}" in
        logs)
            run journalctl -u "${svc}" -n 80 --no-pager
            ;;
        status|start|stop|restart|enable|disable)
            if [[ "${svc}" == "apache2" || "${svc}" == "nginx" ]]; then
                check_and_prepare_web_ports_before_service_ops
            fi
            run systemctl "${action}" "${svc}"
            ;;
        *)
            warn "Invalid action: ${action}"
            ;;
    esac
}

repair_apache_proxy_menu() {
    local port proxy_server nginx_port apache_port
    port="$(ask_input "Panel port for web proxy" "$(panel_port_from_service_file)")"
    if [[ ! "${port}" =~ ^[0-9]+$ ]] || (( port < 1 || port > 65535 )); then
        warn "Invalid port: ${port}"
        return
    fi
    if is_dual_stack_mode; then
        proxy_server="$(ask_input "Proxy server (apache/nginx/both)" "both")"
    else
        proxy_server="$(ask_input "Proxy server (apache/nginx/both)" "apache")"
    fi
    PANEL_PORT="${port}"
    case "${proxy_server}" in
        both)
            nginx_port="$(ask_input "Nginx frontend port" "${NGINX_PRIMARY_PORT}")"
            if [[ "${nginx_port}" =~ ^[0-9]+$ ]] && (( nginx_port >= 1 && nginx_port <= 65535 )); then
                NGINX_PRIMARY_PORT="${nginx_port}"
            fi
            apache_port="$(ask_input "Apache backend port" "${APACHE_BACKEND_PORT}")"
            if [[ "${apache_port}" =~ ^[0-9]+$ ]] && (( apache_port >= 1 && apache_port <= 65535 )); then
                APACHE_BACKEND_PORT="${apache_port}"
            fi
            if [[ "${NGINX_PRIMARY_PORT}" == "${PANEL_PORT}" ]]; then
                warn "Nginx frontend port cannot match panel port ${PANEL_PORT}."
                return
            fi
            if [[ "${APACHE_BACKEND_PORT}" == "${PANEL_PORT}" ]]; then
                warn "Apache backend port cannot match panel port ${PANEL_PORT}."
                return
            fi
            if [[ "${APACHE_BACKEND_PORT}" == "${NGINX_PRIMARY_PORT}" ]]; then
                warn "Apache backend and Nginx frontend ports must be different."
                return
            fi
            WEB_SERVER="both"
            configure_apache_backend_for_dual_stack
            setup_nginx_proxy_default_site
            ;;
        nginx)
            nginx_port="$(ask_input "Nginx frontend port" "${NGINX_PRIMARY_PORT}")"
            if [[ "${nginx_port}" =~ ^[0-9]+$ ]] && (( nginx_port >= 1 && nginx_port <= 65535 )); then
                NGINX_PRIMARY_PORT="${nginx_port}"
            fi
            if [[ "${NGINX_PRIMARY_PORT}" == "${PANEL_PORT}" ]]; then
                warn "Nginx frontend port cannot match panel port ${PANEL_PORT}."
                return
            fi
            WEB_SERVER="nginx"
            setup_nginx_proxy_default_site
            ;;
        apache|*)
            WEB_SERVER="apache"
            setup_apache_proxy_default_site
            ;;
    esac
}

ensure_nginx_default_site() {
    if [[ -f /etc/nginx/sites-available/default ]]; then
        return 0
    fi

    cat > /etc/nginx/sites-available/default <<EOF
server {
    listen ${NGINX_PRIMARY_PORT} default_server;
    listen [::]:${NGINX_PRIMARY_PORT} default_server;
    server_name _;

    root /var/www/html;
    index index.php index.html;

    location / {
        try_files \$uri \$uri/ =404;
    }
}
EOF
}

disable_panel_direct_ip_proxy() {
    local apply_apache="${1:-true}"
    local apply_nginx="${2:-true}"

    if [[ "${apply_apache}" == "true" ]] && systemctl cat apache2.service >/dev/null 2>&1; then
        info "Disabling Apache panel proxy mapping on :80"
        disable_apache_site_if_enabled "serverpanel-proxy"
        if [[ -f /etc/apache2/sites-available/000-default.conf ]]; then
            run a2ensite 000-default || true
        fi
        restart_apache_safely
    fi

    if [[ "${apply_nginx}" == "true" ]] && systemctl cat nginx.service >/dev/null 2>&1; then
        info "Disabling Nginx panel proxy mapping on :${NGINX_PRIMARY_PORT}"
        run rm -f /etc/nginx/sites-enabled/serverpanel-proxy || true
        ensure_nginx_default_site
        run ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
        restart_nginx_safely
    fi

    ok "Direct IP route no longer points to panel proxy. Use http://<server-ip>:${PANEL_PORT} for panel."
}

disable_panel_direct_ip_proxy_menu() {
    local target
    target="$(ask_input "Disable panel proxy for (apache/nginx/both/auto)" "auto")"

    case "${target,,}" in
        apache)
            disable_panel_direct_ip_proxy "true" "false"
            ;;
        nginx)
            disable_panel_direct_ip_proxy "false" "true"
            ;;
        both)
            disable_panel_direct_ip_proxy "true" "true"
            ;;
        auto|*)
            disable_panel_direct_ip_proxy "true" "true"
            ;;
    esac
}

show_port_diagnostics() {
    local extra_ports=""
    if is_webtools_separate_mode_active; then
        extra_ports="|:${PHPMYADMIN_PORT} |:${ROUNDCUBE_PORT} "
    fi
    run bash -lc "ss -ltnp | egrep ':22 |:${NGINX_PRIMARY_PORT} |:${APACHE_BACKEND_PORT} |:${PANEL_PORT} ${extra_ports}|:443 |:3306 ' || true"
}

restart_apache_safely() {
    local apache_check_port="80"
    local apache_test_status=0
    if ! systemctl cat apache2.service >/dev/null 2>&1; then
        return 0
    fi
    sanitize_apache_legacy_panel_port_bindings
    if [[ "${WEB_SERVER}" == "both" ]]; then
        apache_check_port="${APACHE_BACKEND_PORT}"
    fi

    set +e
    apache_configtest_with_self_heal 2
    apache_test_status=$?
    set -e
    if [[ "${apache_test_status}" -ne 0 ]]; then
        warn "Apache config test failed. Skipping Apache restart."
        warn "Quick checks:"
        echo "  apache2ctl configtest"
        echo "  tail -n 80 /tmp/serverpanel-apache-configtest.log"
        echo "  journalctl -u apache2 -n 80 --no-pager"
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
        echo "  ss -ltnp | grep ':${apache_check_port}'"
    fi
}

restart_nginx_safely() {
    local nginx_test_status=0
    if ! systemctl cat nginx.service >/dev/null 2>&1; then
        return 0
    fi

    if command -v nginx >/dev/null 2>&1; then
        set +e
        nginx -t >/tmp/serverpanel-nginx-configtest.log 2>&1
        nginx_test_status=$?
        set -e
        if [[ "${nginx_test_status}" -ne 0 ]]; then
            warn "Nginx config test failed. Skipping Nginx restart."
            warn "Quick checks:"
            echo "  nginx -t"
            echo "  tail -n 80 /tmp/serverpanel-nginx-configtest.log"
            echo "  journalctl -u nginx -n 80 --no-pager"
            return 0
        fi
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

is_valid_db_identifier() {
    local value="$1"
    [[ "${value}" =~ ^[A-Za-z0-9_]{1,64}$ ]]
}

reset_panel_login_password_menu() {
    local panel_dir env_file email new_password hash
    local db_cli db_name db_name_sql users_table_exists sql_email sql_hash user_count

    panel_dir="$(ask_input "Panel project path" "$(default_panel_project_dir)")"
    env_file="${panel_dir}/.env"
    if [[ ! -f "${panel_dir}/artisan" || ! -f "${panel_dir}/composer.json" || ! -f "${env_file}" ]]; then
        warn "Invalid panel path or missing .env: ${panel_dir}"
        return
    fi

    db_name="$(read_env_value "${env_file}" "DB_DATABASE")"
    if ! is_valid_db_identifier "${db_name}"; then
        warn "Invalid DB_DATABASE in ${env_file}: ${db_name}"
        return
    fi

    email="$(ask_input "Panel login email to reset" "test@example.com")"
    if [[ -z "${email}" ]]; then
        warn "Email is required."
        return
    fi

    new_password="$(ask_secret_input "New panel password (leave blank = auto-generate)")"
    if [[ -z "${new_password}" ]]; then
        new_password="$(generate_random_password)"
        info "Generated random panel password."
    fi

    hash="$(NEW_PANEL_PASSWORD="${new_password}" php -r 'echo password_hash((string) getenv("NEW_PANEL_PASSWORD"), PASSWORD_BCRYPT);' 2>/dev/null || true)"
    if [[ -z "${hash}" ]]; then
        fail "Failed to generate password hash with PHP."
    fi

    ensure_database_running
    db_cli="$(detect_db_cli)"
    if [[ -z "${db_cli}" ]]; then
        fail "No database CLI found (mysql/mariadb)."
    fi

    db_name_sql="$(escape_sql_string "${db_name}")"
    users_table_exists="$("${db_cli}" -N -s -e "SELECT 1 FROM information_schema.tables WHERE table_schema='${db_name_sql}' AND table_name='users' LIMIT 1;" 2>/dev/null || true)"
    if [[ "${users_table_exists}" != "1" ]]; then
        warn "users table not found in database: ${db_name}"
        return
    fi

    sql_email="$(escape_sql_string "${email}")"
    sql_hash="$(escape_sql_string "${hash}")"
    user_count="$("${db_cli}" -N -s "${db_name}" -e "SELECT COUNT(*) FROM users WHERE email='${sql_email}' LIMIT 1;" 2>/dev/null || true)"
    if [[ "${user_count}" != "1" ]]; then
        warn "No user found with email: ${email}"
        return
    fi

    run "${db_cli}" "${db_name}" -e "UPDATE users SET password='${sql_hash}', updated_at=NOW() WHERE email='${sql_email}';"

    if [[ -f "${panel_dir}/artisan" ]]; then
        (
            cd "${panel_dir}"
            run php artisan optimize:clear || true
            run php artisan config:clear || true
        )
    fi
    run systemctl restart serverpanel || true

    ok "Panel login password updated."
    echo "Email    : ${email}"
    echo "Password : ${new_password}"
}

reset_panel_db_password_menu() {
    local panel_dir env_file db_name db_user old_db_password
    local pdns_db_user pdns_db_password new_password db_cli
    local sql_db sql_user sql_password

    panel_dir="$(ask_input "Panel project path" "$(default_panel_project_dir)")"
    env_file="${panel_dir}/.env"
    if [[ ! -f "${panel_dir}/artisan" || ! -f "${panel_dir}/composer.json" || ! -f "${env_file}" ]]; then
        warn "Invalid panel path or missing .env: ${panel_dir}"
        return
    fi

    db_name="$(read_env_value "${env_file}" "DB_DATABASE")"
    db_user="$(read_env_value "${env_file}" "DB_USERNAME")"
    old_db_password="$(read_env_value "${env_file}" "DB_PASSWORD")"
    pdns_db_user="$(read_env_value "${env_file}" "PDNS_DB_USERNAME")"
    pdns_db_password="$(read_env_value "${env_file}" "PDNS_DB_PASSWORD")"

    if ! is_valid_db_identifier "${db_name}"; then
        warn "Invalid DB_DATABASE in ${env_file}: ${db_name}"
        return
    fi
    if ! is_valid_db_identifier "${db_user}"; then
        warn "Invalid DB_USERNAME in ${env_file}: ${db_user}"
        return
    fi

    new_password="$(ask_secret_input "New DB password for ${db_user} (leave blank = auto-generate)")"
    if [[ -z "${new_password}" ]]; then
        new_password="$(generate_random_password)"
        info "Generated random DB password."
    fi

    ensure_database_running
    db_cli="$(detect_db_cli)"
    if [[ -z "${db_cli}" ]]; then
        fail "No database CLI found (mysql/mariadb)."
    fi

    sql_db="$(escape_sql_string "${db_name}")"
    sql_user="$(escape_sql_string "${db_user}")"
    sql_password="$(escape_sql_string "${new_password}")"

    run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_user}'@'127.0.0.1' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_user}'@'localhost' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "ALTER USER '${sql_user}'@'127.0.0.1' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "ALTER USER '${sql_user}'@'localhost' IDENTIFIED BY '${sql_password}';"
    run "${db_cli}" -e "GRANT ALL PRIVILEGES ON \`${sql_db}\`.* TO '${sql_user}'@'127.0.0.1';"
    run "${db_cli}" -e "GRANT ALL PRIVILEGES ON \`${sql_db}\`.* TO '${sql_user}'@'localhost';"
    run "${db_cli}" -e "FLUSH PRIVILEGES;"

    upsert_env_value "${env_file}" "DB_PASSWORD" "${new_password}"
    if [[ "${pdns_db_user}" == "${db_user}" || "${pdns_db_password}" == "${old_db_password}" ]]; then
        upsert_env_value "${env_file}" "PDNS_DB_PASSWORD" "${new_password}"
    fi

    DB_NAME="${db_name}"
    DB_USER="${db_user}"
    DB_PASSWORD="${new_password}"

    (
        cd "${panel_dir}"
        run php artisan optimize:clear || true
        run php artisan config:clear || true
    )
    run systemctl restart serverpanel || true

    ok "Panel database password updated."
    echo "DB Name   : ${db_name}"
    echo "DB User   : ${db_user}"
    echo "Password  : ${new_password}"
}

reset_phpmyadmin_admin_password_menu() {
    local admin_creds_file="/etc/phpmyadmin/serverpanel-admin-user.conf"
    local admin_user admin_password db_cli sql_admin_user sql_admin_password

    admin_user="${PHPMYADMIN_ADMIN_USER:-dbadmin}"
    if [[ -f "${admin_creds_file}" ]]; then
        admin_user="$(grep -E '^DB_USER=' "${admin_creds_file}" | head -n1 | cut -d= -f2- || true)"
    fi
    if ! is_valid_db_identifier "${admin_user}"; then
        admin_user="dbadmin"
    fi

    admin_user="$(ask_input "phpMyAdmin admin DB user" "${admin_user}")"
    if ! is_valid_db_identifier "${admin_user}"; then
        warn "Invalid DB user. Use letters, numbers, underscore only (max 64)."
        return
    fi

    admin_password="$(ask_secret_input "New password for ${admin_user} (leave blank = auto-generate)")"
    if [[ -z "${admin_password}" ]]; then
        admin_password="$(generate_random_password)"
        info "Generated random phpMyAdmin admin password."
    fi

    ensure_database_running
    db_cli="$(detect_db_cli)"
    if [[ -z "${db_cli}" ]]; then
        fail "No database CLI found (mysql/mariadb)."
    fi

    sql_admin_user="$(escape_sql_string "${admin_user}")"
    sql_admin_password="$(escape_sql_string "${admin_password}")"

    run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_admin_user}'@'127.0.0.1' IDENTIFIED BY '${sql_admin_password}';"
    run "${db_cli}" -e "CREATE USER IF NOT EXISTS '${sql_admin_user}'@'localhost' IDENTIFIED BY '${sql_admin_password}';"
    run "${db_cli}" -e "ALTER USER '${sql_admin_user}'@'127.0.0.1' IDENTIFIED BY '${sql_admin_password}';"
    run "${db_cli}" -e "ALTER USER '${sql_admin_user}'@'localhost' IDENTIFIED BY '${sql_admin_password}';"
    run "${db_cli}" -e "GRANT ALL PRIVILEGES ON *.* TO '${sql_admin_user}'@'127.0.0.1' WITH GRANT OPTION;"
    run "${db_cli}" -e "GRANT ALL PRIVILEGES ON *.* TO '${sql_admin_user}'@'localhost' WITH GRANT OPTION;"
    run "${db_cli}" -e "FLUSH PRIVILEGES;"

    run mkdir -p /etc/phpmyadmin
    cat > "${admin_creds_file}" <<EOF
DB_USER=${admin_user}
DB_PASSWORD=${admin_password}
EOF
    run chmod 600 "${admin_creds_file}"

    PHPMYADMIN_ADMIN_USER="${admin_user}"
    PHPMYADMIN_ADMIN_PASSWORD="${admin_password}"

    ok "phpMyAdmin admin DB user password updated."
    echo "DB User   : ${admin_user}"
    echo "Password  : ${admin_password}"
}

reset_roundcube_db_password_menu() {
    local creds_file="/etc/roundcube/serverpanel-db.conf"
    local roundcube_db roundcube_user roundcube_password

    roundcube_db="${ROUNDCUBE_DB_NAME:-roundcube}"
    roundcube_user="${ROUNDCUBE_DB_USER:-roundcube}"
    if [[ -f "${creds_file}" ]]; then
        roundcube_db="$(grep -E '^DB_NAME=' "${creds_file}" | head -n1 | cut -d= -f2- || true)"
        roundcube_user="$(grep -E '^DB_USER=' "${creds_file}" | head -n1 | cut -d= -f2- || true)"
    fi
    if ! is_valid_db_identifier "${roundcube_db}"; then
        roundcube_db="roundcube"
    fi
    if ! is_valid_db_identifier "${roundcube_user}"; then
        roundcube_user="roundcube"
    fi

    roundcube_db="$(ask_input "Roundcube DB name" "${roundcube_db}")"
    roundcube_user="$(ask_input "Roundcube DB user" "${roundcube_user}")"
    if ! is_valid_db_identifier "${roundcube_db}" || ! is_valid_db_identifier "${roundcube_user}"; then
        warn "Invalid Roundcube DB name/user."
        return
    fi

    roundcube_password="$(ask_secret_input "New Roundcube DB password for ${roundcube_user} (leave blank = auto-generate)")"
    if [[ -z "${roundcube_password}" ]]; then
        roundcube_password="$(generate_random_password)"
        info "Generated random Roundcube DB password."
    fi

    ROUNDCUBE_DB_NAME="${roundcube_db}"
    ROUNDCUBE_DB_USER="${roundcube_user}"
    ROUNDCUBE_DB_PASSWORD="${roundcube_password}"

    configure_roundcube_runtime

    ok "Roundcube DB password updated."
    echo "DB Name   : ${roundcube_db}"
    echo "DB User   : ${roundcube_user}"
    echo "Password  : ${roundcube_password}"
}

password_manager_menu() {
    local choice

    while true; do
        echo
        echo -e "${CYAN}================ Password Manager ==================${NC}"
        echo "1) Reset panel login password (by email)"
        echo "2) Reset panel DB user password (.env DB_PASSWORD)"
        echo "3) Reset phpMyAdmin admin DB user password (dbadmin)"
        echo "4) Reset Roundcube DB user password"
        echo "0) Back"
        choice="$(ask_input "Select option" "1")"

        case "${choice}" in
            1)
                reset_panel_login_password_menu
                ;;
            2)
                reset_panel_db_password_menu
                ;;
            3)
                reset_phpmyadmin_admin_password_menu
                ;;
            4)
                reset_roundcube_db_password_menu
                ;;
            0)
                return 0
                ;;
            *)
                warn "Invalid option. Choose 0-4."
                ;;
        esac
    done
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

    if ask_yes_no "Copy ${target_dir}/extra/serverroot.html to /var/www/html/index.html?" "Y"; then
        copy_server_root_file_menu "${target_dir}"
    fi

    cd "${target_dir}"
    upsert_env_value ".env" "APP_ENV" "production"
    upsert_env_value ".env" "APP_DEBUG" "false"
    run php artisan optimize:clear || true
    cleanup_vite_hot_file "${target_dir}"

    if ask_yes_no "Run composer install (production flags)?" "Y"; then
        ensure_default_php_binary
        ensure_default_php_extension "curl" true
        ensure_default_php_extension "xml" true
        enable_php_modules_for_version "${PHP_DEFAULT_VERSION}"
        verify_php_extensions
        install_composer_dependencies
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
    configure_phpmyadmin_runtime
    configure_roundcube_runtime
    if is_webtools_separate_mode_active; then
        WEBTOOLS_SEPARATE_PORTS="true"
        cleanup_panel_embedded_webtools_links "${target_dir}"
        setup_separate_webtools_services
    else
        disable_separate_webtools_services
        expose_panel_tools_on_port "${target_dir}"
    fi
    sync_panel_webtools_env "${target_dir}/.env"
    run systemctl restart serverpanel || true

    check_and_prepare_web_ports_before_service_ops
    if is_dual_stack_mode; then
        WEB_SERVER="both"
        restart_apache_safely
        restart_nginx_safely
    elif systemctl cat nginx.service >/dev/null 2>&1 && (systemctl is-active --quiet nginx || ! systemctl is-active --quiet apache2); then
        WEB_SERVER="nginx"
        restart_nginx_safely
    else
        WEB_SERVER="apache"
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
        warn "Web proxy mode is enabled (:${NGINX_PRIMARY_PORT} -> panel port), so /var/www/html index file may not be visible at root URL."
        warn "Use menu option 5 (Repair web proxy) to switch behavior, or disable proxy site manually if you want static root index."
    fi
}

repair_panel_web_tools_menu() {
    local panel_dir host_ip mode_default pma_port rc_port
    panel_dir="$(ask_input "Panel project path" "$(default_panel_project_dir)")"

    if [[ ! -d "${panel_dir}/public" ]]; then
        warn "Invalid panel path: ${panel_dir} (public directory not found)"
        return
    fi

    if [[ -f "${panel_dir}/artisan" && -f "${panel_dir}/composer.json" ]]; then
        PROJECT_DIR="${panel_dir}"
    fi

    mode_default="N"
    if is_webtools_separate_mode_active; then
        mode_default="Y"
    fi
    if ask_yes_no "Use separate ports/services for phpMyAdmin + Roundcube?" "${mode_default}"; then
        WEBTOOLS_SEPARATE_PORTS="true"
        pma_port="$(ask_input "phpMyAdmin port" "${PHPMYADMIN_PORT}")"
        rc_port="$(ask_input "Roundcube port" "${ROUNDCUBE_PORT}")"
        [[ -n "${pma_port}" ]] && PHPMYADMIN_PORT="${pma_port}"
        [[ -n "${rc_port}" ]] && ROUNDCUBE_PORT="${rc_port}"
        if [[ ! "${PHPMYADMIN_PORT}" =~ ^[0-9]+$ || ! "${ROUNDCUBE_PORT}" =~ ^[0-9]+$ ]]; then
            warn "Invalid webtool port values."
            return
        fi
        if (( PHPMYADMIN_PORT < 1 || PHPMYADMIN_PORT > 65535 || ROUNDCUBE_PORT < 1 || ROUNDCUBE_PORT > 65535 )); then
            warn "Webtool ports must be between 1 and 65535."
            return
        fi
        if [[ "${PHPMYADMIN_PORT}" == "${ROUNDCUBE_PORT}" || "${PHPMYADMIN_PORT}" == "${PANEL_PORT}" || "${ROUNDCUBE_PORT}" == "${PANEL_PORT}" ]]; then
            warn "Webtool ports must be unique and different from panel port ${PANEL_PORT}."
            return
        fi
    else
        WEBTOOLS_SEPARATE_PORTS="false"
    fi

    info "Repairing phpMyAdmin + Roundcube install and runtime config"
    install_mariadb_phpmyadmin
    configure_phpmyadmin_runtime
    configure_roundcube_runtime
    if is_webtools_separate_mode_active; then
        WEBTOOLS_SEPARATE_PORTS="true"
        cleanup_panel_embedded_webtools_links "${panel_dir}"
        setup_separate_webtools_services
    else
        disable_separate_webtools_services
        expose_panel_tools_on_port "${panel_dir}"
    fi
    sync_panel_webtools_env "${panel_dir}/.env"
    run systemctl restart serverpanel || true
    if systemctl cat apache2.service >/dev/null 2>&1; then
        restart_apache_safely
    fi
    if systemctl cat nginx.service >/dev/null 2>&1; then
        restart_nginx_safely
    fi
    host_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    ok "Repair completed. Test URLs:"
    echo "  $(phpmyadmin_access_url "${host_ip:-127.0.0.1}")"
    echo "  $(roundcube_access_url "${host_ip:-127.0.0.1}")"
}

show_control_menu() {
    echo
    echo -e "${CYAN}============================================================${NC}"
    echo -e "${CYAN}               Int Tool (Linux Server)                     ${NC}"
    echo -e "${CYAN}============================================================${NC}"
    echo "1) Install ServerPanel (default auto)"
    if is_webtools_separate_mode_active; then
        echo "2) Repair phpMyAdmin + Roundcube (separate: ${PHPMYADMIN_PORT}/${ROUNDCUBE_PORT})"
    else
        echo "2) Repair phpMyAdmin + Roundcube (via panel ${PANEL_PORT})"
    fi
    echo "3) Show credentials file"
    echo "4) Password manager (SSH reset: panel/dbadmin/db users)"
    echo "5) Disable direct-IP panel proxy (keep panel on :${PANEL_PORT})"
    echo "0) Exit"
}

run_control_center() {
    local choice
    sync_webtools_mode_from_installed_services
    while true; do
        sync_webtools_mode_from_installed_services
        show_control_menu
        choice="$(ask_input "Select option" "1")"
        case "${choice}" in
            1)
                if ask_yes_no "Start default install from remote archive now?" "Y"; then
                    if [[ -n "${REMOTE_PANEL_ARCHIVE_URL}" ]]; then
                        installer_main --project-url "${REMOTE_PANEL_ARCHIVE_URL}"
                    else
                        installer_main
                    fi
                fi
                ;;
            2)
                repair_panel_web_tools_menu
                ;;
            3)
                show_credentials_file
                ;;
            4)
                password_manager_menu
                ;;
            5)
                disable_panel_direct_ip_proxy_menu
                ;;
            0)
                info "Bye."
                return 0
                ;;
            *)
                warn "Invalid option. Choose 0-5."
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
        if [[ -n "${REMOTE_PANEL_ARCHIVE_URL}" ]]; then
            installer_main --project-url "${REMOTE_PANEL_ARCHIVE_URL}"
        else
            installer_main
        fi
    fi
}

entrypoint "$@"
