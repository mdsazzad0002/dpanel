#!/usr/bin/env bash
set -euo pipefail

LIKESOFT_BASE_DIR="${LIKESOFT_BASE_DIR:-/opt/likesoft}"
LIKESOFT_RUNTIME_DIR="${LIKESOFT_RUNTIME_DIR:-${LIKESOFT_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/core.sh"
# shellcheck disable=SC1091
source "${LIKESOFT_RUNTIME_DIR}/package-manager.sh"

username=""
password=""
email=""
ssh_key=""
shell_path="/bin/bash"
disable_root="false"

usage() {
  cat <<'EOF'
Usage:
  create-admin-user.sh --username <name> [--password <password>] [--email <address>] [--ssh-key <path|inline-key>] [--shell <path>] [--disable-root|--keep-root]
EOF
}

while (($#)); do
  case "$1" in
    --username)
      username="${2:-}"
      shift 2
      ;;
    --password)
      password="${2:-}"
      shift 2
      ;;
    --panel-password)
      password="${2:-}"
      shift 2
      ;;
    --email)
      email="${2:-}"
      shift 2
      ;;
    --panel-email)
      email="${2:-}"
      shift 2
      ;;
    --ssh-key)
      ssh_key="${2:-}"
      shift 2
      ;;
    --shell)
      shell_path="${2:-/bin/bash}"
      shift 2
      ;;
    --disable-root)
      disable_root="true"
      shift
      ;;
    --keep-root|--no-disable-root)
      disable_root="false"
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      if [[ -z "$username" && "$1" != -* ]]; then
        username="$1"
        shift
      else
        panel_die "Unknown option: $1"
      fi
      ;;
  esac
done

username="$(printf '%s' "$username" | xargs)"

if [[ -z "$username" ]]; then
  panel_die "Username is required."
fi

if [[ "${EUID:-$(id -u)}" -ne 0 ]]; then
  panel_die "This command must run as root."
fi

if ! [[ "$username" =~ ^[a-z_][a-z0-9_-]*$ ]]; then
  panel_die "Invalid username: ${username}"
fi

email="$(printf '%s' "$email" | xargs)"
if [[ -n "$email" && ! "$email" =~ ^[^[:space:]@]+@[^[:space:]@]+\.[^[:space:]@]+$ ]]; then
  panel_die "Invalid email address: ${email}"
fi

if ! getent passwd "$username" >/dev/null 2>&1; then
  useradd -m -s "$shell_path" "$username"
  panel_info_log "Created user ${username}."
else
  usermod -s "$shell_path" "$username"
  panel_info_log "Updated shell for existing user ${username}."
fi

if getent group sudo >/dev/null 2>&1; then
  usermod -aG sudo "$username"
fi

if getent group wheel >/dev/null 2>&1; then
  usermod -aG wheel "$username"
fi

if [[ -n "$email" ]]; then
  usermod -c "panel-email=${email}" "$username"
  panel_info_log "Panel email recorded for ${username}."
fi

if [[ -z "$password" && -z "$ssh_key" ]]; then
  password="$(panel_generate_token | cut -c1-16)"
  printf 'Generated temporary password for %s: %s\n' "$username" "$password"
  panel_warn_log "No password or SSH key provided. Generated temporary password for ${username}: ${password}"
fi

if [[ -n "$password" ]]; then
  printf '%s:%s\n' "$username" "$password" | chpasswd
  panel_info_log "Password configured for ${username}."
else
  passwd -l "$username" >/dev/null 2>&1 || true
fi

if [[ -n "$ssh_key" ]]; then
  key_data="$ssh_key"
  if [[ -f "$ssh_key" ]]; then
    key_data="$(cat "$ssh_key")"
  fi

  home_dir="$(getent passwd "$username" | awk -F: '{print $6}')"
  primary_group="$(id -gn "$username")"
  ssh_dir="${home_dir}/.ssh"
  auth_keys="${ssh_dir}/authorized_keys"

  install -d -m 0700 -o "$username" -g "$primary_group" "$ssh_dir"
  touch "$auth_keys"
  chmod 0600 "$auth_keys"
  chown "$username:$primary_group" "$auth_keys"

  if ! grep -qxF "$key_data" "$auth_keys"; then
    printf '%s\n' "$key_data" >> "$auth_keys"
  fi

  chown "$username:$primary_group" "$auth_keys"
  panel_info_log "SSH key installed for ${username}."
fi

if [[ -n "$password" || -n "$ssh_key" ]]; then
  passwd -u "$username" >/dev/null 2>&1 || true
fi

if [[ "$disable_root" == "true" ]]; then
  bash "${LIKESOFT_RUNTIME_DIR}/scripts/disable-root-login.sh"
fi

printf 'Admin user setup completed for %s\n' "$username"
panel_info_log "Admin user setup completed for ${username}."
