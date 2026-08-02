#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel}/runtime/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel}/runtime/package-manager.sh"; }

action="${1:-install}"

dns_install() {
  local family
  family="$(pkg_distro_family)"

  case "$family" in
    debian)
      pkg_install bind9 bind9-utils bind9-dnsutils
      if [[ "${DSCRIPT_DRY_RUN:-false}" != "true" ]]; then
        cat >/etc/bind/named.conf.options <<'EOF'
options {
        directory "/var/cache/bind";

        recursion no;
        allow-query { any; };
        allow-recursion { none; };
        listen-on port 53 { any; };
        listen-on-v6 { any; };
        dnssec-validation auto;
        auth-nxdomain no;
};
EOF
      fi
      systemctl enable bind9 >/dev/null 2>&1 || true
      systemctl restart bind9 >/dev/null 2>&1 || true
      ;;
    rpm)
      pkg_install bind bind-utils
      if [[ "${DSCRIPT_DRY_RUN:-false}" != "true" ]]; then
        cat >/etc/named.conf <<'EOF'
options {
        listen-on port 53 { any; };
        listen-on-v6 port 53 { any; };
        allow-query { any; };
        recursion no;
};
EOF
      fi
      systemctl enable named >/dev/null 2>&1 || true
      systemctl restart named >/dev/null 2>&1 || true
      ;;
    *)
      panel_warn_log "Unsupported distro family for automatic DNS package install: ${family}"
      ;;
  esac

  panel_info_log "DNS package layer installed. Remember to publish authoritative zone config and open TCP/UDP 53."
}

dns_remove() {
  panel_warn_log "DNS removal is not automated."
}

dns_update() {
  dns_install
}

case "$action" in
  install)
    dns_install
    ;;
  remove)
    dns_remove
    ;;
  update)
    dns_update
    ;;
  *)
    panel_die "Unsupported dns action: $action"
    ;;
esac
