#!/usr/bin/env bash
# Authoritative DNS: PowerDNS serving zones straight from the panel database
# (gmysql backend). The panel writes the `domains` and `records` tables;
# PowerDNS answers from them, so no zone files or reloads are involved.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel}/runtime/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel}/runtime/package-manager.sh"; }

action="${1:-install}"

dns_conf_dir() {
  if [[ -d /etc/powerdns ]]; then
    printf '%s' /etc/powerdns
  else
    printf '%s' /etc/pdns
  fi
}

# Both the Debian and RPM packages ship pdns.service.
dns_service() {
  printf '%s' pdns
}

# Reads DB_KEY from the panel .env, preferring PDNS_DB_KEY when set.
dns_env_value() {
  local env_file="$1" key="$2" value
  value="$(sed -n "s/^PDNS_DB_${key}=//p" "$env_file" | tail -n1 | tr -d '"'"'")"
  if [[ -z "$value" ]]; then
    value="$(sed -n "s/^DB_${key}=//p" "$env_file" | tail -n1 | tr -d '"'"'")"
  fi
  printf '%s' "$value"
}

# Same address the drust service installer picks (drust/deploy/
# install-service.sh), so either installer leaves the same listener.
# 0.0.0.0 would collide with systemd-resolved on 127.0.0.53:53.
dns_listen_address() {
  local address
  address="$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "src") {print $(i + 1); exit}}')"
  [[ -n "$address" ]] || address="$(hostname -I 2>/dev/null | awk '{print $1}')"
  printf '%s' "$address"
}

# Old dPanel versions installed BIND, which also wants port 53.
dns_stop_bind() {
  local unit
  for unit in bind9 named; do
    if systemctl is-active --quiet "$unit" 2>/dev/null; then
      panel_warn_log "Stopping ${unit}: PowerDNS serves DNS for the panel."
    fi
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
  done
  systemctl mask named.service >/dev/null 2>&1 || true
}

# Writes gmysql.conf and listener.conf exactly as the drust service
# installer does; keep the two in step.
dns_write_config() {
  local env_file conf_dir include_dir db_host db_port db_name db_user db_password listen file
  env_file="$(panel_resolve_app_env_file)"
  [[ -n "$env_file" && -f "$env_file" ]] || panel_die "dPanel .env not found; install the panel database before the dns module."

  db_host="$(dns_env_value "$env_file" HOST)"
  db_port="$(dns_env_value "$env_file" PORT)"
  db_name="$(dns_env_value "$env_file" DATABASE)"
  db_user="$(dns_env_value "$env_file" USERNAME)"
  db_password="$(dns_env_value "$env_file" PASSWORD)"
  [[ -n "$db_name" && -n "$db_user" ]] || panel_die "DB_DATABASE / DB_USERNAME missing in ${env_file}."
  # PowerDNS reads everything after # as a comment, even inside a value.
  [[ "$db_password" != *'#'* ]] || panel_die "The database password contains '#', which PowerDNS cannot read. Set PDNS_DB_USERNAME/PDNS_DB_PASSWORD in ${env_file} to a user without it."
  listen="${DNS_LISTEN_ADDRESS:-$(dns_listen_address)}"
  [[ -n "$listen" ]] || panel_die "Could not detect this server's IPv4 address; set DNS_LISTEN_ADDRESS."

  conf_dir="$(dns_conf_dir)"
  include_dir="${conf_dir}/pdns.d"
  mkdir -p "$include_dir"
  if [[ -f "${conf_dir}/pdns.conf" ]] && ! grep -q '^include-dir=' "${conf_dir}/pdns.conf"; then
    printf '\ninclude-dir=%s\n' "$include_dir" >>"${conf_dir}/pdns.conf"
  fi

  # Any other backend or listener (the distro's bind.conf, hand-made files)
  # would launch twice or fight over the port; park it.
  for file in "$include_dir"/*.conf; do
    [[ -f "$file" ]] || continue
    [[ "$file" == "${include_dir}/gmysql.conf" || "$file" == "${include_dir}/listener.conf" ]] && continue
    if grep -qE '^\s*(launch|local-address|local-port)' "$file"; then
      panel_warn_log "Disabling ${file}; dPanel manages the PowerDNS backend and listener."
      mv "$file" "${file}.disabled"
    fi
  done

  install -m 640 /dev/null "${include_dir}/gmysql.conf"
  printf 'launch+=gmysql\ngmysql-host=%s\ngmysql-port=%s\ngmysql-dbname=%s\ngmysql-user=%s\ngmysql-password=%s\ngmysql-dnssec=no\n' \
    "${db_host:-127.0.0.1}" "${db_port:-3306}" "$db_name" "$db_user" "$db_password" >"${include_dir}/gmysql.conf"
  chown root:pdns "${include_dir}/gmysql.conf" 2>/dev/null || true
  printf 'local-address=%s\nlocal-port=53\n' "$listen" >"${include_dir}/listener.conf"
  chmod 644 "${include_dir}/listener.conf"
  panel_info_log "PowerDNS reads zones from ${db_name} on ${db_host:-127.0.0.1}; listening on ${listen}:53."
}

dns_restart() {
  local service
  service="$(dns_service)"
  pdns_server --config=check >/dev/null || panel_die "PowerDNS rejected its configuration; run pdns_server --config=check for details."
  systemctl reset-failed "$service" >/dev/null 2>&1 || true
  systemctl enable "$service" >/dev/null 2>&1 || true
  if ! systemctl restart "$service"; then
    journalctl -u "$service" -n 20 --no-pager 2>/dev/null || true
    panel_die "PowerDNS failed to start; see the log above."
  fi
  sleep 1
  if ! systemctl is-active --quiet "$service"; then
    journalctl -u "$service" -n 20 --no-pager 2>/dev/null || true
    panel_die "PowerDNS stopped right after starting; see the log above."
  fi
}

dns_install() {
  case "$(pkg_distro_family)" in
    debian) pkg_install pdns-server pdns-backend-mysql ;;
    rpm) pkg_install pdns pdns-backend-mysql ;;
    *) panel_die "Unsupported distro family for the dns module: $(pkg_distro_family)" ;;
  esac
  if [[ "${DSCRIPT_DRY_RUN:-false}" == "true" ]]; then
    return 0
  fi

  dns_stop_bind
  dns_write_config
  dns_restart
  panel_info_log "PowerDNS is serving panel zones. Open TCP/UDP 53 and point your nameservers' glue records at this server."
}

dns_remove() {
  systemctl disable --now "$(dns_service)" >/dev/null 2>&1 || true
  panel_info_log "PowerDNS stopped. Packages and zone data were kept."
}

case "$action" in
  install|update)
    dns_install
    ;;
  remove)
    dns_remove
    ;;
  *)
    panel_die "Unsupported dns action: $action"
    ;;
esac
