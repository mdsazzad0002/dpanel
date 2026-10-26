#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"

# FTP accounts made in the panel are system users (drust: useradd + chpasswd)
# with the website's group, a home inside the site and /usr/sbin/nologin as
# shell. vsftpd authenticates them through PAM and jails them to that home.
# The panel shows clients "Explicit TLS" on port 21, so TLS is required.
#
# Overrides: DPANEL_FTP_PASV_ADDRESS (public IP sent in PASV replies),
# DPANEL_FTP_PASV_MIN_PORT / DPANEL_FTP_PASV_MAX_PORT.
FTP_PASV_MIN_PORT="${DPANEL_FTP_PASV_MIN_PORT:-30000}"
FTP_PASV_MAX_PORT="${DPANEL_FTP_PASV_MAX_PORT:-30100}"
FTP_NOLOGIN_SHELL="/usr/sbin/nologin"
FTP_MANAGED_MARKER="# Managed by dPanel (ftp module)."

ftp_conf_path() {
  case "$(pkg_distro_family)" in
    rpm) printf '/etc/vsftpd/vsftpd.conf' ;;
    *) printf '/etc/vsftpd.conf' ;;
  esac
}

# PAM's pam_shells only lets in users whose shell is listed in /etc/shells.
ftp_allow_nologin_shell() {
  grep -qxF "$FTP_NOLOGIN_SHELL" /etc/shells 2>/dev/null || printf '%s\n' "$FTP_NOLOGIN_SHELL" >> /etc/shells
}

# The address clients must reach for passive data. Behind NAT the server only
# knows its private IP, which vsftpd would otherwise advertise, so fall back
# to what the panel domain resolves to.
ftp_pasv_address() {
  local candidate="${DPANEL_FTP_PASV_ADDRESS:-}"
  if [[ -n "$candidate" ]]; then
    panel_is_public_ipv4 "$candidate" || panel_die "DPANEL_FTP_PASV_ADDRESS must be a public IPv4 address."
    printf '%s' "$candidate"
    return 0
  fi

  candidate="$(panel_detect_public_ipv4 || true)"
  if [[ -z "$candidate" ]]; then
    panel_load_existing_panel_env
    if [[ -n "${PANEL_DOMAIN:-}" ]]; then
      candidate="$(getent ahostsv4 "$PANEL_DOMAIN" 2>/dev/null | awk 'NR == 1 {print $1}')"
    fi
  fi
  panel_is_public_ipv4 "$candidate" && printf '%s' "$candidate"
}

# Clients connect to the panel domain, so its certificate matches; the
# snakeoil one makes every client warn.
ftp_tls_files() {
  panel_load_existing_panel_env
  local live="/etc/letsencrypt/live/${PANEL_DOMAIN:-}"
  if [[ -n "${PANEL_DOMAIN:-}" && -r "${live}/fullchain.pem" && -r "${live}/privkey.pem" ]]; then
    printf '%s %s' "${live}/fullchain.pem" "${live}/privkey.pem"
    return 0
  fi
  if [[ ! -r /etc/ssl/certs/ssl-cert-snakeoil.pem ]] && command -v make-ssl-cert >/dev/null 2>&1; then
    make-ssl-cert generate-default-snakeoil >/dev/null 2>&1 || true
  fi
  printf '%s %s' /etc/ssl/certs/ssl-cert-snakeoil.pem /etc/ssl/private/ssl-cert-snakeoil.key
}

