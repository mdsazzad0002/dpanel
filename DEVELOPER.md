# ServerPanel Developer Guide

This guide is for developers who want to understand, run, extend, and safely upgrade this project.

ServerPanel is a hosting control panel stack built from three cooperating layers:

- `dpanel` - Laravel + Vue control panel UI and application backend
- `drust` - Rust localhost execution API for privileged server operations
- `dscript` - shell bootstrap, installer, and repair scripts

The important design idea is simple: the Laravel app owns product logic and user experience; the Rust API owns machine-level execution; shell scripts are used for installation and repair, not as the main runtime control plane.

## Repository Layout

```text
/var/www
├── dpanel/       Laravel 12 app, Vue/Inertia frontend, queue jobs, models, UI
├── drust/        Rust execution daemon, protected localhost API
├── dscript/      installer, server bootstrap, and recovery scripts
├── phpmyadmin/   bundled phpMyAdmin integration assets
├── html/         default web root/static fallback files
├── installer.sh  first-run installer entrypoint
├── README.md     root quick reference
└── DEVELOPER.md  this developer guide
```

## System Architecture

```text
Browser
  |
  v
dpanel: Laravel + Inertia/Vue
  |
  | HTTP API with bearer token, normally 127.0.0.1:9500
  v
drust: Rust root-owned execution API
  |
  | controlled OS operations
  v
Linux services: nginx, Apache, PHP-FPM, MariaDB, Redis, certbot, filesystem

dscript is used beside this flow for installation, bootstrap, and repair wrappers.
```

### `dpanel`

Location: `dpanel/`

Responsibilities:

- authentication, authorization, roles, and panel sessions
- website, database, DNS, mail, SSL, backup, cron, monitoring records
- Inertia/Vue pages and Laravel controllers
- queue jobs and audit logs
- AI-assisted command analysis and task history
- calling `drust` for privileged host actions

Laravel should not directly run unsafe shell commands for production server changes. Add privileged operations to `drust` and call them through a service/gateway.

### `drust`

Location: `drust/`

Responsibilities:

- localhost-only execution API
- filemanager operations
- vhost sync and web-stack repair
- database provisioning
- SSL issuance hooks
- PHP config operations
- Laravel installer operations
- permission and ownership repair

`drust` is expected to run as `root` through systemd and must stay protected by a bearer token.

### `dscript`

Location: `dscript/`

Responsibilities:

- first server installation
- module install/update/repair commands
- wrapper scripts that call `drust`
- web-stack recovery utilities

Runtime panel actions should usually go through `dpanel -> drust`. Use `dscript` for setup and repair tooling.

## Local Development

### Requirements

- PHP 8.2 or newer
- Composer
- Node.js and npm
- Rust toolchain with Cargo
- MariaDB/MySQL
- Redis for queues
- nginx/Apache/PHP-FPM for full server integration testing

### Laravel App

