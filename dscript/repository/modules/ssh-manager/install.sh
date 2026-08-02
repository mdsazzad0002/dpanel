#!/usr/bin/env bash
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

module_action="${1:-install}"
shift || true
command_name="${1:-help}"
shift || true

require_root() { panel_require_root; }
ssh_service() { systemctl list-unit-files ssh.service >/dev/null 2>&1 && printf 'ssh' || printf 'sshd'; }
sshd_binary() {
  if command -v sshd >/dev/null 2>&1; then command -v sshd
  elif [[ -x /usr/sbin/sshd ]]; then printf '/usr/sbin/sshd\n'
  fi
}
require_ssh() { [[ -n "$(sshd_binary)" ]] || panel_die "OpenSSH is not installed. Run: sudo dpanel ssh install"; }
current_port() { "$(sshd_binary)" -T 2>/dev/null | awk '$1 == "port" { print $2; exit }' || printf '22\n'; }

valid_port() { [[ "$1" =~ ^[0-9]+$ ]] && (( 1 <= 10#$1 && 10#$1 <= 65535 )); }
valid_ip() {
  python3 - "$1" <<'PY' >/dev/null 2>&1
import ipaddress, sys
ipaddress.ip_address(sys.argv[1])
PY
}

ensure_ufw() {
  if ! command -v ufw >/dev/null 2>&1; then
    case "$(pkg_distro_family)" in
      debian) pkg_install ufw ;;
      *) panel_die "This SSH access command currently requires UFW." ;;
    esac
  fi
}

install_ssh() {
  require_root
  case "$(pkg_distro_family)" in
    debian) pkg_install openssh-server ufw ;;
    rpm) pkg_install openssh-server firewalld ;;
    *) panel_die "Unsupported Linux distribution." ;;
  esac
  systemctl enable --now "$(ssh_service)"
  panel_info_log "OpenSSH installed and enabled."
}

set_config_value() {
  require_root; require_ssh
  local key="$1" value="$2" path="/etc/ssh/sshd_config.d/99-dpanel.conf" backup="${path}.dpanel-backup"
  mkdir -p /etc/ssh/sshd_config.d
  [[ -f "$path" ]] && cp -f "$path" "$backup" || : > "$backup"
  touch "$path"
  sed -i -E "/^[[:space:]]*${key}[[:space:]]+/Id" "$path"
  printf '%s %s\n' "$key" "$value" >> "$path"
  chmod 0644 "$path"
  if ! "$(sshd_binary)" -t; then
    if [[ -s "$backup" ]]; then cp -f "$backup" "$path"; else rm -f "$path"; fi
    panel_die "Invalid SSH configuration; previous configuration restored."
  fi
  systemctl reload "$(ssh_service)"
  rm -f "$backup"
}

set_port() {
  local port="${1:-}"
  valid_port "$port" || panel_die "Usage: sudo dpanel ssh port <1-65535>"
  ensure_ufw
  ufw allow "${port}/tcp"
  set_config_value Port "$port"
  panel_info_log "SSH port changed to ${port}. Keep the old session open until a new connection succeeds."
}

access_rule() {
  local action="$1" ip="${2:-}" port="${3:-$(current_port)}"
  require_root; require_ssh; ensure_ufw
  valid_ip "$ip" || panel_die "A valid IPv4 or IPv6 address is required."
  valid_port "$port" || panel_die "Invalid SSH port."
  if [[ "$action" == "allow" ]]; then
    ufw allow from "$ip" to any port "$port" proto tcp
  else
    ufw --force delete allow from "$ip" to any port "$port" proto tcp
  fi
  panel_info_log "SSH access rule updated for ${ip} on port ${port}."
}

global_rule() {
  local action="$1" port="${2:-$(current_port)}"
  require_root; require_ssh; ensure_ufw
  valid_port "$port" || panel_die "Invalid SSH port."
  if [[ "$action" == "allow" ]]; then
    ufw allow "${port}/tcp"
    panel_info_log "Global SSH access allowed on port ${port}."
  else
    while ufw status | awk -v port="$port" '$1 ~ ("^" port "(/tcp)?$") && $2 == "ALLOW" && $3 ~ /^Anywhere/ { found=1 } END { exit !found }'; do
      ufw --force delete allow "${port}/tcp" || break
    done
    panel_info_log "Global SSH access removed on port ${port}; specific-IP rules were kept."
  fi
}

show_status() {
  if [[ -z "$(sshd_binary)" ]]; then printf 'SSH: not installed\n'; return 0; fi
  printf 'SSH service: %s\n' "$(systemctl is-active "$(ssh_service)" 2>/dev/null || true)"
  printf 'SSH enabled: %s\n' "$(systemctl is-enabled "$(ssh_service)" 2>/dev/null || true)"
  printf 'SSH port: %s\n' "$(current_port)"
  "$(sshd_binary)" -T 2>/dev/null | awk '$1 ~ /^(passwordauthentication|permitrootlogin|pubkeyauthentication)$/ { print $1 ": " $2 }' || true
}

show_help() {
  cat <<'HELP'
Simple SSH management
  sudo dpanel ssh install
  sudo dpanel ssh status
  sudo dpanel ssh enable | disable
  sudo dpanel ssh port <port>
  sudo dpanel ssh deny-global [port]
  sudo dpanel ssh allow-global [port]
  sudo dpanel ssh allow-ip <ip> [port]
  sudo dpanel ssh remove-ip <ip> [port]
  sudo dpanel ssh list-access
  sudo dpanel ssh root-login <disable|keys-only|enable>
  sudo dpanel ssh password-auth <enable|disable>
HELP
}

[[ "$module_action" == "install" ]] || panel_die "Unsupported ssh-manager module action: ${module_action}"
case "$command_name" in
  install) install_ssh ;;
  status) show_status ;;
  enable) require_root; require_ssh; systemctl enable --now "$(ssh_service)" ;;
  disable) require_root; require_ssh; systemctl disable --now "$(ssh_service)" ;;
  port) set_port "${1:-}" ;;
  allow-ip) access_rule allow "${1:-}" "${2:-}" ;;
  remove-ip) access_rule remove "${1:-}" "${2:-}" ;;
  deny-global) global_rule deny "${1:-}" ;;
  allow-global) global_rule allow "${1:-}" ;;
  list-access) ensure_ufw; ufw status numbered ;;
  root-login)
    case "${1:-}" in disable) set_config_value PermitRootLogin no ;; keys-only) set_config_value PermitRootLogin prohibit-password ;; enable) set_config_value PermitRootLogin yes ;; *) panel_die "Use: disable, keys-only, or enable" ;; esac ;;
  password-auth)
    case "${1:-}" in disable) set_config_value PasswordAuthentication no ;; enable) set_config_value PasswordAuthentication yes ;; *) panel_die "Use: enable or disable" ;; esac ;;
  help|-h|--help) show_help ;;
  *) show_help; panel_die "Unknown SSH command: ${command_name}" ;;
esac
