#!/usr/bin/env bash
# User-facing command router for dscript. The implementation functions live in
# bootstrap/core.sh; this file keeps command parsing, help and diagnostics in one place.

DSCRIPT_VERSION="2.0.0"
DSCRIPT_CONTINUE_ON_ERROR="${DSCRIPT_CONTINUE_ON_ERROR:-false}"
DSCRIPT_DRY_RUN="${DSCRIPT_DRY_RUN:-false}"
DSCRIPT_ASSUME_YES="${DSCRIPT_ASSUME_YES:-false}"
DSCRIPT_VERBOSE="${DSCRIPT_VERBOSE:-false}"

dscript_usage() {
  cat <<'EOF'
dscript / dpanel - server installation and maintenance toolkit

Usage:
  dpanel [global-options] <command> [arguments]
  dpanel
      Open the interactive menu.

Processes:
  chain <install|update|verify|repair> [module,...]
      Run a complete process. Without a module list, PANEL_MODULES is used.

  module <name> <install|update|remove|reinstall|info> [arguments]
      Run one module independently.

Commands:
  install [module] [args]        Compatibility alias for chain/module install
  update [module] [args]        Compatibility alias for chain/module update
  remove <module> [args]        Remove one module
  php <action> [version|all]    Manage PHP versions
  site:create <args>            Create a website configuration scaffold
  filemanager <action> <args>   Run file-manager operations
  script <list|help|run> [...]  List or run maintenance shell scripts
  doctor [--fix]                Diagnose dscript and its host dependencies
  info                           Show server and installed-module information
  list                           List modules and maintenance scripts
  logs [install|update|agent]    Show a runtime log
  runtime refresh                Refresh installed runtime and launcher
  help [command|module|script]   Show detailed help

Global options:
  -h, --help                     Show help
  -V, --version                  Show version
  -n, --dry-run                  Explain a mutating command without running it
  -y, --yes                      Answer yes where a script supports automation
  -v, --verbose                  Enable verbose diagnostics

Examples:
  dpanel
  dpanel help
  sudo dpanel default-install
  sudo dpanel chain install
  sudo dpanel chain install apache,nginx,php,mariadb
  sudo dpanel chain update
  sudo dpanel module php install 8.3
  sudo dpanel nginx update
  dpanel script list
  sudo dpanel script run reset-web-stack --yes
  dpanel doctor

Run `dpanel help <command>` for command-specific help.
EOF
}

dscript_chain_help() {
  cat <<'EOF'
Usage: dpanel chain <install|update|verify|repair> [module,...]

install   Install modules in order. Default: apache,nginx,php,mariadb,
          supervisor,firewall,fail2ban.
update    Refresh the remote manifest and update changed modules.
verify    Run read-only repository, dependency and runtime checks.
repair    Apply safe local repairs, then verify again.

Module lists may be comma-separated or space-separated. Examples:
  sudo dpanel chain install apache,nginx,php
Useful environment variables: PANEL_MODULES, SKIP_FIREWALL, SKIP_SSL,
SKIP_TEST, PANEL_DOMAIN, PANEL_PORT and DPANEL_BASE_URL.
EOF
}

dscript_module_help() {
  local module="${1:-}"
  if [[ -z "$module" ]]; then
    cat <<'EOF'
Usage: dpanel module <name> <install|update|remove|reinstall|info> [arguments]

Use `dpanel module list` to see available modules.
Short form is also supported: `dpanel nginx update`.
EOF
    return 0
  fi

  case "$module" in
    php)
      cat <<'EOF'
PHP module:
  dpanel php versions
  dpanel php install [version|all]
  dpanel php update [version|all]
  dpanel php reinstall [version|all]
  dpanel php remove <version>
  dpanel php default <version>
EOF
      ;;
    filemanager)
      cat <<'EOF'
Filemanager module:
  dpanel filemanager create <path>...
  dpanel filemanager remove <path>...
  dpanel filemanager exists <path>...
  dpanel filemanager file-exists <path>...
  dpanel filemanager user create <username> [--home PATH] [--shell PATH]
  dpanel filemanager user ensure <username> [options]
EOF
      ;;
    *)
      printf 'Module: %s\n' "$module"
      printf 'Usage: dpanel module %s <install|update|remove|reinstall|info> [arguments]\n' "$module"
      ;;
  esac
}

