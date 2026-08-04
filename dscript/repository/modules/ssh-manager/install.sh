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
current_port() {
  local config port
  config="$("$(sshd_binary)" -T 2>/dev/null)" || panel_die "Unable to read the effective SSH port. Specify the port explicitly."
  port="$(awk '$1 == "port" { print $2; exit }' <<< "$config")"
  valid_port "$port" || panel_die "Unable to detect a valid SSH port. Specify the port explicitly."
  printf '%s\n' "$port"
}

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

enable_ssh() {
  require_root; require_ssh
  systemctl enable --now "$(ssh_service)"
}

disable_ssh() {
  require_root; require_ssh
  if systemctl list-unit-files ssh.socket >/dev/null 2>&1; then
    systemctl disable --now ssh.socket
  fi
  systemctl disable --now "$(ssh_service)"
}

set_config_value() {
  require_root; require_ssh
  local key="$1" value="$2"
  local path="/etc/ssh/sshd_config.d/99-dpanel.conf"
  local backup="${path}.dpanel-backup"
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
  if systemctl list-unit-files ssh.socket >/dev/null 2>&1; then
    mkdir -p /etc/systemd/system/ssh.socket.d
    printf '[Socket]\nListenStream=\nListenStream=0.0.0.0:%s\nListenStream=[::]:%s\n' "$port" "$port" > /etc/systemd/system/ssh.socket.d/99-dpanel-port.conf
    systemctl daemon-reload
    systemctl restart ssh.socket
    systemctl restart "$(ssh_service)"
  fi
  panel_info_log "SSH port changed to ${port}. Keep the old session open until a new connection succeeds."
}

access_rule() {
  local action="$1" ip="${2:-}" port="${3:-}"
  require_root; require_ssh; ensure_ufw
  [[ -n "$port" ]] || port="$(current_port)"
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
  local action="$1" port="${2:-}"
  require_root; require_ssh; ensure_ufw
  [[ -n "$port" ]] || port="$(current_port)"
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

list_ssh_users() {
  local uid_min ssh_config password_auth pubkey_auth username password uid gid gecos home shell status auth
  uid_min="$(awk '$1 == "UID_MIN" { print $2; exit }' /etc/login.defs 2>/dev/null)"
  [[ "$uid_min" =~ ^[0-9]+$ ]] || uid_min=1000
  ssh_config="$("$(sshd_binary)" -T 2>/dev/null)" || panel_die "Unable to read the effective SSH configuration."
  password_auth="$(awk '$1 == "passwordauthentication" { print $2; exit }' <<< "$ssh_config")"
  pubkey_auth="$(awk '$1 == "pubkeyauthentication" { print $2; exit }' <<< "$ssh_config")"

  printf '%-24s %-8s %-12s %-32s %s\n' USERNAME UID AUTH HOME SHELL
  while IFS=: read -r username password uid gid gecos home shell; do
    (( uid >= uid_min && uid != 65534 )) || continue
    [[ "$shell" != *nologin && "$shell" != */false ]] || continue

    auth=""
    status="$(passwd -S "$username" 2>/dev/null | awk '{ print $2 }')"
    [[ "$password_auth" == "yes" && "$status" == "P" ]] && auth="password"
    if [[ "$pubkey_auth" == "yes" && -s "$home/.ssh/authorized_keys" ]]; then
      auth="${auth:+${auth},}key"
    fi
    [[ -n "$auth" ]] || continue

    printf '%-24s %-8s %-12s %-32s %s\n' "$username" "$uid" "$auth" "$home" "$shell"
  done < <(getent passwd)
}

remove_ssh_user() {
  local username="${1:-}" confirmation="${2:-}" uid uid_min
  require_root
  [[ "$username" =~ ^[a-z_][a-z0-9_-]*[$]?$ ]] || panel_die "Usage: sudo dpanel ssh remove-user <username> --yes"
  [[ "$confirmation" == "--yes" ]] || panel_die "User deletion removes the home directory. Confirm with: sudo dpanel ssh remove-user ${username} --yes"
  [[ "$username" != "root" ]] || panel_die "The root account cannot be removed."
  [[ "$username" != "${SUDO_USER:-}" ]] || panel_die "The user running this command cannot be removed."
  getent passwd "$username" >/dev/null || panel_die "User does not exist: ${username}"

  uid="$(id -u "$username")"
  uid_min="$(awk '$1 == "UID_MIN" { print $2; exit }' /etc/login.defs 2>/dev/null)"
  [[ "$uid_min" =~ ^[0-9]+$ ]] || uid_min=1000
  (( uid >= uid_min && uid != 65534 )) || panel_die "System users cannot be removed with this command."
  if who | awk -v user="$username" '$1 == user { found=1 } END { exit !found }'; then
    panel_die "User ${username} has an active login session. Sign it out before removal."
  fi

  userdel --remove "$username"
  panel_info_log "SSH user ${username} and its home directory were removed."
}

show_sessions() {
  printf 'Active login sessions:\n'
  who || true
  printf '\nEstablished SSH connections:\n'
  ss -tnp 2>/dev/null | awk 'NR == 1 || /sshd/' || true
}

diagnose_ssh() {
  local service
  require_root; require_ssh
  service="$(ssh_service)"
  printf '== Effective SSH settings ==\n'
  show_status
  printf '\n== Configuration validation ==\n'
  if "$(sshd_binary)" -t; then printf 'sshd configuration: valid\n'; fi
  printf '\n== Listening sockets ==\n'
  ss -ltnp 2>/dev/null | awk 'NR == 1 || /sshd/' || true
  printf '\n== Active connections ==\n'
  ss -tnp 2>/dev/null | awk 'NR == 1 || /sshd/' || true
  printf '\n== Recent service logs ==\n'
  journalctl -u "$service" -n 30 --no-pager 2>/dev/null || true
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
  sudo dpanel ssh list-users
  sudo dpanel ssh remove-user <username> --yes
  sudo dpanel ssh sessions
  sudo dpanel ssh diagnose
HELP
}

[[ "$module_action" == "install" ]] || panel_die "Unsupported ssh-manager module action: ${module_action}"
case "$command_name" in
  install) install_ssh ;;
  status) show_status ;;
  enable) enable_ssh ;;
  disable) disable_ssh ;;
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
  list-users) list_ssh_users ;;
  remove-user) remove_ssh_user "${1:-}" "${2:-}" ;;
  sessions) show_sessions ;;
  diagnose) diagnose_ssh ;;
  help|-h|--help) show_help ;;
  *) show_help; panel_die "Unknown SSH command: ${command_name}" ;;
esac
