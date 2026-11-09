#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "${SCRIPT_DIR}/../_load.sh" 2>/dev/null || { source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/core.sh"; source "${DPANEL_RUNTIME_DIR:-/opt/dpanel/runtime}/package-manager.sh"; }

action="${1:-install}"
[[ $# -gt 0 ]] && shift

# Automatic spam filtering for hosted mailboxes:
#   Postfix --milter--> Rspamd (scores every message, adds X-Spam and
#   Authentication-Results with SPF/DKIM/DMARC) --LMTP--> Dovecot, whose
#   global Sieve script files X-Spam: Yes mail into the mailbox's Junk.
# Score 15+ is rejected at SMTP time. If Rspamd is down, Postfix accepts mail
# unscanned (milter_default_action=accept), so mail is never lost.
# OpenDKIM keeps signing outgoing mail; Rspamd's own signing stays off.

RSPAMD_MILTER="inet:127.0.0.1:11332"
LIMIT_PLUGIN=/etc/rspamd/lua.local.d/dpanel_ratelimit.lua
LIMIT_MAP_DIR=/etc/rspamd/dpanel
SIEVE_DIR=/etc/dovecot/sieve
SIEVE_SCRIPT="${SIEVE_DIR}/dpanel-spam.sieve"
DOVECOT_SIEVE_CONF=/etc/dovecot/conf.d/91-dpanel-sieve.conf

rspamd_install_packages() {
  case "$(pkg_distro_family)" in
    debian) pkg_install rspamd dovecot-sieve ;;
    rpm)
      pkg_install epel-release || true
      pkg_package_available rspamd || panel_die "rspamd is not in this distro's repositories; add the repository from https://rspamd.com/downloads.html and rerun."
      pkg_install rspamd dovecot-pigeonhole
      ;;
    *) panel_die "Unsupported distro for Rspamd: ${DISTRO:-unknown}" ;;
  esac
}

# Writes $2 to $1 when it differs. Returns 0 when the file changed.
rspamd_write() {
  local file="$1" content="$2"
  [[ "$(cat "$file" 2>/dev/null)" == "$content" ]] && return 1
  install -d "$(dirname "$file")"
  printf '%s\n' "$content" > "$file"
  return 0
}

# The Lua plugin that counts and refuses (P5). Kept in this file because a
# module can be fetched as this one script.
rspamd_limit_plugin() {
  cat <<'LUA'
--[[
Managed by dPanel (backlog P5): each account's outbound mail limit.

The panel writes two maps (mail-send-limits.sh):
  /etc/rspamd/dpanel/senders.map  sender -> account
      "info@example.com a12"   a mailbox (SMTP login, or envelope sender)
      "example.com a12"        a domain the account owns
      "user:shop a12"          a site's system user (PHP mail() sends as
                               shop@<server hostname> unless the site sets -f)
  /etc/rspamd/dpanel/limits.map   account -> "<per hour>:<per day>", 0 = none

Only outbound mail counts: a logged-in SMTP client, or mail handed to
sendmail on this server (no client address, or loopback). Mail from other
servers is never counted. Each recipient counts as one message. Over a limit,
the message gets a temporary reject: an SMTP client is told to try later,
and sendmail's copy stays in the queue and leaves once the hour or day is
over. The window is fixed (UTC hour, UTC day); the panel reads the same
Redis keys to show usage and send the alert.

Redis keys (no expiry surprises: each expires after its window has passed):
  dpanel:mail:<account>:h:<YYYYMMDDHH>   count this hour
  dpanel:mail:<account>:d:<YYYYMMDD>     count today
  dpanel:mail:<account>:hit              "<h|d>:<window>" of the last refusal
]]

local rspamd_logger = require "rspamd_logger"
local lua_redis = require "lua_redis"
local lua_util = require "lua_util"

local N = 'dpanel_ratelimit'
local MAP_DIR = '/etc/rspamd/dpanel'

local redis_params = lua_redis.parse_redis_server(N)
if not redis_params then
  rspamd_logger.infox(rspamd_config, '%s: no Redis configured, outbound mail limits are off', N)
  return
