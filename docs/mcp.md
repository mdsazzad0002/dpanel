# MCP Access

dPanel includes a remote [Model Context Protocol](https://modelcontextprotocol.io)
(MCP) server. With it, an AI client such as Claude Code, Claude Desktop, Cursor
or VS Code can connect to your panel from any machine and diagnose and fix
problems: it reads service status and logs, looks up website configuration, and
(with full access) runs commands on the panel host and your other servers.

## Contents

- [Quick start](#quick-start)
- [Connecting a client](#connecting-a-client)
- [Signing in with OAuth](#signing-in-with-oauth)
- [Tokens and access levels](#tokens-and-access-levels)
- [Commands on the panel host](#commands-on-the-panel-host)
- [Tools](#tools)
- [Command safety](#command-safety)
- [Configuration](#configuration)
- [Protocol details](#protocol-details)

## Quick start

1. Let MCP clients run commands on the panel host (optional, but most fixes
   need it):

   ```bash
   sudo dpanel mcp local on read    # diagnose only
   sudo dpanel mcp local on full    # diagnose and fix
   ```

2. Connect a client, using either method:
   - **claude.ai and the Claude apps**: under Settings → Connectors, add a
     custom connector with the URL `https://<your-panel-domain>/api/mcp`. You
     sign in to dPanel and approve it. No token is needed.
   - **Token**: sign in as an **admin**, open **Profile menu → MCP Access**,
     create a token (**Read-only** or **Full**) and paste the setup snippet for
     your client. The page fills in the endpoint and the token.
3. Start asking, for example: *"shop.example.com shows a 502. Find out why."*

The endpoint is `https://<your-panel-domain>/api/mcp`. Use the panel over
HTTPS so the token is never sent in clear text.

## Connecting a client

**claude.ai, Claude Desktop and the mobile apps**: open Settings →
Connectors → **Add custom connector**, enter `https://panel.example.com/api/mcp`
and click **Connect**. See [Signing in with OAuth](#signing-in-with-oauth).

**Claude Code**: either sign in with OAuth (`claude mcp add --transport http
dpanel https://panel.example.com/api/mcp`, then run `/mcp` and choose
**Authenticate**), or pass a token:

```bash
claude mcp add --transport http dpanel https://panel.example.com/api/mcp \
  --header "Authorization: Bearer dp-mcp-…"
```

**Claude Desktop with a token** instead of the connector: add this to
`claude_desktop_config.json`. It uses
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

Any other client that supports the Streamable HTTP transport works the same
way, with either a custom `Authorization` header or OAuth sign-in.

## Signing in with OAuth

Clients that can't take a pasted token connect with OAuth 2.1. claude.ai
connects from Anthropic's servers, so the panel must be reachable from the
internet over HTTPS with a valid certificate.

1. The client finds the sign-in details itself. A request without a token gets
   `401` with a `WWW-Authenticate` header pointing at
   `/.well-known/oauth-protected-resource/api/mcp`, and from there at
   `/.well-known/oauth-authorization-server`.
2. It registers itself (`POST /oauth/register`) and opens
   `/oauth/authorize` in your browser.
3. You sign in to dPanel as usual, including two-factor, and land on a consent
   screen that shows the client's name and where it will return. Choose
   **Read-only** or **Full** and click **Allow**.
4. The client receives an access token valid for one hour, plus a refresh
   token valid for 30 days that it renews automatically.

Each connection appears on the MCP Access page with an **OAuth** badge. Disable
or revoke it there like any token. Only admins can approve a connection, and it
stops working if the approving admin is suspended or loses the admin role.
Registering a client grants nothing until an admin approves it.

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

## Commands on the panel host

Commands can run on the machine dPanel itself runs on, without adding it as an
SSH server. Root turns this on or off on the host. The panel can only read the
setting, so it cannot raise its own access:

```bash
sudo dpanel mcp local status
sudo dpanel mcp local on read     # MCP clients may diagnose this host
sudo dpanel mcp local on full     # full-access clients may also fix it, as root
sudo dpanel mcp local off
```

| Host setting | Read-only token | Full token |
| --- | --- | --- |
| `off` | Panel host not available | Panel host not available |
| `read` | Runs as `nobody` | Runs as `nobody`; `restart_service` is refused |
| `full` | Runs as `nobody` | Runs as root |

`nobody` runs with the `adm` and `systemd-journal` groups, so it can read the
system logs but cannot change anything, even if the panel itself were
compromised. Commands go through drust (`mcp-exec.sh`), are limited to the
panel's command timeout, and are recorded in the activity log as
`mcp.local_command`.

When the panel host is enabled, it is the default target. Pass
`server_id: "local"` or a server id from `list_servers` to choose explicitly.

## Tools

| Tool | Read-only | What it does |
| --- | --- | --- |
| `get_panel_overview` | ✓ | Host health: OS, uptime, load, memory, disk, core service states, counts |
| `list_servers` | ✓ | The panel host (`local`) and servers managed over SSH, with ids for the other tools |
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

Commands run on the panel host (see above) or on the servers listed under
**Servers**, over the same SSH connection the Server Task Runner uses. Without
`server_id`, they go to the panel host when it is enabled, otherwise to the only
server.

## Command safety

Every command goes through the panel's
[command safety rules](ssh-command-runner.md). Commands on SSH servers are
recorded as command jobs tagged `mcp`; commands on the panel host go to the
activity log. Command output, reports and AI error analysis work the same as
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
| `DPANEL_MCP_OAUTH_ENABLED` | `true` | Set to `false` to allow pasted tokens only |
| `DPANEL_MCP_OAUTH_ACCESS_TTL` | `3600` | OAuth access token lifetime, in seconds |
| `DPANEL_MCP_OAUTH_REFRESH_TTL_DAYS` | `30` | OAuth refresh token lifetime, in days |

Whether commands may run on the panel host is set with `dpanel mcp local`, not
in `.env`. It is stored in `/etc/dpanel/mcp-local.conf`.

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
  header that names the protected resource metadata URL.
- OAuth: protected resource metadata (RFC 9728), authorization server metadata
  (RFC 8414), dynamic client registration (RFC 7591), authorization code with
  PKCE (`S256` only), refresh token rotation, and revocation (RFC 7009) at
  `/oauth/revoke`. Scopes are `mcp:read` and `mcp:full`. Redirect URIs must use
  https, a loopback `http://127.0.0.1` / `localhost` address, or an app scheme.

Example:

```bash
curl -s https://panel.example.com/api/mcp \
  -H "Authorization: Bearer dp-mcp-…" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"get_panel_overview","arguments":{}}}'
```
