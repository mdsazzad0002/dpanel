#!/usr/bin/env bash
# Logging helpers for dscript

DSCRIPT_LOG_DIR="${DSCRIPT_LOG_DIR:-/var/www/installer/logs}"

dscript_ensure_log_dir() {
  mkdir -p "$DSCRIPT_LOG_DIR"
}

dscript_log() {
  local level="$1"
  shift
  dscript_ensure_log_dir
  printf '[%s] %s\n' "$level" "$*" | tee -a "${DSCRIPT_LOG_DIR}/install.log"
}

dscript_info() {
  dscript_log INFO "$@"
}

dscript_warn() {
  dscript_log WARN "$@"
}

dscript_error() {
  dscript_log ERROR "$@" >&2
}

dscript_die() {
  dscript_error "$@"
  exit 1
}
