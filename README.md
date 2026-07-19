# dPanel + dScript Root Guide

This root folder contains the two main parts of the project:

- `dpanel` - the Laravel panel application
- `dscript` - installer, bootstrap, and server maintenance scripts

Canonical paths:

- Panel app: `/var/www/dpanel`
- Script repo: `/var/www/dscript`

## Installation

1. Make sure both folders are present in `/var/www`.
2. Install the panel from the script repository:

```bash
cd /var/www/dscript
sudo env \
  PANEL_INSTALL_BASE_URL="https://your-domain.example" \
  SERVER_BASE_DIR="/var/www" \
  PANEL_APP_DIR="/var/www/dpanel" \
  PANEL_DOMAIN="panel.example.com" \
  bash install.sh panel install
```

3. If you want a secure production-style install, add modules as needed:

```bash
sudo env \
  PANEL_INSTALL_BASE_URL="https://your-domain.example" \
  SERVER_BASE_DIR="/var/www" \
  PANEL_APP_DIR="/var/www/dpanel" \
  PANEL_DOMAIN="panel.example.com" \
  PANEL_MODULES="apache,nginx,php,mariadb,supervisor,firewall,fail2ban,ssl,ssh-root-login" \
  bash install.sh panel install
```

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
