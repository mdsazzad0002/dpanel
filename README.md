# dPanel + dScript Root Guide

This root folder contains the two main parts of the project:

- `dpanel` - the Laravel panel application
- `dscript` - installer, bootstrap, and server maintenance scripts

Canonical paths:

- Panel app: `/var/www/dpanel`
- Script repo: `/var/www/dscript`

## Installation

Use the separate installer, which delegates all work to dscript:

```bash
sudo /var/www/installer.sh chain install
```

`installer.sh` downloads `dscript.zip`, extracts it directly into
`/var/www/dscript`, restores executable permissions, registers `dpanel` and
`panel` under `/usr/local/bin`, and runs the dscript chain.
There is no separate `/var/www/installer/` directory anymore.

Or use dscript directly:

```bash
sudo /var/www/dscript/dpanel chain install
sudo /var/www/dscript/dpanel module nginx update
/var/www/dscript/dpanel doctor
```

See the complete command, module, script, environment and recovery reference in
[`dscript/guide.md`](dscript/guide.md).

For a fresh device/server checklist, see
[`dscript/guide.md#new-server-install-checklist`](dscript/guide.md#new-server-install-checklist).

## First Run

The first install flow can:

- create the panel runtime
- write database credentials
- configure web stack files
- generate a starter website index file

If you need to repair the panel web stack after moving the app root, run:

```bash
sudo /var/www/dscript/scripts/fix-dpanel-root.sh panel.example.com
```

## Usage

### Update the installer/runtime

```bash
cd /var/www/dscript
sudo bash update.sh
```

### Create or repair panel web stack

```bash
sudo /var/www/dscript/scripts/fix-panel-web-stack.sh panel.example.com
sudo /var/www/dscript/scripts/fix-panel-web-stack.sh installer.likesoftbd.com

sudo /var/www/dscript/scripts/fix-panel-web-stack.sh installer.likesoftbd.com \
  --alias installer.localhost \
  --alias panel.localhost
```

### Reset Apache and all vhosts

```bash
sudo bash /var/www/dscript/scripts/reset-web-stack.sh --yes
```

This backs up `/etc/apache2` and `/etc/nginx`, removes enabled vhosts, writes fresh defaults, and restarts the services.

### Sync a website vhost

```bash
sudo /var/www/dscript/scripts/sync-vhost.sh sync example.com /home/example/public_html 8.3
sudo /var/www/dscript/scripts/sync-vhost.sh sync likesoftbd.com /var/www/html 8.3

sudo /var/www/dscript/scripts/sync-vhost.sh sync likesoftbd.com /var/www/html 8.3 \
  --alias localhost \
  --alias init.localhost
```

### Create a demo site page

The panel can generate a starter `index.html` inside the selected website root on first creation.

## Environment Variables

Common variables used by the installer:

- `PANEL_INSTALL_BASE_URL`
- `SERVER_BASE_DIR`
- `PANEL_APP_DIR`
- `PANEL_DOMAIN`
- `PANEL_MODULES`
- `PANEL_DB_NAME`
- `PANEL_DB_USER`
- `PANEL_DB_PASSWORD`
- `PANEL_DB_HOST`
- `PANEL_DB_PORT`
- `PANEL_SERVER_ALIAS`

## Notes

- Script discovery now points to `/var/www/dscript`.
- The panel app is expected at `/var/www/dpanel`.
- For development, you can set a custom alias with `PANEL_SERVER_ALIAS`.
