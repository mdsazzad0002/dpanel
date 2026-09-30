#!/usr/bin/env bash
set -euo pipefail

# Ensures Postfix announces a real FQDN in HELO/EHLO. Leaves an existing valid
# hostname untouched so an admin-chosen name is never overwritten, unless
# --set is given: the panel passes it when an admin (or the update's mail host
# detection) picks a name that resolves to this server.
#
# Usage: ensure-mail-hostname.sh <hostname> [--set]

hostname_arg="${1:-}"
force="${2:-}"

fail() { printf '[mail-hostname] %s\n' "$*" >&2; exit 1; }
valid_fqdn() {
  local name="${1,,}"
  [[ "$name" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || return 1
  [[ "$name" != *.localdomain && "$name" != *.local && "$name" != *.lan && "$name" != *.internal ]]
}

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v postconf >/dev/null 2>&1 || fail 'Postfix is not installed.'

current="$(postconf -h myhostname 2>/dev/null || true)"
if [[ "${current,,}" == "${hostname_arg,,}" ]] || { [[ "$force" != "--set" ]] && valid_fqdn "$current"; }; then
  printf 'MAIL_HOSTNAME=%s\nMAIL_HOSTNAME_CHANGED=0\n' "${current,,}"
  exit 0
fi

hostname_arg="${hostname_arg,,}"
valid_fqdn "$hostname_arg" || fail "A valid mail hostname is required (current: ${current:-unset})."

cp -a /etc/postfix/main.cf "/etc/postfix/main.cf.dpanel-$(date +%Y%m%d%H%M%S).bak"
postconf -e "myhostname=${hostname_arg}"
postfix check
systemctl reload postfix

printf 'MAIL_HOSTNAME=%s\nMAIL_HOSTNAME_CHANGED=1\n' "$hostname_arg"
