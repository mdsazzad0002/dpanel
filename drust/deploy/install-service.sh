#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

DRUST_ROOT="/var/www/drust"
DPANEL_ROOT="${DRUST_ROOT}/../dpanel"
# Keep the daemon toolchain isolated from any developer user's rustup state.
export CARGO_HOME="/root/.cargo"
export RUSTUP_HOME="/root/.rustup"
export PATH="${CARGO_HOME}/bin:/usr/local/bin:/usr/bin:/bin:${PATH}"

# Ubuntu's needrestart opens a full-screen "Which services should be
# restarted?" dialog after apt installs, which blocks an unattended install.
# Suspend it for this run only; the installer restarts its own services.
export NEEDRESTART_SUSPEND=1 NEEDRESTART_MODE=a DEBIAN_FRONTEND=noninteractive

# Another package manager often holds the dpkg lock (unattended-upgrades right
# after a fresh boot, or a second apt session). Wait for it with a progress line
# every 15 s instead of failing; give up after APT_LOCK_TIMEOUT seconds.
apt_lock_holder() {
  if command -v fuser >/dev/null 2>&1; then
    fuser /var/lib/dpkg/lock-frontend /var/lib/dpkg/lock /var/lib/apt/lists/lock \
      /var/cache/apt/archives/lock 2>/dev/null | tr -s ' ' '\n' | grep -m1 -E '^[0-9]+$' || true
  else
    pgrep -o -x 'apt|apt-get|dpkg|unattended-upgr|aptd|packagekitd' || true
  fi
}

apt_wait_for_lock() {
  local timeout="${APT_LOCK_TIMEOUT:-1800}" waited=0 pid holder
  while pid="$(apt_lock_holder)"; [[ -n "$pid" ]]; do
    holder="$(ps -o args= -p "$pid" 2>/dev/null | cut -c1-70)"
    if (( waited >= timeout )); then
      printf '[ERROR] apt is still busy after %ss (PID %s: %s). Retry later, or stop it with: kill %s\n' \
        "$waited" "$pid" "${holder:-unknown}" "$pid" >&2
      return 1
    fi
    if (( waited % 15 == 0 )); then
      printf '[WAIT] apt/dpkg is busy (PID %s: %s); waiting... %ss elapsed, limit %ss\n' \
        "$pid" "${holder:-unknown}" "$waited" "$timeout"
    fi
    sleep 5
    waited=$((waited + 5))
  done
  # A run killed mid-install leaves dpkg half-configured and apt refuses to
  # continue until it is finished.
  if compgen -G '/var/lib/dpkg/updates/[0-9]*' >/dev/null; then
    printf '[INFO] Finishing an interrupted dpkg run (dpkg --configure -a).\n'
    DEBIAN_FRONTEND=noninteractive dpkg --configure -a || true
  fi
}

# apt-get that waits for the lock first. Lock::Timeout covers the race where
# another apt starts between the check and this call.
apt_get() {
  apt_wait_for_lock || return 1
  DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=300 "$@"
}

