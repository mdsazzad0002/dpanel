#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
[[ $# -gt 0 ]] && shift

# Automatic spam filtering for hosted mailboxes:
#   Postfix --milter--> Rspamd (scores every message, adds X-Spam and
#   Authentication-Results with SPF/DKIM/DMARC) --LMTP--> Dovecot, whose
#   global Sieve script files X-Spam: Yes mail into the mailbox's Junk.
# Score 15+ is rejected at SMTP time. If Rspamd is down, Postfix accepts mail
# unscanned (milter_default_action=accept), so mail is never lost.
# OpenDKIM keeps signing outgoing mail; Rspamd's own signing stays off.

RSPAMD_MILTER="inet:127.0.0.1:11332"
SIEVE_DIR=/etc/dovecot/sieve
SIEVE_SCRIPT="${SIEVE_DIR}/dpanel-spam.sieve"
DOVECOT_SIEVE_CONF=/etc/dovecot/conf.d/91-dpanel-sieve.conf

rspamd_install_packages() {
  case "$(pkg_distro_family)" in
    debian) pkg_install rspamd dovecot-sieve ;;
    rpm)
      pkg_install epel-release || true
      pkg_package_available rspamd || panel_die "rspamd is not in this distro's repositories; add the repository from https://rspamd.com/downloads.html and rerun."
      pkg_install rspamd dovecot-pigeonhole
      ;;
    *) panel_die "Unsupported distro for Rspamd: ${DISTRO:-unknown}" ;;
  esac
}

# Writes $2 to $1 when it differs. Returns 0 when the file changed.
rspamd_write() {
  local file="$1" content="$2"
  [[ "$(cat "$file" 2>/dev/null)" == "$content" ]] && return 1
  install -d "$(dirname "$file")"
  printf '%s\n' "$content" > "$file"
  return 0
}

rspamd_configure() {
  local changed=false

  # Milter on localhost only; scans what Postfix hands it.
  rspamd_write /etc/rspamd/local.d/worker-proxy.inc 'bind_socket = "127.0.0.1:11332";
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}' && changed=true

  # No greylisting: it delays first mail from every new sender by minutes.
  rspamd_write /etc/rspamd/local.d/actions.conf 'reject = 15;
add_header = 6;
greylist = null;' && changed=true
  rspamd_write /etc/rspamd/local.d/greylist.conf 'enabled = false;' && changed=true

  # Authentication-Results carries SPF/DKIM/DMARC verdicts. Its authserv-id is
  # the MTA name Postfix passes in the milter {j} macro (myhostname), which the
  # panel trusts when it verifies senders for BIMI logos.
  rspamd_write /etc/rspamd/local.d/milter_headers.conf 'use = ["x-spamd-bar", "x-spam-level", "x-spam-status", "authentication-results"];
extended_spam_headers = false;
skip_local = false;
skip_authenticated = true;' && changed=true

  # Redis (installed by the redis module) for Bayes learning and history.
  if command -v redis-cli >/dev/null 2>&1; then
    rspamd_write /etc/rspamd/local.d/redis.conf 'servers = "127.0.0.1";' && changed=true
  fi

  rspamd_write /etc/rspamd/local.d/dkim_signing.conf 'enabled = false;' && changed=true
  rspamd_write /etc/rspamd/local.d/arc.conf 'enabled = false;' && changed=true

  rspamadm configtest >/dev/null || panel_die "Rspamd rejected its configuration; run 'rspamadm configtest' to see why."
  systemctl enable rspamd >/dev/null 2>&1 || true
  if [[ "$changed" == true ]] || ! systemctl is-active --quiet rspamd; then
    systemctl restart rspamd
  fi
}