end

local senders = rspamd_config:add_map({
  type = 'map',
  url = MAP_DIR .. '/senders.map',
  description = 'dPanel: mail sender to account',
})
local limits = rspamd_config:add_map({
  type = 'map',
  url = MAP_DIR .. '/limits.map',
  description = 'dPanel: account mail limits per hour and day',
})
if not senders or not limits then
  rspamd_logger.errx(rspamd_config, '%s: cannot load the maps in %s', N, MAP_DIR)
  return
end

-- KEYS: hour, day, hit. ARGV: hour limit, day limit, recipients, hour window, day window.
-- Counts only when the message is let through, so refused retries cost nothing.
local check_script = [[
local h = tonumber(redis.call('GET', KEYS[1]) or '0')
local d = tonumber(redis.call('GET', KEYS[2]) or '0')
local hl, dl, n = tonumber(ARGV[1]), tonumber(ARGV[2]), tonumber(ARGV[3])
if hl > 0 and h + n > hl then
  redis.call('SET', KEYS[3], 'h:' .. ARGV[4], 'EX', 172800)
  return {1, h, d}
end
if dl > 0 and d + n > dl then
  redis.call('SET', KEYS[3], 'd:' .. ARGV[5], 'EX', 172800)
  return {2, h, d}
end
redis.call('INCRBY', KEYS[1], n)
redis.call('EXPIRE', KEYS[1], 7200)
redis.call('INCRBY', KEYS[2], n)
redis.call('EXPIRE', KEYS[2], 172800)
return {0, h + n, d + n}
]]
local check_id = lua_redis.add_redis_script(check_script, redis_params)

local function lookup(key)
  if not key or key == '' then
    return nil
  end
  return senders:get_key(string.lower(key))
end

local function is_local_submission(task)
  local ip = task:get_from_ip()
  if not ip or not ip:is_valid() then
    return true
  end
  local addr = tostring(ip)
  return addr == '127.0.0.1' or addr == '::1'
end

-- The account the message is charged to, or nil when it is not outbound
-- mail of a hosted account.
local function account_for(task)
  local user = task:get_user()
  if user then
    local account = lookup(user)
    if account then
      return account, user
    end
    local domain = string.match(user, '@(.+)$')
    return lookup(domain), user
  end

  if not is_local_submission(task) then
    return nil
  end
  local from = task:get_from('smtp')
  if not from or not from[1] or not from[1].addr or from[1].addr == '' then
    return nil -- bounces and other null-sender mail are never limited
  end
  local sender = from[1]
  local account = lookup(sender.addr) or lookup(sender.domain)
  if not account and sender.user then
    account = lookup('user:' .. sender.user)
  end
  return account, sender.addr
end

local function check(task)
  local account, sender = account_for(task)
  if not account then
    return
  end
  local limit = limits:get_key(account)
  if not limit then
    return
  end
  local per_hour, per_day = string.match(limit, '^(%d+):(%d+)$')
  per_hour, per_day = tonumber(per_hour or 0), tonumber(per_day or 0)
  if per_hour == 0 and per_day == 0 then
    return
  end

  local rcpts = task:get_recipients('smtp') or {}
  local count = math.max(#rcpts, 1)
  local now = os.time()
  local hour_window = os.date('!%Y%m%d%H', now)
  local day_window = os.date('!%Y%m%d', now)
  local prefix = 'dpanel:mail:' .. account

  local function callback(err, data)
    if err then
      -- Never hold mail because Redis is down.
      rspamd_logger.errx(task, '%s: Redis error, mail let through: %s', N, err)
      return
    end
    local status = tonumber(data and data[1] or 0)
    if status == 0 then
      return
    end
    local what = status == 1 and string.format('%d per hour', per_hour) or string.format('%d per day', per_day)
    rspamd_logger.infox(task, '%s: %s (account %s) is over its limit of %s', N, sender, account, what)
    task:set_pre_result('soft reject', 'Sending limit reached (' .. what .. '); try again later', N)
  end

  lua_redis.exec_redis_script(check_id, { task = task, is_write = true }, callback,
    { prefix .. ':h:' .. hour_window, prefix .. ':d:' .. day_window, prefix .. ':hit' },
    { tostring(per_hour), tostring(per_day), tostring(count), hour_window, day_window })
end

rspamd_config:register_symbol({
  name = 'DPANEL_RATELIMIT_CHECK',
  type = 'prefilter',
  callback = check,
  priority = (lua_util.symbols_priorities and lua_util.symbols_priorities.medium) or 5,
  flags = 'empty,nostat',
})
LUA
}

