#!/usr/bin/env bash
set -euo pipefail

# Single-file cross-platform SSH/SCP interactive manager.
# Works on Linux/macOS and Windows (Git Bash/WSL) as long as ssh/scp exist.

PROFILE_HOST=""
PROFILE_USER=""
PROFILE_PORT="22"
PROFILE_KEY=""

info() { echo "[INFO] $*"; }
warn() { echo "[WARN] $*"; }
err() { echo "[ERROR] $*" >&2; }

has_cmd() { command -v "$1" >/dev/null 2>&1; }

ask() {
    local prompt="$1"
    local default="${2:-}"
    local input
    if [[ -n "$default" ]]; then
        read -r -p "$prompt [$default]: " input
        printf "%s\n" "${input:-$default}"
    else
        read -r -p "$prompt: " input
        printf "%s\n" "$input"
    fi
}

ask_yes_no() {
    local prompt="$1"
    local default="${2:-N}"
    local answer
    if [[ "$default" == "Y" ]]; then
        read -r -p "$prompt (Y/n): " answer
        answer="${answer:-Y}"
    else
        read -r -p "$prompt (y/N): " answer
        answer="${answer:-N}"
    fi
    [[ "${answer,,}" == "y" || "${answer,,}" == "yes" ]]
}

target() {
    printf "%s@%s" "$PROFILE_USER" "$PROFILE_HOST"
}

require_profile() {
    if [[ -z "$PROFILE_HOST" || -z "$PROFILE_USER" ]]; then
        warn "Profile not configured. Run step 1 first."
        return 1
    fi
    return 0
}

ssh_base() {
    local cmd=(ssh -p "$PROFILE_PORT")
    if [[ -n "$PROFILE_KEY" ]]; then
        cmd+=(-i "$PROFILE_KEY")
    fi
    printf "%q " "${cmd[@]}"
}

scp_base() {
    local cmd=(scp -P "$PROFILE_PORT")
    if [[ -n "$PROFILE_KEY" ]]; then
        cmd+=(-i "$PROFILE_KEY")
    fi
    printf "%q " "${cmd[@]}"
}

configure_profile() {
    echo
    echo "=== Step 1: Configure Profile ==="
    PROFILE_HOST="$(ask "Host/IP" "$PROFILE_HOST")"
    PROFILE_USER="$(ask "SSH user" "$PROFILE_USER")"
    PROFILE_PORT="$(ask "SSH port" "$PROFILE_PORT")"
    PROFILE_KEY="$(ask "Private key path (optional)" "$PROFILE_KEY")"

    if [[ -n "$PROFILE_KEY" && ! -f "$PROFILE_KEY" ]]; then
        warn "Key file not found: $PROFILE_KEY (will be ignored if invalid)"
    fi

    info "Profile saved: $(target) port=$PROFILE_PORT"
}

test_connection() {
    require_profile || return
    echo
    echo "=== Step 2: Test SSH Connection ==="
    local cmd
    cmd="$(ssh_base) $(target) \"echo SSH_OK\""
    set +e
    local output
    output="$(eval "$cmd" 2>&1)"
    local status=$?
    set -e
    if [[ $status -eq 0 && "$output" == *"SSH_OK"* ]]; then
        info "SSH connection successful."
    else
        err "SSH connection failed."
        [[ -n "$output" ]] && echo "$output"
    fi
}

remote_exists() {
    local remote_path="$1"
    local cmd
    cmd="$(ssh_base) $(target) \"test -e $(printf '%q' "$remote_path")\""
    set +e
    eval "$cmd" >/dev/null 2>&1
    local status=$?
    set -e
    [[ $status -eq 0 ]]
}

scp_send() {
    require_profile || return
    echo
    echo "=== Step 3: SCP Send (local -> remote) ==="
    local local_path remote_path cmd
    local_path="$(ask "Local file path")"
    remote_path="$(ask "Remote absolute path")"

    if [[ ! -f "$local_path" ]]; then
        err "Local file not found: $local_path"
        return
    fi
    if [[ -z "$remote_path" ]]; then
        err "Remote path is required."
        return
    fi

    if remote_exists "$remote_path"; then
        if ! ask_yes_no "Remote file exists. Overwrite?" "N"; then
            warn "Upload canceled."
            return
        fi
    fi

    cmd="$(scp_base) \"$local_path\" \"$(target):$remote_path\""
    set +e
    eval "$cmd"
    local status=$?
    set -e
    if [[ $status -eq 0 ]]; then
        info "Upload successful."
    else
        err "Upload failed."
    fi
}

