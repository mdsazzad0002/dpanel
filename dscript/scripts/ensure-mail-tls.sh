#!/usr/bin/env bash
set -euo pipefail

# Gives Postfix and Dovecot a Let's Encrypt certificate for the mail hostname.
# Without it they serve the distro's snakeoil certificate, named after the
# machine (e.g. vmi123.contaboserver.net), and mail clients fail STARTTLS
# with "subjectAltName did not match". The mail hostname is not a website, so
# the edge gateway answers its HTTP-01 challenge from a shared webroot.
# Safe to run daily: certbot only renews within 30 days of expiry, and the
# mail services reload only when the certificate or their config changed.
#
# Several IPs each have their own hostname: the first host is the default
# certificate; the others are served by SNI (Postfix tls_server_sni_maps,
# Dovecot local_name). A host whose certificate cannot be issued is reported
# in MAIL_TLS_FAILED and left out, without blocking the others.
#
# Usage: ensure-mail-tls.sh <default-hostname> [<hostname> ...]
#        ensure-mail-tls.sh --status <hostname> ...

acme_root="/var/lib/dpanel/acme"
challenge_dir="${acme_root}/.well-known/acme-challenge"
dovecot_file="/etc/dovecot/conf.d/99-dpanel-ssl.conf"
sni_file="/etc/postfix/dpanel-sni"
state_file="/var/lib/dpanel/mail-tls.fingerprint"

fail() { printf '[mail-tls] %s\n' "$*" >&2; exit 1; }
valid_fqdn() { [[ "$1" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]]; }
live() { printf '/etc/letsencrypt/live/%s' "$1"; }
# Valid for 30 more days and naming the host.
cert_good() {
  openssl x509 -in "$(live "$1")/fullchain.pem" -noout -checkend $((30 * 86400)) >/dev/null 2>&1 \
    && openssl x509 -in "$(live "$1")/fullchain.pem" -noout -checkhost "$1" 2>/dev/null | grep -q 'does match'
}
cert_usable() {
  openssl x509 -in "$(live "$1")/fullchain.pem" -noout -checkend 0 >/dev/null 2>&1 && [[ -r "$(live "$1")/privkey.pem" ]]
}

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v postconf >/dev/null 2>&1 || fail 'Postfix is not installed.'

if [[ "${1:-}" == "--status" ]]; then
  shift
  first=1
  for host in "$@"; do
    host="${host,,}"
    cert="$(live "$host")/fullchain.pem"
    # Before its first issue, the default host is served whatever Postfix has (often snakeoil).
    [[ ! -r "$cert" && "$first" -eq 1 ]] && cert="$(postconf -h smtpd_tls_cert_file 2>/dev/null || true)"
    first=0
    if [[ -n "$cert" && -r "$cert" ]]; then
      printf 'MAIL_TLS_HOST=%s|%s|%s|%s\n' "$host" \
        "$(openssl x509 -in "$cert" -noout -ext subjectAltName 2>/dev/null | grep -o 'DNS:[^,]*' | cut -d: -f2 | paste -sd, -)" \
        "$(openssl x509 -in "$cert" -noout -issuer | sed 's/^issuer=//')" \
        "$(openssl x509 -in "$cert" -noout -enddate | cut -d= -f2)"
    else
      printf 'MAIL_TLS_HOST=%s|||\n' "$host"
    fi
  done
  exit 0
fi

hosts=()
for host in "$@"; do
  host="${host,,}"
  valid_fqdn "$host" || fail "Not a valid mail hostname: '${host}'."
  [[ " ${hosts[*]} " == *" ${host} "* ]] || hosts+=("$host")
