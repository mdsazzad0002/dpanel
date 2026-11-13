#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
[[ $# -gt 0 ]] && shift

# The web application firewall (backlog S2, spike plans/spikes/waf.md):
# libmodsecurity 3 and the OWASP Core Rule Set. drust loads the library at
# run time in its `waf-worker` processes, so nothing is linked; installing
# this module and restarting the edge gateway turns the `waf` capability on.
# Each website then chooses off, detect (the default) or block.

WAF_DIR=/etc/drust/waf
CRS_VERSION=4.30.0
CRS_SHA256=a4bb3688ef6205b64471a9ccbf0d7b024eb8edf39b97c6f6c0006e7a948f8550
CRS_URL="https://github.com/coreruleset/coreruleset/archive/refs/tags/v${CRS_VERSION}.tar.gz"

waf_install_packages() {
  case "$(pkg_distro_family)" in
    debian)
      # Renamed for the 64-bit time_t transition (Ubuntu 24.04, Debian 13).
      if pkg_package_available libmodsecurity3t64; then
        pkg_install libmodsecurity3t64
      else
        pkg_install libmodsecurity3
      fi
      ;;
    rpm)
      pkg_install epel-release || true
      pkg_package_available libmodsecurity || panel_die "libmodsecurity is not in this distro's repositories (EPEL)."
      pkg_install libmodsecurity
      ;;
    *) panel_die "Unsupported distro for the WAF: ${DISTRO:-unknown}" ;;
  esac
  ldconfig -p | grep -q 'libmodsecurity\.so' || panel_die 'libmodsecurity was installed but the library is not found.'
}

waf_install_crs() {
  local target="${WAF_DIR}/crs-${CRS_VERSION}" tmp
  if [[ ! -f "${target}/rules/REQUEST-901-INITIALIZATION.conf" ]]; then
    tmp="$(mktemp -d)"
    panel_fetch "$CRS_URL" "${tmp}/crs.tar.gz"
    echo "${CRS_SHA256}  ${tmp}/crs.tar.gz" | sha256sum -c --quiet - || { rm -rf "$tmp"; panel_die 'The OWASP CRS download does not match its checksum.'; }
    install -d -m 0750 "$target"
    tar -xzf "${tmp}/crs.tar.gz" -C "$target" --strip-components=1 --no-same-owner
    rm -rf "$tmp"
  fi
  # Local changes to the setup survive updates.
  [[ -f "${WAF_DIR}/crs-setup.conf" ]] || cp "${target}/crs-setup.conf.example" "${WAF_DIR}/crs-setup.conf"
  ln -sfn "crs-${CRS_VERSION}" "${WAF_DIR}/crs"
}

waf_write_config() {
  # Request phases only: response bodies are never inspected, there is no
  # audit log (matches go to each site's waf.log), and the engine is always
  # On here: drust decides per site whether a match blocks or only logs.
  cat > "${WAF_DIR}/modsecurity.conf" <<'CONF'
# Managed by dPanel (waf module).
SecRuleEngine On
SecRequestBodyAccess On
SecRule REQUEST_HEADERS:Content-Type "^(?:application(?:/soap\+|/)|text/)xml" "id:200000,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=XML"
SecRule REQUEST_HEADERS:Content-Type "^application/json" "id:200001,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=JSON"
SecRule REQUEST_HEADERS:Content-Type "^application/[a-z0-9.-]+[+]json" "id:200006,phase:1,t:none,t:lowercase,pass,nolog,ctl:requestBodyProcessor=JSON"
SecRequestBodyLimit 13107200
SecRequestBodyNoFilesLimit 131072
SecRequestBodyLimitAction ProcessPartial
SecRequestBodyJsonDepthLimit 512
SecArgumentsLimit 1000
SecRule &ARGS "@ge 1000" "id:200007,phase:2,t:none,log,deny,status:400,msg:'Failed to fully parse request body due to large argument count',severity:2"
SecRule REQBODY_ERROR "!@eq 0" "id:200002,phase:2,t:none,log,deny,status:400,msg:'Failed to parse request body.',logdata:'%{reqbody_error_msg}',severity:2"
SecPcreMatchLimit 1000
SecPcreMatchLimitRecursion 1000
SecResponseBodyAccess Off
SecTmpDir /tmp/
SecDataDir /tmp/
SecAuditEngine Off
SecArgumentSeparator &
SecCookieFormat 0
SecStatusEngine Off
CONF
  printf '# Managed by dPanel (waf module): what drust-s WAF workers load.\nInclude %s/modsecurity.conf\nInclude %s/crs-setup.conf\nInclude %s/crs/rules/*.conf\n' \
    "$WAF_DIR" "$WAF_DIR" "$WAF_DIR" > "${WAF_DIR}/main.conf"
  # Read by the workers as root, before they become nobody.
  chmod 0640 "${WAF_DIR}/modsecurity.conf" "${WAF_DIR}/main.conf" "${WAF_DIR}/crs-setup.conf"
}

# The binary the gateway runs: the newest one, as deploy/drust-edge-gateway picks it.
waf_drust_binary() {
  local candidate binary=/usr/local/bin/drust
  for candidate in /usr/local/bin/drust /var/www/drust/target/release/drust /var/www/drust/target/debug/drust; do
    [[ -x "$candidate" ]] || continue
    if [[ ! -x "$binary" || "$candidate" -nt "$binary" ]]; then
      binary="$candidate"
    fi
  done
  printf '%s' "$binary"
}

waf_check() {
  local drust
  drust="$(waf_drust_binary)"
  if [[ ! -x "$drust" ]]; then
    panel_warn_log "drust not found at ${drust}; the WAF rules were not test-loaded."
    return 0
  fi
  # A worker that loads the rules and answers one request proves the setup.
  if ! printf '' | timeout 20 "$drust" waf-worker "${WAF_DIR}/main.conf"; then
    panel_die "The WAF rules did not load; run '${drust} waf-worker ${WAF_DIR}/main.conf' to see why."
  fi
}

waf_restart_gateway() {
  if systemctl is-active --quiet edge-gateway.service 2>/dev/null; then
    systemctl restart edge-gateway.service
  fi
}

waf_install() {
  install -d -m 0750 /etc/drust "$WAF_DIR"
  waf_install_packages
  waf_install_crs
  waf_write_config
  waf_check
  waf_restart_gateway
  panel_info_log "WAF ready: OWASP CRS ${CRS_VERSION}. Websites are in detect-only mode until set to block (Rules > Site Protection)."
}

waf_remove() {
  rm -f "${WAF_DIR}/main.conf"
  waf_restart_gateway
  panel_info_log "WAF off: the rules stay in ${WAF_DIR}; the library package is left installed."
}

case "$action" in
  install|update) waf_install ;;
  remove) waf_remove ;;
  *) panel_die "Unsupported waf action: $action" ;;
esac
