#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run as root: sudo $0" >&2
  exit 1
fi

DRUST_ROOT="/var/www/drust"
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

ensure_rust_toolchain
if ! command -v certbot >/dev/null 2>&1; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y certbot
fi
install -d -m 0750 /etc/drust
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
LARAVEL_ENV="${DRUST_ROOT}/../dpanel/.env"
if [[ -f "${LARAVEL_ENV}" ]]; then
  if grep -q '^SERVERPANEL_EXECUTION_API_TOKEN=' "${LARAVEL_ENV}"; then
    sed -i "s#^SERVERPANEL_EXECUTION_API_TOKEN=.*#SERVERPANEL_EXECUTION_API_TOKEN=${DRUST_API_TOKEN}#" "${LARAVEL_ENV}"
  else
    printf '\nSERVERPANEL_EXECUTION_API_TOKEN=%s\n' "${DRUST_API_TOKEN}" >> "${LARAVEL_ENV}"
  fi
  if ! grep -q '^SERVERPANEL_FILEMANAGER_API_URL=' "${LARAVEL_ENV}"; then
    printf 'SERVERPANEL_FILEMANAGER_API_URL=http://127.0.0.1:%s/api/v1/filemanager\n' "${DRUST_API_PORT:-9500}" >> "${LARAVEL_ENV}"
  fi
  if [[ -x "${DRUST_ROOT}/../dpanel/artisan" ]]; then
    (cd "${DRUST_ROOT}/../dpanel" && php artisan config:clear >/dev/null 2>&1 || true)
  fi
fi

cargo build --release --manifest-path "${DRUST_ROOT}/Cargo.toml"
install -m 0755 "${DRUST_ROOT}/deploy/drust-start" /usr/local/bin/drust-start
install -m 0644 "${DRUST_ROOT}/deploy/drust.service" /etc/systemd/system/drust.service
systemctl daemon-reload
systemctl enable drust.service
systemctl restart drust.service
systemctl --no-pager --full status drust.service
