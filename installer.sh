#!/bin/bash
set -euo pipefail
IFS=$'"'"'\n\t'"'"'

# ------------------------------------------------------------
# Global configuration
# ------------------------------------------------------------
LOG_FILE="/var/log/serverpanel-installer.log"
MIN_MEMORY_KB=$((2 * 1024 * 1024))
SWAP_FILE="/swapfile"
SWAP_SIZE="2G"

WEB_SERVER_CHOICE=""
DB_ENGINE=""
DB_SERVICE=""
DB_ROOT_PASSWORD=""
DB_APP_NAME=""
DB_APP_USER=""
DB_APP_PASSWORD=""
OS_ID=""
OS_CODENAME=""

declare -a MYSQL_CMD=()
declare -a INSTALLED_COMPONENTS=()
declare -a INSTALLED_PHP_VERSIONS=()
declare -a CREATED_DOMAINS=()
declare -a LARAVEL_SITES=()

# ------------------------------------------------------------
# Utility helpers
# ------------------------------------------------------------
log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*"
}

add_component() {
    local component="$1"
    INSTALLED_COMPONENTS+=("$component")
}

command_exists() {
    command -v "$1" >/dev/null 2>&1
}

clear_directory_contents() {
    local target_dir="$1"
    find "$target_dir" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
}

escape_sql_string() {
    printf "%s" "$1" | sed "s/'/''/g"
}

update_env_key() {
    local env_file="$1"
    local key="$2"
    local value="$3"
    local escaped_value

    escaped_value="$(printf "%s" "$value" | sed 's/[\/&]/\\&/g')"
    if grep -q "^${key}=" "$env_file"; then
        sed -i "s/^${key}=.*/${key}=${escaped_value}/" "$env_file"
    else
        echo "${key}=${value}" >> "$env_file"
    fi
}

get_php_binary_for_version() {
    local version="$1"
    local php_bin="/usr/bin/php${version}"
    if [[ -x "$php_bin" ]]; then
        echo "$php_bin"
    else
        echo "/usr/bin/php"
    fi
}

require_root() {
    if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
        echo "This script must be run as root. Example: sudo bash installer.sh"
        exit 1
    fi
}

setup_logging() {
    touch "$LOG_FILE"
    chmod 600 "$LOG_FILE"
    exec > >(tee -a "$LOG_FILE") 2>&1
    log "Logging initialized: $LOG_FILE"
}

run_with_retry() {
    local description="$1"
    shift

    while true; do
        set +e
        "$@"
        local status=$?
        set -e

        if [[ $status -eq 0 ]]; then
            return 0
        fi

        log "Command failed ($status): $description"
        echo "Action for failed command: $description"
        read -r -p "[R]etry / [S]kip / [A]bort: " action

        case "${action,,}" in
            r|retry)
                ;;
            s|skip)
                log "Skipped: $description"
                return 0
                ;;
            a|abort|"")
                log "Aborted by user during: $description"
                exit 1
                ;;
            *)
                echo "Invalid choice. Enter R, S, or A."
                ;;
        esac
    done
}

validate_service_active() {
    local service="$1"
    if systemctl is-active --quiet "$service"; then
        log "Service active: $service"
    else
        log "Service is not active: $service"
        return 1
    fi
}

# ------------------------------------------------------------
# Security cleanup
# ------------------------------------------------------------
remove_sensitive_files() {
    log "Scanning for sensitive files before prompts..."
    local -a scan_paths=("$PWD" "/root" "/tmp" "/var/tmp")
    local removed=0

    for base in "${scan_paths[@]}"; do
        [[ -d "$base" ]] || continue
        while IFS= read -r file; do
            rm -f "$file" || true
            log "Removed sensitive file: $file"
            removed=$((removed + 1))
        done < <(find "$base" -maxdepth 5 -type f \( -name "*.sql" -o -name ".env" -o -name "*credential*" -o -name "*credentials*" \) 2>/dev/null || true)
    done

    log "Sensitive cleanup complete. Files removed: $removed"
}

