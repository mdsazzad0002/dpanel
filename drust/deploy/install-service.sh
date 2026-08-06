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

ensure_rust_toolchain() {
  if command -v cargo >/dev/null 2>&1 && cargo --version >/dev/null 2>&1; then
    return 0
  fi

  if ! command -v rustup >/dev/null 2>&1; then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y rustup build-essential pkg-config openssl ca-certificates
  else
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y build-essential pkg-config openssl ca-certificates
  fi

  export PATH="${CARGO_HOME}/bin:${PATH}"
  if ! cargo --version >/dev/null 2>&1; then
    rustup toolchain install stable --profile minimal
    rustup default stable
  fi

  command -v cargo >/dev/null 2>&1 || {
    echo "Rust cargo is unavailable. Install Rust with rustup and rerun this script." >&2
    exit 1
  }
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
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y pdns-server pdns-backend-mysql
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
if ! command -v certbot >/dev/null 2>&1; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y certbot
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

cargo build --release --manifest-path "${DRUST_ROOT}/Cargo.toml"
install -m 0755 "${DRUST_ROOT}/deploy/drust-start" /usr/local/bin/drust-start
install -m 0755 "${DRUST_ROOT}/deploy/drust-edge-gateway" /usr/local/bin/drust-edge-gateway
install -m 0755 "${DRUST_ROOT}/deploy/serverinstaller-site" /usr/local/bin/serverinstaller-site
install -m 0644 "${DRUST_ROOT}/deploy/drust.service" /etc/systemd/system/drust.service
install -m 0644 "${DRUST_ROOT}/deploy/edge-gateway.service" /etc/systemd/system/edge-gateway.service
systemctl daemon-reload
systemctl enable drust.service
systemctl enable edge-gateway.service
systemctl restart drust.service
systemctl restart edge-gateway.service
systemctl --no-pager --full status drust.service
systemctl --no-pager --full status pdns.service