rspamd_configure_postfix() {
  local milters changed=false setting
  milters="$(postconf -h smtpd_milters 2>/dev/null || true)"
  if [[ " ${milters//,/ } " != *" ${RSPAMD_MILTER} "* ]]; then
    # Rspamd first so OpenDKIM signs the message Rspamd already approved.
    postconf -e "smtpd_milters=${RSPAMD_MILTER}${milters:+, ${milters}}"
    changed=true
  fi
  for setting in "milter_default_action=accept" "milter_protocol=6"; do
    if [[ "$(postconf -h "${setting%%=*}" 2>/dev/null)" != "${setting#*=}" ]]; then
      postconf -e "$setting"
      changed=true
    fi
  done
  if [[ "$changed" == true ]]; then
    postfix check || panel_die 'Postfix configuration check failed.'
    systemctl reload postfix
  fi
}

rspamd_configure_dovecot() {
  local version changed=false
  version="$(dovecot --version 2>/dev/null | awk '{print $1}')"

  rspamd_write "$SIEVE_SCRIPT" '# Managed by dPanel: mail Rspamd marked as spam goes to Junk.
require ["fileinto", "mailbox"];
if header :is "X-Spam" "Yes" {
  fileinto :create "Junk";
  stop;
}' && changed=true

  if [[ "$version" == 2.4* ]]; then
    rspamd_write "$DOVECOT_SIEVE_CONF" "# Managed by dPanel: run the spam filter on every delivery.
protocol lmtp {
  mail_plugins {
    sieve = yes
  }
}
sieve_script dpanel_spam {
  type = before
  path = ${SIEVE_SCRIPT}
}" && changed=true
  else
    rspamd_write "$DOVECOT_SIEVE_CONF" "# Managed by dPanel: run the spam filter on every delivery.
protocol lmtp {
  mail_plugins = \$mail_plugins sieve
}
plugin {
  sieve_before = ${SIEVE_SCRIPT}
}" && changed=true
  fi

  # Mailboxes run as their site user, which cannot write a compiled script
  # next to the source; compile it here, readable by everyone.
  sievec "$SIEVE_SCRIPT" || panel_die "The spam Sieve script did not compile."
  chmod 0644 "$SIEVE_SCRIPT" "${SIEVE_SCRIPT%.sieve}.svbin"
  chmod 0755 "$SIEVE_DIR"

  doveconf -n >/dev/null || panel_die "Dovecot rejected its configuration; run 'doveconf -n' to see why."
  [[ "$changed" == true ]] && systemctl reload-or-restart dovecot
  return 0
}

rspamd_install() {
  command -v postconf >/dev/null 2>&1 && command -v dovecot >/dev/null 2>&1 \
    || panel_die 'Postfix and Dovecot must be installed first (mail services).'
  rspamd_install_packages
  rspamd_configure
  rspamd_configure_dovecot
  rspamd_configure_postfix
  panel_info_log "Rspamd spam filter active: spam goes to each mailbox's Junk folder, score 15+ is rejected."
}

rspamd_update() {
  if ! command -v rspamd >/dev/null 2>&1; then
    panel_info_log "rspamd not installed; skipping update. Install with: dpanel module rspamd install"
    return 0
  fi
  rspamd_install
}

rspamd_remove() {
  local milters
  milters="$(postconf -h smtpd_milters 2>/dev/null || true)"
  milters="$(printf '%s' "$milters" | tr ',' '\n' | sed 's/^ *//; s/ *$//' | grep -vxF "$RSPAMD_MILTER" | paste -sd, - | sed 's/,/, /g')"
  if [[ -n "$milters" ]]; then postconf -e "smtpd_milters=${milters}"; else postconf -X smtpd_milters; fi
  systemctl reload postfix || true
  rm -f "$DOVECOT_SIEVE_CONF"
  systemctl reload-or-restart dovecot || true
  systemctl disable --now rspamd >/dev/null 2>&1 || true
  pkg_remove_if_installed rspamd
  panel_info_log "Rspamd removed; mail is delivered unfiltered."
}

case "$action" in
  install) rspamd_install ;;
  update) rspamd_update ;;
  remove) rspamd_remove ;;
  *) panel_die "Unsupported rspamd action: $action" ;;
esac
