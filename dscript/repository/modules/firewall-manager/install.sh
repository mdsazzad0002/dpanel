#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

module_action="${1:-install}"
shift || true
command_name="${1:-help}"
shift || true

require_ufw() {
  command -v ufw >/dev/null 2>&1 || panel_die "UFW is not installed. Run: sudo dpanel module firewall install"
}

valid_port() { [[ "$1" =~ ^[0-9]+$ ]] && (( 1 <= 10#$1 && 10#$1 <= 65535 )); }
valid_protocol() { [[ "$1" == "tcp" || "$1" == "udp" ]]; }
valid_ip() {
  python3 - "$1" <<'PY' >/dev/null 2>&1
import ipaddress, sys
ipaddress.ip_network(sys.argv[1], strict=False)
PY
}

show_status() {
  require_ufw
  ufw status verbose
}

show_rules() {
  require_ufw
  ufw status numbered
}

show_logs() {
  local lines="${1:-100}"
  [[ "$lines" =~ ^[0-9]+$ ]] && (( lines >= 1 && lines <= 1000 )) || panel_die "Log lines must be between 1 and 1000."
  journalctl -k -g UFW -n "$lines" --no-pager
}

manage_port() {
  local action="$1" port="${2:-}" protocol="${3:-tcp}"
  require_ufw
  valid_port "$port" || panel_die "A valid port between 1 and 65535 is required."
  valid_protocol "$protocol" || panel_die "Protocol must be tcp or udp."
  if [[ "$action" == "allow" ]]; then
    ufw allow "${port}/${protocol}"
  else
    ufw --force delete allow "${port}/${protocol}"
  fi
}

manage_ip() {
  local action="$1" address="${2:-}" port="${3:-}" protocol="${4:-tcp}"
  require_ufw
  valid_ip "$address" || panel_die "A valid IPv4/IPv6 address or CIDR subnet is required."
  [[ -z "$port" ]] || valid_port "$port" || panel_die "A valid port between 1 and 65535 is required."
  valid_protocol "$protocol" || panel_die "Protocol must be tcp or udp."
  local rule=(allow from "$address")
  [[ -z "$port" ]] || rule+=(to any port "$port" proto "$protocol")
  if [[ "$action" == "allow" ]]; then
    ufw "${rule[@]}"
  else
    ufw --force delete "${rule[@]}"
  fi
}

limit_ssh() {
  local port="${1:-22}"
  require_ufw
  valid_port "$port" || panel_die "A valid SSH port between 1 and 65535 is required."
  ufw limit "${port}/tcp"
}

delete_rule() {
  local number="${1:-}" confirmation="${2:-}"
  require_ufw
  [[ "$number" =~ ^[0-9]+$ ]] && (( number >= 1 )) || panel_die "A valid rule number is required. Run: sudo dpanel firewall rules"
  [[ "$confirmation" == "--yes" ]] || panel_die "Confirm with: sudo dpanel firewall delete-rule ${number} --yes"
  ufw --force delete "$number"
}

set_logging() {
  local level="${1:-medium}"
  require_ufw
  case "$level" in off|low|medium|high|full) ufw logging "$level" ;; *) panel_die "Logging level must be: off, low, medium, high or full" ;; esac
}

set_firewall_state() {
  local state="$1" confirmation="${2:-}"
  require_ufw
  [[ "$confirmation" == "--yes" ]] || panel_die "Confirm with: sudo dpanel firewall ${state} --yes"
  if [[ "$state" == "enable" ]]; then ufw --force enable; else ufw disable; fi
}

diagnose_firewall() {
  require_ufw
  printf '== Firewall status ==\n'
  ufw status verbose
  printf '\n== Numbered rules ==\n'
  ufw status numbered
  printf '\n== Listening sockets ==\n'
  ss -lntup 2>/dev/null || true
  printf '\n== Recent firewall logs ==\n'
  journalctl -k -g UFW -n 30 --no-pager 2>/dev/null || true
}

show_help() {
  cat <<'HELP'
Simple UFW management
  sudo dpanel firewall status
  sudo dpanel firewall rules
  sudo dpanel firewall logs [1-1000]
  sudo dpanel firewall diagnose
  sudo dpanel firewall allow-port <port> [tcp|udp]
  sudo dpanel firewall remove-port <port> [tcp|udp]
  sudo dpanel firewall allow-ip <ip-or-cidr> [port] [tcp|udp]
  sudo dpanel firewall remove-ip <ip-or-cidr> [port] [tcp|udp]
  sudo dpanel firewall limit-ssh [port]
  sudo dpanel firewall delete-rule <number> --yes
  sudo dpanel firewall logging <off|low|medium|high|full>
  sudo dpanel firewall enable|disable --yes
HELP
}

[[ "$module_action" == "install" ]] || panel_die "Unsupported firewall-manager module action: ${module_action}"
case "$command_name" in
  status) show_status ;;
  rules) show_rules ;;
  logs) show_logs "${1:-}" ;;
  diagnose) diagnose_firewall ;;
  allow-port) manage_port allow "${1:-}" "${2:-tcp}" ;;
  remove-port) manage_port remove "${1:-}" "${2:-tcp}" ;;
  allow-ip) manage_ip allow "${1:-}" "${2:-}" "${3:-tcp}" ;;
  remove-ip) manage_ip remove "${1:-}" "${2:-}" "${3:-tcp}" ;;
  limit-ssh) limit_ssh "${1:-}" ;;
  delete-rule) delete_rule "${1:-}" "${2:-}" ;;
  logging) set_logging "${1:-medium}" ;;
  enable|disable) set_firewall_state "$command_name" "${1:-}" ;;
  help|-h|--help) show_help ;;
  *) show_help; panel_die "Unknown firewall command: ${command_name}" ;;
esac