# ------------------------------------------------------------
# OS and package preparation
# ------------------------------------------------------------
detect_os() {
    if [[ ! -f /etc/os-release ]]; then
        log "Unsupported OS: /etc/os-release missing"
        exit 1
    fi

    # shellcheck disable=SC1091
    source /etc/os-release
    OS_ID="$ID"
    OS_CODENAME="${VERSION_CODENAME:-}"

    if [[ -z "$OS_CODENAME" ]] && command_exists lsb_release; then
        OS_CODENAME="$(lsb_release -sc)"
    fi

    case "$OS_ID" in
        ubuntu|debian)
            log "Detected OS: ${PRETTY_NAME:-$OS_ID}"
            ;;
        *)
            log "Unsupported distribution: $OS_ID (Ubuntu/Debian only)"
            exit 1
            ;;
    esac
}

system_update() {
    log "Updating package index and upgrading system..."
    run_with_retry "apt-get update" apt-get update
    run_with_retry "apt-get full-upgrade" env DEBIAN_FRONTEND=noninteractive apt-get -y full-upgrade
    run_with_retry "Install base tools" env DEBIAN_FRONTEND=noninteractive apt-get -y install curl wget gnupg2 ca-certificates lsb-release software-properties-common apt-transport-https unzip git composer
    add_component "System updated"
}

create_swap_if_needed() {
    local mem_total_kb
    mem_total_kb="$(awk '/MemTotal/ {print $2}' /proc/meminfo)"

    if [[ -z "$mem_total_kb" ]]; then
        log "Could not read system memory information. Skipping swap check."
        return
    fi

    if (( mem_total_kb >= MIN_MEMORY_KB )); then
        log "Memory is >= 2GB. Swap creation not required."
        return
    fi

    if swapon --show | grep -q .; then
        log "Swap already exists. Skipping swap creation."
        return
    fi

    log "Memory below 2GB and no swap found. Creating ${SWAP_SIZE} swap..."
    if command_exists fallocate; then
        run_with_retry "Create swap file with fallocate" fallocate -l "$SWAP_SIZE" "$SWAP_FILE"
    else
        run_with_retry "Create swap file with dd" dd if=/dev/zero of="$SWAP_FILE" bs=1M count=2048
    fi

    run_with_retry "Set swap permissions" chmod 600 "$SWAP_FILE"
    run_with_retry "Initialize swap" mkswap "$SWAP_FILE"
    run_with_retry "Enable swap" swapon "$SWAP_FILE"

    if ! grep -q "^${SWAP_FILE}" /etc/fstab; then
        echo "${SWAP_FILE} none swap sw 0 0" >> /etc/fstab
    fi

    add_component "Swap (${SWAP_SIZE})"
    log "Swap setup completed."
}

# ------------------------------------------------------------
# Port conflict handling
# ------------------------------------------------------------
stop_process_or_service() {
    local proc="$1"

    if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx "${proc}.service"; then
        run_with_retry "Stop ${proc}.service" systemctl stop "${proc}.service"
        return
    fi

    run_with_retry "Stop process ${proc}" pkill -f "$proc"
}

handle_port_conflicts() {
    local listeners
    listeners="$(ss -ltnp | awk '$4 ~ /:80$/ || $4 ~ /:443$/ {print}')"

    if [[ -z "$listeners" ]]; then
        log "No listeners found on ports 80/443."
        return
    fi

    log "Detected listeners on ports 80/443:"
    echo "$listeners"

    mapfile -t processes < <(echo "$listeners" | grep -oP 'users:\(\("\K[^"]+' | sort -u)

    for proc in "${processes[@]}"; do
        [[ -n "$proc" ]] || continue
        read -r -p "Stop conflicting process/service '$proc'? [y/N]: " confirm
        if [[ "$confirm" =~ ^[Yy]$ ]]; then
            stop_process_or_service "$proc" || true
        fi
    done

    if ss -ltnp | awk '$4 ~ /:80$/ || $4 ~ /:443$/ {exit 1}'; then
        log "Port conflicts resolved."
    else
        echo "Ports 80/443 are still occupied."
        read -r -p "Continue anyway? [y/N]: " continue_anyway
        [[ "$continue_anyway" =~ ^[Yy]$ ]] || exit 1
    fi
}