rspamd_configure() {
  local changed=false

  # Milter on localhost only; scans what Postfix hands it.
  rspamd_write /etc/rspamd/local.d/worker-proxy.inc 'bind_socket = "127.0.0.1:11332";
milter = yes;
timeout = 120s;
upstream "local" {
  default = yes;
  self_scan = yes;
}' && changed=true

  # No greylisting: it delays first mail from every new sender by minutes.
  rspamd_write /etc/rspamd/local.d/actions.conf 'reject = 15;
add_header = 6;
greylist = null;' && changed=true
  rspamd_write /etc/rspamd/local.d/greylist.conf 'enabled = false;' && changed=true

  # Authentication-Results carries SPF/DKIM/DMARC verdicts. Its authserv-id is
  # the MTA name Postfix passes in the milter {j} macro (myhostname), which the
  # panel trusts when it verifies senders for BIMI logos.
  rspamd_write /etc/rspamd/local.d/milter_headers.conf 'use = ["x-spamd-bar", "x-spam-level", "x-spam-status", "authentication-results"];
extended_spam_headers = false;
skip_local = false;
skip_authenticated = true;' && changed=true

  # Redis (installed by the redis module) for Bayes learning and history.
  if command -v redis-cli >/dev/null 2>&1; then
    rspamd_write /etc/rspamd/local.d/redis.conf 'servers = "127.0.0.1";' && changed=true
  fi

  # Each account's outbound limit (backlog P5). The panel fills the maps
  # (mail-send-limits.sh); empty maps limit nothing. Rspamd rereads a map
  # when the file changes, so a new limit needs no restart.
  install -d -m 0755 "$LIMIT_MAP_DIR"
  local map
  for map in senders.map limits.map; do
    [[ -f "${LIMIT_MAP_DIR}/${map}" ]] || install -m 0644 /dev/null "${LIMIT_MAP_DIR}/${map}"
  done
  rspamd_write "$LIMIT_PLUGIN" "$(rspamd_limit_plugin)" && changed=true

  rspamd_write /etc/rspamd/local.d/dkim_signing.conf 'enabled = false;' && changed=true
  rspamd_write /etc/rspamd/local.d/arc.conf 'enabled = false;' && changed=true

  rspamadm configtest >/dev/null || panel_die "Rspamd rejected its configuration; run 'rspamadm configtest' to see why."
  systemctl enable rspamd >/dev/null 2>&1 || true
  if [[ "$changed" == true ]] || ! systemctl is-active --quiet rspamd; then
    systemctl restart rspamd
  fi
}

rspamd_configure_postfix() {
  local milters changed=false setting list
  # smtpd_milters: mail over SMTP. non_smtpd_milters: mail handed to
  # sendmail on this server, such as PHP mail(), so a site's mail also
  # counts against its account's limit (P5).
  for list in smtpd_milters non_smtpd_milters; do
    milters="$(postconf -h "$list" 2>/dev/null || true)"
    if [[ " ${milters//,/ } " != *" ${RSPAMD_MILTER} "* ]]; then
      # Rspamd first so OpenDKIM signs the message Rspamd already approved.
      postconf -e "${list}=${RSPAMD_MILTER}${milters:+, ${milters}}"
      changed=true
    fi
  done
  for setting in "milter_default_action=accept" "milter_protocol=6"; do
    if [[ "$(postconf -h "${setting%%=*}" 2>/dev/null)" != "${setting#*=}" ]]; then
      postconf -e "$setting"
      changed=true
    fi
  done
  if [[ "$changed" == true ]]; then
    postfix check || panel_die 'Postfix configuration check failed.'
    systemctl reload postfix
  fi
}

