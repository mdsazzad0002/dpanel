#!/usr/bin/env bash
set -euo pipefail

# Sends each mail domain's outgoing mail from the IP the panel assigned it,
# announcing that IP's hostname in HELO. One Postfix smtp transport per extra
# IP (bind address + HELO name); a MySQL map picks the transport from the
# sender's domain. Domains on the default IP keep the plain smtp transport
# and myhostname. Passing no IPs removes every per-IP transport.
#
# Usage: configure-mail-ips.sh [<ip>=<hostname> ...]   (non-default IPs only)

fail() { printf '[mail-ips] %s\n' "$*" >&2; exit 1; }
valid_fqdn() { [[ "${1,,}" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v postconf >/dev/null 2>&1 || fail 'Postfix is not installed.'

sql_file="/etc/postfix/dpanel-sender-transport.cf"
connection_source="/etc/postfix/dpanel-virtual-domains.cf"
map="proxy:mysql:${sql_file}"

declare -A wanted=()
for pair in "$@"; do
  ip="${pair%%=*}"
  host="${pair#*=}"
  host="${host,,}"
  [[ "$ip" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]] || fail "Not an IPv4 address: ${ip}"
  valid_fqdn "$host" || fail "Not a valid hostname for ${ip}: ${host}"
  # smtp_bind_address only works for an address on one of this machine's interfaces.
  ip -4 -o addr show | grep -qw "inet ${ip}" || fail "${ip} is not assigned to any network interface on this server."
  wanted["dpanel-ip-${ip//./-}"]="${ip}=${host}"
done

changed=0
# Back up both files once, before the first edit of this run.
mark_changed() {
  if [[ "$changed" -eq 0 ]]; then
    local stamp
    stamp="$(date +%Y%m%d%H%M%S)"
    cp -a /etc/postfix/main.cf "/etc/postfix/main.cf.dpanel-${stamp}.bak"
    cp -a /etc/postfix/master.cf "/etc/postfix/master.cf.dpanel-${stamp}.bak"
  fi
  changed=1
}
for service in $(postconf -M 2>/dev/null | awk '$1 ~ /^dpanel-ip-/ && $2 == "unix" {print $1}'); do
  if [[ -z "${wanted[$service]:-}" ]]; then
    mark_changed
    postconf -MX "${service}/unix"
  fi
done

for service in "${!wanted[@]}"; do
  ip="${wanted[$service]%%=*}"
  host="${wanted[$service]#*=}"
  if [[ -z "$(postconf -M "${service}/unix" 2>/dev/null)" ]]; then
    mark_changed
    postconf -M "${service}/unix=${service} unix - - y - - smtp"
  fi
  for setting in "smtp_bind_address=${ip}" "smtp_helo_name=${host}" "syslog_name=postfix/${service}"; do
    if [[ "$(postconf -Ph "${service}/unix/${setting%%=*}" 2>/dev/null)" != "${setting#*=}" ]]; then
      mark_changed
      postconf -P "${service}/unix/${setting}"
    fi
  done
done

if [[ ${#wanted[@]} -gt 0 ]]; then
  [[ -r "$connection_source" ]] || fail "${connection_source} is missing; run the panel update first so Postfix reads mail domains from MySQL."
  # The transport name is derived from the IP so it never depends on row ids.
  content="$(grep -E '^(hosts|user|password|dbname) *=' "$connection_source")
query = SELECT CONCAT('dpanel-ip-', REPLACE(i.ip, '.', '-'), ':') FROM mail_domains d JOIN mail_ips i ON i.id = d.mail_ip_id WHERE d.domain = '%d' AND i.is_default = 0 LIMIT 1"
  if [[ "$(cat "$sql_file" 2>/dev/null)" != "$content" ]]; then
    mark_changed
    printf '%s\n' "$content" > "$sql_file"
  fi
  chown root:postfix "$sql_file" 2>/dev/null || chown root:root "$sql_file"
  chmod 0640 "$sql_file"
  if [[ "$(postconf -h sender_dependent_default_transport_maps)" != "$map" ]]; then
    mark_changed
    postconf -e "sender_dependent_default_transport_maps=${map}"
  fi
elif [[ "$(postconf -h sender_dependent_default_transport_maps)" == "$map" ]]; then
  mark_changed
  postconf -X sender_dependent_default_transport_maps
  rm -f "$sql_file"
fi

if [[ "$changed" -eq 1 ]]; then
  postfix check || fail 'Postfix configuration check failed.'
  systemctl reload postfix
fi
printf 'MAIL_IPS_TRANSPORTS=%s\nMAIL_IPS_CHANGED=%s\n' "${#wanted[@]}" "$changed"
