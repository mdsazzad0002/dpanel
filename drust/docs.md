# drust API Docs

`drust` is the execution API used by Laravel to run server-side operations.

## Base URL

Local dev:

```text
http://127.0.0.1:9500
```

Production:

```text
http://127.0.0.1:9500
```

The service is intended to stay on localhost and be called by Laravel or a local control plane.

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

## Response Shape

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

## Endpoints

### 1. Health check

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

### 1b. Authenticated health checker

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

### 2. Fix web stack

```http
POST /api/v1/fix-web-stack
```

Body:

```json
{
  "apache_backend_port": 8080,
  "nginx_frontend_port": 80
}
```

### 3. Fix panel web stack

```http
POST /api/v1/fix-panel-web-stack
```

Body:

```json
{
  "domain": "panel.example.com",
  "backend_port": 8080,
  "frontend_port": 80,
  "app_dir": "/var/www/dpanel",
  "conf_name": "dpanel.conf",
  "aliases": ["www.panel.example.com"],
  "no_www": false
}
```

### 4. Sync vhost

```http
POST /api/v1/sync-vhost
```

Body:

```json
{
  "action": "create",
  "domain": "example.com",
  "root_path": "/home/example/public_html",
  "php_version": "8.3",
  "old_domain": null,
  "aliases": ["www.example.com"],
  "no_www": false
}
```

Notes:

- `php_version` is optional in the API, but Laravel usually sends a real version.
- If omitted, the API uses `8.3` as the default internal fallback.

### 5. Create admin user

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

### 6. Disable root login

```http
POST /api/v1/disable-root-login
```

No JSON body required.

### 7. File manager create

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

### 8. File manager remove

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

### 9. File manager exists

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

### 10. File manager user

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

### 11. File manager write

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

### 12. File manager upload

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

### 13. File manager unzip

```http
POST /api/v1/filemanager/unzip
```

Body:

```json
{
  "username": "example",
  "path": "/home/example/public_html/archive.zip"
}
```

The archive is extracted beside the zip file. Paths must remain inside the
account home. Symbolic-link entries and unsafe paths are rejected. The default
limits are 100,000 entries and 20 GiB expanded data; override them with
`DRUST_MAX_ZIP_ENTRIES` and `DRUST_MAX_ZIP_EXPANDED_BYTES`.

### 14. SSL ensure

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

The daemon validates the real certificate hostname and expiry with OpenSSL. It invokes Certbot only when the certificate is missing, invalid, or inside the renewal window, then validates the resulting certificate again.

### 13. Run script

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

### 12. Laravel install

```http
POST /api/v1/laravel-install
```

Body:

```json
{
  "root_path": "/home/example/public_html",
  "domain": "example.com",
  "php_version": "8.3",
  "start_directory": "public",
  "db_name": "example_db",
  "db_user": "example_user",
  "db_password": "secret",
  "db_host": "127.0.0.1",
  "db_port": "3306",
  "no_demo": false,
  "no_db": false,
  "no_vhost": false
}
```

## Postman Setup

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
5. `POST {{base_url}}/api/v1/sync-vhost`

## Laravel and drust handshake

Laravel and `drust` must use the same token value.

If Laravel sends a token that does not match `DRUST_API_TOKEN`, the API will reject the request.

## Notes

- Keep `drust` bound to `127.0.0.1` unless you explicitly need remote access.
- The `script/run` endpoint is the safest way to let Laravel trigger helper scripts.
- For PHP version discovery, use `php-detect-versions.sh` through `/api/v1/script/run`.