rspamd_configure_dovecot() {
  local version changed=false
  version="$(dovecot --version 2>/dev/null | awk '{print $1}')"

  rspamd_write "$SIEVE_SCRIPT" '# Managed by dPanel: mail Rspamd marked as spam goes to Junk.
require ["fileinto", "mailbox"];
if header :is "X-Spam" "Yes" {
  fileinto :create "Junk";
  stop;
}' && changed=true

  if [[ "$version" == 2.4* ]]; then
    rspamd_write "$DOVECOT_SIEVE_CONF" "# Managed by dPanel: run the spam filter, then the mailbox's own reply, on every delivery.
protocol lmtp {
  mail_plugins {
    sieve = yes
  }
}
sieve_script dpanel_spam {
  type = before
  path = ${SIEVE_SCRIPT}
}
# A mailbox's own script: its automatic reply, written by the panel.
sieve_script personal {
  driver = file
  path = ~/sieve
  active_path = ~/.dovecot.sieve
}" && changed=true
  else
    rspamd_write "$DOVECOT_SIEVE_CONF" "# Managed by dPanel: run the spam filter, then the mailbox's own reply, on every delivery.
protocol lmtp {
  mail_plugins = \$mail_plugins sieve
}
plugin {
  sieve_before = ${SIEVE_SCRIPT}
  # A mailbox's own script: its automatic reply, written by the panel.
  sieve = file:~/sieve;active=~/.dovecot.sieve
}" && changed=true
  fi

  # Mailboxes run as their site user, which cannot write a compiled script
  # next to the source; compile it here, readable by everyone.
  sievec "$SIEVE_SCRIPT" || panel_die "The spam Sieve script did not compile."
  chmod 0644 "$SIEVE_SCRIPT" "${SIEVE_SCRIPT%.sieve}.svbin"
  chmod 0755 "$SIEVE_DIR"

  doveconf -n >/dev/null || panel_die "Dovecot rejected its configuration; run 'doveconf -n' to see why."
  [[ "$changed" == true ]] && systemctl reload-or-restart dovecot
  return 0
}

rspamd_install() {
  command -v postconf >/dev/null 2>&1 && command -v dovecot >/dev/null 2>&1 \
    || panel_die 'Postfix and Dovecot must be installed first (mail services).'
  rspamd_install_packages
  rspamd_configure
  rspamd_configure_dovecot
  rspamd_configure_postfix
  panel_info_log "Rspamd spam filter active: spam goes to each mailbox's Junk folder, score 15+ is rejected."
  if [[ ! -f /etc/rspamd/local.d/redis.conf ]]; then
    panel_warn_log "No Redis for Rspamd: outbound mail limits stay off until the redis module is installed and rspamd is updated."
  fi
}

rspamd_update() {
  if ! command -v rspamd >/dev/null 2>&1; then
    panel_info_log "rspamd not installed; skipping update. Install with: dpanel module rspamd install"
    return 0
  fi
  rspamd_install
}

rspamd_remove() {
  local milters list
  for list in smtpd_milters non_smtpd_milters; do
    milters="$(postconf -h "$list" 2>/dev/null || true)"
    milters="$(printf '%s' "$milters" | tr ',' '\n' | sed 's/^ *//; s/ *$//' | { grep -vxF "$RSPAMD_MILTER" || true; } | paste -sd, - | sed 's/,/, /g')"
    if [[ -n "$milters" ]]; then postconf -e "${list}=${milters}"; else postconf -X "$list"; fi
  done
  systemctl reload postfix || true
  rm -f "$DOVECOT_SIEVE_CONF" "$LIMIT_PLUGIN"
  systemctl reload-or-restart dovecot || true
  systemctl disable --now rspamd >/dev/null 2>&1 || true
  pkg_remove_if_installed rspamd
  panel_info_log "Rspamd removed; mail is delivered unfiltered."
}

case "$action" in
  install) rspamd_install ;;
  update) rspamd_update ;;
  remove) rspamd_remove ;;
  *) panel_die "Unsupported rspamd action: $action" ;;
esac