# ------------------------------------------------------------
# Web server installation
# ------------------------------------------------------------
remove_old_web_servers() {
    log "Detecting old web server installations..."

    local -a remove_pkgs=()

    if dpkg -l | awk '{print $2}' | grep -Eq '^apache2$|^apache2-bin$|^apache2-utils$'; then
        remove_pkgs+=(apache2 apache2-bin apache2-data apache2-utils)
    fi

    if dpkg -l | awk '{print $2}' | grep -Eq '^nginx$|^nginx-common$|^nginx-core$|^nginx-full$'; then
        remove_pkgs+=(nginx nginx-common nginx-core nginx-full)
    fi

    if dpkg -l | awk '{print $2}' | grep -Eq '^openlitespeed$|^lsphp'; then
        remove_pkgs+=(openlitespeed)
    fi

    for svc in apache2 nginx lsws lshttpd openlitespeed; do
        if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx "${svc}.service"; then
            run_with_retry "Stop ${svc}.service" systemctl stop "${svc}.service"
            run_with_retry "Disable ${svc}.service" systemctl disable "${svc}.service"
        fi
    done

    if (( ${#remove_pkgs[@]} > 0 )); then
        run_with_retry "Purge old web server packages" env DEBIAN_FRONTEND=noninteractive apt-get -y purge "${remove_pkgs[@]}"
        run_with_retry "Autoremove old packages" env DEBIAN_FRONTEND=noninteractive apt-get -y autoremove --purge
        log "Old web server packages removed."
    else
        log "No old web server packages detected."
    fi
}

choose_web_server() {
    while true; do
        echo
        echo "Choose web server:"
        echo "1) OpenLiteSpeed"
        echo "2) Nginx"
        read -r -p "Select [1-2]: " choice

        case "$choice" in
            1)
                WEB_SERVER_CHOICE="openlitespeed"
                return
                ;;
            2)
                WEB_SERVER_CHOICE="nginx"
                return
                ;;
            *)
                echo "Invalid selection."
                ;;
        esac
    done
}

detect_ols_service_name() {
    for svc in lsws lshttpd openlitespeed; do
        if systemctl list-unit-files --type=service --no-legend 2>/dev/null | awk '{print $1}' | grep -qx "${svc}.service"; then
            echo "$svc"
            return
        fi
    done
}

install_openlitespeed() {
    log "Installing OpenLiteSpeed..."

    if ! apt-cache show openlitespeed >/dev/null 2>&1; then
        run_with_retry "Add OpenLiteSpeed repository" bash -c "curl -fsSL https://repo.litespeed.sh | bash"
        run_with_retry "apt-get update after OLS repo" apt-get update
    fi

    run_with_retry "Install openlitespeed" env DEBIAN_FRONTEND=noninteractive apt-get -y install openlitespeed

    local ols_service
    ols_service="$(detect_ols_service_name)"
    if [[ -z "$ols_service" ]]; then
        log "OpenLiteSpeed service not found after install."
        exit 1
    fi

    run_with_retry "Enable ${ols_service}" systemctl enable "$ols_service"
    run_with_retry "Start ${ols_service}" systemctl start "$ols_service"
    validate_service_active "$ols_service"

    add_component "OpenLiteSpeed"
}

install_nginx() {
    log "Installing Nginx..."
    run_with_retry "Install nginx" env DEBIAN_FRONTEND=noninteractive apt-get -y install nginx
    run_with_retry "Enable nginx" systemctl enable nginx
    run_with_retry "Start nginx" systemctl start nginx
    validate_service_active nginx
    add_component "Nginx"
}

install_selected_web_server() {
    case "$WEB_SERVER_CHOICE" in
        openlitespeed)
            install_openlitespeed
            ;;
        nginx)
            install_nginx
            ;;
        *)
            log "Unknown web server choice: $WEB_SERVER_CHOICE"
            exit 1
            ;;
    esac
}

# ------------------------------------------------------------
# Database installation and hardening
# ------------------------------------------------------------
choose_database_engine() {
    while true; do
        echo
        echo "Choose database server:"
        echo "1) MariaDB"
        echo "2) MySQL"
        read -r -p "Select [1-2]: " choice

        case "$choice" in
            1)
                DB_ENGINE="mariadb"
                return
                ;;
            2)
                DB_ENGINE="mysql"
                return
                ;;
            *)
                echo "Invalid selection."
                ;;
        esac
    done
}

