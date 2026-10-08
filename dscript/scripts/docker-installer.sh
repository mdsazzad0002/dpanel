#!/usr/bin/env bash
# Optional Docker add-on for dPanel. The main installer never installs Docker;
# this checks the server, shows what Docker will cost, asks, installs and then
# verifies it. Panel > Docker appears once Docker is on the server.
set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DSCRIPT_ROOT="${DSCRIPT_ROOT:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
MODULE="${DSCRIPT_ROOT}/repository/modules/docker/install.sh"
DOCS_URL="https://github.com/mdsazzad0002/dpanel/blob/main/docs/docker.md"

# Below MIN_RAM_MB the install is refused unless --force; below the
# recommended size it only warns. Measured idle cost on Ubuntu 24.04 with
# Docker 29: dockerd ~55 MB + containerd ~33 MB, ~0.1% CPU, ~330 MB of packages.
MIN_RAM_MB=1024
RECOMMENDED_RAM_MB=2048
MIN_DISK_MB=3072
RECOMMENDED_DISK_MB=10240
DATA_DIRS=(/var/lib/docker /var/lib/containerd)

action=""
assume_yes=false
force=false
purge=false

usage() {
  cat <<'EOF'
Usage: sudo dpanel docker [install|status|remove] [options]
       sudo bash docker-installer.sh [install|status|remove] [options]

  (none)    Status when Docker is installed, otherwise the install wizard
  install   Check the server, show the cost, ask, install and verify
  status    Version, service, containers, images, disk and memory use
  remove    Uninstall Docker; images, containers and volumes are kept

Options:
  -y, --yes   Do not ask (needed when there is no terminal)
  --force     Install even when the server is below the minimum RAM or disk
  --purge     With remove: also delete every image, container and volume
  -h, --help  Show this help

Docker is off by default. Docs: docs/docker.md
EOF
}

while (($#)); do
  case "$1" in
    install|status|remove) action="$1" ;;
    -y|--yes) assume_yes=true ;;
    --force) force=true ;;
    --purge) purge=true ;;
    -h|--help|help) usage; exit 0 ;;
    *) printf '[ERROR] Unknown option: %s\n' "$1" >&2; usage >&2; exit 64 ;;
  esac
  shift
done

if [[ -t 1 ]]; then
  c_ok=$'\e[32m' c_warn=$'\e[33m' c_fail=$'\e[31m' c_dim=$'\e[2m' c_bold=$'\e[1m' c_off=$'\e[0m'
else
  c_ok='' c_warn='' c_fail='' c_dim='' c_bold='' c_off=''
fi

ok() { printf '  %s[ OK ]%s %s\n' "$c_ok" "$c_off" "$*"; }
warn() { printf '  %s[WARN]%s %s\n' "$c_warn" "$c_off" "$*"; }
fail() { printf '  %s[FAIL]%s %s\n' "$c_fail" "$c_off" "$*"; }
note() { printf '  %s       %s%s\n' "$c_dim" "$*" "$c_off"; }
heading() { printf '\n%s%s%s\n' "$c_bold" "$*" "$c_off"; }
die() { printf '%s[ERROR]%s %s\n' "$c_fail" "$c_off" "$*" >&2; exit 1; }

docker_present() { command -v docker >/dev/null 2>&1; }
daemon_running() { docker info >/dev/null 2>&1; }

meminfo_mb() {
  awk -v key="$1:" '$1 == key { printf "%d", $2 / 1024; found = 1 } END { if (!found) print 0 }' /proc/meminfo 2>/dev/null || printf '0'
}

disk_free_mb() {
  local path=/var/lib
  [[ -d /var/lib/docker ]] && path=/var/lib/docker
  df -Pm "$path" 2>/dev/null | awk 'NR == 2 { print $4 }'
}

daemon_rss_mb() {
  ps -o rss= -C dockerd,containerd 2>/dev/null | awk '{ sum += $1 } END { printf "%d", sum / 1024 }'
}

distro() {
  local ID="" VERSION_ID=""
  # shellcheck disable=SC1091
  [[ -r /etc/os-release ]] && source /etc/os-release
  printf '%s %s' "${ID:-unknown}" "${VERSION_ID:-}"
}

# Reads the terminal directly, so it works under `curl ... | bash` too.
confirm() {
  local answer=""
  $assume_yes && return 0
  { : < /dev/tty; } 2>/dev/null || die "No terminal to ask on; re-run with --yes."
  read -r -p "$1 [y/N] " answer < /dev/tty || answer=""
  [[ "${answer,,}" == y || "${answer,,}" == yes ]]
}

require_root() {
  [[ "${EUID:-$(id -u)}" -eq 0 ]] || die "Run as root: sudo dpanel docker ${action:-install}"
}