```bash
cd /var/www/dpanel
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

Useful commands:

```bash
php artisan test
composer test
npm run build
php artisan queue:work redis --queue=server-commands,default --tries=1 --timeout=300
```

Important environment values:

```env
SERVERPANEL_EXECUTION_API_BASE_URL=http://127.0.0.1:9500
SERVERPANEL_EXECUTION_API_TOKEN=change-me
SERVERPANEL_FILEMANAGER_API_URL=http://127.0.0.1:9500/api/v1/filemanager
QUEUE_CONNECTION=redis
```

### Rust Execution API

```bash
cd /var/www/drust
cargo fmt
cargo test
cargo build
```

Run manually for development:

```bash
DRUST_API_TOKEN=change-me cargo run -- --port 9500 --token change-me
```

Production service:

```bash
sudo systemctl status drust
sudo systemctl restart drust
```

The service should listen on localhost only. Do not expose `drust` directly to the public internet.

### dscript

```bash
cd /var/www/dscript
./dpanel doctor
sudo ./dpanel chain install
sudo ./dpanel script list
```

Many scripts call the `drust` API through `scripts/_drust-api.sh`.

## API Boundary

`drust` endpoints live under:

```text
http://127.0.0.1:9500/api/v1
```

Protected calls require:

```http
Authorization: Bearer <DRUST_API_TOKEN>
Content-Type: application/json
```

Common endpoints:

- `GET /api/v1/health-checker`
- `POST /api/v1/filemanager/create`
- `POST /api/v1/filemanager/write`
- `POST /api/v1/filemanager/upload`
- `POST /api/v1/filemanager/unzip`
- `POST /api/v1/filemanager/move`
- `POST /api/v1/filemanager/delete`
- `POST /api/v1/filemanager/fix-permissions`
- `POST /api/v1/sync-vhost`
- `POST /api/v1/fix-web-stack`
- `POST /api/v1/fix-panel-web-stack`
- `POST /api/v1/database-request`
- `POST /api/v1/ssl/ensure`
- `POST /api/v1/php/config`
- `POST /api/v1/script/run`

See `drust/docs.md` for detailed API examples.

## Permission And Ownership Model

Website roots normally live at:

```text
/home/<site-user>/public_html
```

The durable target state is:

- owner: `<site-user>`
- group: `www-data`
- directories: group writable with setgid
- files: user/group writable where needed
- default ACL: `<site-user>` and `www-data` can write future files
- public/world write access is removed

This prevents common Laravel/PHP errors such as:

```text
file_put_contents(...): Failed to open stream: Permission denied
```

Repair all projects:

```bash
/var/www/dscript/scripts/fix-permissions.sh --all
```

Repair one project:

```bash
/var/www/dscript/scripts/fix-permissions.sh --path /home/example/public_html
/var/www/dscript/scripts/fix-permissions.sh --user example
```

Direct API body:

```json
{
  "all": true
}
```

or:

```json
{
  "username": "example",
  "root_path": "/home/example/public_html"
}
```

## Adding A Feature

Use this path when adding a new server operation.

1. Add database/model/UI changes in `dpanel` if the feature has user-facing state.
2. Add a service class in `dpanel/app/Services` to call the execution API.
3. Add or extend a `drust` endpoint for privileged host operations.
4. Keep path validation strict in `drust`; never trust a browser-provided path directly.
5. Add a wrapper script in `dscript/scripts` only when the operation is also useful as maintenance tooling.
6. Add tests or command-level verification notes.
7. Update docs for the new endpoint and environment variables.

Good separation:

```text
dpanel: "Create website example.com with PHP 8.3"
drust: "Write vhost files, prepare root, reload services"
dscript: "Repair or bootstrap the stack if something is missing"
```

Avoid:

- calling arbitrary shell from a Laravel controller
- exposing `drust` outside localhost
- accepting paths outside `/home/<user>` for filemanager operations
- using `chmod 777` as a fix
- storing secrets in logs, reports, or frontend props

## Coding Standards

### Laravel

- Keep controllers thin; move host/API logic to services.
- Use queued jobs for slow operations.
- Use encrypted casts for secrets.
- Keep authorization close to controllers and policies.
- Prefer typed request validation before calling services.
- Do not return secret fields to Inertia props or JSON responses.

### Vue/Inertia

- Pages live in `dpanel/resources/js/Pages`.
- Shared UI should live in `dpanel/resources/js/Components`.
- Keep operational tools compact and task-focused.
- Prefer clear states for loading, success, failure, and approval-required actions.

### Rust

- Validate all input before touching the filesystem or services.
- Keep endpoints small and delegate reusable logic to modules.
- Use `Result<T, String>` consistently with clear operator-facing errors.
- Run `cargo fmt` before committing.
- Keep privileged operations explicit and auditable.

### Shell

- Use `set -euo pipefail`.
- Quote variables.
- Validate arguments before calling destructive commands.
- Prefer wrapper scripts that call `drust` rather than duplicating privileged logic.

## Testing And Verification

Before opening a pull request, run the relevant checks:

```bash
cd /var/www/dpanel
php artisan test
npm run build
```

```bash
cd /var/www/drust
cargo fmt
cargo test
cargo build
```

For execution API changes:

```bash
sudo systemctl restart drust
/var/www/dscript/scripts/fix-permissions.sh --path /home/example/public_html
```

For web-stack changes:

```bash
sudo nginx -t
sudo apache2ctl configtest
sudo systemctl reload nginx
sudo systemctl reload apache2
```

For Laravel permissions:

```bash
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan queue:work --once
```

## Upgrade Workflow

1. Pull or apply source changes.
2. Review changed environment variables.
3. Run Laravel migrations.
4. Build frontend assets.
5. Build `drust`.
6. Restart workers and services.
7. Run permission repair if files were deployed by another user.
8. Smoke-test login, website list, filemanager, vhost sync, and one API health check.

Example:

```bash
cd /var/www/dpanel
composer install --no-dev --optimize-autoloader
npm install
npm run build
php artisan migrate --force
php artisan optimize:clear

cd /var/www/drust
cargo build --release
sudo systemctl restart drust

/var/www/dscript/scripts/fix-permissions.sh --all
sudo systemctl restart supervisor
```

## Security Notes

- Keep `DRUST_API_TOKEN` and `SERVERPANEL_EXECUTION_API_TOKEN` synchronized and secret.
- Keep `drust` bound to `127.0.0.1`.
- Treat `/etc/drust/drust.env` and Laravel `.env` as sensitive files.
- Do not log passwords, private keys, API tokens, database passwords, or SSH key contents.
- Prefer allowlists for commands and paths.
- Back up service config before writing nginx, Apache, PHP-FPM, or mail files.

## Troubleshooting

### `Unauthorized` from `drust`

Check token alignment:

```bash
sudo cat /etc/drust/drust.env
grep SERVERPANEL_EXECUTION_API_TOKEN /var/www/dpanel/.env
```

### `Permission denied` in Laravel

Run:

```bash
/var/www/dscript/scripts/fix-permissions.sh --all
```

Then retry as the web user:

```bash
cd /home/example/public_html
sudo -u www-data php artisan optimize:clear
```

### `drust` is not responding

```bash
sudo systemctl status drust
sudo journalctl -u drust -n 100 --no-pager
```

### Vhost or web server reload failed

```bash
sudo nginx -t
sudo apache2ctl configtest
sudo journalctl -u nginx -n 100 --no-pager
sudo journalctl -u apache2 -n 100 --no-pager
```

## Public Contribution Checklist

Before submitting code:

- explain the user-facing problem
- keep `dpanel`, `drust`, and `dscript` responsibilities separate
- add migrations for schema changes
- add or update tests where behavior changes
- run format/build commands for touched layers
- update `README.md`, `DEVELOPER.md`, or `drust/docs.md` when APIs or setup change
- avoid committing `.env`, logs, database dumps, private keys, or generated secrets

## Project Philosophy

ServerPanel should be practical, secure, and understandable. The control panel should make hosting operations easier without hiding risky system changes. When in doubt, prefer explicit validation, auditable API calls, small modules, and repair commands that can be safely repeated.