# whisper-rs builds whisper.cpp (cmake, clang) and the tesseract bindings
# need libtesseract/leptonica headers and libclang for bindgen.
DRUST_CARGO_FEATURE_ARGS=()
ensure_native_build_deps() {
  local packages=(cmake clang libclang-dev libtesseract-dev libleptonica-dev
    tesseract-ocr tesseract-ocr-eng tesseract-ocr-ben)
  local missing=() package
  ensure_tesseract5_repo
  for package in "${packages[@]}"; do
    dpkg-query -W -f='${db:Status-Abbrev}' "$package" 2>/dev/null | grep -q '^ii' \
      || missing+=("$package")
  done
  if (( ${#missing[@]} )); then
    echo "[drust] Installing build dependencies: ${missing[*]}"
    apt_get update
    apt_get install -y "${missing[@]}"
  fi

  # The tesseract crate uses the Tesseract 5 C API. Older system versions
  # (Ubuntu 22.04 ships 4.1) fail to compile, so OCR is left out there
  # instead of breaking the whole build.
  if ! pkg-config --atleast-version=5 tesseract 2>/dev/null; then
    echo "[drust] Tesseract $(pkg-config --modversion tesseract 2>/dev/null || echo missing) is older than 5; building without image OCR."
    DRUST_CARGO_FEATURE_ARGS=(--no-default-features)
    return 0
  fi

  # tesseract-sys generates its bindings from the system headers once and
  # never re-checks them, so after a 4.x -> 5.x upgrade cargo keeps the stale
  # 4.x bindings (error: no `TessBaseAPIInit5`). Rebuild them when the
  # installed version changes.
  local version stamp="${DRUST_ROOT}/target/.tesseract-version"
  version="$(pkg-config --modversion tesseract 2>/dev/null || true)"
  if [[ "$(cat "$stamp" 2>/dev/null || true)" != "$version" ]]; then
    echo "[drust] Tesseract is now ${version}; rebuilding its Rust bindings."
    cargo clean --release --manifest-path "${DRUST_ROOT}/Cargo.toml" \
      -p tesseract-sys -p tesseract-plumbing -p tesseract >/dev/null 2>&1 || true
    mkdir -p "${DRUST_ROOT}/target"
    printf '%s\n' "$version" > "$stamp"
  fi
}

tesseract_candidate_major() {
  apt-cache policy libtesseract-dev 2>/dev/null \
    | awk '/Candidate:/ {print $2}' | sed -E 's/^[0-9]+://; s/[^0-9].*//'
}

# Ubuntu releases before 23.04 only package Tesseract 4, so OCR would be left
# out there. Add the Tesseract maintainer's PPA to get 5.x. Set
# DRUST_TESSERACT_PPA=0 to skip it and build without OCR.
ensure_tesseract5_repo() {
  local distro="" major
  [[ "${DRUST_TESSERACT_PPA:-1}" == "1" ]] || return 0
  [[ -r /etc/os-release ]] && distro="$(. /etc/os-release; printf '%s' "${ID:-}")"
  [[ "$distro" == "ubuntu" ]] || return 0

  pkg-config --atleast-version=5 tesseract 2>/dev/null && return 0
  major="$(tesseract_candidate_major)"
  [[ -z "$major" ]] && { apt_get update >/dev/null 2>&1 || true; major="$(tesseract_candidate_major)"; }
  [[ "$major" =~ ^[0-9]+$ ]] && (( major >= 5 )) && return 0

  echo "[drust] This Ubuntu packages Tesseract ${major:-?}; adding ppa:alex-p/tesseract-ocr5 for OCR."
  if ! command -v add-apt-repository >/dev/null 2>&1; then
    apt_get install -y software-properties-common || return 0
  fi
  if apt_wait_for_lock && add-apt-repository -y ppa:alex-p/tesseract-ocr5; then
    apt_get update || true
    # Upgrade an already installed 4.x so the headers match the 5.x library.
    if dpkg-query -W libtesseract-dev >/dev/null 2>&1; then
      apt_get install -y --only-upgrade libtesseract-dev libtesseract5 tesseract-ocr || true
    fi
  else
    echo "[drust] Could not add the Tesseract 5 PPA; building without image OCR."
  fi
  return 0
}

# artisan cannot boot without Composer's autoloader. A fresh release archive
# ships without vendor/, so install it here instead of relying on the caller.
ensure_dpanel_dependencies() {
  [[ -f "${DPANEL_ROOT}/vendor/autoload.php" ]] && return 0

  if ! command -v composer >/dev/null 2>&1; then
    echo "[drust] composer is missing; installing it."
    apt_get update
    apt_get install -y composer
  fi

  echo "[drust] Installing dPanel PHP dependencies with composer."
  (cd "${DPANEL_ROOT}" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --prefer-dist --optimize-autoloader)
}

ensure_rust_toolchain() {
  # Only the toolchain in CARGO_HOME counts: a distro rustup (/usr/bin/rustup,
  # packaged on Ubuntu 24.04) refuses to work with CARGO_HOME=/root/.cargo and
  # fails with "rustup is not installed at '/root/.cargo'".
  if [[ -x "${CARGO_HOME}/bin/cargo" ]] && "${CARGO_HOME}/bin/cargo" --version >/dev/null 2>&1; then
    return 0
  fi

  apt_get update
  apt_get install -y build-essential pkg-config openssl ca-certificates curl
  if [[ ! -x "${CARGO_HOME}/bin/rustup" ]]; then
    # The official installer, on every distro. The path check would stop it
    # when a distro rustc or rustup is already on PATH.
    curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs \
      | RUSTUP_INIT_SKIP_PATH_CHECK=yes sh -s -- -y --profile minimal --default-toolchain stable --no-modify-path
  fi

  export PATH="${CARGO_HOME}/bin:${PATH}"
  hash -r
  if ! "${CARGO_HOME}/bin/cargo" --version >/dev/null 2>&1; then
    "${CARGO_HOME}/bin/rustup" toolchain install stable --profile minimal
    "${CARGO_HOME}/bin/rustup" default stable
  fi

  "${CARGO_HOME}/bin/cargo" --version >/dev/null 2>&1 || {
    echo "Rust cargo is unavailable in ${CARGO_HOME}. Install Rust with rustup and rerun this script." >&2
    exit 1
  }
}

# The tesseract crate links the system Tesseract and Leptonica libraries and
# generates its bindings with bindgen, which needs libclang. whisper-rs
# compiles its bundled whisper.cpp with cmake.
ensure_build_dependencies() {
  local packages=(build-essential pkg-config cmake libleptonica-dev libtesseract-dev libclang-dev clang)
  local missing=()
  local package

  for package in "${packages[@]}"; do
    dpkg-query -W -f='${db:Status-Abbrev}' "${package}" 2>/dev/null | grep -q '^ii' || missing+=("${package}")
  done
  (( ${#missing[@]} == 0 )) && return 0

  echo "[drust] Installing build dependencies: ${missing[*]}"
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y "${missing[@]}"
}

read_env_value() {
  local file="$1"
  local key="$2"
  local value

  value="$(awk -F= -v key="${key}" '$1 == key {print substr($0, index($0, "=") + 1); exit}' "${file}")"
  value="${value%\"}"
  value="${value#\"}"
  value="${value%\'}"
  value="${value#\'}"
  printf '%s' "${value}"
}

install_powerdns() {
  local laravel_env="${DPANEL_ROOT}/.env"
  if [[ ! -f "${laravel_env}" ]]; then
    echo "[drust] Skipping PowerDNS: ${laravel_env} is missing."
    return 0
  fi

  # PowerDNS owns authoritative port 53. BIND must not compete for it.
  systemctl disable --now named.service bind9.service >/dev/null 2>&1 || true
  systemctl mask named.service >/dev/null 2>&1 || true

  if ! dpkg-query -W -f='${db:Status-Abbrev}\n' pdns-server pdns-backend-mysql 2>/dev/null \
    | awk 'BEGIN { ok = 1; count = 0 } { count++; if ($0 !~ /^ii /) ok = 0 } END { exit !(ok && count == 2) }'; then
    apt_get update
    apt_get install -y pdns-server pdns-backend-mysql
  fi
  systemctl stop pdns.service >/dev/null 2>&1 || true

  local db_host db_port db_name db_user db_password listen_address
  db_host="$(read_env_value "${laravel_env}" PDNS_DB_HOST)"
  db_port="$(read_env_value "${laravel_env}" PDNS_DB_PORT)"
  db_name="$(read_env_value "${laravel_env}" PDNS_DB_DATABASE)"
  db_user="$(read_env_value "${laravel_env}" PDNS_DB_USERNAME)"
  db_password="$(read_env_value "${laravel_env}" PDNS_DB_PASSWORD)"
  [[ -n "${db_host}" ]] || db_host="$(read_env_value "${laravel_env}" DB_HOST)"
  [[ -n "${db_port}" ]] || db_port="$(read_env_value "${laravel_env}" DB_PORT)"
  [[ -n "${db_name}" ]] || db_name="$(read_env_value "${laravel_env}" DB_DATABASE)"
  [[ -n "${db_user}" ]] || db_user="$(read_env_value "${laravel_env}" DB_USERNAME)"
  [[ -n "${db_password}" ]] || db_password="$(read_env_value "${laravel_env}" DB_PASSWORD)"
  db_host="${db_host:-127.0.0.1}"
  db_port="${db_port:-3306}"

  if [[ -z "${db_name}" || -z "${db_user}" ]]; then
    echo "[drust] PowerDNS database name or user is missing from ${laravel_env}." >&2
    exit 1
  fi

  listen_address="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") {print $(i + 1); exit}}')"
  if [[ -z "${listen_address}" ]]; then
    listen_address="$(hostname -I 2>/dev/null | awk '{print $1}')"
  fi
  if [[ -z "${listen_address}" ]]; then
    echo "[drust] Unable to detect the IPv4 address for PowerDNS." >&2
    exit 1
  fi

  # PowerDNS 5 requires the schema migration shipped with dPanel.
  ensure_dpanel_dependencies
  (cd "${DPANEL_ROOT}" && php artisan migrate --force)

  if [[ -f /etc/powerdns/pdns.d/bind.conf ]]; then
    mv /etc/powerdns/pdns.d/bind.conf /etc/powerdns/pdns.d/bind.conf.disabled
  fi

  local gmysql_config
  gmysql_config="$(mktemp)"
  {
    printf 'launch+=gmysql\n'
    printf 'gmysql-host=%s\n' "${db_host}"
    printf 'gmysql-port=%s\n' "${db_port}"
    printf 'gmysql-dbname=%s\n' "${db_name}"
    printf 'gmysql-user=%s\n' "${db_user}"
    printf 'gmysql-password=%s\n' "${db_password}"
    printf 'gmysql-dnssec=no\n'
  } > "${gmysql_config}"
  install -o root -g pdns -m 0640 "${gmysql_config}" /etc/powerdns/pdns.d/gmysql.conf
  rm -f "${gmysql_config}"

  printf 'local-address=%s\nlocal-port=53\n' "${listen_address}" \
    > /etc/powerdns/pdns.d/listener.conf
  chmod 0644 /etc/powerdns/pdns.d/listener.conf

  if command -v ufw >/dev/null 2>&1; then
    ufw allow 53/udp comment 'Authoritative DNS'
    ufw allow 53/tcp comment 'Authoritative DNS'
  fi

  pdns_server --config=check
  systemctl reset-failed pdns.service || true
  systemctl enable pdns.service
  systemctl restart pdns.service
  systemctl is-active --quiet pdns.service || {
    journalctl -u pdns.service -n 50 --no-pager >&2
    exit 1
  }
  echo "[drust] PowerDNS is serving ${listen_address}:53 over UDP and TCP."
}

ensure_rust_toolchain
ensure_build_dependencies
if ! command -v certbot >/dev/null 2>&1; then
  apt_get update
  apt_get install -y certbot
fi
install -d -m 0750 /etc/drust
# Drust executes only scripts from its isolated runtime directory. Keep that
# directory synchronized on every install/update so newly shipped operations
# are available immediately after the daemon restarts.
install -d -o root -g root -m 0755 /opt/dpanel/runtime/scripts
for runtime_script in "${DRUST_ROOT}/../dscript/scripts/"*.sh; do
  [[ -f "${runtime_script}" ]] || continue
  install -o root -g root -m 0755 "${runtime_script}" "/opt/dpanel/runtime/scripts/$(basename "${runtime_script}")"
done
if [[ ! -f /etc/drust/edge-gateway.env ]]; then
  install -m 0600 "${DRUST_ROOT}/deploy/edge-gateway.env.example" /etc/drust/edge-gateway.env
fi
if [[ ! -f /etc/drust/drust.env ]]; then
  install -m 0600 "${DRUST_ROOT}/deploy/drust.env.example" /etc/drust/drust.env
  token="$(openssl rand -hex 32)"
  sed -i "s/^DRUST_API_TOKEN=.*/DRUST_API_TOKEN=${token}/" /etc/drust/drust.env
fi

DRUST_API_TOKEN="$(awk -F= '$1 == "DRUST_API_TOKEN" {print substr($0, index($0, "=") + 1); exit}' /etc/drust/drust.env)"
if [[ -z "${DRUST_API_TOKEN}" ]]; then
  DRUST_API_TOKEN="$(openssl rand -hex 32)"
  if grep -q '^DRUST_API_TOKEN=' /etc/drust/drust.env; then
    sed -i "s/^DRUST_API_TOKEN=.*/DRUST_API_TOKEN=${DRUST_API_TOKEN}/" /etc/drust/drust.env
  else
    printf '\nDRUST_API_TOKEN=%s\n' "${DRUST_API_TOKEN}" >> /etc/drust/drust.env
  fi
fi

# Keep Laravel's client token aligned with the daemon token automatically.
LARAVEL_ENV="${DPANEL_ROOT}/.env"
if [[ -f "${LARAVEL_ENV}" ]]; then
  if grep -q '^SERVERPANEL_EXECUTION_API_TOKEN=' "${LARAVEL_ENV}"; then
    sed -i "s#^SERVERPANEL_EXECUTION_API_TOKEN=.*#SERVERPANEL_EXECUTION_API_TOKEN=${DRUST_API_TOKEN}#" "${LARAVEL_ENV}"
  else
    printf '\nSERVERPANEL_EXECUTION_API_TOKEN=%s\n' "${DRUST_API_TOKEN}" >> "${LARAVEL_ENV}"
  fi
  DRUST_LIVE_API_URL="http://127.0.0.1:${DRUST_API_PORT:-9500}"
  for key_value in \
    "SERVERPANEL_EXECUTION_API_BASE_URL=${DRUST_LIVE_API_URL}" \
    "SERVERPANEL_FILEMANAGER_API_URL=${DRUST_LIVE_API_URL}/api/v1/filemanager"; do
    key="${key_value%%=*}"
    if grep -q "^${key}=" "${LARAVEL_ENV}"; then
      sed -i "s#^${key}=.*#${key_value}#" "${LARAVEL_ENV}"
    else
      printf '%s\n' "${key_value}" >> "${LARAVEL_ENV}"
    fi
  done
  # The file now carries a token that is a root capability on this host.
  chown root:www-data "${LARAVEL_ENV}" 2>/dev/null || true
  chmod 640 "${LARAVEL_ENV}" 2>/dev/null || true
  if [[ -x "${DRUST_ROOT}/../dpanel/artisan" ]]; then
    (cd "${DRUST_ROOT}/../dpanel" && php artisan config:clear >/dev/null 2>&1 || true)
  fi
fi

install_powerdns

# Parallel codegen is what makes rustc peak; on a small VPS that peak is an
# OOM kill. One job is slower but finishes on a 1 GB machine.
MEMORY_MB="$(awk '/^MemTotal:/ {printf "%d", $2 / 1024; found = 1} END {if (!found) print 0}' /proc/meminfo 2>/dev/null || printf '0')"
if [[ "${MEMORY_MB}" =~ ^[0-9]+$ ]] && (( MEMORY_MB > 0 && MEMORY_MB < 2048 )); then
  echo "[drust] ${MEMORY_MB} MB RAM detected; building with a single job to avoid an out-of-memory kill."
  export CARGO_BUILD_JOBS=1
fi

# cargo can sit on one crate for minutes on a small VPS; print a heartbeat.
ensure_native_build_deps
cargo build --release --manifest-path "${DRUST_ROOT}/Cargo.toml" "${DRUST_CARGO_FEATURE_ARGS[@]}" &
cargo_pid=$!
trap 'kill "$cargo_pid" 2>/dev/null' INT TERM
cargo_elapsed=0
while kill -0 "$cargo_pid" 2>/dev/null; do
  sleep 1
  cargo_elapsed=$((cargo_elapsed + 1))
  if (( cargo_elapsed % 30 == 0 )) && kill -0 "$cargo_pid" 2>/dev/null; then
    printf '[drust] Still building (%dm %02ds)...\n' $((cargo_elapsed / 60)) $((cargo_elapsed % 60))
  fi
done
cargo_status=0
wait "$cargo_pid" || cargo_status=$?
trap - INT TERM
(( cargo_status == 0 )) || { echo "[drust] cargo build failed (exit ${cargo_status})." >&2; exit "$cargo_status"; }
install -m 0755 "${DRUST_ROOT}/deploy/drust-start" /usr/local/bin/drust-start
install -m 0755 "${DRUST_ROOT}/deploy/drust-edge-gateway" /usr/local/bin/drust-edge-gateway
install -m 0755 "${DRUST_ROOT}/deploy/serverinstaller-site" /usr/local/bin/serverinstaller-site
install -d -m 0755 /usr/local/libexec
install -m 0755 "${DRUST_ROOT}/deploy/drust-ssh-askpass" /usr/local/libexec/drust-ssh-askpass
install -m 0644 "${DRUST_ROOT}/deploy/drust.service" /etc/systemd/system/drust.service
install -m 0644 "${DRUST_ROOT}/deploy/edge-gateway.service" /etc/systemd/system/edge-gateway.service
systemctl daemon-reload
systemctl enable drust.service
systemctl enable edge-gateway.service
systemctl restart drust.service
systemctl restart edge-gateway.service
systemctl --no-pager --full status drust.service
systemctl --no-pager --full status pdns.service
