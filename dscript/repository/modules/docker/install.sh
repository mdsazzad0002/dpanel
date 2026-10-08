#!/usr/bin/env bash
set -euo pipefail

DPANEL_BASE_DIR="${DPANEL_BASE_DIR:-/opt/dpanel}"
DPANEL_RUNTIME_DIR="${DPANEL_RUNTIME_DIR:-${DPANEL_BASE_DIR}/runtime}"

# shellcheck disable=SC1091
source "$(dirname "${BASH_SOURCE[0]}")/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR}/core.sh"; source "${DPANEL_RUNTIME_DIR}/package-manager.sh"; }

action="${1:-install}"

# Optional module (not in the default chain): the panel's Docker manager
# needs only the engine and CLI; drust talks to it as root.
docker_install() {
  if command -v docker >/dev/null 2>&1; then
    panel_info_log "Docker is already installed."
  else
    case "$(pkg_distro_family)" in
      debian)
        pkg_install docker.io
        pkg_install docker-compose-v2 || panel_warn_log "docker-compose-v2 is not available; continuing without compose."
        ;;
      rpm)
        pkg_install dnf-plugins-core
        dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo >/dev/null
        pkg_install docker-ce docker-ce-cli containerd.io docker-compose-plugin
        ;;
      *)
        panel_die "Docker install is not supported on this distribution."
        ;;
    esac
    # Only on a fresh install: restarting an existing Docker would restart its containers.
    docker_write_daemon_config
  fi

  pkg_enable_service docker
  systemctl start docker >/dev/null 2>&1 || panel_warn_log "Docker installed but its service did not start; check 'systemctl status docker'."
  panel_info_log "Docker installed."
}

# Docker keeps every container log forever by default, which fills the disk on
# a busy container. Cap them, and keep containers running across daemon
# restarts. An existing daemon.json belongs to the admin and is never touched.
docker_write_daemon_config() {
  local config=/etc/docker/daemon.json
  if [[ -e "$config" ]]; then
    panel_info_log "Keeping existing ${config}."
    return 0
  fi
  mkdir -p /etc/docker
  cat > "$config" <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "live-restore": true
}
JSON
  systemctl restart docker >/dev/null 2>&1 || true
}

docker_remove() {
  systemctl stop docker docker.socket >/dev/null 2>&1 || true
  case "$(pkg_distro_family)" in
    debian) pkg_remove docker.io docker-compose-v2 ;;
    rpm) pkg_remove docker-ce docker-ce-cli containerd.io docker-compose-plugin ;;
  esac
  # Images, containers and volumes under /var/lib/docker are kept on purpose.
  panel_info_log "Docker removed. Its data in /var/lib/docker was kept."
}

docker_update() {
  docker_install
  pkg_restart_service docker || true
  panel_info_log "Docker updated."
}

case "$action" in
  install)
    docker_install
    ;;
  remove)
    docker_remove
    ;;
  update)
    docker_update
    ;;
  *)
    panel_die "Unsupported docker action: $action"
    ;;
esac
