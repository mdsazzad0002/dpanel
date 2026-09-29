# Installation Guide

This guide covers a first dPanel install, the configuration files, and the
supported way to set up and repair website file permissions.

## Contents

- [Requirements](#requirements)
- [Install dPanel](#install-dpanel)
- [Installed paths](#installed-paths)
- [Configuration](#configuration)
- [Website ownership and permissions](#website-ownership-and-permissions)
- [Verify the installation](#verify-the-installation)

## Requirements

- A fresh Linux server you control, with `sudo` or root access
- Ports `80` and `443` open to the internet
- A domain name for the panel (for example `panel.example.com`)

## Install dPanel

Download and run the installer:

```bash
curl -fsSL https://raw.githubusercontent.com/mdsazzad0002/dpanel/main/installer.sh -o installer.sh
chmod +x installer.sh
sudo ./installer.sh
```

The installer downloads the release, installs the `dpanel` command, and then
runs the default install chain. To see what an action would do without changing
the server, use `--dry-run`:

```bash
sudo dpanel --dry-run chain install
```

To install or refresh the Rust services on their own:

```bash
sudo /var/www/drust/deploy/install-service.sh
```

> **Note for maintainers:** the install URL always serves `installer.sh` from
> the `main` branch. Pushing to `main` is the only step needed to publish
> installer changes.

## Installed paths

```text
/var/www/dpanel               Laravel/Vue panel
/var/www/drust                Rust API and edge gateway
/var/www/dscript              Installer and recovery toolkit
/var/www/phpmyadmin           Bundled phpMyAdmin
/etc/drust/drust.env          drust API configuration
/etc/drust/edge-gateway.env   Edge gateway configuration
```

## Configuration

### `/etc/drust/drust.env`

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

### `/etc/drust/edge-gateway.env`

```dotenv
DRUST_HTTP_BIND=0.0.0.0:80
DRUST_HTTPS_BIND=0.0.0.0:443
DRUST_PANEL_DOMAIN=panel.example.com
DRUST_DEFAULT_SITE_ROOT=/var/www/html
DRUST_SITE_POOLS=1
DRUST_SITE_POOL_MAX_CHILDREN=4
```

### Panel token

The panel talks to drust with the same token. Keep these values identical in
`/var/www/dpanel/.env`:

```dotenv
SERVERPANEL_EXECUTION_API_TOKEN=the_same_value_as_DRUST_API_TOKEN
```

### Apply changes

Restart the services after editing either environment file:

```bash
sudo systemctl restart drust.service edge-gateway.service
```

## Website ownership and permissions

Website roots live at `/home/<site-user>/public_html` and are owned by the
matching site account. Never use broad permissions such as `chmod -R 777`.

| Task | Command |
| --- | --- |
| Repair every managed website | `sudo dpanel script run fix-permissions --all` |
| Repair one account | `sudo dpanel script run fix-permissions --user <site-user>` |
| Repair one path | `sudo dpanel script run fix-permissions --user <site-user> --path /home/<site-user>/public_html` |

Run the full repair once after the first install or after migrating projects.

To inspect ownership, directory traversal, and ACLs:

```bash
namei -l /home/<site-user>/public_html
getfacl /home/<site-user>/public_html
```

The shared PHP fallback pool may need ACL or group access for `www-data`. Use
the repair command above so ownership and ACLs stay consistent with dPanel's
website records.

## Verify the installation

Check the services and their recent logs:

```bash
sudo systemctl status drust.service edge-gateway.service
sudo journalctl -u drust.service -n 100 --no-pager
sudo journalctl -u edge-gateway.service -n 100 --no-pager
curl http://127.0.0.1:9500/health
```

Test a website hostname locally without changing DNS:

```bash
curl -H 'Host: example.com' http://127.0.0.1/
```

Run the built-in health check:

```bash
sudo dpanel doctor
```

## Next steps

- [Operations](operations.md): everyday commands and troubleshooting
- [dscript CLI](dscript.md): the full `dpanel` command reference
- [Security Policy](../SECURITY.md): hardening checklist for public servers
