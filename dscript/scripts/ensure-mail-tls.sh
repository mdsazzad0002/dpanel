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
# Usage: ensure-mail-tls.sh <mail-hostname>
#        ensure-mail-tls.sh --status   (the certificate Postfix serves now)

host="${1:-}"
host="${host,,}"
acme_root="/var/lib/dpanel/acme"
challenge_dir="${acme_root}/.well-known/acme-challenge"
live_dir="/etc/letsencrypt/live/${host}"
dovecot_file="/etc/dovecot/conf.d/99-dpanel-ssl.conf"
state_file="/var/lib/dpanel/mail-tls.fingerprint"

fail() { printf '[mail-tls] %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'

if [[ "$host" == "--status" ]]; then
  cert="$(postconf -h smtpd_tls_cert_file 2>/dev/null || true)"
  printf 'MAIL_TLS_CERT=%s\n' "$cert"
  [[ -n "$cert" && -r "$cert" ]] || exit 0
  printf 'MAIL_TLS_NAMES=%s\n' "$(openssl x509 -in "$cert" -noout -ext subjectAltName 2>/dev/null | grep -o 'DNS:[^,]*' | cut -d: -f2 | paste -sd, -)"
  printf 'MAIL_TLS_ISSUER=%s\n' "$(openssl x509 -in "$cert" -noout -issuer | sed 's/^issuer=//')"
  printf 'MAIL_TLS_EXPIRES=%s\n' "$(openssl x509 -in "$cert" -noout -enddate | cut -d= -f2)"
  exit 0
fi
[[ "$host" =~ ^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$ ]] || fail "A valid mail hostname is required (got '${host}')."
command -v postconf >/dev/null 2>&1 || fail 'Postfix is not installed.'
certbot="$(command -v certbot 2>/dev/null)" || fail 'certbot is not installed.'

# Renew only when the certificate is missing, expires within 30 days, or
# does not name the host; otherwise certbot is not touched at all.
if ! { openssl x509 -in "${live_dir}/fullchain.pem" -noout -checkend $((30 * 86400)) >/dev/null 2>&1 \
    && openssl x509 -in "${live_dir}/fullchain.pem" -noout -checkhost "$host" 2>/dev/null | grep -q 'does match'; }; then
  mkdir -p "$challenge_dir"
  chmod 0755 "$acme_root" "${acme_root}/.well-known" "$challenge_dir"
  printf -v auth_script 'umask 022; printf %%s "$CERTBOT_VALIDATION" > %q/"$CERTBOT_TOKEN"' "$challenge_dir"
  printf -v cleanup_script 'rm -f -- %q/"$CERTBOT_TOKEN"' "$challenge_dir"
  email_args=(--register-unsafely-without-email)
  [[ -n "${LETSENCRYPT_EMAIL:-}" ]] && email_args=(--email "$LETSENCRYPT_EMAIL")
  "$certbot" certonly --non-interactive --agree-tos --manual --preferred-challenges http \
    --manual-auth-hook "/bin/sh -c $(printf '%q' "$auth_script")" \
    --manual-cleanup-hook "/bin/sh -c $(printf '%q' "$cleanup_script")" \
    --cert-name "$host" --force-renewal "${email_args[@]}" -d "$host" \
    || fail "certbot could not issue a certificate for ${host}. Check that its A record points here (DNS only, not proxied) and port 80 is open."
fi

changed=0
for setting in \
  "smtpd_tls_cert_file=${live_dir}/fullchain.pem" \
  "smtpd_tls_key_file=${live_dir}/privkey.pem"; do
  if [[ "$(postconf -h "${setting%%=*}" 2>/dev/null)" != "${setting#*=}" ]]; then
    postconf -e "$setting"
    changed=1
  fi
done

if [[ -d /etc/dovecot/conf.d ]] && command -v doveconf >/dev/null 2>&1; then
  # Dovecot 2.4 renamed ssl_cert/ssl_key and takes a plain path.
  if [[ "$(dovecot --version 2>/dev/null)" =~ ^2\.[0-3]\. ]]; then
    settings="ssl_cert = <${live_dir}/fullchain.pem
ssl_key = <${live_dir}/privkey.pem"
  else
    settings="ssl_server_cert_file = ${live_dir}/fullchain.pem
ssl_server_key_file = ${live_dir}/privkey.pem"
  fi
  content="# Managed by dPanel: the mail hostname's certificate (ensure-mail-tls.sh).
${settings}"
  if [[ "$(cat "$dovecot_file" 2>/dev/null)" != "$content" ]]; then
    previous="$(cat "$dovecot_file" 2>/dev/null || true)"
    printf '%s\n' "$content" > "$dovecot_file"
    # A file Dovecot rejects would keep it from starting on the next restart.
    if ! doveconf -n >/dev/null; then
      if [[ -n "$previous" ]]; then printf '%s\n' "$previous" > "$dovecot_file"; else rm -f "$dovecot_file"; fi
      fail 'Dovecot rejected the certificate settings; its configuration was left unchanged.'
    fi
    changed=1
  fi
fi

# The live path stays the same across renewals, so a renewed certificate is
# only noticed through its fingerprint.
fingerprint="$(openssl x509 -in "${live_dir}/fullchain.pem" -noout -fingerprint -sha256)"
if [[ "$(cat "$state_file" 2>/dev/null)" != "$fingerprint" ]]; then
  changed=1
fi

if [[ "$changed" -eq 1 ]]; then
  postfix check || fail 'Postfix configuration check failed.'
  systemctl reload postfix
  if [[ -f "$dovecot_file" ]] && systemctl is-active --quiet dovecot; then
    systemctl reload dovecot
  fi
  printf '%s\n' "$fingerprint" > "$state_file"
fi

printf 'MAIL_TLS_HOST=%s\nMAIL_TLS_EXPIRES=%s\nMAIL_TLS_CHANGED=%s\n' \
  "$host" "$(openssl x509 -in "${live_dir}/fullchain.pem" -noout -enddate | cut -d= -f2)" "$changed"
