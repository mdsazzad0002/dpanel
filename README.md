# dPanel Production Manual

dPanel is a Laravel/Vue hosting panel backed by local Rust services:

- `dpanel`: UI, authentication, authorization, records, and queues.
- `drust.service`: bearer-token protected privileged localhost API.
- `edge-gateway.service`: public Rust HTTP/TLS website gateway.
- `dscript`: installation, update, and recovery commands.

This README is the single production and developer manual. The current stack
uses the Rust edge gateway directly and does not generate legacy vhost files.

## Product and Support Model

> **Free forever. The same software for everyone. No license fee, no feature
> lock, and no forced subscription. Users pay only when they request support.**

```text
                         dPanel
                            │
                   Free Core Software
                            │
          ┌─────────────────┴─────────────────┐
          │                                   │
   Self-Service User                    Supported User
          │                                   │
   Software: free                      Software: free
   Updates: free                       Updates: free
   Features: identical                 Features: identical
   Community help                      Paid expert assistance
          │                                   │
          └──────── Optional donation ────────┘
```

### Operating Structure

1. Maintain one public product and one release channel for every user.
2. Include all software features and updates at no license cost.
3. Charge for human work such as installation, migration, troubleshooting,
   priority response, and managed operations.
4. Keep donations optional and treat them as community contributions rather
   than predictable operating revenue.

This structure avoids customer-specific editions, license checks, and separate
feature branches. Engineering effort stays focused on one codebase, while the
commercial service remains independent from software access.

### Revenue and Capacity Calculation

Use support revenue—not downloads or active installations—for planning:

```text
Monthly support revenue
  = one-time support jobs
  + recurring support plans

One-time support jobs
  = completed jobs × average fee per job

Recurring support plans
  = active supported customers × average monthly support fee

Available support hours
  = support engineers × billable hours per engineer

Required support hours
  = total estimated hours across all accepted support requests

Operating margin
  = support revenue + donations - support cost - infrastructure cost
```

Before accepting more paid work, `Required support hours` should remain below
`Available support hours`. Donations should be excluded from the baseline
forecast because they are voluntary and may vary from month to month.

Unlike a traditional per-server licensing model, dPanel monetizes optional
expert service without restricting access to the software itself.

## Request Flow

```text
Browser
  -> edge-gateway.service (:80/:443)
  -> active website matched from the DPanel database
  -> static file, PHP-FPM, DPanel, or phpMyAdmin dispatch

DPanel
  -> drust.service (127.0.0.1:9500)
  -> privileged filesystem, user, database, SSL, PHP, and script operations
```

There is no website preview URL. Websites open through their configured live
hostname.

## Installation

```bash
curl -fsSL https://installer.dengrweb.com/installer.sh -o installer.sh
chmod +x installer.sh
sudo ./installer.sh
```

Install or refresh the Rust services:

```bash
sudo /var/www/drust/deploy/install-service.sh
sudo systemctl status drust.service edge-gateway.service
```

## Installed Paths

```text
/var/www/dpanel       Laravel/Vue panel
/var/www/drust        Rust API and edge gateway
/var/www/dscript      installer and recovery runtime
/var/www/phpmyadmin   bundled phpMyAdmin
/etc/drust/drust.env
/etc/drust/edge-gateway.env
```

## Configuration

`/etc/drust/drust.env`:

```dotenv
DRUST_API_PORT=9500
DRUST_API_TOKEN=replace-with-a-long-random-token
DRUST_MAX_UPLOAD_SIZE_BYTES=10737418240
DRUST_MAX_ZIP_ENTRIES=100000
DRUST_MAX_ZIP_EXPANDED_BYTES=21474836480
DRUST_SCRIPTS_DIR=/opt/dpanel/runtime/scripts
DRUST_DATABASE_ADMIN_USER=
DRUST_DATABASE_ADMIN_PASSWORD=
DRUST_DATABASE_ADMIN_HOST=127.0.0.1
DRUST_DATABASE_ADMIN_PORT=3306
```

`/etc/drust/edge-gateway.env`:

```dotenv
DRUST_HTTP_BIND=0.0.0.0:80
DRUST_HTTPS_BIND=0.0.0.0:443
DRUST_PANEL_DOMAIN=panel.example.com
DRUST_DEFAULT_SITE_ROOT=/var/www/html
DRUST_SITE_POOLS=1
DRUST_SITE_POOL_MAX_CHILDREN=4
```

Apply configuration:

```bash
sudo systemctl restart drust.service edge-gateway.service
```

## Website and PHP Execution

The edge gateway reads active website records containing hostname, scope,
`site_owner`, document root, PHP version, SSL state, and status.

User-scope PHP websites normally execute through:

```text
/run/php/dpanel-<site_owner>-php<version>.sock
```

If the socket is absent, the gateway validates the Linux user, creates an
ondemand PHP-FPM pool, tests the configuration, reloads PHP-FPM, and waits for
the socket. Invalid owners or provisioning failures fall back to the shared
PHP-FPM socket without failing the request.

System-scope websites, DPanel, and phpMyAdmin always use the shared `www-data`
PHP-FPM pool.

Website roots normally live at `/home/<site-user>/public_html`. Files should be
owned by the site account. Shared PHP fallback requires ACL/group permission for
`www-data`.

## Database Provisioning

Database creation:

1. Creates the database when absent.
2. Creates or updates its user and password.
3. Grants `ALL PRIVILEGES` on that database only.
4. Synchronizes `user@127.0.0.1` and `user@localhost` for local hosts.
5. Flushes privileges.

The user can create/drop its assigned database and manage all its objects, but
does not receive global server-admin privileges.

```http
POST http://127.0.0.1:9500/api/v1/database-request
Authorization: Bearer <DRUST_API_TOKEN>
Content-Type: application/json
```

