# Installation Guide

This guide covers a first dPanel install, the configuration files, and the
supported way to set up and repair website file permissions.

## Contents

- [Requirements](#requirements)
- [Install dPanel](#install-dpanel)
- [Versions and updates](#versions-and-updates)
- [Installed paths](#installed-paths)
- [Configuration](#configuration)
- [Website ownership and permissions](#website-ownership-and-permissions)
- [Verify the installation](#verify-the-installation)

## Requirements

- A fresh Linux server you control, with `sudo` or root access
- Ports `80` and `443` open to the internet
- A domain name for the panel (for example `panel.example.com`)
- Ubuntu 22.04 or newer, Debian 12, or a RHEL-family distribution

No extra configuration is needed for Ubuntu 22.04. Its packages are too old in
five places, and the installer handles each one:

| Tool | Ubuntu 22.04 package | What the installer uses |
| --- | --- | --- |
| PHP 8.2+ | Only 8.1 | `ppa:ondrej/php` |
| Composer | 2.2, and it pulls in php8.1 | Official composer at `/usr/local/bin/composer` |
| Node.js | 12, too old for Vite | Node.js 20 in `/opt/dpanel`, linked into `/usr/local/bin` |
| Rust | No `rustup` package | The official rustup installer |
| Tesseract (image OCR) | 4.1; drust needs 5 | `ppa:alex-p/tesseract-ocr5`. Set `DRUST_TESSERACT_PPA=0` to skip it; drust then builds without OCR |

Website installers (Laravel, Drupal, and others) look for `composer` and `npm`
in `/usr/local/bin` first, then `/usr/bin`. If an app install fails with
`composer is not installed` or `npm is not installed`, run
`sudo dpanel chain update`.

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

## Versions and updates

Everything is downloaded straight from GitHub. There is no separate download
server and no zip file to manage: the installer uses GitHub's source zip of the
version you choose.

| `DPANEL_VERSION` | Installs |
| --- | --- |
| `latest` *(default)* | The highest release tag, such as `v1.2.3`. Falls back to `main` if the repository has no tags yet |
| `v1.2.3` | That exact release tag |
| `main`, another branch, or a commit SHA | That branch or commit |

```bash
sudo ./installer.sh                                  # latest release
sudo env DPANEL_VERSION=v1.2.3 ./installer.sh        # one release
sudo env DPANEL_VERSION=main ./installer.sh          # development branch
sudo env DPANEL_REPO=your-user/dpanel DPANEL_VERSION=my-branch ./installer.sh   # a fork
```

Update an existing server the same way:

```bash
sudo ./installer.sh update                           # to the latest release
sudo env DPANEL_VERSION=v1.3.0 ./installer.sh update # to one release
sudo env DPANEL_VERSION=main ./installer.sh update   # unreleased fixes on main
```

`update` with no version installs the **latest release tag**, not the newest
commit. A fix that is merged to `main` reaches servers only after a new tag is
published, or when you update with `DPANEL_VERSION=main`.

An update runs these steps in order:

1. Copies the selected release into `/var/www/dscript`, `/var/www/drust`, and
   `/var/www/dpanel`.
2. Updates each installed module.
3. Rebuilds drust (`cargo build --release`) and restarts `drust.service` and
   `edge-gateway.service`. If the build fails, the old binary keeps running.
4. Refreshes the panel: installs or updates composer, runs `composer install`
   and `php artisan migrate --force`, makes sure Node.js 20+ is installed, and
   runs `npm run build`.
5. Repairs website ownership, regenerates vhosts, records the version in
   `.env`, rebuilds the config cache, and fixes app permissions.

A step that fails logs a warning and the update continues. Check the output
for `[WARN]` lines.

### The version is recorded automatically

Each install or update writes the selected version to `/var/www/dpanel/.env`,
so the panel sidebar and footer always show what is running:

```dotenv
APP_VERSION=1.2.3                                        # tag without the "v"; main-<sha> for a branch
DPANEL_RELEASE_REF=v1.2.3                                # tag, branch, or commit that was installed
DPANEL_RELEASE_COMMIT=3f2c1e0d9b8a7f6e5d4c3b2a1f0e9d8c7b6a5f4e
```

Check the installed version at any time:

```bash
sudo grep -E '^(APP_VERSION|DPANEL_RELEASE_)' /var/www/dpanel/.env
```

### Publishing a release (maintainers)

Push a version tag. Nothing else is needed. A GitHub Release is optional.

```bash
git tag -a v1.2.3 -m "dPanel 1.2.3"
git push origin v1.2.3
```

Use `vMAJOR.MINOR.PATCH` tags. Pre-release tags such as `v2.0.0-beta` are never
picked by `latest`, but can still be installed with
`DPANEL_VERSION=v2.0.0-beta`.

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
