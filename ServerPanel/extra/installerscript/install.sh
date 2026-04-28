#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPTS_DIR="$BASE_DIR/scripts"
DOCS_DIR="$BASE_DIR/docs"
STATE_DIR="$BASE_DIR/state"

source "$SCRIPTS_DIR/helpers.sh"
source "$SCRIPTS_DIR/00-load-context.sh"

USER_REQUEST="${*:-fresh install this server}"
INSTALL_MODE="${INSTALL_MODE:-fresh}"

if [[ "$USER_REQUEST" =~ complete\ install\ server ]]; then
  INSTALL_MODE="resume"
fi

log_info "User request: $USER_REQUEST"
log_info "Install mode: $INSTALL_MODE"

ensure_base_files
load_install_context
load_server_memory

# Mandatory memory-first checks.
check_error_signatures
check_resolved_errors
check_success_commands
check_failed_commands

STEPS=(
  "01-system-check.sh"
  "02-package-check.sh"
  "03-project-setup.sh"
  "04-env-setup.sh"
  "05-database-setup.sh"
  "06-webserver-detect.sh"
  "07-supervisor-setup.sh"
  "08-final-check.sh"
)

for step in "${STEPS[@]}"; do
  if step_completed "$step"; then
    log_info "Skipping completed step: $step"
    continue
  fi

  log_info "Running step: $step"
  if bash "$SCRIPTS_DIR/$step"; then
    mark_step_completed "$step"
    clear_last_failed_step
  else
    set_last_failed_step "$step"
    write_next_action "Fix $step failure and rerun: ./install.sh \"complete install server\""
    log_error "Step failed: $step"
    exit 1
  fi
done

write_next_action "Installation complete. Next: ./health-check.sh"
log_info "Install flow complete."
