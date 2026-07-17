#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

config_path="/etc/ssh/sshd_config"
backup_path="${config_path}.likesoft.backup.$(date +%Y%m%d%H%M%S)"
dropin_dir="/etc/ssh/sshd_config.d"
dropin_file="${dropin_dir}/99-likesoft-root-login.conf"

detect_ssh_service() {
  if systemctl list-unit-files ssh.service >/dev/null 2>&1; then
    printf 'ssh'
    return 0
  fi

  if systemctl list-unit-files sshd.service >/dev/null 2>&1; then
    printf 'sshd'
    return 0
  fi

  printf 'ssh'
}

validate_ssh_config() {
  if command -v sshd >/dev/null 2>&1; then
    sshd -t
    return 0
  fi

  if [[ -x /usr/sbin/sshd ]]; then
    /usr/sbin/sshd -t
    return 0
  fi

  panel_warn_log "sshd binary not found; skipping config syntax check."
}

apply_fallback_edit() {
  if grep -qE '^[[:space:]]*PermitRootLogin[[:space:]]+' "$config_path"; then
    sed -i -E 's/^[[:space:]]*#?[[:space:]]*PermitRootLogin[[:space:]]+.*/PermitRootLogin no/' "$config_path"
  else
    printf '\nPermitRootLogin no\n' >> "$config_path"
  fi
}

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  panel_die "This command must run as root."
fi

if [[ ! -f "$config_path" ]]; then
  panel_die "SSH config not found: ${config_path}"
fi

cp -a "$config_path" "$backup_path"

mkdir -p "$dropin_dir"
cat > "$dropin_file" <<'EOF'
PermitRootLogin no
EOF

apply_fallback_edit

if ! validate_ssh_config; then
  cp -a "$backup_path" "$config_path"
  rm -f "$dropin_file"
  panel_die "SSH config validation failed. Original file restored."
fi

service_name="$(detect_ssh_service)"
systemctl restart "$service_name"

printf 'Root SSH login disabled.\n'
panel_info_log "Root SSH login disabled."
