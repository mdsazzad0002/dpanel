#!/usr/bin/env bash
set -euo pipefail

# ============================================================
# ServerInstaller - AlmaLinux web stack installer
# Components:
# - OpenLiteSpeed
# - Apache (httpd)
# - MariaDB (MySQL-compatible)
# - phpMyAdmin
# - LSAPI PHP versions: 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 (if available)
# - Mail stack: Postfix + Dovecot
# ============================================================

SCRIPT_VERSION="2.0.0"
APP_REPO_URL="${APP_REPO_URL:-}"
APP_DIR="${APP_DIR:-/opt/serverinstaller}"
APP_BRANCH="${APP_BRANCH:-main}"
PHPMYADMIN_URL="${PHPMYADMIN_URL:-https://www.phpmyadmin.net/downloads/phpMyAdmin-latest-all-languages.tar.gz}"

OS_ID=""
OS_VERSION=""
PKG_MANAGER=""

log() { printf "\n[+] %s\n" "$*"; }
warn() { printf "\n[!] %s\n" "$*"; }
err() { printf "\n[x] %s\n" "$*"; }
step_start() { printf "\n[>] STEP %s: %s\n" "$1" "$2"; }
step_done() { printf "[<] STEP %s DONE\n" "$1"; }

run_pkg() {
  # Run package manager command for dnf/yum compatibility.
  case "${PKG_MANAGER}" in
    dnf) dnf -y "$@" ;;
    yum) yum -y "$@" ;;
    *) err "Unsupported package manager: ${PKG_MANAGER}"; exit 1 ;;
  esac
}

pkg_available() {
  # Check if a package exists in enabled repositories.
  local pkg="$1"
  case "${PKG_MANAGER}" in
    dnf) dnf list --available "${pkg}" >/dev/null 2>&1 ;;
    yum) yum list available "${pkg}" >/dev/null 2>&1 ;;
    *) return 1 ;;
  esac
}

require_root() {
  # Validate root privileges.
  log "Checking root privilege"
  if [[ "${EUID}" -ne 0 ]]; then
    err "Run as root: sudo bash install.sh"
    exit 1
  fi
  log "Root privilege check passed"
}

detect_os() {
  # Detect OS and restrict to AlmaLinux.
  log "Detecting operating system details"
  if [[ -f /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
    OS_ID="${ID:-}"
    OS_VERSION="${VERSION_ID:-}"
  else
    err "Cannot detect OS. /etc/os-release not found."
    exit 1
  fi

  if [[ "${OS_ID}" != "almalinux" ]]; then
    err "This installer is configured for AlmaLinux only. Detected: ${OS_ID} ${OS_VERSION}"
    exit 1
  fi

  PKG_MANAGER="dnf"
  command -v dnf >/dev/null 2>&1 || PKG_MANAGER="yum"
  log "Detected OS: ${OS_ID} ${OS_VERSION} | Package Manager: ${PKG_MANAGER}"
}

confirm_start() {
  # Confirm before making server changes.
  log "Waiting for installation confirmation"
  echo
  read -r -p "Proceed with AlmaLinux stack installation? [y/N]: " answer
  case "${answer}" in
    y|Y|yes|YES) ;;
    *) warn "Installation cancelled."; exit 0 ;;
  esac
  log "Installation confirmed by user"
}

update_system() {
  # Update installed packages.
  log "Updating system packages"
  run_pkg update
  log "System update completed"
}

install_base_packages() {
  # Install core tools and services baseline.
  log "Installing base dependencies"
  run_pkg install curl wget git unzip tar lsof sudo ca-certificates gnupg2 firewalld policycoreutils-python-utils
  systemctl enable --now firewalld || true
  log "Base dependency installation completed"
}