scp_receive() {
    require_profile || return
    echo
    echo "=== Step 4: SCP Receive (remote -> local) ==="
    local remote_path local_path cmd
    remote_path="$(ask "Remote absolute file path")"
    local_path="$(ask "Local target path")"

    if [[ -z "$remote_path" || -z "$local_path" ]]; then
        err "Both remote and local paths are required."
        return
    fi

    if [[ -f "$local_path" ]]; then
        if ! ask_yes_no "Local file exists. Overwrite?" "N"; then
            warn "Download canceled."
            return
        fi
    fi

    local local_dir
    local_dir="$(dirname "$local_path")"
    mkdir -p "$local_dir"

    cmd="$(scp_base) \"$(target):$remote_path\" \"$local_path\""
    set +e
    eval "$cmd"
    local status=$?
    set -e
    if [[ $status -eq 0 ]]; then
        info "Download successful."
    else
        err "Download failed."
    fi
}

run_remote_command() {
    require_profile || return
    echo
    echo "=== Step 5: Run Remote Command ==="
    local command cmd
    command="$(ask "Remote command")"
    if [[ -z "$command" ]]; then
        warn "No command entered."
        return
    fi
    cmd="$(ssh_base) $(target) \"$command\""
    set +e
    eval "$cmd"
    local status=$?
    set -e
    [[ $status -ne 0 ]] && warn "Command exited with code $status"
}

service_control() {
    require_profile || return
    echo
    echo "=== Step 6: Remote Service Control ==="
    local service action command cmd
    service="$(ask "Service name (ssh/nginx/apache2/serverpanel)")"
    [[ -z "$service" ]] && { warn "Service name required."; return; }

    echo "1) status  2) start  3) stop  4) restart  5) enable  6) disable"
    action="$(ask "Action number" "1")"
    case "$action" in
        1) action="status" ;;
        2) action="start" ;;
        3) action="stop" ;;
        4) action="restart" ;;
        5) action="enable" ;;
        6) action="disable" ;;
        *) action="status" ;;
    esac

    if [[ "$action" == "status" || "$action" == "start" || "$action" == "stop" || "$action" == "restart" ]]; then
        command="(command -v systemctl >/dev/null 2>&1 && sudo systemctl $action $service) || sudo service $service $action"
    else
        command="sudo systemctl $action $service"
    fi

    cmd="$(ssh_base) $(target) \"$command\""
    set +e
    eval "$cmd"
    local status=$?
    set -e
    [[ $status -ne 0 ]] && warn "Service command exited with code $status"
}

show_menu() {
    local current="not configured"
    if [[ -n "$PROFILE_HOST" && -n "$PROFILE_USER" ]]; then
        current="$(target) (port $PROFILE_PORT)"
    fi
    echo
    echo "=============================================================="
    echo " SSH/SCP Multipurpose Single-File Manager"
    echo "=============================================================="
    echo "Current profile: $current"
    echo "1) Configure SSH profile"
    echo "2) Test SSH connection"
    echo "3) SCP send file (confirm overwrite)"
    echo "4) SCP receive file (confirm overwrite)"
    echo "5) Run remote command"
    echo "6) Remote service control"
    echo "0) Exit"
}

main() {
    if ! has_cmd ssh || ! has_cmd scp; then
        err "ssh/scp command not found. Install OpenSSH client first."
        exit 1
    fi

    while true; do
        show_menu
        local choice
        choice="$(ask "Select step" "1")"
        case "$choice" in
            1) configure_profile ;;
            2) test_connection ;;
            3) scp_send ;;
            4) scp_receive ;;
            5) run_remote_command ;;
            6) service_control ;;
            0) info "Bye."; exit 0 ;;
            *) warn "Invalid step. Choose 0-6." ;;
        esac
    done
}

main "$@"