install_database_server() {
    case "$DB_ENGINE" in
        mariadb)
            run_with_retry "Install MariaDB server" env DEBIAN_FRONTEND=noninteractive apt-get -y install mariadb-server mariadb-client
            DB_SERVICE="mariadb"
            add_component "MariaDB"
            ;;
        mysql)
            run_with_retry "Install MySQL server" env DEBIAN_FRONTEND=noninteractive apt-get -y install mysql-server mysql-client
            DB_SERVICE="mysql"
            add_component "MySQL"
            ;;
        *)
            log "Unknown DB engine: $DB_ENGINE"
            exit 1
            ;;
    esac

    run_with_retry "Enable ${DB_SERVICE}" systemctl enable "$DB_SERVICE"
    run_with_retry "Start ${DB_SERVICE}" systemctl start "$DB_SERVICE"
    validate_service_active "$DB_SERVICE"
}

initialize_mysql_client() {
    if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
        MYSQL_CMD=(mysql -u root)
        return
    fi

    while true; do
        read -r -s -p "Enter current database root password: " DB_ROOT_PASSWORD
        echo
        if mysql -u root -p"$DB_ROOT_PASSWORD" -e "SELECT 1" >/dev/null 2>&1; then
            MYSQL_CMD=(mysql -u root -p"$DB_ROOT_PASSWORD")
            return
        fi
        echo "Authentication failed."
        read -r -p "Retry DB root password? [y/N]: " retry
        [[ "$retry" =~ ^[Yy]$ ]] || exit 1
    done
}

mysql_exec() {
    local sql="$1"
    run_with_retry "Run SQL command" "${MYSQL_CMD[@]}" -e "$sql"
}

secure_database_setup() {
    log "Running database secure setup..."

    initialize_mysql_client

    mysql_exec "DELETE FROM mysql.user WHERE User='';"
    mysql_exec "DROP DATABASE IF EXISTS test;"
    mysql_exec "DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';"
    mysql_exec "FLUSH PRIVILEGES;"

    read -r -s -p "Set or rotate DB root password now (leave blank to keep current): " new_root_password
    echo
    if [[ -n "$new_root_password" ]]; then
        local escaped_root
        escaped_root="$(escape_sql_string "$new_root_password")"
        mysql_exec "ALTER USER 'root'@'localhost' IDENTIFIED BY '${escaped_root}';"
        DB_ROOT_PASSWORD="$new_root_password"
        MYSQL_CMD=(mysql -u root -p"$DB_ROOT_PASSWORD")
    fi

    add_component "Database hardening"
}

create_database_interactive() {
    local db_name db_user db_pass db_pass_confirm

    while true; do
        read -r -p "Database name: " db_name
        [[ "$db_name" =~ ^[A-Za-z0-9_]+$ ]] && break
        echo "Use only letters, numbers, underscore."
    done

    while true; do
        read -r -p "Database username: " db_user
        [[ "$db_user" =~ ^[A-Za-z0-9_]+$ ]] && break
        echo "Use only letters, numbers, underscore."
    done

    while true; do
        read -r -s -p "Database user password: " db_pass
        echo
        read -r -s -p "Confirm password: " db_pass_confirm
        echo
        [[ -n "$db_pass" ]] || { echo "Password cannot be empty."; continue; }
        [[ "$db_pass" == "$db_pass_confirm" ]] && break
        echo "Passwords do not match."
    done

    local escaped_pass
    escaped_pass="$(escape_sql_string "$db_pass")"

    mysql_exec "CREATE DATABASE IF NOT EXISTS \`${db_name}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    mysql_exec "CREATE USER IF NOT EXISTS '${db_user}'@'localhost' IDENTIFIED BY '${escaped_pass}';"
    mysql_exec "GRANT ALL PRIVILEGES ON \`${db_name}\`.* TO '${db_user}'@'localhost';"
    mysql_exec "FLUSH PRIVILEGES;"

    DB_APP_NAME="$db_name"
    DB_APP_USER="$db_user"
    DB_APP_PASSWORD="$db_pass"

    add_component "Database: ${db_name}"
    log "Database and user created successfully."
}