dscript_script_catalog() {
  cat <<'EOF'
create-admin-user|Create an administrator through the drust execution API|<username> <password> <email> [ssh-key] [shell] [disable-root]
create-demo-site|Write a starter site page|<root-path> <domain> [php-version] [start-directory]
database-request|Create, update, delete or test a MariaDB database request|<action> <db> <user> <password> [host] [port] [charset] [collation]
disable-root-login|Disable SSH root login through the drust execution API|
fix-dpanel-root|Repair the local panel web-stack configuration|[domain] [options]
fix-panel-web-stack|Repair panel Apache/Nginx configuration through drust|<domain> [options]
fix-web-stack|Repair Apache/Nginx base configuration through drust|[options]
install-roundcube-dovecot-mysql|Install/check Roundcube and PHP MySQL integration|[--check-only] [--skip-update]
configure-phpmyadmin-signon|Create an isolated phpMyAdmin sign-on instance without changing existing config|[--root PATH]
issue-ssl|Issue a webroot certificate|<domain> <root-path> [include-www=0|1]
php-config-apply|Apply php.ini settings|--version VERSION [settings]
php-detect-config|Print effective PHP configuration|[--version VERSION]
php-detect-extensions|Print loaded PHP extensions|[--version VERSION]
php-detect-versions|Detect installed PHP versions|
reset-web-stack|Back up and reset Apache/Nginx configuration|--yes
sync-vhost|Create/update/remove a vhost through drust|<action> <domain> <root-path> [php-version] [options]
EOF
}

dscript_script_path() {
  local name="$1"
  local root script_root
  root="$(panel_repository_root)"
  if [[ -d "${root}/scripts" ]]; then
    script_root="${root}/scripts"
  else
    script_root="${DPANEL_RUNTIME_DIR}/scripts"
  fi
  case "$name" in
    configure-phpmyadmin-signon|create-admin-user|create-demo-site|database-request|disable-root-login|fix-dpanel-root|fix-panel-web-stack|fix-web-stack|install-roundcube-dovecot-mysql|issue-ssl|php-config-apply|php-detect-config|php-detect-extensions|php-detect-versions|reset-web-stack|sync-vhost)
      printf '%s/%s.sh' "$script_root" "$name"
      ;;
    *) printf '%s' '' ;;
  esac
}

dscript_script_list() {
  printf '%-34s %s\n' SCRIPT DESCRIPTION
  printf '%-34s %s\n' '----------------------------------' '-----------'
  while IFS='|' read -r name description usage; do
    printf '%-34s %s\n' "$name" "$description"
  done < <(dscript_script_catalog)
}

dscript_script_help() {
  local wanted="${1:-}"
  [[ -n "$wanted" ]] || { dscript_script_list; return 0; }

  while IFS='|' read -r name description usage; do
    if [[ "$name" == "$wanted" ]]; then
      printf '%s - %s\n' "$name" "$description"
      printf 'Usage: dpanel script run %s%s\n' "$name" "${usage:+ $usage}"
      return 0
    fi
  done < <(dscript_script_catalog)
  panel_die "Unknown maintenance script: ${wanted}. Run 'dpanel script list'."
}

dscript_is_mutating_script() {
  case "$1" in
    php-detect-config|php-detect-extensions|php-detect-versions) return 1 ;;
    *) return 0 ;;
  esac
}

