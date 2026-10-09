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
  (`/etc/letsencrypt/live/<domain>/`, then `/etc/drust/tls/<domain>.crt`).
  Certificates are swapped in place on every reload, whether it is a full
  reload or one for a single domain. The HTTPS listener starts even when no site
  has a certificate yet, and an unreadable certificate is skipped instead of
  blocking the others. The `www` alias is served when the certificate covers it.
- **System paths:** the panel and phpMyAdmin always use the shared `www-data` PHP pool

Each website record includes its hostname, scope, `site_owner`, document root,
PHP version, SSL state, and status.

### Reloads

dPanel publishes reload events on the Redis channel `edge:reload` (with an
HTTP fallback to `/__admin/reload`). A payload with `domains` reloads only those
sites; an empty payload (`{}`) reloads everything. Events are batched for
100 ms, and a full reload in a batch takes priority over per-domain reloads.
After a certificate is issued or renewed, both drust and dPanel send a full
reload, so the new certificate goes live without a restart.

A per-domain reload queries only those domains' rows, not every website. As a
safety net for a lost reload event, the gateway also re-reads the database every
`DRUST_SNAPSHOT_POLL_SECONDS` (60; `0` turns it off) and applies the result
only when something changed, so its caches are not cleared for nothing.

### Edge cache

Each site can turn on a shared response cache in the gateway (settings in the
`website_edge_cache` table; off by default, never used for system sites):

- **Standard** caches what the origin marks cacheable (`s-maxage`/`max-age`)
  and static-looking paths. **Everything** also caches HTML.
- Never stored: responses with `Set-Cookie`, `private`, `no-store` or
  `no-cache`, a `Vary` other than `Accept-Encoding`, or `Content-Encoding`.
  Requests with `Authorization`, a `Range`, a bypass cookie or a bypass path
  go straight to the origin.
- With **serve stale**, an expired copy is kept for a day and returned when the
  origin answers 5xx, and to everyone else while one request refreshes it.
- A missing or expired copy is fetched by one request per URL; the others
  wait for it (up to `DRUST_EDGE_CACHE_LOCK_TIMEOUT_MS`, 5000) instead of all
  reaching PHP at once.
- Static files read from disk are not copied into it; the static file layer
  already keeps them in memory and notices changes. That layer holds files up
  to `DRUST_STATIC_CACHE_MAX_FILE_BYTES` (1 MiB), at most
  `DRUST_STATIC_CACHE_MAX_ENTRIES` (8192) and `DRUST_STATIC_CACHE_MAX_BYTES`
  (128 MiB) in all, dropping the least recently used when full.

Every response carries `x-dpanel-cache`: `HIT`, `MISS`, `EXPIRED`, `STALE`,
`BYPASS`, `DYNAMIC` (not cacheable) or `STATIC`. A per-domain reload purges
that site's copies. `POST /__admin/cache/purge` (`{"domain", "urls",
"prefixes"}` or `{"everything": true}`) and `GET /__admin/cache/stats?domain=`
take the drust API token. Memory limits: `DRUST_EDGE_CACHE_MAX_BYTES`
(256 MiB), `DRUST_EDGE_CACHE_MAX_OBJECT_BYTES` (8 MiB) and
`DRUST_EDGE_CACHE_MAX_ENTRIES` (100000). The cache is split into 16 shards
by key, each with an equal part of these limits, so storing or evicting in one
does not block lookups in the others.

Cached copies (edge cache entries and in-memory static files) are compressed
once per encoding (brotli, gzip) on the first request that asks for it, and
that copy is reused afterwards instead of compressing on every hit. The copies
live next to the cached body and are not counted in the cache limits; for text
they add roughly a third of its size.

### Connections

Both listeners share a limit of `DRUST_MAX_CONNECTIONS` (30000) open
connections; past it, new ones wait in the kernel backlog. A TLS handshake must
finish within 10 s and HTTP/1 request headers within 30 s, so idle or slowloris
clients are dropped. TLS sessions resume through session tickets and a
10240-entry session cache. `edge-gateway.service` raises `LimitNOFILE`, since
the systemd default of 1024 file descriptors caps the gateway near 1000
connections. Node, Python and Docker responses without a `Content-Length` are
read up to 2 MiB and streamed beyond that.

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

PHP output up to 1 MiB is read whole, so the edge cache can store it; larger
output (downloads, exports) is streamed to the client as PHP writes it. Request
bodies over 1 MiB with a `Content-Length` are streamed into PHP-FPM, so upload
size is limited only by PHP's `post_max_size`; bodies without a length are
still read first, up to 64 MiB.

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
