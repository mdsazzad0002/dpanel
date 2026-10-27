# MCP Access

dPanel includes a remote [Model Context Protocol](https://modelcontextprotocol.io)
(MCP) server. With it, an AI client such as Claude Code, Claude Desktop, Cursor
or VS Code can connect to your panel from any machine and diagnose and fix
problems: it reads service status and logs, looks up website configuration, and
(with a full-access token) runs commands on your servers.

## Contents

- [Quick start](#quick-start)
- [Connecting a client](#connecting-a-client)
- [Tokens and access levels](#tokens-and-access-levels)
- [Tools](#tools)
- [Command safety](#command-safety)
- [Configuration](#configuration)
- [Protocol details](#protocol-details)

## Quick start

1. Sign in as an **admin** and open **Profile menu → MCP Access**
   (`/cpsess…/settings/mcp`).
2. Create a token. Choose **Read-only** to diagnose only, or **Full** so the
   client can also fix problems.
3. Copy the token. It is shown once.
4. Paste the setup snippet for your client (the page fills in the endpoint and
   token) and start asking, for example: *"shop.example.com shows a 502. Find
   out why."*

The endpoint is `https://<your-panel-domain>/api/mcp`. Use the panel over
HTTPS so the token is never sent in clear text.

## Connecting a client

**Claude Code**

```bash
claude mcp add --transport http dpanel https://panel.example.com/api/mcp \
  --header "Authorization: Bearer dp-mcp-…"
```

**Claude Desktop**: add this to `claude_desktop_config.json`. It uses
[`mcp-remote`](https://www.npmjs.com/package/mcp-remote), so Node.js must be
installed.

```json
{
  "mcpServers": {
    "dpanel": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://panel.example.com/api/mcp",
               "--header", "Authorization:${DPANEL_MCP_AUTH}"],
      "env": { "DPANEL_MCP_AUTH": "Bearer dp-mcp-…" }
    }
  }
}
```

**Cursor** (`~/.cursor/mcp.json`) and **VS Code** (`.vscode/mcp.json`, use
`"servers"` with `"type": "http"`):

```json
{
  "mcpServers": {
    "dpanel": {
      "url": "https://panel.example.com/api/mcp",
      "headers": { "Authorization": "Bearer dp-mcp-…" }
    }
  }
}
```

Any other client that supports the Streamable HTTP transport with a custom
`Authorization` header works the same way. The endpoint does not support
OAuth, so connectors that require OAuth sign-in (such as claude.ai custom
connectors) cannot use it directly.

## Tokens and access levels

| Access | Can do |
| --- | --- |
| **Read-only** | All read tools, plus `run_command` limited to known diagnostic commands |
| **Full** | Everything, including state-changing commands and `restart_service` |

- A token acts as the admin who created it. If that user is suspended or is no
  longer an admin, all of their tokens stop working.
- Tokens can expire after a set number of days, and can be disabled or revoked
  at any time from the MCP Access page.
- Only a SHA-256 hash of each token is stored. The page shows when and from
  which IP each token was last used.

## Tools

| Tool | Read-only | What it does |
| --- | --- | --- |
| `get_panel_overview` | ✓ | Host health: OS, uptime, load, memory, disk, core service states, counts |
| `list_servers` | ✓ | Servers managed over SSH, with ids for the other tools |
| `list_websites` | ✓ | Websites, filtered by domain or status |
| `get_website` | ✓ | One site's document root, runtime, PHP-FPM socket, SSL certificate, aliases |
| `list_ssl_certificates` | ✓ | Certificates by expiry, e.g. ones expiring within 14 days |
| `get_service_status` | ✓ | `systemctl status` for a unit |
| `read_service_logs` | ✓ | `journalctl -u` with line count, `since` and `grep` filters |
| `read_log_file` | ✓ | Tail (and optionally grep) a file under `/var/log` |
| `list_recent_commands` | ✓ | Recent server commands, with the panel's AI error summaries |
| `list_recent_activity` | ✓ | The panel activity log: who changed what, and when |
| `run_command` | limited | Run a shell command on a server (see below) |
| `restart_service` | ✗ | Restart, reload, start or stop a unit, then show its status |

Commands run on the servers listed under **Servers**, over the same SSH
connection the Server Task Runner uses. When only one server exists,
`server_id` can be left out.

## Command safety

Every command goes through the panel's
[command safety rules](ssh-command-runner.md) and is recorded as a command job
tagged `mcp`. Command output, reports and AI error analysis work the same as
for commands started in the panel. Calls to tools that can change state are
also written to the activity log as `mcp.tool_called`.

- **Blocked** commands (`rm -rf /`, `mkfs`, `reboot`, `curl … | bash`, …) are
  always refused, whatever the token.
- **Read-only tokens** may run only commands the safety rules mark as safe
  (`df -h`, `free -m`, `uptime`, `ls`, `systemctl status`, `journalctl -u`,
  `cat /var/log/…`, `php -v`, …). They cannot chain or redirect commands
  (`;`, `&&`, `||`, `>`, `<`, `` ` ``, `$(`), can pipe only into `grep`, `head`,
  `tail`, `wc`, `sort`, `uniq`, `cut` or `column`, and cannot read secrets such
  as `/etc/shadow`, SSH and TLS private keys, `.env`, `wp-config.php` or MySQL
  credential files.
- **Full tokens** may run any command that is not blocked. Creating a full
  token is your standing approval, so the command job records the token's
  owner as the approver.

Output returned to the client is cut to the last 30,000 characters, because
errors are usually at the end.

## Configuration

Set these in the panel's `.env`:

| Variable | Default | Meaning |
| --- | --- | --- |
| `DPANEL_MCP_ENABLED` | `true` | Set to `false` to turn the `/api/mcp` endpoint off |
| `DPANEL_MCP_RATE_LIMIT` | `120` | Requests per minute |
| `DPANEL_MCP_MAX_OUTPUT_CHARS` | `30000` | Command output returned per call |

## Protocol details

- Transport: Streamable HTTP. Clients `POST` JSON-RPC 2.0 messages to
  `/api/mcp` and get `application/json` responses. There is no server-sent
  event stream, so `GET` returns `405`. Notifications get `202 Accepted`.
- Protocol versions: `2025-06-18`, `2025-03-26`, `2024-11-05`.
- Methods: `initialize`, `ping`, `tools/list`, `tools/call` (plus empty
  `resources/list` and `prompts/list`).
- Tool results carry JSON both as text content and as `structuredContent`.
  Refused commands and bad input come back as tool results with
  `isError: true`, so the model can read the reason and change course.
- Authentication failures return `401` with a `WWW-Authenticate: Bearer`
  header.

Example:

```bash
curl -s https://panel.example.com/api/mcp \
  -H "Authorization: Bearer dp-mcp-…" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_panel_overview","arguments":{}}}'
```