configure_firewall() {
  # Open web and mail-related ports.
  log "Configuring firewall rules"
  local ports=(
    22/tcp 80/tcp 443/tcp
    7080/tcp 8088/tcp
    25/tcp 465/tcp 587/tcp
    110/tcp 995/tcp
    143/tcp 993/tcp
  )

  for p in "${ports[@]}"; do
    firewall-cmd --permanent --add-port="${p}" || true
  done
  firewall-cmd --reload || true
  log "Firewall configuration completed"
}

install_mariadb() {
  # Install and start MariaDB (MySQL-compatible database server).
  log "Installing MariaDB server"
  run_pkg install mariadb-server
  systemctl enable --now mariadb
  log "MariaDB installed and service started"
  warn "Run 'mysql_secure_installation' manually to harden DB root/user settings."
}

install_apache() {
  # Install and enable Apache httpd.
  log "Installing Apache httpd"
  run_pkg install httpd
  systemctl enable --now httpd
  log "Apache installed and service started"
}

setup_litespeed_repo() {
  # Add OpenLiteSpeed package repository.
  log "Configuring OpenLiteSpeed repository"
  if ! rpm -q litespeed-repo >/dev/null 2>&1; then
    run_pkg install https://rpms.litespeedtech.com/centos/litespeed-repo-1.3-1.el8.noarch.rpm || \
    run_pkg install https://rpms.litespeedtech.com/centos/litespeed-repo-1.4-1.el9.noarch.rpm
  fi
  log "OpenLiteSpeed repository ready"
}

install_openlitespeed() {
  # Install and start OpenLiteSpeed.
  log "Installing OpenLiteSpeed"
  run_pkg install openlitespeed
  systemctl enable --now lsws
  log "OpenLiteSpeed installed and service started"
}

install_lsphp_versions() {
  # Install requested LSAPI PHP versions where available.
  log "Installing LSAPI PHP versions (7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5)"
  local versions=(74 80 81 82 83 84 85)
  local ext=(mysqlnd common process mbstring xml gd cli zip curl imap intl opcache bcmath)

  for v in "${versions[@]}"; do
    local base="lsphp${v}"
    if pkg_available "${base}"; then
      log "Installing ${base} and extensions"
      local pkgs=("${base}")
      local e
      for e in "${ext[@]}"; do
        if pkg_available "${base}-${e}"; then
          pkgs+=("${base}-${e}")
        fi
      done
      run_pkg install "${pkgs[@]}"
    else
      warn "Package ${base} not found in repo. Skipping this PHP version."
    fi
  done
  log "LSAPI PHP version installation step completed"
}

install_mail_stack() {
  # Install and start mail transfer + IMAP/POP services.
  log "Installing mailbox stack (Postfix + Dovecot)"
  run_pkg install postfix dovecot cyrus-sasl cyrus-sasl-plain mailx
  systemctl enable --now postfix
  systemctl enable --now dovecot
  log "Mailbox stack installed and services started"
}

