#!/usr/bin/env bash
# Shared helper functions for dscript

dscript_require_root() {
  if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
    echo "[ERROR] This script must run as root." >&2
    exit 1
  fi
}

dscript_detect_os() {
  if [[ -r /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
    DISTRO_ID="${ID:-unknown}"
    DISTRO_VERSION="${VERSION_ID:-unknown}"
  else
    echo "[ERROR] Unable to detect OS: /etc/os-release is missing." >&2
    exit 1
  fi

  case "$DISTRO_ID" in
    ubuntu|debian)
      DISTROFamily="debian"
      ;;
    rocky|almalinux|rhel|centos|fedora)
      DISTROFamily="rpm"
      ;;
    *)
      echo "[WARN] Unsupported distro '$DISTRO_ID'; continuing with best effort."
      DISTROFamily="unknown"
      ;;
  esac
}

dscript_pkg_install() {
  if [[ "$DISTROFamily" == "debian" ]]; then
    apt-get update -qq
    apt-get install -y -qq "$@"
  elif [[ "$DISTROFamily" == "rpm" ]]; then
    if command -v dnf >/dev/null 2>&1; then
      dnf install -y -q "$@"
    else
      yum install -y -q "$@"
    fi
  else
    echo "[WARN] Unknown package manager family, attempting apt-get..."
    apt-get update -qq && apt-get install -y -qq "$@"
  fi
}

dscript_service_enable() {
  local service="$1"
  systemctl enable "$service" 2>/dev/null || true
}

dscript_service_restart() {
  local service="$1"
  systemctl restart "$service" 2>/dev/null || true
}

dscript_service_reload() {
  local service="$1"
  systemctl reload "$service" 2>/dev/null || systemctl restart "$service" 2>/dev/null || true
}

dscript_program_exists() {
  command -v "$1" >/dev/null 2>&1
}

dscript_write_file() {
  local path="$1"
  local content="$2"
  mkdir -p "$(dirname "$path")"
  printf '%s\n' "$content" > "$path"
}