done
[[ ${#hosts[@]} -gt 0 ]] || fail 'At least one mail hostname is required.'
certbot="$(command -v certbot 2>/dev/null)" || fail 'certbot is not installed.'

# Renew only when a certificate is missing, near expiry or for another name;
# otherwise certbot is not touched at all.
failed=()
for host in "${hosts[@]}"; do
  cert_good "$host" && continue
  mkdir -p "$challenge_dir"
  chmod 0755 "$acme_root" "${acme_root}/.well-known" "$challenge_dir"
  printf -v auth_script 'umask 022; printf %%s "$CERTBOT_VALIDATION" > %q/"$CERTBOT_TOKEN"' "$challenge_dir"
  printf -v cleanup_script 'rm -f -- %q/"$CERTBOT_TOKEN"' "$challenge_dir"
  email_args=(--register-unsafely-without-email)
  [[ -n "${LETSENCRYPT_EMAIL:-}" ]] && email_args=(--email "$LETSENCRYPT_EMAIL")
  if ! output="$("$certbot" certonly --non-interactive --agree-tos --manual --preferred-challenges http \
      --manual-auth-hook "/bin/sh -c $(printf '%q' "$auth_script")" \
      --manual-cleanup-hook "/bin/sh -c $(printf '%q' "$cleanup_script")" \
      --cert-name "$host" --force-renewal "${email_args[@]}" -d "$host" 2>&1)"; then
    reason="$(grep -m1 -E 'Detail:' <<< "$output" | sed 's/^ *Detail: *//')"
    printf 'MAIL_TLS_FAILED=%s|%s\n' "$host" "${reason:-certbot failed; check that the A record points here (DNS only, not proxied) and port 80 is open.}"
    failed+=("$host")
  fi
done

# Hosts with a certificate that can be served (a failed renewal keeps the old one until it expires).
ready=()
for host in "${hosts[@]}"; do
  cert_usable "$host" && ready+=("$host")
done
default="${hosts[0]}"

changed=0
if cert_usable "$default"; then
  for setting in \
    "smtpd_tls_cert_file=$(live "$default")/fullchain.pem" \
    "smtpd_tls_key_file=$(live "$default")/privkey.pem"; do
    if [[ "$(postconf -h "${setting%%=*}" 2>/dev/null)" != "${setting#*=}" ]]; then
      postconf -e "$setting"
      changed=1
    fi
  done
fi

# SNI: clients that ask for another IP's hostname get that hostname's certificate.
sni_content=""
for host in "${ready[@]}"; do
  [[ "$host" == "$default" ]] && continue
  sni_content+="${host} $(live "$host")/privkey.pem $(live "$host")/fullchain.pem"$'\n'
done
if [[ -n "$sni_content" ]]; then
  if [[ "$(cat "$sni_file" 2>/dev/null)"$'\n' != "$sni_content" ]]; then
    printf '%s' "$sni_content" > "$sni_file"
    changed=1
  fi
  if [[ "$(postconf -h tls_server_sni_maps)" != "hash:${sni_file}" ]]; then
    postconf -e "tls_server_sni_maps=hash:${sni_file}"
    changed=1
  fi
elif [[ "$(postconf -h tls_server_sni_maps)" == "hash:${sni_file}" ]]; then
  postconf -X tls_server_sni_maps
  rm -f "$sni_file" "${sni_file}.db"
  changed=1
fi

if [[ -d /etc/dovecot/conf.d ]] && command -v doveconf >/dev/null 2>&1; then
  # Dovecot 2.4 renamed ssl_cert/ssl_key and takes a plain path.
  dovecot_ssl() {
    if [[ "$(dovecot --version 2>/dev/null)" =~ ^2\.[0-3]\. ]]; then
      printf '%sssl_cert = <%s/fullchain.pem\n%sssl_key = <%s/privkey.pem\n' "$2" "$(live "$1")" "$2" "$(live "$1")"
    else
      printf '%sssl_server_cert_file = %s/fullchain.pem\n%sssl_server_key_file = %s/privkey.pem\n' "$2" "$(live "$1")" "$2" "$(live "$1")"
    fi
  }
  content="# Managed by dPanel: mail hostname certificates (ensure-mail-tls.sh)."$'\n'
  cert_usable "$default" && content+="$(dovecot_ssl "$default" '')"$'\n'
  for host in "${ready[@]}"; do
    [[ "$host" == "$default" ]] && continue
    content+="local_name ${host} {"$'\n'"$(dovecot_ssl "$host" '  ')"$'\n'"}"$'\n'
  done
  if [[ "$(cat "$dovecot_file" 2>/dev/null)"$'\n' != "$content" ]]; then
    previous="$(cat "$dovecot_file" 2>/dev/null || true)"
    printf '%s' "$content" > "$dovecot_file"
    # A file Dovecot rejects would keep it from starting on the next restart.
    if ! doveconf -n >/dev/null; then
      if [[ -n "$previous" ]]; then printf '%s\n' "$previous" > "$dovecot_file"; else rm -f "$dovecot_file"; fi
      fail 'Dovecot rejected the certificate settings; its configuration was left unchanged.'
    fi
    changed=1
  fi
fi

# Live paths stay the same across renewals, so a renewed certificate is only
# noticed through its fingerprint.
fingerprint=""
for host in "${ready[@]}"; do
  fingerprint+="${host} $(openssl x509 -in "$(live "$host")/fullchain.pem" -noout -fingerprint -sha256)"$'\n'
done
[[ "$(cat "$state_file" 2>/dev/null)"$'\n' != "$fingerprint" ]] && changed=1

if [[ "$changed" -eq 1 ]]; then
  # postmap -F embeds the files, so it must run again after every renewal.
  [[ -f "$sni_file" ]] && postmap -F "hash:${sni_file}"
  postfix check || fail 'Postfix configuration check failed.'
  systemctl reload postfix
  if [[ -f "$dovecot_file" ]] && systemctl is-active --quiet dovecot; then
    systemctl reload dovecot
  fi
  mkdir -p "$(dirname "$state_file")"
  printf '%s' "$fingerprint" > "$state_file"
fi

for host in "${ready[@]}"; do
  printf 'MAIL_TLS_READY=%s|%s\n' "$host" "$(openssl x509 -in "$(live "$host")/fullchain.pem" -noout -enddate | cut -d= -f2)"
done
printf 'MAIL_TLS_CHANGED=%s\n' "$changed"
