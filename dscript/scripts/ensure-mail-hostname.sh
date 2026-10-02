#!/usr/bin/env bash
set -euo pipefail

# Ensures Postfix announces a real FQDN in HELO/EHLO. Leaves an existing valid
# hostname untouched so an admin-chosen name is never overwritten, unless
# --set is given: the panel passes it when an admin (or the update's mail host
# detection) picks a name that resolves to this server. A name set that way
# also becomes the system hostname, so `hostname -f`, /etc/hosts and the
# Postfix HELO name all agree.
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

# Cloud images reset the hostname on boot unless cloud-init is told to keep it.
sync_system_hostname() {
  local name="$1" short="${1%%.*}"
  if [[ "$(hostname -f 2>/dev/null || hostname)" != "$name" ]]; then
    if command -v hostnamectl >/dev/null 2>&1; then
      hostnamectl set-hostname "$name" || printf '%s\n' "$name" > /etc/hostname
    else
      printf '%s\n' "$name" > /etc/hostname
    fi
    hostname "$name" 2>/dev/null || true
  fi
  if [[ -f /etc/hosts ]] && ! grep -qE "^127\.0\.1\.1[[:space:]]+${name//./\\.}([[:space:]]|$)" /etc/hosts; then
    sed -i '/^127\.0\.1\.1[[:space:]]/d' /etc/hosts
    printf '127.0.1.1\t%s %s\n' "$name" "$short" >> /etc/hosts
  fi
  if [[ -d /etc/cloud/cloud.cfg.d ]]; then
    printf 'preserve_hostname: true\n' > /etc/cloud/cloud.cfg.d/99-dpanel-hostname.cfg
  fi
}

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v postconf >/dev/null 2>&1 || fail 'Postfix is not installed.'

current="$(postconf -h myhostname 2>/dev/null || true)"
if [[ "${current,,}" == "${hostname_arg,,}" ]] || { [[ "$force" != "--set" ]] && valid_fqdn "$current"; }; then
  [[ "$force" == "--set" ]] && valid_fqdn "$current" && sync_system_hostname "${current,,}"
  printf 'MAIL_HOSTNAME=%s\nMAIL_HOSTNAME_CHANGED=0\n' "${current,,}"
  exit 0
fi

hostname_arg="${hostname_arg,,}"
valid_fqdn "$hostname_arg" || fail "A valid mail hostname is required (current: ${current:-unset})."

cp -a /etc/postfix/main.cf "/etc/postfix/main.cf.dpanel-$(date +%Y%m%d%H%M%S).bak"
postconf -e "myhostname=${hostname_arg}"
postfix check
systemctl reload postfix
sync_system_hostname "$hostname_arg"

printf 'MAIL_HOSTNAME=%s\nMAIL_HOSTNAME_CHANGED=1\n' "$hostname_arg"
