# drust API Reference

`drust` is the privileged, localhost-only execution API that the dPanel
Laravel app uses for server-side operations.

## Contents

- [Base URL](#base-url)
- [Authentication](#authentication)
- [Response shape](#response-shape)
- [Endpoint index](#endpoint-index)
- [Endpoint details](#endpoint-details)
- [Testing with Postman](#testing-with-postman)

## Base URL

```text
http://127.0.0.1:9500
```

The service stays on localhost in both development and production. It is
called only by the Laravel panel or another local control plane. See
[drust Service](drust-service.md) for installation and service management.

## Authentication

All protected endpoints require a bearer token.

### Header

```http
Authorization: Bearer <DRUST_API_TOKEN>
Content-Type: application/json
Accept: application/json
```

### Token alignment

Use the same token value in both places:

- `drust` runtime token: `DRUST_API_TOKEN`
- Laravel client token: `serverpanel.execution_api_token`

If the values do not match, the request will fail with `Unauthorized`.

## Response shape

Most endpoints return this JSON shape:

```json
{
  "success": true,
  "message": "Done",
  "data": null
}
```

Error example:

```json
{
  "success": false,
  "message": "Unauthorized",
  "data": null
}
```

For script execution, the response includes output data:

```json
{
  "success": true,
  "message": "Script executed",
  "data": {
    "output": "8.4\n8.3\n8.2\n"
  }
}
```

## Endpoint index

Every endpoint except `GET /health` requires the bearer token.

| Group | Endpoints |
| --- | --- |
| Health | `GET /health`, `GET /api/v1/health-checker` |
| Accounts | `create-admin-user`, `disable-root-login`, `ssh-key/generate`, `ftp-account` |
| File manager | `filemanager/{user, browse, read, write, create, remove, delete, exists, inspect, size, copy, move, chmod, upload, zip, unzip, fix-permissions}` |
| App installers | `filemanager/{wordpress-install, laravel, artisan}`, `project-dependencies`, `git-deploy` |
| Websites | `website/delete`, `website/archive`, `website/archive/restore`, `website/archive/delete`, `website/redis-config`, `website-terminal` |
| Runtimes | `php/config`, `node/control`, `python/control`, `cron-job` |
| SSL | `ssl/ensure` |
| Databases | `database-request`, `database-config`, `postgresql/service`, `postgresql/pgadmin-login` |
| Mail | `mailbox-storage` |
| Backups | `backup/run`, `backup/delete` |
| Migration | `migration/cpanel/{inspect, restore}`, `migration/cyberpanel-ssh/{discover, transfer}`, `migration/generic/restore` |
| Security | `security`, `security/scan` |
| Docker | `docker` (GET status, POST actions), `docker/{logs, inspect, stats, exec}`, `docker/{networks, volumes, system, stacks}` (GET list, POST actions), `docker/stacks/{show, logs}` |
| Media | `media/ocr`, `media/transcribe` |
| Scripts | `script/run` |

Paths in the table are relative to `/api/v1/`. The legacy `sync-vhost`
endpoint is kept only for compatibility and returns an error; the edge gateway
now reads websites directly from the database.

## Endpoint details

The most commonly used endpoints are documented below.

### Health check

```http
GET /health
```

No auth required.

Example response:

```json
{
  "status": "ok",
  "service": "drust",
  "version": "0.1.0"
}
```

### Authenticated health checker

```http
GET /api/v1/health-checker
```

Requires bearer token.

Example response:

```json
{
  "success": true,
  "message": "Health check passed",
  "data": {
    "status": "ok",
    "service": "drust",
    "version": "0.1.0"
  }
}
```

### Create admin user

```http
POST /api/v1/create-admin-user
```

Body:

```json
{
  "username": "admin",
  "password": "secret",
  "email": "admin@example.com",
  "ssh_key": null,
  "shell": "/bin/bash",
  "disable_root": true
}
```

### Disable root login

```http
POST /api/v1/disable-root-login
```

No JSON body required.

### File manager create

```http
POST /api/v1/filemanager/create
```

Body:

```json
{
  "paths": [
    "/home/example/public_html",
    "/home/example/logs"
  ]
}
```

### File manager remove

```http
POST /api/v1/filemanager/remove
```

Body:

```json
{
  "paths": [
    "/home/example/tmp"
  ]
}
```

### File manager exists

```http
POST /api/v1/filemanager/exists
```

Body:

```json
{
  "paths": [
    "/home/example/public_html"
  ],
  "check_file": false
}
```

Notes:

- `check_file: false` means directory check.
- `check_file: true` means file check.

### File manager user

```http
POST /api/v1/filemanager/user
```

Body:

```json
{
  "action": "create",
  "username": "example",
  "home": "/home/example",
  "shell": "/bin/bash",
  "site_directory": "public_html"
}
```

### File manager write

```http
POST /api/v1/filemanager/write
```

Body:

```json
{
  "username": "example",
  "path": "/home/example/public_html/index.html",
  "content": "<h1>Ready</h1>"
}
```

The path must remain inside `/home/{username}`. The daemon creates missing parent directories and applies account ownership, directory mode `0755`, and file mode `0644`.

### File manager upload

```http
POST /api/v1/filemanager/upload
Content-Type: multipart/form-data
```

Multipart fields:

- `username`: account owner
- `path`: absolute target path inside `/home/{username}`
- `upload`: binary file body

Uploads are streamed to a staging file, installed atomically with account ownership
and mode `0644`, and limited to 10 GiB by default. Set
`DRUST_MAX_UPLOAD_SIZE_BYTES` on the daemon to change the API-side limit.

### File manager unzip

```http
POST /api/v1/filemanager/unzip
```

Body:

```json
{
  "username": "example",
  "path": "/home/example/public_html/archive.zip",
  "destination": "/home/example/public_html"
}
```

The archive is extracted into `destination` when provided, otherwise beside the
zip file. Paths must remain inside the account home. Symbolic-link entries and
unsafe paths are skipped. Existing regular files are replaced, and extracted
files/folders are owned by the account user. Dotfiles are preserved. The default
limits are 100,000 entries and 20 GiB expanded data; override them with
`DRUST_MAX_ZIP_ENTRIES` and `DRUST_MAX_ZIP_EXPANDED_BYTES`.

### File manager chmod

```http
POST /api/v1/filemanager/chmod
```

Body:

```json
{
  "username": "example",
  "path": "/home/example/public_html/storage",
  "mode": "775",
  "recursive": true
}
```

The path must remain inside the account home. The target is also assigned to the
account user/group. Recursive changes reject symbolic links instead of following
them.

### SSL ensure

```http
POST /api/v1/ssl/ensure
```

Body:

```json
{
  "domain": "example.com",
  "root_path": "/home/example/public_html",
  "include_www": false,
  "renew_before_days": 30
}
```

The daemon validates the real certificate hostname and expiry with OpenSSL. It invokes Certbot only when the certificate is missing, invalid, or inside the renewal window, then validates the resulting certificate again. After a successful validation it restarts `edge-gateway.service` so the SNI certificate store and port 443 listener are refreshed immediately.

### Database request

```http
POST /api/v1/database-request
Authorization: Bearer <token>
Content-Type: application/json
```

Body:

```json
{
  "action": "create",
  "database_name": "example_db",
  "database_user": "example_user",
  "database_password": "use-a-strong-secret",
  "database_host": "127.0.0.1",
  "database_port": 3306,
  "charset": "utf8mb4",
  "collation": "utf8mb4_unicode_ci"
}
```

Allowed actions are `create` and `upsert`. Both are idempotent: the database is
created first when absent, the user account/password is synchronized, and the
user receives `ALL PRIVILEGES` on that database only. Local requests synchronize
both `user@127.0.0.1` and `user@localhost`. They do not grant global privileges.

### Docker

```http
GET  /api/v1/docker              POST /api/v1/docker
POST /api/v1/docker/logs         POST /api/v1/docker/inspect
GET  /api/v1/docker/stats        POST /api/v1/docker/exec
GET  /api/v1/docker/networks     POST /api/v1/docker/networks
GET  /api/v1/docker/volumes      POST /api/v1/docker/volumes
GET  /api/v1/docker/system       POST /api/v1/docker/system
GET  /api/v1/docker/stacks       POST /api/v1/docker/stacks
POST /api/v1/docker/stacks/show  POST /api/v1/docker/stacks/logs
```

`GET docker` returns `installed`, `running`, `version`, `compose`,
`containers` (with `project`, `service`, `networks`, `mounts`), `images` and
`networks` (names). `POST docker` takes an `action` and returns the same status:

| Action | Fields |
| --- | --- |
| `start`, `stop`, `restart`, `remove`, `pause`, `unpause`, `kill` | `id` |
| `rename` | `id`, `name` |
| `run` | `spec` (below) |
| `recreate` | `id`, `spec`: the old container is stopped and renamed aside, and put back if the new one fails |
| `update` | `id`: pull its image again and recreate with the same settings |
| `pull` | `image` |
| `remove_image` | `image`, `force` |
| `prune_images` | `all` (also tagged images no container uses) |
| `prune_containers` | none |

A `spec` is `image`, `name`, `restart`, `ports[{host, container, protocol, public}]`,
`env[{key, value}]`, `volumes[{source, target, read_only}]`, `network`,
`aliases[]`, `hostname`, `memory` (`512m`), `cpus` (`0.5`), `entrypoint`,
`command[]` (arguments after the image) and `pull`.

- `docker/logs`: `{"id", "lines"}` (1 to 5000).
- `docker/inspect`: `{"id"}` → state, mounts, networks, env, ports, limits, and
  the `spec` that recreates the container.
- `docker/stats`: CPU, memory, network and disk use of running containers.
- `docker/exec`: `{"id", "command", "user", "workdir"}` → `output`,
  `exit_code`, `timed_out`. Runs `sh -c` under `timeout --signal=KILL 60s`;
  output is cut at 256 KB.
- `docker/networks` actions: `create` (`spec: {name, internal, subnet}`),
  `remove` (`name`), `connect` (`name`, `container`, `aliases`), `disconnect`,
  `prune`. Built-in networks cannot be created or removed.
- `docker/volumes` actions: `create`, `remove` (`name`), `prune` (`all`).
- `docker/system`: `GET` → engine info and `system df`; actions `prune`
  (`all` adds unused images; never volumes) and `prune_build_cache`.
- `docker/stacks`: `GET` → stacks the panel manages plus `compose ls`.
  Actions take `name` (lowercase, `[a-z0-9][a-z0-9_-]*`) and optionally
  `service`: `create`/`save` (`compose`, `env`; checked with
  `docker compose config` in a staging folder first), `deploy`/`deploy_new`
  (save, then up), `up`, `pull`, `update`, `start`, `stop`, `restart`, `down`,
  `remove` (`remove_volumes`). Stacks started from the shell support start,
  stop, restart, down and remove only. Files live in
  `/opt/dpanel/docker/stacks/<name>/` (override with `DPANEL_DOCKER_STACKS_DIR`).
- `docker/stacks/show`: file, variables, services with published ports, and
  warnings (public ports, privileged, host network, Docker socket).

Only the docker CLI runs, with an argument list and no shell. Names, images,
ports, variable names and mounts are validated, and nothing may start with
`-`. Ports bind to `127.0.0.1` unless `public` is true: Docker writes its own
iptables rules, so ufw does not guard a public port. Install Docker with
`sudo dpanel docker` (see [Docker](docker.md)).

### Run script

```http
POST /api/v1/script/run
```

This is the main endpoint Laravel uses for script execution.

Body:

```json
{
  "script": "php-detect-versions.sh",
  "args": []
}
```

Important:

- Send only the script file name, not a full path.
- Do not send `script_path`.
- The script name must exist under the `drust/scripts/` directory.

Example for PHP version detection:

```json
{
  "script": "php-detect-versions.sh",
  "args": []
}
```

Expected output:

```json
{
  "success": true,
  "message": "Script executed",
  "data": {
    "output": "8.4\n8.3\n8.2\n"
  }
}
```

## Testing with Postman

Create one environment with these variables:

```text
base_url = http://127.0.0.1:9500
token = your-shared-secret-token
```

Then set these request headers:

```http
Authorization: Bearer {{token}}
Content-Type: application/json
Accept: application/json
```

### Suggested test order

1. `GET {{base_url}}/health`
2. `GET {{base_url}}/api/v1/health-checker`
3. `POST {{base_url}}/api/v1/script/run`
4. `POST {{base_url}}/api/v1/filemanager/exists`

## Notes

- Keep `drust` bound to `127.0.0.1` unless you explicitly need remote access.
- The `script/run` endpoint is the safest way to let Laravel trigger helper scripts.
- For PHP version discovery, use `php-detect-versions.sh` through `/api/v1/script/run`.