# Prints a checklist and returns the number of hard failures.
preflight() {
  local failures=0 os total available free
  heading "Server check"

  os="$(distro)"
  case "${os%% *}" in
    ubuntu|debian|rocky|almalinux) ok "Operating system: ${os}" ;;
    *) fail "Operating system ${os} is not supported (Ubuntu, Debian, Rocky, AlmaLinux)"; failures=$((failures + 1)) ;;
  esac

  total="$(meminfo_mb MemTotal)"
  available="$(meminfo_mb MemAvailable)"
  if (( total < MIN_RAM_MB )); then
    fail "RAM: ${total} MB total; Docker needs at least ${MIN_RAM_MB} MB next to the panel"
    failures=$((failures + 1))
  elif (( total < RECOMMENDED_RAM_MB )); then
    warn "RAM: ${total} MB total (${available} MB free now); fine for one or two small containers"
  else
    ok "RAM: ${total} MB total (${available} MB free now)"
  fi

  free="$(disk_free_mb)"
  free="${free:-0}"
  if (( free < MIN_DISK_MB )); then
    fail "Disk: ${free} MB free on /var/lib; need at least ${MIN_DISK_MB} MB"
    failures=$((failures + 1))
  elif (( free < RECOMMENDED_DISK_MB )); then
    warn "Disk: ${free} MB free on /var/lib; images fill this up quickly"
  else
    ok "Disk: ${free} MB free on /var/lib"
  fi

  if systemctl is-active --quiet drust 2>/dev/null; then
    ok "drust service is running (the panel talks to Docker through it)"
  else
    warn "drust service is not running; Panel > Docker will not work until it is"
  fi

  if command -v curl >/dev/null 2>&1; then
    if curl -s -o /dev/null -m 8 https://registry-1.docker.io/v2/; then
      ok "Docker Hub is reachable"
    else
      warn "Docker Hub is not reachable; pulling images will fail until it is"
    fi
  fi

  if command -v ufw >/dev/null 2>&1 && ufw status 2>/dev/null | grep -q '^Status: active'; then
    ok "ufw is active; panel containers listen on 127.0.0.1 unless you mark a port public"
    note "Docker writes its own iptables rules, so ufw does not block a public container port."
  fi

  if command -v podman >/dev/null 2>&1; then
    warn "Podman is installed too; dPanel only manages Docker"
  fi

  return "$failures"
}

show_cost() {
  heading "What Docker costs"
  note "Idle: about 90 MB RAM (dockerd + containerd) and near 0% CPU."
  note "Disk: about 350 MB for the engine; every image, container and volume adds more."
  note "Logs: capped at 3 x 10 MB per container (set in /etc/docker/daemon.json)."
  note "Containers you run use their own RAM and CPU on top of this."
}

show_status() {
  heading "Docker status"
  if ! docker_present; then
    warn "Docker is not installed (off by default)"
    note "Panel > Docker stays hidden until it is installed."
    note "Install: sudo dpanel docker install"
    return 0
  fi

  if ! systemctl is-active --quiet docker 2>/dev/null && ! daemon_running; then
    fail "Docker is installed but its service is not running"
    note "Start it: sudo systemctl start docker"
    return 0
  fi
  # Only root (or the docker group) may talk to the daemon.
  if ! daemon_running; then
    ok "Docker service is running ($(daemon_rss_mb) MB RAM)"
    note "Run with sudo for containers, images and disk use."
    return 0
  fi
  ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null) is running"
  ok "Containers: $(docker ps -q | wc -l) running, $(docker ps -aq | wc -l) in total"
  ok "Images: $(docker images -q | sort -u | wc -l)"
  ok "Docker daemons use $(daemon_rss_mb) MB RAM"
  if grep -q '"max-size"' /etc/docker/daemon.json 2>/dev/null; then
    ok "Container logs are size-capped"
  else
    warn "Container logs are not size-capped; see docs/docker.md (Log limits)"
  fi
  heading "Disk use"
  docker system df 2>/dev/null | sed 's/^/  /'
}

run_install() {
  require_root
  if docker_present; then
    printf 'Docker is already installed.\n'
    show_status
    return 0
  fi

  heading "Docker for dPanel (optional add-on)"
  note "Lets admins run containers and images from Panel > Docker."
  show_cost

  local failures=0
  preflight || failures=$?
  if (( failures > 0 )); then
    if $force; then
      warn "Continuing despite ${failures} failed check(s) because of --force"
    else
      printf '\n'
      die "${failures} check(s) failed. Fix them, or re-run with --force if you are sure."
    fi
  fi

  printf '\n'
  confirm "Install Docker now?" || { printf 'Nothing changed. Docker stays off.\n'; return 0; }

  heading "Installing"
  [[ -f "$MODULE" ]] || die "Docker module is missing: ${MODULE}. Run 'sudo dpanel chain update' first."
  bash "$MODULE" install

  heading "Verifying"
  local tries=0
  until daemon_running; do
    tries=$((tries + 1))
    (( tries > 15 )) && die "Docker installed but did not start. Check: systemctl status docker"
    sleep 1
  done
  ok "Docker $(docker version --format '{{.Server.Version}}' 2>/dev/null) is running"
  ok "Docker daemons use $(daemon_rss_mb) MB RAM"

  heading "Next"
  note "Open the panel and refresh: Docker now shows in the menu (admins only)."
  note "Status any time: sudo dpanel docker status"
  note "Docs: ${DOCS_URL}"
}

run_remove() {
  require_root
  if ! docker_present; then
    printf 'Docker is not installed; nothing to remove.\n'
    return 0
  fi

  heading "Remove Docker"
  if daemon_running; then
    local running
    running="$(docker ps -q | wc -l)"
    if (( running > 0 )); then warn "${running} running container(s) will stop"; fi
  fi
  if $purge; then
    warn "--purge also deletes every image, container and volume: ${DATA_DIRS[*]}"
  else
    note "Images, containers and volumes stay in ${DATA_DIRS[*]} for a later reinstall."
  fi
  printf '\n'
  confirm "Remove Docker now?" || { printf 'Nothing changed.\n'; return 0; }

  bash "$MODULE" remove
  if $purge; then
    rm -rf "${DATA_DIRS[@]}"
    ok "Docker data deleted"
  fi
  ok "Docker removed. Panel > Docker is hidden again."
}

case "$action" in
  install) run_install ;;
  remove) run_remove ;;
  status) show_status ;;
  "")
    if docker_present; then show_status; else run_install; fi
    ;;
esac