dscript_run_script() {
  local name="${1:-}"
  [[ -n "$name" ]] || panel_die "Usage: dpanel script run <name> [arguments]"
  shift || true

  local path
  path="$(dscript_script_path "$name")"
  [[ -n "$path" && -f "$path" ]] || panel_die "Unknown or missing maintenance script: ${name}"

  if [[ "$DSCRIPT_DRY_RUN" == "true" ]] && dscript_is_mutating_script "$name"; then
    printf '[DRY-RUN] bash %q' "$path"
    if [[ $# -gt 0 ]]; then printf ' %q' "$@"; fi
    printf '\n'
    return 0
  fi

  if [[ "$DSCRIPT_ASSUME_YES" == "true" && "$name" == "reset-web-stack" ]]; then
    set -- --yes "$@"
  fi

  panel_info_log "Running maintenance script: ${name}"
  bash "$path" "$@"
}

dscript_manifest_path() {
  local root local_manifest cached_manifest
  root="$(panel_repository_root)"
  local_manifest="${root}/repository/manifests/modules.json"
  cached_manifest="${DPANEL_CACHE_DIR}/modules.manifest.json"

  if [[ -f "$local_manifest" ]]; then
    printf '%s' "$local_manifest"
  else
    printf '%s' "$cached_manifest"
  fi
}

dscript_manifest_modules() {
  local root manifest
  root="$(panel_repository_root)"
  manifest="$(dscript_manifest_path)"

  if command -v python3 >/dev/null 2>&1 && [[ -f "$manifest" ]]; then
    python3 - "$manifest" <<'PY'
import json
import sys
with open(sys.argv[1], encoding="utf-8") as handle:
    for name in json.load(handle):
        print(name)
PY
    return 0
  fi

  local discovered
  discovered="$(find "${root}/repository/modules" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' 2>/dev/null | sort || true)"
  if [[ -n "$discovered" ]]; then
    printf '%s\n' "$discovered"
  else
    printf '%s\n' apache nginx php redis mariadb filemanager ssl firewall fail2ban queue supervisor admin-user ssh-root-login
  fi
}

dscript_module_exists() {
  local wanted="$1" module
  while IFS= read -r module; do
    [[ "$module" == "$wanted" ]] && return 0
  done < <(dscript_manifest_modules)
  return 1
}

dscript_module_info() {
  local module="$1"
  dscript_module_exists "$module" || panel_die "Unknown module: ${module}. Run 'dpanel module list'."

  local remote installed root action actions=()
  root="$(panel_repository_root)"
  remote="$(panel_manifest_version_for "$module" "$(dscript_manifest_path)")"
  installed="$(panel_installed_manifest_value "$module")"
  for action in install update remove; do
    [[ -f "${root}/repository/modules/${module}/${action}.sh" ]] && actions+=("$action")
  done
  [[ ${#actions[@]} -eq 0 ]] && actions=(install update remove)

  printf 'Module:            %s\n' "$module"
  printf 'Repository version: %s\n' "${remote:-unknown}"
  printf 'Installed version:  %s\n' "${installed:-not recorded}"
  printf 'Actions:            %s\n' "${actions[*]:-install}"
  [[ "$module" == "php" ]] && printf 'Versions:           %s\n' "$(panel_php_versions | paste -sd, -)"
}

dscript_module_list() {
  local module version installed root
  root="$(panel_repository_root)"
  printf '%-20s %-12s %s\n' MODULE VERSION INSTALLED
  printf '%-20s %-12s %s\n' '--------------------' '------------' '---------'
  while IFS= read -r module; do
    version="$(panel_manifest_version_for "$module" "$(dscript_manifest_path)")"
    installed="$(panel_installed_manifest_value "$module")"
    printf '%-20s %-12s %s\n' "$module" "${version:-unknown}" "${installed:-no}"
  done < <(dscript_manifest_modules)
}

dscript_run_module() {
  local module="${1:-}" action="${2:-info}"
  [[ -n "$module" ]] || panel_die "Usage: dpanel module <name> <action> [arguments]"
  shift 2 || true
  dscript_module_exists "$module" || panel_die "Unknown module: ${module}. Run 'dpanel module list'."

  case "$action" in
    info|status) dscript_module_info "$module" ;;
    install|update|remove|reinstall)
      if [[ "$DSCRIPT_DRY_RUN" == "true" ]]; then
        printf '[DRY-RUN] module %s %s' "$module" "$action"
        if [[ $# -gt 0 ]]; then printf ' %q' "$@"; fi
        printf '\n'
        return 0
      fi
      if [[ "$module" == "php" ]]; then
        panel_php_manage_versions "$action" "$@"
      elif [[ "$action" == "reinstall" ]]; then
        panel_run_module "$module" remove "$@"
        panel_run_module "$module" install "$@"
      else
        panel_run_module "$module" "$action" "$@"
      fi
      ;;
    *) panel_die "Unsupported action '${action}' for ${module}. Use install, update, remove, reinstall or info." ;;
  esac
}

dscript_join_modules() {
  local joined="" item
  for item in "$@"; do
    item="${item// /,}"
    joined="${joined:+${joined},}${item}"
  done
  printf '%s' "$joined"
}

dscript_run_chain() {
  local action="${1:-install}"
  shift || true
  case "$action" in
    install)
      if [[ $# -gt 0 ]]; then
        PANEL_MODULES="$(dscript_join_modules "$@")"
        export PANEL_MODULES
      fi
      if [[ "$DSCRIPT_DRY_RUN" == "true" ]]; then
        printf '[DRY-RUN] chain install: %s\n' "${PANEL_MODULES:-apache,nginx,php,mariadb,supervisor,firewall,fail2ban}"
      else
        PANEL_BOOTSTRAP_MODE=install panel_bootstrap
      fi
      ;;
    update)
      if [[ "$DSCRIPT_DRY_RUN" == "true" ]]; then
        printf '[DRY-RUN] chain update from remote manifest\n'
      else
        PANEL_BOOTSTRAP_MODE=update panel_bootstrap
      fi
      ;;
    verify) dscript_doctor ;;
    repair) dscript_doctor --fix && dscript_doctor ;;
    *) panel_die "Unknown chain action: ${action}. Use install, update, verify or repair." ;;
  esac
}

dscript_check() {
  local label="$1" status="$2" detail="$3"
  printf '[%s] %-24s %s\n' "$status" "$label" "$detail"
  [[ "$status" != "FAIL" ]]
}

dscript_doctor() {
  local fix="false" failures=0 warnings=0 root manifest file syntax_root
  [[ "${1:-}" == "--fix" ]] && fix="true"
  root="$(panel_repository_root)"
  manifest="$(dscript_manifest_path)"
  syntax_root="$root"
  [[ -d "${root}/repository" ]] || syntax_root="$DPANEL_RUNTIME_DIR"

  printf 'dscript doctor %s\n' "$DSCRIPT_VERSION"
  printf 'Repository: %s\n\n' "$root"

  [[ -r /etc/os-release ]] && dscript_check "Operating system" OK "$(. /etc/os-release; printf '%s %s' "${ID:-unknown}" "${VERSION_ID:-unknown}")" || { dscript_check "Operating system" FAIL '/etc/os-release missing' || true; ((failures+=1)); }
  command -v bash >/dev/null 2>&1 && dscript_check "Bash" OK "$(bash --version | head -n1)" || { dscript_check "Bash" FAIL 'not found' || true; ((failures+=1)); }
  command -v curl >/dev/null 2>&1 || command -v wget >/dev/null 2>&1 && dscript_check "Downloader" OK "$(command -v curl 2>/dev/null || command -v wget)" || { dscript_check "Downloader" FAIL 'install curl or wget' || true; ((failures+=1)); }
  command -v apt-get >/dev/null 2>&1 || command -v dnf >/dev/null 2>&1 || command -v yum >/dev/null 2>&1 && dscript_check "Package manager" OK 'available' || { dscript_check "Package manager" FAIL 'apt-get, dnf or yum required' || true; ((failures+=1)); }
  command -v systemctl >/dev/null 2>&1 && dscript_check "systemd" OK "$(command -v systemctl)" || { dscript_check "systemd" WARN 'service operations may fail' || true; ((warnings+=1)); }

  if command -v python3 >/dev/null 2>&1 && python3 -m json.tool "$manifest" >/dev/null 2>&1; then
    dscript_check "Module manifest" OK "$manifest"
  elif [[ -f "$manifest" ]]; then
    dscript_check "Module manifest" WARN 'present; JSON validation unavailable/failed' || true
    ((warnings+=1))
  else
    dscript_check "Module manifest" FAIL 'missing' || true
    ((failures+=1))
  fi

  local syntax_failures=0
  while IFS= read -r -d '' file; do
    if ! bash -n "$file"; then
      ((syntax_failures+=1))
    elif [[ "$fix" == "true" && ! -x "$file" ]]; then
      chmod 0755 "$file"
    fi
  done < <(find "$syntax_root" -type f \( -name '*.sh' -o -name dpanel \) -print0)
  if ((syntax_failures == 0)); then
    dscript_check "Shell syntax" OK 'all scripts parsed'
  else
    dscript_check "Shell syntax" FAIL "${syntax_failures} script(s) failed" || true
    ((failures+=syntax_failures))
  fi

  if [[ "$fix" == "true" ]]; then
    mkdir -p "$DPANEL_BASE_DIR" "$DPANEL_RUNTIME_DIR" "$DPANEL_CACHE_DIR" "$DPANEL_LOG_DIR"
    dscript_check "Safe repairs" OK 'directories and executable bits refreshed'
  fi

  if [[ -x "${DPANEL_RUNTIME_DIR}/launcher.sh" ]]; then
    dscript_check "Installed runtime" OK "${DPANEL_RUNTIME_DIR}/launcher.sh"
  else
    dscript_check "Installed runtime" WARN "not installed; run 'sudo dpanel chain install'" || true
    ((warnings+=1))
  fi

  printf '\nResult: %d failure(s), %d warning(s)\n' "$failures" "$warnings"
  ((failures == 0))
}

dscript_show_log() {
  local name="${1:-install}"
  case "$name" in install|update|agent) ;; *) panel_die "Unknown log: ${name}" ;; esac
  local file="${DPANEL_LOG_DIR}/${name}.log"
  [[ -f "$file" ]] || panel_die "Log not found: ${file}"
  tail -n "${DSCRIPT_LOG_LINES:-100}" "$file"
}

