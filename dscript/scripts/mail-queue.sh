#!/usr/bin/env bash
set -euo pipefail

# The Postfix mail queue for the panel (backlog P5).
#
# Listing stays with the Mail Health page (postqueue -p needs no root).
#
#   --delete=ID ...   delete these messages for good (postsuper -d).
#   --retry           try to deliver everything waiting now (postqueue -f).
#
# Prints MAIL_QUEUE_DELETED=<count> after --delete.

fail() { printf '[mail-queue] %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail 'Run as root.'
command -v postqueue >/dev/null 2>&1 || fail 'Postfix is not installed.'

mode=''
ids=()
for arg in "$@"; do
  case "$arg" in
    --retry) mode=retry ;;
    --delete=*)
      mode=delete
      id="${arg#--delete=}"
      # Short (hex) and long (base 52 with a "z") queue ids.
      [[ "$id" =~ ^[0-9A-Za-z]{5,20}$ ]] || fail "Invalid queue id: ${id}"
      ids+=("$id")
      ;;
    *) fail "Unknown argument: ${arg}" ;;
  esac
done
[[ -n "$mode" ]] || fail 'Pass --delete=ID or --retry.'

case "$mode" in
  retry)
    postqueue -f
    ;;
  delete)
    # postsuper reports "postsuper: Deleted: N messages" on stderr.
    output="$(printf '%s\n' "${ids[@]}" | postsuper -d - 2>&1)" || fail "postsuper failed: ${output}"
    deleted="$(sed -nE 's/.*Deleted: ([0-9]+) messages?.*/\1/p' <<<"$output" | tail -n 1)"
    printf 'MAIL_QUEUE_DELETED=%s\n' "${deleted:-0}"
    ;;
esac