ftp_render_config() {
  local pasv_address="$1" cert="$2" key="$3"
  cat <<CONF
${FTP_MANAGED_MARKER} Rerun "dpanel module ftp update" instead of
# editing; overrides go in DPANEL_FTP_* environment variables.
listen=YES
listen_ipv6=NO
anonymous_enable=NO
local_enable=YES
write_enable=YES
dirmessage_enable=YES
use_localtime=YES
xferlog_enable=YES
connect_from_port_20=YES
secure_chroot_dir=/var/run/vsftpd/empty
pam_service_name=vsftpd
chroot_local_user=YES
allow_writeable_chroot=YES
check_shell=NO
userlist_enable=NO
seccomp_sandbox=NO

ssl_enable=YES
rsa_cert_file=${cert}
rsa_private_key_file=${key}
force_local_data_ssl=YES
force_local_logins_ssl=YES
# vsftpd 3.0.x spells these without the second underscore; ssl_tlsv1_2 and
# friends are rejected and stop the service from starting.
ssl_tlsv1=NO
ssl_tlsv11=NO
ssl_tlsv12=YES
ssl_tlsv13=YES
require_ssl_reuse=NO
ssl_ciphers=HIGH

pasv_enable=YES
pasv_min_port=${FTP_PASV_MIN_PORT}
pasv_max_port=${FTP_PASV_MAX_PORT}
CONF
  [[ -n "$pasv_address" ]] && printf 'pasv_address=%s\n' "$pasv_address"
  return 0
}

# Start vsftpd on a spare port with the new config. A config it rejects exits
# at once; one it accepts keeps running until timeout stops it (124).
ftp_config_is_valid() {
  local candidate="$1" probe status=0
  probe="$(mktemp)"
  { cat "$candidate"; printf 'listen_port=%s\n' "$(( 40000 + RANDOM % 20000 ))"; } > "$probe"
  timeout 3 vsftpd "$probe" >/dev/null 2>&1 || status=$?
  rm -f "$probe"
  [[ "$status" -eq 124 ]]
}

ftp_open_firewall() {
  command -v ufw >/dev/null 2>&1 || return 0
  ufw allow 21/tcp comment 'FTP control' >/dev/null 2>&1 || true
  ufw allow "${FTP_PASV_MIN_PORT}:${FTP_PASV_MAX_PORT}/tcp" comment 'FTP passive data' >/dev/null 2>&1 || true
}

ftp_apply_config() {
  local conf pasv_address cert key candidate
  conf="$(ftp_conf_path)"
  pasv_address="$(ftp_pasv_address || true)"
  read -r cert key <<< "$(ftp_tls_files)"

  candidate="$(mktemp)"
  ftp_render_config "$pasv_address" "$cert" "$key" > "$candidate"
  if ! ftp_config_is_valid "$candidate"; then
    rm -f "$candidate"
    panel_die "Generated vsftpd config was rejected by vsftpd; ${conf} left unchanged."
  fi

  if [[ -f "$conf" ]] && cmp -s "$candidate" "$conf"; then
    rm -f "$candidate"
    panel_info_log "vsftpd config already up to date."
  else
    # Keep the hand-made or distro config once, before the first takeover.
    if [[ -f "$conf" ]] && ! grep -qF "$FTP_MANAGED_MARKER" "$conf" && [[ ! -f "${conf}.pre-dpanel" ]]; then
      cp -p "$conf" "${conf}.pre-dpanel"
    fi
    install -m 644 "$candidate" "$conf"
    rm -f "$candidate"
    pkg_restart_service vsftpd
    panel_info_log "vsftpd config written to ${conf} and service restarted."
  fi

  if [[ -z "$pasv_address" ]]; then
    panel_warn_log "No public IPv4 found for FTP passive mode; clients outside this network may fail to list files. Set DPANEL_FTP_PASV_ADDRESS and rerun: dpanel module ftp update"
  elif ! panel_detect_public_ipv4 >/dev/null; then
    panel_warn_log "This server is behind NAT. Forward TCP 21 and ${FTP_PASV_MIN_PORT}-${FTP_PASV_MAX_PORT} on the router to this machine, or FTP listings will fail."
  fi
}

ftp_install() {
  pkg_install vsftpd
  ftp_allow_nologin_shell
  ftp_apply_config
  pkg_enable_service vsftpd
  ftp_open_firewall
  panel_info_log "ftp installed."
}

ftp_remove() {
  pkg_remove vsftpd
  panel_info_log "ftp removed. FTP system accounts were left in place; delete them from the panel."
}

ftp_update() {
  ftp_install
}

case "$action" in
  install)
    ftp_install
    ;;
  remove)
    ftp_remove
    ;;
  update)
    ftp_update
    ;;
  *)
    panel_die "Unsupported ftp action: $action"
    ;;
esac