dscript_runtime_refresh() {
  panel_require_root
  panel_ensure_dirs
  panel_install_cli_launcher
  panel_info_log "Installed dscript runtime refreshed."
}

dscript_help() {
  local topic="${1:-}"
  case "$topic" in
    '') dscript_usage ;;
    chain|install|update) dscript_chain_help ;;
    module) dscript_module_help "${2:-}" ;;
    script) dscript_script_help "${2:-}" ;;
    php|filemanager) dscript_module_help "$topic" ;;
    doctor) printf '%s\n' "Usage: dpanel doctor [--fix]" "Runs dependency, manifest, syntax and runtime checks." ;;
    *)
      if dscript_module_exists "$topic"; then dscript_module_help "$topic"; else panel_die "No help topic: ${topic}"; fi
      ;;
  esac
}

dscript_cli() {
  local command
  while (($#)); do
    case "$1" in
      -h|--help) dscript_usage; return 0 ;;
      -V|--version) printf 'dscript %s\n' "$DSCRIPT_VERSION"; return 0 ;;
      -n|--dry-run) DSCRIPT_DRY_RUN=true ;;
      -y|--yes) DSCRIPT_ASSUME_YES=true ;;
      --continue-on-error) panel_die "--continue-on-error is disabled; chain stops on the first error." ;;
      -v|--verbose) DSCRIPT_VERBOSE=true; export DSCRIPT_VERBOSE ;;
      --) shift; break ;;
      *) break ;;
    esac
    shift
  done
  command="${1:-help}"
  shift || true

  case "$command" in
    help) dscript_help "$@" ;;
    chain) dscript_run_chain "$@" ;;
    module)
      [[ "${1:-}" == "list" ]] && { dscript_module_list; return 0; }
      dscript_run_module "$@"
      ;;
    script|scripts)
      local action="${1:-list}"; shift || true
      case "$action" in list) dscript_script_list ;; help) dscript_script_help "$@" ;; run) dscript_run_script "$@" ;; *) dscript_run_script "$action" "$@" ;; esac
      ;;
    list) dscript_module_list; printf '\n'; dscript_script_list ;;
    doctor) dscript_doctor "$@" ;;
    logs) dscript_show_log "$@" ;;
    runtime)
      [[ "${1:-}" == "refresh" ]] || panel_die "Usage: dpanel runtime refresh"
      dscript_runtime_refresh
      ;;
    info) panel_info ;;
    install)
      if [[ $# -gt 0 ]] && dscript_module_exists "$1"; then local m="$1"; shift; dscript_run_module "$m" install "$@"; else dscript_run_chain install "$@"; fi
      ;;
    update)
      if [[ $# -gt 0 ]] && dscript_module_exists "$1"; then local m="$1"; shift; dscript_run_module "$m" update "$@"; else dscript_run_chain update "$@"; fi
      ;;
    remove) local m="${1:-}"; shift || true; dscript_run_module "$m" remove "$@" ;;
    php) local a="${1:-versions}"; shift || true; dscript_run_module php "$a" "$@" ;;
    site:create) [[ "$DSCRIPT_DRY_RUN" == true ]] && printf '[DRY-RUN] site:create %q\n' "$*" || panel_site_create "$@" ;;
    filemanager) panel_cli_dispatch filemanager "$@" ;;
    user:create) dscript_run_script create-admin-user "$@" ;;
    ssh:disable-root) dscript_run_script disable-root-login "$@" ;;
    *)
      if dscript_module_exists "$command"; then
        local a="${1:-info}"; shift || true; dscript_run_module "$command" "$a" "$@"
      else
        panel_die "Unknown command: ${command}. Run 'dpanel help' or 'dpanel list'."
      fi
      ;;
  esac
}
