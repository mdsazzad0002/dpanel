#!/usr/bin/env bash
set -euo pipefail

# Asks Dovecot whether it can find each mailbox, the same lookup LMTP does
# before it accepts a delivery. Postfix accepts mail for every active panel
# mailbox, so one Dovecot cannot find is accepted and then bounced
# ("550 5.1.1 User doesn't exist") — this finds those before mail is lost.
#
# Usage: mail-mailbox-check.sh <email>...
# Prints one line per address: "OK <email>" or "MISSING <email> <reason>".
# Exits 2 with "ERROR <reason>" when Dovecot itself cannot answer, so the
# panel never treats an auth outage as every mailbox being broken.

fail() { printf 'ERROR %s\n' "$*"; exit 2; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v doveadm >/dev/null 2>&1 || fail 'Dovecot (doveadm) is not installed.'
(( $# >= 1 && $# <= 200 )) || fail 'Pass between 1 and 200 addresses.'

for email in "$@"; do
  [[ "$email" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,63}$ ]] || fail "Invalid address: ${email}"
done

for email in "$@"; do
  output="$(doveadm user -- "$email" 2>&1)" && code=0 || code=$?
  # doveadm user: 0 found, 67 (EX_NOUSER) unknown user, anything else an error.
  case "$code" in
    0) printf 'OK %s\n' "$email" ;;
    67) printf 'MISSING %s %s\n' "$email" "$(printf '%s' "$output" | tr '\n' ' ' | cut -c1-300)" ;;
    *) fail "doveadm user ${email} failed (exit ${code}): $(printf '%s' "$output" | tr '\n' ' ' | cut -c1-300)" ;;
  esac
done