```json
{
  "action": "create",
  "database_name": "example_db",
  "database_user": "example_user",
  "database_password": "strong-secret",
  "database_host": "127.0.0.1",
  "database_port": 3306,
  "charset": "utf8mb4",
  "collation": "utf8mb4_unicode_ci"
}
```

Allowed actions: `create`, `upsert`.

## Drust API

Protected endpoints require `Authorization: Bearer <DRUST_API_TOKEN>`.

```text
GET  /health
GET  /api/v1/health-checker
POST /api/v1/create-admin-user
POST /api/v1/disable-root-login
POST /api/v1/database-request
POST /api/v1/filemanager/user
POST /api/v1/filemanager/create
POST /api/v1/filemanager/write
POST /api/v1/filemanager/upload
POST /api/v1/filemanager/unzip
POST /api/v1/filemanager/move
POST /api/v1/filemanager/delete
POST /api/v1/filemanager/fix-permissions
POST /api/v1/ssl/ensure
POST /api/v1/php/config
POST /api/v1/script/run
```

Keep the API bound to localhost; never expose it publicly.

## File Manager Safety

User file operations stay inside `/home/<username>`. Drust validates the Linux
user and path, rejects traversal and unsafe symlinks, applies account ownership,
preserves dotfiles, and enforces upload/archive limits.

## Command Cookbook

### Developer: test and build Drust

```bash
cd /var/www/drust
CARGO_TARGET_DIR="/tmp/drust-${USER}-target" cargo test
CARGO_TARGET_DIR="/tmp/drust-${USER}-target" cargo build --release
```

The separate target avoids permission conflicts with the root-owned production
build. Building alone does not restart production. To build, install launchers and
units, align the API token, and restart both Rust services:

```bash
sudo /var/www/drust/deploy/install-service.sh
```

Restart only the component changed:

```bash
sudo systemctl restart drust.service          # privileged API change
sudo systemctl restart edge-gateway.service   # HTTP/PHP/TLS gateway change
```

### Developer: build DPanel

```bash
cd /var/www/dpanel
npm run build
sudo -u www-data php artisan optimize:clear
```

After adding a migration:

```bash
cd /var/www/dpanel
sudo -u www-data php artisan migrate --force
```

### Installer: refresh and repair

```bash
sudo dpanel runtime refresh
sudo dpanel doctor
sudo dpanel doctor --fix
sudo dpanel chain verify
sudo dpanel chain repair
```

Preview an installer action without changing the server:

```bash
sudo dpanel --dry-run chain update
```

### Targeted fixes

Repair all website filesystem ownership/ACLs:

```bash
sudo dpanel script run fix-permissions --all
```

Repair only one website account or path:

```bash
sudo dpanel script run fix-permissions --user <site-user>
sudo dpanel script run fix-permissions --user <site-user> --path /home/<site-user>/public_html
```

Validate and restart one PHP-FPM version:

```bash
sudo php-fpm8.3 -t
sudo systemctl reload-or-restart php8.3-fpm
```

Reapply one database/user configuration and its database-scoped privileges:

```bash
sudo dpanel script run database-request upsert <db> <user> '<password>' 127.0.0.1 3306 utf8mb4 utf8mb4_unicode_ci
```

Check services and recent logs:

```bash
sudo systemctl status drust.service edge-gateway.service
sudo journalctl -u drust.service -n 100 --no-pager
sudo journalctl -u edge-gateway.service -n 100 --no-pager
```

Quick decision map:

| Changed area | Run |
| --- | --- |
| Rust source | `sudo /var/www/drust/deploy/install-service.sh` |
| Vue/CSS | `cd /var/www/dpanel && npm run build` |
| Laravel config/routes | `cd /var/www/dpanel && sudo -u www-data php artisan optimize:clear` |
| Laravel migration | `cd /var/www/dpanel && sudo -u www-data php artisan migrate --force` |
| Installer/runtime scripts | `sudo dpanel runtime refresh` |
| Website permissions | `sudo dpanel script run fix-permissions --all` |

Test a hostname without changing DNS:

```bash
curl -H 'Host: example.com' http://127.0.0.1/
```

Verify database grants:

```sql
SHOW GRANTS FOR 'example_user'@'127.0.0.1';
SHOW GRANTS FOR 'example_user'@'localhost';
```

## Troubleshooting

Gateway/PHP:

```bash
systemctl is-active edge-gateway.service
journalctl -u edge-gateway.service -n 100 --no-pager
ls -la /run/php
systemctl status php8.3-fpm
```

Drust API:

```bash
systemctl is-active drust.service
journalctl -u drust.service -n 100 --no-pager
curl http://127.0.0.1:9500/health
```

Filesystem permissions:

```bash
namei -l /home/<site-user>/public_html
getfacl /home/<site-user>/public_html
```

Database permissions:

```bash
sudo mariadb -e "SHOW GRANTS FOR 'example_user'@'127.0.0.1';"
sudo mariadb -e "SHOW GRANTS FOR 'example_user'@'localhost';"
```

## Development Rules

- DPanel owns UI, authorization, records, and workflows.
- Drust owns privileged host operations.
- Edge gateway owns public HTTP/TLS dispatch.
- Dscript owns installation and recovery.
- Prefer a validated Drust endpoint over privileged shell execution in Laravel.
- Validate usernames, identifiers, paths, and versions before use.
- Never log tokens, passwords, private keys, or customer data.
- Run relevant Rust, PHP, Laravel, and frontend checks before deployment.

## Policy Files

- [Contributing](CONTRIBUTING.md)
- [Security](SECURITY.md)
- [License](LICENSE)

These policy files intentionally remain separate from the production manual.
