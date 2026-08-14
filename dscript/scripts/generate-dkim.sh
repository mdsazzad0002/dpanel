#!/usr/bin/env bash
set -euo pipefail

domain="${1:-}"
selector="${2:-default}"

fail() { printf '[dkim] %s\n' "$*" >&2; exit 1; }
valid_domain() { [[ "$1" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ && "$1" == *.* ]]; }
valid_selector() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$ ]]; }
set_directive() {
  local file="$1" key="$2" value="$3"
  if grep -Eq "^[[:space:]]*${key}[[:space:]]+" "$file" 2>/dev/null; then
    sed -i -E "s|^[[:space:]]*${key}[[:space:]]+.*|${key} ${value}|" "$file"
  else
    printf '%s %s\n' "$key" "$value" >> "$file"
  fi
}
set_postfix() {
  postconf -e "$1=$2"
}

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v opendkim-genkey >/dev/null 2>&1 || fail 'opendkim-tools is not installed.'
command -v postconf >/dev/null 2>&1 || fail 'Postfix is not installed.'

install -d -o opendkim -g opendkim -m 0750 /etc/opendkim/keys
touch /etc/opendkim/KeyTable /etc/opendkim/SigningTable /etc/opendkim/TrustedHosts
chown root:opendkim /etc/opendkim/KeyTable /etc/opendkim/SigningTable /etc/opendkim/TrustedHosts
chmod 0640 /etc/opendkim/KeyTable /etc/opendkim/SigningTable /etc/opendkim/TrustedHosts
grep -Fqx '127.0.0.1' /etc/opendkim/TrustedHosts || printf '127.0.0.1\n' >> /etc/opendkim/TrustedHosts
grep -Fqx '::1' /etc/opendkim/TrustedHosts || printf '::1\n' >> /etc/opendkim/TrustedHosts

touch /etc/opendkim.conf
set_directive /etc/opendkim.conf 'Mode' 'sv'
set_directive /etc/opendkim.conf 'Canonicalization' 'relaxed/simple'
set_directive /etc/opendkim.conf 'Socket' 'inet:8891@127.0.0.1'
set_directive /etc/opendkim.conf 'KeyTable' 'refile:/etc/opendkim/KeyTable'
set_directive /etc/opendkim.conf 'SigningTable' 'refile:/etc/opendkim/SigningTable'
set_directive /etc/opendkim.conf 'ExternalIgnoreList' 'refile:/etc/opendkim/TrustedHosts'
set_directive /etc/opendkim.conf 'InternalHosts' 'refile:/etc/opendkim/TrustedHosts'

set_postfix smtpd_milters 'inet:127.0.0.1:8891'
set_postfix non_smtpd_milters 'inet:127.0.0.1:8891'
set_postfix milter_default_action accept
set_postfix milter_protocol 6

if [[ "$domain" == '--configure-only' ]]; then
  systemctl enable opendkim postfix >/dev/null 2>&1 || true
  systemctl restart opendkim
  systemctl reload postfix
  printf '[dkim] OpenDKIM and Postfix milter configured.\n'
  exit 0
fi

domain="${domain,,}"
valid_domain "$domain" || fail 'A valid domain is required.'
valid_selector "$selector" || fail 'Invalid DKIM selector.'

key_dir="/etc/opendkim/keys/${domain}"
private_key="${key_dir}/${selector}.private"
record_name="${selector}._domainkey.${domain}"
install -d -o opendkim -g opendkim -m 0750 "$key_dir"

if [[ ! -s "$private_key" ]]; then
  opendkim-genkey -b 2048 -d "$domain" -D "$key_dir" -s "$selector"
fi
chown -R opendkim:opendkim "$key_dir"
chmod 0750 "$key_dir"
chmod 0600 "$private_key"

sed -i -E "\\|^[[:space:]]*${record_name//./\\.}[[:space:]]|d" /etc/opendkim/KeyTable
printf '%s %s:%s:%s\n' "$record_name" "$domain" "$selector" "$private_key" >> /etc/opendkim/KeyTable
sed -i -E "\\|^[[:space:]]*\\*@${domain//./\\.}[[:space:]]|d" /etc/opendkim/SigningTable
printf '*@%s %s\n' "$domain" "$record_name" >> /etc/opendkim/SigningTable

systemctl enable opendkim postfix >/dev/null 2>&1 || true
systemctl restart opendkim
systemctl reload postfix

public_key="$(openssl pkey -in "$private_key" -pubout -outform DER 2>/dev/null | base64 -w 0)"
[[ -n "$public_key" ]] || fail 'Unable to derive the public key.'
printf 'DKIM_DOMAIN=%s\nDKIM_SELECTOR=%s\nDKIM_PUBLIC_KEY=%s\n' "$domain" "$selector" "$public_key"
