# Architecture

dPanel is split into three components with clear boundaries. The panel never
runs privileged shell commands itself; it asks a local Rust service to do that
work through a validated API.

## Contents

- [Components](#components)
- [Request flow](#request-flow)
- [Edge gateway](#edge-gateway)
- [PHP execution](#php-execution)
- [Database provisioning](#database-provisioning)
- [File manager safety](#file-manager-safety)
- [Design rules](#design-rules)

## Components

| Component | Runs as | Owns |
| --- | --- | --- |
| `dpanel` | `www-data` (Laravel + Vue) | UI, authentication, authorization, database records, queues, and workflows |
| `drust.service` | `root`, bound to `127.0.0.1:9500` | Privileged host operations: files, Linux users, databases, SSL, PHP, and scripts |
| `edge-gateway.service` | `root`, bound to `:80` and `:443` | Public HTTP/TLS traffic for every website, the panel, and phpMyAdmin |
| `dscript` | `root`, from the command line | Installation, updates, diagnostics, and recovery |

## Request flow

```text
Browser
  → edge-gateway.service (:80 / :443)
  → active website matched from the dPanel database
  → static file, PHP-FPM, dPanel, or phpMyAdmin

dPanel
  → drust.service (127.0.0.1:9500, bearer token)
  → privileged filesystem, user, database, SSL, PHP, and script operations
```

Websites are always reached through their configured live hostname. There is
no separate preview URL.

## Edge gateway

`drust edge-gateway` is the production entry point for all websites. It reads
active rows from dPanel's `websites` table and compiles them into an in-memory
snapshot that refreshes from the live database.

- **Hostname matching:** the configured domain plus its `www` alias
- **Static files:** normalized safe paths, index resolution, SPA fallback, and ETags
- **PHP:** front-controller and direct `.php` requests through PHP-FPM
- **TLS:** SNI certificate selection from the configured certificate paths
- **System paths:** the panel and phpMyAdmin always use the shared `www-data` PHP pool

Each website record includes its hostname, scope, `site_owner`, document root,
PHP version, SSL state, and status.

## PHP execution

User-scope PHP websites run in their own PHP-FPM pool:

```text
/run/php/dpanel-<site_owner>-php<version>.sock
```

If that socket is missing, the gateway validates the Linux user, creates an
on-demand PHP-FPM pool, tests the configuration, reloads PHP-FPM, and waits for
the socket. If the owner is invalid or provisioning fails, the request falls
back to the shared pool (`/run/php/php<version>-fpm.sock`) instead of failing.

System-scope websites, the panel, and phpMyAdmin always use the shared
`www-data` pool. Per-site pools are enabled with `DRUST_SITE_POOLS=1` (the
default) so one site's PHP cannot read another site's files.

## Database provisioning

When dPanel creates a database, drust:

1. Creates the database if it does not exist.
2. Creates or updates its user and password.
3. Grants `ALL PRIVILEGES` on that database only.
4. Synchronizes both `user@127.0.0.1` and `user@localhost` for local hosts.
5. Flushes privileges.

The user can fully manage its own database but never receives global
server-admin privileges. See the
[`database-request` endpoint](drust-api.md#database-request).

## File manager safety

All file operations stay inside `/home/<username>`. drust validates the Linux
user and every path, rejects traversal and unsafe symlinks, applies account
ownership, preserves dotfiles, and enforces upload and archive size limits.

## Design rules

- Prefer a validated drust endpoint over privileged shell execution in Laravel.
- Validate usernames, identifiers, paths, and versions before use.
- Keep the drust API on localhost; never expose it publicly.
- Never log tokens, passwords, private keys, or customer data.