install_phpmyadmin() {
  # Deploy phpMyAdmin for both Apache and OpenLiteSpeed document roots.
  log "Installing phpMyAdmin"
  local temp_dir="/usr/local/src/phpmyadmin-install"
  local extracted_dir=""

  mkdir -p "${temp_dir}"
  rm -rf "${temp_dir:?}"/*

  curl -fL "${PHPMYADMIN_URL}" -o "${temp_dir}/phpmyadmin.tar.gz"
  tar -xzf "${temp_dir}/phpmyadmin.tar.gz" -C "${temp_dir}"
  extracted_dir="$(find "${temp_dir}" -maxdepth 1 -mindepth 1 -type d -name 'phpMyAdmin-*' | head -n1)"

  if [[ -z "${extracted_dir}" ]]; then
    err "phpMyAdmin extraction failed"
    exit 1
  fi

  mkdir -p /var/www/html
  rm -rf /var/www/html/phpmyadmin
  cp -a "${extracted_dir}" /var/www/html/phpmyadmin

  mkdir -p /usr/local/lsws/Example/html
  rm -rf /usr/local/lsws/Example/html/phpmyadmin
  cp -a "${extracted_dir}" /usr/local/lsws/Example/html/phpmyadmin

  cp /var/www/html/phpmyadmin/config.sample.inc.php /var/www/html/phpmyadmin/config.inc.php
  cp /usr/local/lsws/Example/html/phpmyadmin/config.sample.inc.php /usr/local/lsws/Example/html/phpmyadmin/config.inc.php

  chown -R apache:apache /var/www/html/phpmyadmin || true
  chown -R nobody:nobody /usr/local/lsws/Example/html/phpmyadmin || true

  log "phpMyAdmin installed at /var/www/html/phpmyadmin"
  log "phpMyAdmin installed at /usr/local/lsws/Example/html/phpmyadmin"
  warn "Create phpMyAdmin blowfish_secret in config.inc.php before production use."
}

clone_or_update_repo() {
  # Pull latest application source or clone fresh repository.
  log "Preparing application source"
  if [[ -z "${APP_REPO_URL}" ]]; then
    warn "APP_REPO_URL not set. Skipping app clone/update step."
    return
  fi

  mkdir -p "$(dirname "${APP_DIR}")"
  if [[ -d "${APP_DIR}/.git" ]]; then
    git -C "${APP_DIR}" fetch --all --prune
    git -C "${APP_DIR}" checkout "${APP_BRANCH}"
    git -C "${APP_DIR}" pull origin "${APP_BRANCH}"
  else
    git clone -b "${APP_BRANCH}" "${APP_REPO_URL}" "${APP_DIR}"
  fi
  log "Application source step completed"
}

show_banner() {
  cat <<'EOF'
==============================================================
   ServerInstaller | AlmaLinux Stack (OLS + Apache + Mail)
==============================================================
EOF
}

summary() {
  cat <<EOF

Installation complete.

Summary:
- Script version: ${SCRIPT_VERSION}
- OS: ${OS_ID} ${OS_VERSION}
- MariaDB: enabled
- Apache: enabled (80/443)
- OpenLiteSpeed: enabled (7080 admin, 8088 web)
- PHP (LSAPI): attempted 7.4, 8.0, 8.1, 8.2, 8.3, 8.4, 8.5
- Mail stack: Postfix + Dovecot enabled
- phpMyAdmin:
  - /var/www/html/phpmyadmin
  - /usr/local/lsws/Example/html/phpmyadmin

Important next steps:
1) Run mysql_secure_installation
2) Configure DNS/MX records for mail server
3) Set phpMyAdmin blowfish_secret in config.inc.php
4) Review and secure OpenLiteSpeed admin panel (port 7080)
EOF
}

main() {
  # Run full AlmaLinux installation workflow.
  show_banner

  step_start "1" "Root privilege validation"
  require_root
  step_done "1"

  step_start "2" "Operating system detection"
  detect_os
  step_done "2"

  step_start "3" "User confirmation"
  confirm_start
  step_done "3"

  step_start "4" "System update"
  update_system
  step_done "4"

  step_start "5" "Base dependency installation"
  install_base_packages
  step_done "5"

  step_start "6" "Firewall configuration"
  configure_firewall
  step_done "6"

  step_start "7" "MariaDB installation"
  install_mariadb
  step_done "7"

  step_start "8" "Apache installation"
  install_apache
  step_done "8"

  step_start "9" "OpenLiteSpeed repository setup"
  setup_litespeed_repo
  step_done "9"

  step_start "10" "OpenLiteSpeed installation"
  install_openlitespeed
  step_done "10"

  step_start "11" "LSAPI PHP version installation"
  install_lsphp_versions
  step_done "11"

  step_start "12" "Mailbox stack installation"
  install_mail_stack
  step_done "12"

  step_start "13" "phpMyAdmin installation"
  install_phpmyadmin
  step_done "13"

  step_start "14" "Optional app source deploy/update"
  clone_or_update_repo
  step_done "14"

  step_start "15" "Final summary"
  summary
  step_done "15"
}

main "$@"
