#!/usr/bin/env bash
set -euo pipefail

# Sends all outgoing mail through another SMTP server (backlog M8), such as a
# provider's relay when port 25 is blocked or the IP has no reputation.
#
#   --host=smtp.example.com --port=587 [--username=user]
#                     relay through that server; the password comes from
#                     DPANEL_RELAY_PASSWORD, never the command line. TLS is
#                     required, so the login never crosses the network bare.
#   --disable         send directly again.
#   --status          print the current state only.
#
# Prints MAIL_RELAY_HOST=, MAIL_RELAY_PORT=, MAIL_RELAY_USERNAME=.

fail() { printf '[mail-relay] %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v postconf >/dev/null 2>&1 || fail 'Postfix is not installed.'

PASSWORD_MAP=/etc/postfix/dpanel-relay-passwd
host=''
port='587'
username=''
mode=''
for arg in "$@"; do
  case "$arg" in
    --host=*) host="${arg#--host=}"; mode=enable ;;
    --port=*) port="${arg#--port=}" ;;
    --username=*) username="${arg#--username=}" ;;
    --disable) mode=disable ;;
    --status) mode=status ;;
    *) fail "Unknown argument: ${arg}" ;;
  esac
done
[[ -n "$mode" ]] || fail 'Pass --host=, --disable or --status.'

print_state() {
  local relay current_host current_port current_user=''
  relay="$(postconf -h relayhost 2>/dev/null || true)"
  current_host="$(sed -E 's/^\[([^]]+)\].*/\1/; s/:[0-9]+$//' <<<"$relay")"
  current_port="$(sed -nE 's/.*:([0-9]+)$/\1/p' <<<"$relay")"
  if [[ -n "$relay" && -f "$PASSWORD_MAP" ]]; then
    current_user="$(awk 'NR==1 {split($2, a, ":"); print a[1]}' "$PASSWORD_MAP")"
  fi
  printf 'MAIL_RELAY_HOST=%s\nMAIL_RELAY_PORT=%s\nMAIL_RELAY_USERNAME=%s\n' "$current_host" "$current_port" "$current_user"
}

if [[ "$mode" == status ]]; then
  print_state
  exit 0
fi

set_postconf() {
  local key="${1%%=*}" value="${1#*=}"
  [[ "$(postconf -h "$key" 2>/dev/null)" == "$value" ]] || postconf -e "${key}=${value}"
}

if [[ "$mode" == disable ]]; then
  set_postconf 'relayhost='
  set_postconf 'smtp_sasl_auth_enable=no'
  # Direct delivery must still reach servers without TLS.
  set_postconf 'smtp_tls_security_level=may'
  set_postconf 'smtp_tls_wrappermode=no'
  rm -f "$PASSWORD_MAP" "${PASSWORD_MAP}.db" "${PASSWORD_MAP}.lmdb"
else
  [[ "$host" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || fail "Invalid relay host: ${host}"
  [[ "$port" =~ ^[0-9]{1,5}$ ]] && (( port >= 1 && port <= 65535 )) || fail "Invalid relay port: ${port}"
  [[ -z "$username" || "$username" =~ ^[^[:space:]:]{1,255}$ ]] || fail 'The username cannot hold spaces or colons.'
  password="${DPANEL_RELAY_PASSWORD:-}"
  [[ "$password" != *$'\n'* ]] || fail 'The password cannot hold a line break.'

  relay="[${host}]:${port}"
  if [[ -n "$username" ]]; then
    [[ -n "$password" ]] || fail 'A username needs a password.'
    map_type="$(postconf -h default_database_type 2>/dev/null || echo hash)"
    umask 077
    printf '%s %s:%s\n' "$relay" "$username" "$password" > "$PASSWORD_MAP"
    chmod 0600 "$PASSWORD_MAP"
    postmap "${map_type}:${PASSWORD_MAP}"
    chmod 0600 "${PASSWORD_MAP}".* 2>/dev/null || true
    set_postconf "smtp_sasl_password_maps=${map_type}:${PASSWORD_MAP}"
    set_postconf 'smtp_sasl_auth_enable=yes'
    set_postconf 'smtp_sasl_security_options=noanonymous'
    set_postconf 'smtp_sasl_tls_security_options=noanonymous'
  else
    set_postconf 'smtp_sasl_auth_enable=no'
    rm -f "$PASSWORD_MAP" "${PASSWORD_MAP}.db" "${PASSWORD_MAP}.lmdb"
  fi
  set_postconf "relayhost=${relay}"
  # Port 465 is TLS from the first byte; 587 and 25 upgrade with STARTTLS.
  if [[ "$port" == 465 ]]; then
    set_postconf 'smtp_tls_wrappermode=yes'
  else
    set_postconf 'smtp_tls_wrappermode=no'
  fi
  set_postconf 'smtp_tls_security_level=encrypt'
fi

postfix check || fail "Postfix rejected the change; run 'postfix check' to see why."
systemctl is-active --quiet postfix 2>/dev/null && systemctl reload postfix
print_state
