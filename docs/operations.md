# Operations

Everyday commands for running, updating, and troubleshooting a dPanel server.

## Contents

- [What to run after a change](#what-to-run-after-a-change)
- [Build and deploy](#build-and-deploy)
- [Maintenance and repair](#maintenance-and-repair)
- [Troubleshooting](#troubleshooting)

## What to run after a change

| Changed area | Command |
| --- | --- |
| Rust source | `sudo /var/www/drust/deploy/install-service.sh` |
| Vue / CSS | `cd /var/www/dpanel && npm run build` |
| Laravel config or routes | `cd /var/www/dpanel && sudo -u www-data php artisan optimize:clear` |
| Laravel migration | `cd /var/www/dpanel && sudo -u www-data php artisan migrate --force` |
| Installer or runtime scripts | `sudo dpanel runtime refresh` |
| Website permissions | `sudo dpanel script run fix-permissions --all` |

## Build and deploy

### drust

Test and build with a separate target directory to avoid conflicts with the
root-owned production build:

```bash
cd /var/www/drust
CARGO_TARGET_DIR="/tmp/drust-${USER}-target" cargo test
CARGO_TARGET_DIR="/tmp/drust-${USER}-target" cargo build --release
```

Building alone does not change production. To build, install the launchers and
units, align the API token, and restart both Rust services:

```bash
sudo /var/www/drust/deploy/install-service.sh
```

Restart only the part you changed:

```bash
sudo systemctl restart drust.service          # privileged API
sudo systemctl restart edge-gateway.service   # HTTP / PHP / TLS gateway
```

### dPanel

```bash
cd /var/www/dpanel
npm run build
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan migrate --force   # only after adding a migration
```

## Maintenance and repair

### Health and recovery

```bash
sudo dpanel doctor              # diagnose
sudo dpanel doctor --fix        # diagnose and repair
sudo dpanel chain verify
sudo dpanel chain repair
sudo dpanel runtime refresh
```

Preview any installer action without changing the server:

```bash
sudo dpanel --dry-run chain update
```

### PHP-FPM

Validate and reload one PHP version:

```bash
sudo php-fpm8.3 -t
sudo systemctl reload-or-restart php8.3-fpm
```

### Databases

Reapply one database, its user, and its database-scoped privileges:

```bash
sudo dpanel script run database-request upsert <db> <user> '<password>' 127.0.0.1 3306 utf8mb4 utf8mb4_unicode_ci
```

Verify the grants:

```bash
sudo mariadb -e "SHOW GRANTS FOR 'example_user'@'127.0.0.1';"
sudo mariadb -e "SHOW GRANTS FOR 'example_user'@'localhost';"
```

## Troubleshooting

### Websites do not load

```bash
systemctl is-active edge-gateway.service
journalctl -u edge-gateway.service -n 100 --no-pager
ls -la /run/php
systemctl status php8.3-fpm
curl -H 'Host: example.com' http://127.0.0.1/
```

### Panel actions fail

```bash
systemctl is-active drust.service
journalctl -u drust.service -n 100 --no-pager
curl http://127.0.0.1:9500/health
```

If drust answers `Unauthorized`, make sure `DRUST_API_TOKEN` in
`/etc/drust/drust.env` matches `SERVERPANEL_EXECUTION_API_TOKEN` in
`/var/www/dpanel/.env`.

### SSL is issued but the browser shows no certificate

The website list shows the certificate saved in the database. The manage page
does a live TLS handshake with the gateway, so it shows what visitors actually
get. When they disagree, the gateway has not loaded the certificate yet.

```bash
# What the gateway serves for the domain (SNI):
openssl s_client -connect 127.0.0.1:443 -servername example.com </dev/null 2>/dev/null \
  | openssl x509 -noout -subject -enddate
# The certificate on disk:
sudo openssl x509 -in /etc/letsencrypt/live/example.com/fullchain.pem -noout -subject -enddate
# Is the gateway listening for reloads?
redis-cli PUBSUB NUMSUB edge:reload
journalctl -u edge-gateway.service -n 100 --no-pager | grep -i -E 'tls|reload'
```

The gateway swaps certificates in place on a reload, so a restart is not
needed. To force one:

```bash
redis-cli PUBLISH edge:reload '{}'
```

If the handshake still fails, the server may be running an old gateway build.
Run `sudo ./installer.sh update` (see
[Versions and updates](installation.md#versions-and-updates)).

### Files cannot be created, edited, or uploaded

```bash
namei -l /home/<site-user>/public_html
getfacl /home/<site-user>/public_html
sudo dpanel script run fix-permissions --user <site-user>
```

Never use `chmod -R 777` as a fix.

### Still stuck?

Run `sudo dpanel doctor`, collect the relevant logs with secrets removed, and
open an issue following the [Contributing Guide](../CONTRIBUTING.md#reporting-issues).
