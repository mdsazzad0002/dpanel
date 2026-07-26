#!/usr/bin/env bash
# Shared client for maintenance operations implemented by the drust daemon.

drust_api_load_config() {
  if [[ -r /etc/drust/drust.env ]]; then
    # shellcheck disable=SC1091
    source /etc/drust/drust.env
  fi

  DRUST_API_URL="${DRUST_API_URL:-http://127.0.0.1:${DRUST_API_PORT:-9500}}"
  if [[ -z "${DRUST_API_TOKEN:-}" && -r "${PANEL_APP_DIR:-/var/www/dpanel}/.env" ]]; then
    DRUST_API_TOKEN="$(awk -F= '$1 == "SERVERPANEL_EXECUTION_API_TOKEN" {print substr($0, index($0, "=") + 1); exit}' "${PANEL_APP_DIR:-/var/www/dpanel}/.env")"
  fi
}

drust_api_die() {
  printf '[ERROR] %s\n' "$*" >&2
  printf '[HELP] Check: systemctl status drust; dpanel doctor; /etc/drust/drust.env\n' >&2
  exit 1
}

# The request body and the bearer token are secrets, and anything placed on a
# command line is readable through /proc by every local account. The body goes to
# a private temp file and the token goes through a curl config on stdin, so the
# curl process exposes neither.
drust_api_post() {
  local endpoint="$1" body="${2:-}"
  [[ -n "$body" ]] || body='{}'
  drust_api_load_config
  command -v curl >/dev/null 2>&1 || drust_api_die "curl is required to call drust."
  [[ -n "${DRUST_API_TOKEN:-}" ]] || drust_api_die "DRUST_API_TOKEN is missing. Set it or install /etc/drust/drust.env."

  local body_file status
  body_file="$(mktemp)" || drust_api_die "Unable to create a temporary request file."
  chmod 600 "$body_file"
  printf '%s' "$body" > "$body_file"

  set +e
  printf 'header = "Authorization: Bearer %s"\n' "${DRUST_API_TOKEN}" | curl \
    --config - \
    --fail-with-body --silent --show-error \
    --connect-timeout "${DRUST_CONNECT_TIMEOUT:-5}" \
    --max-time "${DRUST_REQUEST_TIMEOUT:-120}" \
    -H 'Content-Type: application/json' \
    --data-binary "@${body_file}" \
    "${DRUST_API_URL%/}${endpoint}"
  status=$?
  set -e

  rm -f "$body_file"
  [[ $status -eq 0 ]] || return 1
  printf '\n'
}

drust_require_python() {
  command -v python3 >/dev/null 2>&1 || drust_api_die "python3 is required to encode API input safely."
}
