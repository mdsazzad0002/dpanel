#!/usr/bin/env bash
set -euo pipefail

BASE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DOCS_DIR="$BASE_DIR/docs"
MEMORY_DIR="$BASE_DIR/memory"
LOGS_DIR="$BASE_DIR/logs"
STATE_DIR="$BASE_DIR/state"

log_info() { echo "[INFO] $*"; }
log_error() { echo "[ERROR] $*" >&2; }

ensure_base_files() {
  mkdir -p "$DOCS_DIR" "$MEMORY_DIR" "$LOGS_DIR" "$STATE_DIR"
  touch "$LOGS_DIR/latest-install.md" "$LOGS_DIR/latest-update.md" "$LOGS_DIR/latest-health-check.md" "$LOGS_DIR/latest-ai-feedback.md"
  touch "$STATE_DIR/completed-steps.env" "$STATE_DIR/last-failed-step.env" "$STATE_DIR/install-state.env"
}

step_completed() {
  local step="$1"
  grep -q "^${step}=done$" "$STATE_DIR/completed-steps.env" 2>/dev/null
}

mark_step_completed() {
  local step="$1"
  grep -v "^${step}=" "$STATE_DIR/completed-steps.env" > "$STATE_DIR/completed-steps.env.tmp" 2>/dev/null || true
  mv "$STATE_DIR/completed-steps.env.tmp" "$STATE_DIR/completed-steps.env"
  echo "${step}=done" >> "$STATE_DIR/completed-steps.env"
}

set_last_failed_step() {
  local step="$1"
  printf 'LAST_FAILED_STEP=%s\nFAILED_AT=%s\n' "$step" "$(date -u '+%Y-%m-%d %H:%M:%S UTC')" > "$STATE_DIR/last-failed-step.env"
}

clear_last_failed_step() {
  : > "$STATE_DIR/last-failed-step.env"
}

write_next_action() {
  local msg="$1"
  {
    echo "# NEXT ACTION"
    echo "$msg"
  } > "$DOCS_DIR/NEXT_ACTION.md"
}

load_install_context() {
  [[ -f "$DOCS_DIR/INSTALL_CONTEXT.md" ]] && log_info "Read INSTALL_CONTEXT.md"
}

load_server_memory() {
  [[ -f "$MEMORY_DIR/SERVER_MEMORY.md" ]] && log_info "Read SERVER_MEMORY.md"
  [[ -f "$DOCS_DIR/COMMAND_HISTORY.md" ]] && log_info "Read COMMAND_HISTORY.md"
  [[ -f "$STATE_DIR/last-failed-step.env" ]] && log_info "Read last-failed-step.env"
  [[ -f "$DOCS_DIR/RESOLVED_ERRORS.md" ]] && log_info "Read RESOLVED_ERRORS.md"
}

check_error_signatures() { [[ -f "$MEMORY_DIR/ERROR_SIGNATURES.md" ]] && log_info "Checked ERROR_SIGNATURES.md"; }
check_resolved_errors() { [[ -f "$DOCS_DIR/RESOLVED_ERRORS.md" ]] && log_info "Checked RESOLVED_ERRORS.md"; }
check_success_commands() { [[ -f "$MEMORY_DIR/SUCCESS_COMMANDS.md" ]] && log_info "Checked SUCCESS_COMMANDS.md"; }
check_failed_commands() { [[ -f "$MEMORY_DIR/FAILED_COMMANDS.md" ]] && log_info "Checked FAILED_COMMANDS.md"; }