# ------------------------------------------------------------
# PHP installation
# ------------------------------------------------------------
configure_php_repository() {
    log "Configuring PHP repository for multiple versions..."

    if [[ "$OS_ID" == "ubuntu" ]]; then
        if ! grep -Rqs "ppa.launchpadcontent.net/ondrej/php" /etc/apt/sources.list /etc/apt/sources.list.d 2>/dev/null; then
            run_with_retry "Add Ondrej PHP PPA" add-apt-repository -y ppa:ondrej/php
        fi
    else
        if [[ ! -f /etc/apt/sources.list.d/php.list ]]; then
            run_with_retry "Install keyring tools" env DEBIAN_FRONTEND=noninteractive apt-get -y install ca-certificates lsb-release apt-transport-https gnupg2
            run_with_retry "Add Sury key" bash -c "curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor -o /usr/share/keyrings/sury-php.gpg"
            echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ ${OS_CODENAME} main" > /etc/apt/sources.list.d/php.list
        fi
    fi

    run_with_retry "apt-get update for PHP repo" apt-get update
}

choose_php_versions() {
    local input versions_raw version

    echo
    echo "Enter PHP versions to install (comma-separated)."
    echo "Example: 7.4,8.0,8.1,8.2,8.3"
    read -r -p "PHP versions [8.2,8.3]: " input
    input="${input:-8.2,8.3}"

    IFS=',' read -r -a versions_raw <<< "$input"
    INSTALLED_PHP_VERSIONS=()

    for version in "${versions_raw[@]}"; do
        version="$(echo "$version" | xargs)"
        [[ "$version" =~ ^[0-9]+\.[0-9]+$ ]] || { echo "Skipping invalid version format: $version"; continue; }

        if apt-cache show "php${version}-cli" >/dev/null 2>&1; then
            INSTALLED_PHP_VERSIONS+=("$version")
        else
            echo "Skipping unavailable version in repo: $version"
        fi
    done

    if (( ${#INSTALLED_PHP_VERSIONS[@]} == 0 )); then
        log "No valid PHP versions selected."
        exit 1
    fi
}

install_php_versions() {
    local version

    for version in "${INSTALLED_PHP_VERSIONS[@]}"; do
        log "Installing PHP ${version}..."

        run_with_retry "Install PHP ${version} core extensions" \
            env DEBIAN_FRONTEND=noninteractive apt-get -y install \
            "php${version}-cli" \
            "php${version}-common" \
            "php${version}-fpm" \
            "php${version}-opcache" \
            "php${version}-curl" \
            "php${version}-xml" \
            "php${version}-mbstring" \
            "php${version}-zip" \
            "php${version}-bcmath" \
            "php${version}-mysql"

        run_with_retry "Enable PHP ${version} FPM" systemctl enable "php${version}-fpm"
        run_with_retry "Start PHP ${version} FPM" systemctl start "php${version}-fpm"
        validate_service_active "php${version}-fpm"

        add_component "PHP ${version}"
    done
}

# ------------------------------------------------------------
# Redis installation
# ------------------------------------------------------------
install_redis_server() {
    log "Installing Redis server..."
    run_with_retry "Install redis-server" env DEBIAN_FRONTEND=noninteractive apt-get -y install redis-server
    run_with_retry "Enable redis-server" systemctl enable redis-server
    run_with_retry "Start redis-server" systemctl start redis-server
    validate_service_active redis-server
    add_component "Redis server"
}

install_php_redis_extensions() {
    local version

    for version in "${INSTALLED_PHP_VERSIONS[@]}"; do
        if apt-cache show "php${version}-redis" >/dev/null 2>&1; then
            run_with_retry "Install php${version}-redis" env DEBIAN_FRONTEND=noninteractive apt-get -y install "php${version}-redis"
        else
            run_with_retry "Install generic php-redis" env DEBIAN_FRONTEND=noninteractive apt-get -y install php-redis
        fi

        if command_exists phpenmod; then
            run_with_retry "Enable redis extension for PHP ${version}" phpenmod -v "$version" redis
        fi

        run_with_retry "Restart PHP ${version} FPM" systemctl restart "php${version}-fpm"
        validate_service_active "php${version}-fpm"
    done

    add_component "PHP Redis extensions"
}

# ------------------------------------------------------------
# Website provisioning
# ------------------------------------------------------------
detect_ols_php_binary() {
    local candidate
    candidate="$(find /usr/local/lsws -maxdepth 3 -type f -path "*/bin/lsphp" 2>/dev/null | sort -V | tail -n 1 || true)"

    if [[ -n "$candidate" ]]; then
        echo "$candidate"
    else
        echo "/usr/local/lsws/fcgi-bin/lsphp5"
    fi
}

ensure_ols_include_file() {
    local main_conf="/usr/local/lsws/conf/httpd_config.conf"
    local include_file="/usr/local/lsws/conf/vhosts/serverpanel-vhosts.conf"

    mkdir -p /usr/local/lsws/conf/vhosts
    touch "$include_file"

    if ! grep -Fq "include ${include_file}" "$main_conf"; then
        echo "" >> "$main_conf"
        echo "include ${include_file}" >> "$main_conf"
    fi
}

rebuild_ols_custom_config() {
    local include_file="/usr/local/lsws/conf/vhosts/serverpanel-vhosts.conf"
    local domains_file="/usr/local/lsws/conf/vhosts/serverpanel-domains.list"

    : > "$include_file"

    while IFS= read -r domain; do
        [[ -n "$domain" ]] || continue
        cat >> "$include_file" <<EOF
virtualhost ${domain} {
  vhRoot                  /var/www/${domain}/
  configFile              /usr/local/lsws/conf/vhosts/${domain}/vhconf.conf
  allowSymbolLink         1
  enableScript            1
  restrained              1
}
EOF
    done < "$domains_file"

    {
        echo "listener serverpanelHTTP {"
        echo "  address                 *:80"
        echo "  secure                  0"

        while IFS= read -r domain; do
            [[ -n "$domain" ]] || continue
            echo "  map                     ${domain} ${domain}"
            echo "  map                     www.${domain} ${domain}"
        done < "$domains_file"

        echo "}"
    } >> "$include_file"
}

create_website_nginx() {
    local domain="$1"
    local php_version="$2"
    local conf_file="/etc/nginx/sites-available/${domain}.conf"

    cat > "$conf_file" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${domain} www.${domain};

    root /var/www/${domain};
    index index.php index.html index.htm;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \\.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php${php_version}-fpm.sock;
    }

    location ~ /\\.ht {
        deny all;
    }
}
EOF

    ln -sf "$conf_file" "/etc/nginx/sites-enabled/${domain}.conf"
    if [[ -f /etc/nginx/sites-enabled/default ]]; then
        rm -f /etc/nginx/sites-enabled/default
    fi

    run_with_retry "Validate nginx config" nginx -t
    run_with_retry "Reload nginx" systemctl reload nginx
}

create_website_openlitespeed() {
    local domain="$1"
    local vhost_dir="/usr/local/lsws/conf/vhosts/${domain}"
    local vh_conf="${vhost_dir}/vhconf.conf"
    local domains_file="/usr/local/lsws/conf/vhosts/serverpanel-domains.list"
    local ols_php

    mkdir -p "$vhost_dir"
    ols_php="$(detect_ols_php_binary)"

    cat > "$vh_conf" <<EOF
docRoot                   /var/www/${domain}/
vhDomain                  ${domain}
vhAliases                 www.${domain}
adminEmails               admin@${domain}
enableGzip                1

index  {
  useServer               0
  indexFiles              index.php,index.html
}

errorlog /var/log/lsws/${domain}.error_log {
  useServer               0
  logLevel                WARN
  rollingSize             10M
}

accesslog /var/log/lsws/${domain}.access_log {
  useServer               0
  logFormat               "%h %l %u %t \"%r\" %>s %b"
  rollingSize             10M
  keepDays                10
  compressArchive         1
}

scripthandler  {
  add                     lsapi:lsphp php
}

extprocessor lsphp {
  type                    lsapi
  address                 uds://tmp/lshttpd/${domain}.sock
  maxConns                35
  env                     PHP_LSAPI_CHILDREN=35
  initTimeout             60
  retryTimeout            0
  persistConn             1
  pcKeepAliveTimeout      1
  respBuffer              0
  autoStart               1
  path                    ${ols_php}
  backlog                 100
  instances               1
  extUser                 www-data
  extGroup                www-data
  runOnStartUp            3
  priority                0
  memSoftLimit            2047M
  memHardLimit            2047M
  procSoftLimit           400
  procHardLimit           500
}
EOF

    touch "$domains_file"
    if ! grep -Fxq "$domain" "$domains_file"; then
        echo "$domain" >> "$domains_file"
    fi

    ensure_ols_include_file
    rebuild_ols_custom_config

    local ols_service
    ols_service="$(detect_ols_service_name)"
    [[ -n "$ols_service" ]] || { log "Unable to detect OpenLiteSpeed service"; exit 1; }

    run_with_retry "Restart ${ols_service}" systemctl restart "$ols_service"
    validate_service_active "$ols_service"
}

setup_laravel_application() {
    local domain="$1"
    local doc_root="$2"
    local php_version="$3"
    local php_bin
    local env_file
    local replace_existing
    local run_migrations

    log "Preparing Laravel application for ${domain}..."

    if [[ -n "$(find "$doc_root" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null || true)" ]]; then
        read -r -p "Document root ${doc_root} is not empty. Replace contents for Laravel install? [y/N]: " replace_existing
        if [[ ! "$replace_existing" =~ ^[Yy]$ ]]; then
            log "Skipped Laravel setup for ${domain}."
            return
        fi
        run_with_retry "Clear document root for Laravel ${domain}" clear_directory_contents "$doc_root"
    fi

    run_with_retry "Create Laravel project (${domain})" env COMPOSER_ALLOW_SUPERUSER=1 composer create-project --no-interaction laravel/laravel "$doc_root"
    run_with_retry "Set Laravel permissions (${domain})" chown -R www-data:www-data "$doc_root"

    env_file="${doc_root}/.env"
    if [[ ! -f "$env_file" && -f "${doc_root}/.env.example" ]]; then
        run_with_retry "Copy Laravel .env example (${domain})" cp "${doc_root}/.env.example" "$env_file"
    fi

    if [[ -f "$env_file" ]]; then
        update_env_key "$env_file" "APP_NAME" "ServerPanel"
        update_env_key "$env_file" "APP_ENV" "production"
        update_env_key "$env_file" "APP_DEBUG" "false"
        update_env_key "$env_file" "APP_URL" "http://${domain}"

        if [[ -n "$DB_APP_NAME" && -n "$DB_APP_USER" ]]; then
            update_env_key "$env_file" "DB_CONNECTION" "mysql"
            update_env_key "$env_file" "DB_HOST" "127.0.0.1"
            update_env_key "$env_file" "DB_PORT" "3306"
            update_env_key "$env_file" "DB_DATABASE" "$DB_APP_NAME"
            update_env_key "$env_file" "DB_USERNAME" "$DB_APP_USER"
            update_env_key "$env_file" "DB_PASSWORD" "$DB_APP_PASSWORD"
        fi
    fi

    php_bin="$(get_php_binary_for_version "$php_version")"
    (
        cd "$doc_root"
        run_with_retry "Laravel key:generate (${domain})" "$php_bin" artisan key:generate --force
        run_with_retry "Laravel optimize:clear (${domain})" "$php_bin" artisan optimize:clear
    )

    read -r -p "Run Laravel migrations for ${domain}? [y/N]: " run_migrations
    if [[ "$run_migrations" =~ ^[Yy]$ ]]; then
        (
            cd "$doc_root"
            run_with_retry "Laravel migrate (${domain})" "$php_bin" artisan migrate --force
        )
    fi

    LARAVEL_SITES+=("$domain")
    add_component "Laravel app: ${domain}"
    log "Laravel setup completed for ${domain}."
}

create_website_interactive() {
    local domain
    local doc_root
    local php_version
    local create_site
    local setup_laravel

    while true; do
        read -r -p "Create a new website now? [y/N]: " create_site
        [[ "$create_site" =~ ^[Yy]$ ]] || break

        while true; do
            read -r -p "Domain name (example.com): " domain
            if [[ "$domain" =~ ^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; then
                break
            fi
            echo "Invalid domain format."
        done

        doc_root="/var/www/${domain}"
        mkdir -p "$doc_root"
        chown -R www-data:www-data "$doc_root"
        chmod -R 755 "$doc_root"

        cat > "${doc_root}/index.php" <<EOF
<?php
header('Content-Type: text/plain');
echo "ServerPanel site is ready for ${domain}.";
EOF

        php_version="${INSTALLED_PHP_VERSIONS[0]}"
        if [[ "$WEB_SERVER_CHOICE" == "nginx" ]]; then
            echo "Installed PHP versions: ${INSTALLED_PHP_VERSIONS[*]}"
            read -r -p "Choose PHP version for this site [${INSTALLED_PHP_VERSIONS[0]}]: " php_version
            php_version="${php_version:-${INSTALLED_PHP_VERSIONS[0]}}"

            if [[ ! " ${INSTALLED_PHP_VERSIONS[*]} " =~ " ${php_version} " ]]; then
                echo "Invalid PHP version choice. Using ${INSTALLED_PHP_VERSIONS[0]}"
                php_version="${INSTALLED_PHP_VERSIONS[0]}"
            fi

            create_website_nginx "$domain" "$php_version"
        else
            create_website_openlitespeed "$domain"
        fi

        CREATED_DOMAINS+=("$domain")
        add_component "Website: ${domain}"
        log "Website provisioned: ${domain} -> ${doc_root}"

        read -r -p "Setup Laravel application for ${domain}? [y/N]: " setup_laravel
        if [[ "$setup_laravel" =~ ^[Yy]$ ]]; then
            setup_laravel_application "$domain" "$doc_root" "$php_version"
        fi
    done
}

# ------------------------------------------------------------
# Summary output
# ------------------------------------------------------------
print_summary() {
    local server_ip
    server_ip="$(hostname -I | awk '{print $1}')"

    echo
    echo "================ INSTALLATION SUMMARY ================"
    echo "Server IP: ${server_ip:-unknown}"
    echo "Web server: ${WEB_SERVER_CHOICE}"
    echo "Database: ${DB_ENGINE}"
    echo "PHP versions: ${INSTALLED_PHP_VERSIONS[*]}"
    echo "Log file: ${LOG_FILE}"
    echo
    echo "Installed components:"
    for item in "${INSTALLED_COMPONENTS[@]}"; do
        echo "- $item"
    done
    echo

    if (( ${#CREATED_DOMAINS[@]} > 0 )); then
        echo "Website access URLs:"
        for d in "${CREATED_DOMAINS[@]}"; do
            echo "- http://${d}"
        done
    fi

    if (( ${#LARAVEL_SITES[@]} > 0 )); then
        echo
        echo "Laravel apps configured:"
        for d in "${LARAVEL_SITES[@]}"; do
            echo "- ${d}"
        done
    fi

    if [[ "$WEB_SERVER_CHOICE" == "openlitespeed" ]]; then
        echo
        echo "OpenLiteSpeed admin panel: https://${server_ip}:7080"
        echo "If needed, set admin credentials with: /usr/local/lsws/admin/misc/admpass.sh"
    else
        echo
        echo "Nginx has no built-in admin panel. Manage using config files and systemctl."
    fi

    echo "======================================================"
}

# ------------------------------------------------------------
# Main flow
# ------------------------------------------------------------
main() {
    require_root
    setup_logging

    log "Starting ServerPanel installer..."

    remove_sensitive_files
    detect_os
    system_update
    create_swap_if_needed

    remove_old_web_servers
    handle_port_conflicts

    choose_web_server
    install_selected_web_server

    choose_database_engine
    install_database_server
    secure_database_setup
    create_database_interactive

    configure_php_repository
    choose_php_versions
    install_php_versions

    install_redis_server
    install_php_redis_extensions

    create_website_interactive

    validate_service_active "$DB_SERVICE"
    validate_service_active redis-server
    if [[ "$WEB_SERVER_CHOICE" == "nginx" ]]; then
        validate_service_active nginx
    else
        validate_service_active "$(detect_ols_service_name)"
    fi

    print_summary
    log "Installation completed successfully."
}

main "$@"
