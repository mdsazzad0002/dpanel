#!/usr/bin/env bash
set -euo pipefail

# Applies the panel's outbound-mail gate to Postfix. The panel decides from
# DNS (see MailOutboundGate); this script only changes Postfix to match.
#
#   --ipv6=deny       smtp transports connect over IPv4 only. Used when the
#                     server's IPv6 address has no matching PTR: Gmail rejects
#                     such mail with 550-5.7.25, while IPv4 delivery works.
#   --ipv6=allow      remove that restriction.
#   --outbound=paused hold mail for other servers in the queue
#                     (defer_transports) instead of letting it bounce. Nothing
#                     is lost: it leaves once the gate is opened again.
#   --outbound=active deliver normally and flush what was held.
#   --status          print the current state only.
#
# Prints MAIL_OUTBOUND_IPV6=, MAIL_OUTBOUND=, MAIL_OUTBOUND_CHANGED=0|1.

fail() { printf '[mail-outbound] %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v postconf >/dev/null 2>&1 || fail 'Postfix is not installed.'

ipv6=''
outbound=''
status_only=false
for arg in "$@"; do
  case "$arg" in
    --ipv6=allow|--ipv6=deny) ipv6="${arg#--ipv6=}" ;;
    --outbound=active|--outbound=paused) outbound="${arg#--outbound=}" ;;
    --status) status_only=true ;;
    *) fail "Unknown argument: ${arg}" ;;
  esac
done

# Every smtp client transport: the default one and the per-IP ones from
# configure-mail-ips.sh.
smtp_transports() {
  printf 'smtp\n'
  postconf -M 2>/dev/null | awk '$1 ~ /^dpanel-ip-/ && $2 == "unix" {print $1}'
}

current_ipv6() {
  [[ "$(postconf -Ph smtp/unix/inet_protocols 2>/dev/null || true)" == ipv4 ]] && printf 'deny' || printf 'allow'
}

current_outbound() {
  [[ " $(postconf -h defer_transports 2>/dev/null || true) " == *" smtp "* ]] && printf 'paused' || printf 'active'
}

print_state() {
  printf 'MAIL_OUTBOUND_IPV6=%s\nMAIL_OUTBOUND=%s\nMAIL_OUTBOUND_CHANGED=%s\n' "$(current_ipv6)" "$(current_outbound)" "$1"
}

if [[ "$status_only" == true ]]; then
  print_state 0
  exit 0
fi
[[ -n "$ipv6" || -n "$outbound" ]] || fail 'Nothing to do: pass --ipv6=, --outbound= or --status.'

changed=false
backed_up=false
backup() {
  [[ "$backed_up" == true ]] && return 0
  local stamp
  stamp="$(date +%Y%m%d%H%M%S)"
  cp -a /etc/postfix/main.cf "/etc/postfix/main.cf.dpanel-${stamp}.bak"
  cp -a /etc/postfix/master.cf "/etc/postfix/master.cf.dpanel-${stamp}.bak"
  backed_up=true
}

if [[ -n "$ipv6" ]]; then
  while read -r transport; do
    [[ -n "$transport" ]] || continue
    value="$(postconf -Ph "${transport}/unix/inet_protocols" 2>/dev/null || true)"
    if [[ "$ipv6" == deny && "$value" != ipv4 ]]; then
      backup
      postconf -P "${transport}/unix/inet_protocols=ipv4"
      changed=true
    elif [[ "$ipv6" == allow && -n "$value" ]]; then
      backup
      postconf -PX "${transport}/unix/inet_protocols"
      changed=true
    fi
  done < <(smtp_transports)
fi

flush=false
if [[ -n "$outbound" && "$outbound" != "$(current_outbound)" ]]; then
  backup
  if [[ "$outbound" == paused ]]; then
    postconf -e "defer_transports=$(smtp_transports | paste -sd' ')"
  else
    postconf -X defer_transports
    flush=true
  fi
  changed=true
fi

if [[ "$changed" == true ]]; then
  postfix check || fail "Postfix rejected the change; backups are in /etc/postfix/*.dpanel-*.bak."
  if systemctl is-active --quiet postfix 2>/dev/null; then
    systemctl reload postfix
  fi
fi
# Held mail is retried on Postfix's own schedule (up to an hour); send it now.
if [[ "$flush" == true ]]; then
  postqueue -f 2>/dev/null || true
fi

print_state "$([[ "$changed" == true ]] && echo 1 || echo 0)"
