# dscript 2.0 guide

Category-wise documentation is available under [`docs/README.md`](docs/README.md).
Use that directory when you need a separate guide for installation, modules,
vhosts, scripts, recovery or environment configuration.

`dscript` is the shell toolkit for installing, updating, diagnosing and repairing
dPanel servers. The public command is `dpanel`.

The standalone `/var/www/installer.sh` is only a downloader and runtime
preparer. It downloads the release archive, installs `/var/www/dscript/dpanel`,
then hands control to dscript. Installation and system update decisions do not
belong in the standalone installer.

```text
installer.sh              -> dpanel default-install
installer.sh update       -> dpanel chain update
```

## 1. Quick start

```bash
# Discover commands without changing the server
/var/www/dscript/dpanel
/var/www/dscript/dpanel help
/var/www/dscript/dpanel list
/var/www/dscript/dpanel doctor

# Preview a change
/var/www/dscript/dpanel --dry-run chain install

# Complete installation
sudo /var/www/dscript/dpanel default-install

# One independent operation
sudo /var/www/dscript/dpanel module nginx update
```

After a chain install, the public command is available:

```bash
dpanel help
```

## 2. The two process types

### 2.1 Chain process

A chain runs a complete ordered workflow:

```bash
dpanel chain install [module,...]
dpanel chain update
dpanel chain verify
dpanel chain repair
```

Default chain module order:

```text
apache -> nginx -> php -> mariadb -> supervisor -> firewall -> fail2ban
```

The top-level default install adds non-module services around that chain:

```text
apache -> nginx -> php -> mariadb -> supervisor -> rust/drust -> firewall -> fail2ban -> ssl -> postfix -> dovecot -> nodejs
```

Choose modules with either syntax:

```bash
sudo dpanel chain install apache,nginx,php,mariadb
sudo dpanel chain install apache nginx php mariadb
sudo env PANEL_MODULES="nginx,php,mariadb,redis" dpanel chain install
```

The chain stops on the first failure. It does not continue to the next module
after an error. Review the first `[ERROR]`, inspect the log, run
`dpanel doctor`, then retry only the failed item.

### 2.2 Individual process

An individual process changes one module only:

```bash
sudo dpanel module nginx install
sudo dpanel module nginx update
sudo dpanel module nginx remove
sudo dpanel module nginx reinstall
dpanel module nginx info
```

The short form is equivalent:

```bash
sudo dpanel nginx update
```

Legacy forms remain supported:

```bash
sudo dpanel install nginx
sudo dpanel update nginx
sudo dpanel remove nginx
```

## 3. Global options

| Option | Purpose |
|---|---|
| `-h`, `--help` | Print top-level help |
| `-V`, `--version` | Print dscript version |
| `-n`, `--dry-run` | Preview a mutating top-level operation |
| `-y`, `--yes` | Supply confirmation where supported |
| `-v`, `--verbose` | Enable verbose diagnostics for supporting scripts |

`--dry-run` protects commands dispatched by dscript. Do not assume that directly
executing an arbitrary file under `scripts/` supports dry-run.

## 3.1 Interactive menu

Run the bare command to open the guided menu:

```bash
dpanel
```

Run help explicitly when you need the command reference:

```bash
dpanel help
```

Menu items include the matching command beside the option. Mutating actions
show a confirmation screen with `Will run:` and the current configuration
summary, then require the exact answer `yes` before anything changes.

Common menu command hints:

```text
Default Install                         dpanel default-install
Default Update                          dpanel chain update
Apache/Nginx install                    dpanel module apache install && dpanel module nginx install
PHP install all                         dpanel php install all
PHP set default                         dpanel php default <version>
PHP repair one version                  dpanel php reinstall <version>
Website SSL                             dpanel script run issue-ssl <domain> <root> 0 [--alias domain]
Website SSL with www                    dpanel script run issue-ssl <domain> <root> 1 [--alias domain]
Rust/Drust install                      bash /var/www/drust/deploy/install-service.sh
Mail install                            install postfix + dovecot packages
Node.js install                         install nodejs npm packages
Create Linux user                       dpanel filemanager user ensure <user> --home <path> --shell <shell>
Change root password                    passwd root
Disable SSH root login                  dpanel script run disable-root-login
```

## 4. Module reference

List all modules and recorded versions:

```bash
dpanel module list
dpanel module <name> info
```

| Module | Purpose | Individual examples |
|---|---|---|
| `apache` | Apache backend web server | `dpanel apache install` |
| `nginx` | Nginx frontend web server | `dpanel nginx update` |
| `php` | Multi-version PHP/FPM | `dpanel php install 8.3` |
| `mariadb` | MariaDB server | `dpanel mariadb install` |
| `redis` | Redis service | `dpanel redis install` |
| `supervisor` | Supervisor process manager | `dpanel supervisor update` |
| `queue` | Queue runtime based on Supervisor | `dpanel queue install` |
| `firewall` | UFW/firewalld baseline | `dpanel firewall install` |
| `fail2ban` | Fail2ban and panel jail template | `dpanel fail2ban install` |
| `ssl` | Certbot packages | `dpanel ssl install` |
| `filemanager` | Safe file and system-user operations | See section 4.2 |
| `admin-user` | Admin creation through drust | `dpanel admin-user install ...` |
| `ssh-root-login` | Disable SSH root login through drust | `dpanel ssh-root-login install` |

Postfix, Dovecot, Node.js and the Rust/Drust service are installed by
`dpanel default-install` from the entrypoint. They are shown in the interactive
menu with direct package or service commands until they become regular modules.

### 4.1 PHP

```bash
dpanel php versions
sudo dpanel php install 8.3
sudo dpanel php install all
sudo dpanel php update 8.3
sudo dpanel php update all
sudo dpanel php reinstall 8.3
sudo dpanel php remove 8.3
sudo dpanel php default 8.3
```

Supported versions come from `repository/modules/php/php.json`, not from a list
embedded in the CLI. On RPM systems the distribution package stream may limit
simultaneous PHP versions.

### 4.2 Filemanager

```bash
dpanel filemanager exists /home/example/public_html
dpanel filemanager file-exists /home/example/public_html/.env
sudo dpanel filemanager create /home/example/public_html /home/example/logs
sudo dpanel filemanager remove /home/example/old
sudo dpanel filemanager user create example --home /home/example --shell /bin/bash
sudo dpanel filemanager user ensure example --site-directory public_html
```

Protected paths and invalid usernames are rejected by the module.

## 5. Maintenance script reference

Discover scripts through the CLI instead of guessing file paths:

```bash
dpanel script list
dpanel script help sync-vhost
dpanel script run php-detect-versions
```

Every maintained shell file has a stable CLI name:

| Name | Usage |
|---|---|
| `create-admin-user` | `script run create-admin-user <username> [password] [email] [ssh-key] [shell] [disable-root]` |
| `create-demo-site` | `script run create-demo-site <root> <domain> [php-version] [start-directory]` |
| `database-request` | `script run database-request <action> <db> <user> <password> [host] [port] [charset] [collation]` |
| `disable-root-login` | `script run disable-root-login` |
| `fix-dpanel-root` | `script run fix-dpanel-root [domain] [options]` |
| `fix-panel-web-stack` | `script run fix-panel-web-stack <domain> [ports] [options]` |
| `fix-web-stack` | `script run fix-web-stack [apache-port] [nginx-port]` |
| `install-roundcube-dovecot-mysql` | Legacy Roundcube helper; not used by the default stack |
| `issue-ssl` | `script run issue-ssl <domain> <root-path> [include-www=0|1]` |
| `php-config-apply` | `script run php-config-apply --version VERSION [settings]` |
| `php-detect-config` | `script run php-detect-config [--version VERSION]` |
| `php-detect-extensions` | `script run php-detect-extensions [--version VERSION]` |
| `php-detect-versions` | `script run php-detect-versions` |
| `reset-web-stack` | `script run reset-web-stack --yes` |
| `sync-vhost` | `script run sync-vhost <action> <domain> <root> [php] [old-domain] [options]` |

### Drust-backed scripts

`create-admin-user`, `disable-root-login`, `fix-web-stack`,
`fix-panel-web-stack` and `sync-vhost` call the protected local drust API. They
load `DRUST_API_TOKEN` from `/etc/drust/drust.env`, then fall back to
`SERVERPANEL_EXECUTION_API_TOKEN` in the panel `.env`.

Supported client variables:

```text
DRUST_API_URL             default http://127.0.0.1:9500
DRUST_API_PORT            default 9500
DRUST_API_TOKEN           bearer token
DRUST_CONNECT_TIMEOUT     default 5 seconds
DRUST_REQUEST_TIMEOUT     default 120 seconds
```

## 6. Website and SSL commands

Generate cached website templates:

```bash
dpanel site:create <domain> <username> [php-version] [ssl] [web-server] [root]
dpanel site:create example.com example 8.3 yes nginx /home/example/public_html
```

Synchronize a real vhost through drust:

```bash
sudo dpanel script run sync-vhost sync example.com /home/example/public_html 8.3
sudo dpanel script run sync-vhost sync example.com /home/example/public_html 8.3 \
  --alias www.example.com --client-max-body-size 128m
```

Issue a certificate after the vhost and document root exist:

```bash
sudo dpanel script run issue-ssl example.com /home/example/public_html 1
sudo dpanel script run issue-ssl example.com /home/example/public_html 0 --alias www.example.com
```

Default mail support installs Postfix and Dovecot only. Roundcube is not part of
the default stack because the panel uses its own mail service integration.

## 7. Database command

The database helper validates identifiers, host, port, charset and collation
before sending SQL to MariaDB/MySQL:

```bash
sudo dpanel script run database-request create appdb appuser 'secret' 127.0.0.1 3306
sudo dpanel script run database-request update appdb appuser 'new-secret' 127.0.0.1 3306
sudo dpanel script run database-request delete appdb appuser 'secret' 127.0.0.1 3306
```

Passwords should be passed through a protected environment or trusted process
where possible because command-line arguments can be visible to other local users.

## 8. Diagnostics and recovery

Start with the read-only doctor:

```bash
dpanel doctor
dpanel chain verify
```

It checks:

- OS metadata;
- Bash, downloader, package manager and systemd availability;
- module manifest JSON;
- syntax of every maintained shell file;
- installed runtime availability.

Safe repair mode creates missing dscript runtime directories and restores script
executable bits. It does not reset web servers, delete sites or remove packages:

```bash
sudo dpanel doctor --fix
sudo dpanel chain repair
```

Destructive recovery remains explicit:

```bash
sudo dpanel --dry-run script run reset-web-stack --yes
sudo dpanel script run reset-web-stack --yes
```

`reset-web-stack` backs up Apache/Nginx configuration before resetting it.

## 9. Logs and failure workflow

```bash
dpanel logs install
dpanel logs update
dpanel logs agent
DSCRIPT_LOG_LINES=300 dpanel logs install
```

If a previously installed `/usr/local/bin/dpanel` does not know the new `script`
or `runtime` commands, refresh the installed runtime from the checkout:

```bash
sudo /var/www/dscript/dpanel runtime refresh
```

When a command fails:

1. Read the first `[ERROR]` and the command/line printed by the launcher.
2. Run `dpanel doctor`.
3. Inspect `dpanel logs install` or the service journal.
4. Run `dpanel module <name> info`.
5. Retry only that item with `dpanel module <name> <action>`.
6. Use a specific maintenance repair script only when the diagnosis points to it.

Service examples:

```bash
systemctl status nginx apache2 mariadb php8.3-fpm drust --no-pager
journalctl -u drust -n 100 --no-pager
nginx -t
apache2ctl configtest
```

## 10. Environment variables

### Paths and remote source

| Variable | Default | Purpose |
|---|---|---|
| `PANEL_INSTALL_BASE_URL` | `https://installer.likesoftbd.com` | Installer website root |
| `PANEL_DSCRIPT_BASE_URL` | `<site>/dscript` | Explicit dscript asset root |
| `DPANEL_BASE_URL` | dscript asset root | Manifest/module download root |
| `DPANEL_BASE_DIR` | `/opt/dpanel` | Preferred installed state root setting |
| `DPANEL_BASE_DIR` | `/opt/dpanel` | Backward-compatible state root setting |
| `DPANEL_RUNTIME_DIR` | `/opt/dpanel/runtime` | Runtime shell files |
| `DPANEL_CACHE_DIR` | `/opt/dpanel/cache` | Manifest cache |
| `DPANEL_MODULE_DIR` | `/opt/dpanel/modules` | Downloaded fallback module scripts |
| `DPANEL_LOG_DIR` | `/opt/dpanel/logs` | Logs |
| `PANEL_APP_DIR` | `/var/www/dpanel` | Laravel application path |
| `PANEL_APP_ENV_FILE` | auto-detected | Explicit panel `.env` |

### Chain behavior

| Variable | Default | Purpose |
|---|---|---|
| `PANEL_MODULES` | default module chain | Comma-separated install list |
| `SKIP_FIREWALL` | `false` | Skip firewall in a chain |
| `SKIP_SSL` | `false` | Skip SSL in a chain |
| `SKIP_TEST` | `false` | Skip completion test message |
| `DSCRIPT_REFRESH_REMOTE` | `false` | Force remote manifest refresh during install |
| `PHP_VERSION` | detected/8.3 | Preferred PHP version |
| `PANEL_DOMAIN` | `installer.likesoftbd.com` | Panel hostname |
| `PANEL_PORT` | `80` | Panel port |

### Database

| Variable | Default |
|---|---|
| `PANEL_DB_NAME` | `dpanel` |
| `PANEL_DB_USER` | `dpanel` |
| `PANEL_DB_PASSWORD` | generated when empty |
| `PANEL_DB_HOST` | `127.0.0.1` |
| `PANEL_DB_PORT` | `3306` |
| `PANEL_DB_CHARSET` | `utf8mb4` |
| `PANEL_DB_COLLATION` | `utf8mb4_unicode_ci` |

## 11. Standalone installer

Public installation:

```bash
curl -fsSL https://installer.likesoftbd.com/installer.sh -o installer.sh
chmod +x installer.sh
sudo ./installer.sh
```

Forward any dscript command:

```bash
sudo ./installer.sh nginx,php,mariadb
sudo ./installer.sh chain install nginx,php,mariadb
```

Local/offline development can point the installer at a local dscript archive:

```bash
sudo env DSCRIPT_ARCHIVE_PATH=/tmp/dscript.zip /var/www/installer.sh
```

It can also install directly from a checkout without downloading or extracting:

```bash
sudo env DSCRIPT_SOURCE_DIR=/var/www/dscript /var/www/installer.sh
sudo env DSCRIPT_SOURCE_DIR=/var/www/dscript /var/www/installer.sh nginx,php,mariadb
```

Source precedence is `DSCRIPT_SOURCE_DIR`, `DSCRIPT_ARCHIVE_PATH`,
`DSCRIPT_ARCHIVE_URL`, then the default live `/dscript.zip`. With no source
parameter, the installer always downloads and extracts the current live ZIP.

The installer owns no server configuration; it downloads the dscript archive,
extracts it into `/var/www/dscript`, assigns executable permissions to shell
entrypoints, registers `dpanel`, and delegates the request to dscript. With no
arguments it delegates to `dpanel default-install`; with `update` it delegates to
`dpanel chain update`.

Build the archive served to clients with:

```bash
cd /var/www/dscript
bash archive.sh /var/www/dscript.zip
```

The public server should expose that file as `/dscript.zip`. For a private/local
archive:

```bash
sudo env DSCRIPT_ARCHIVE_PATH=/tmp/dscript.zip /var/www/installer.sh
```

### New server install checklist

Use this flow when installing dPanel on a fresh device/server.

1. Download and run the installer:

   ```bash
   curl -fsSL https://installer.likesoftbd.com/installer.sh -o installer.sh
   chmod +x installer.sh
   sudo ./installer.sh
   ```

2. For local/offline development, install from the checked-out source instead:

   ```bash
   cd /var/www
   sudo env DSCRIPT_SOURCE_DIR=/var/www/dscript /var/www/installer.sh
   ```

3. Keep panel runtime API configuration minimal. dPanel should use one drust base
   URL and derive execution, database, and filemanager endpoints automatically:

   ```env
   SERVERPANEL_EXECUTION_API_BASE_URL=http://127.0.0.1:9500
   SERVERPANEL_EXECUTION_API_TOKEN=change-this-token
   PHPMYADMIN_URL=http://installer.localhost/phpmyadmin/
   ```

4. After source changes or local development builds, restart drust:

   ```bash
   cd /var/www/drust
   cargo build
   sudo systemctl restart drust
   sudo systemctl status drust --no-pager
   ```

5. Configure phpMyAdmin signon automatically:

   ```bash
   sudo cp /var/www/dscript/scripts/configure-phpmyadmin-signon.sh /opt/dpanel/runtime/scripts/configure-phpmyadmin-signon.sh
   sudo /opt/dpanel/runtime/scripts/configure-phpmyadmin-signon.sh
   ```

6. Verify the important services:

   ```bash
   systemctl status nginx apache2 mariadb drust --no-pager
   curl -fsS http://127.0.0.1:9500/health
   ```

The installer should keep first-time setup in `dscript`, runtime/system actions
in `drust`, and panel UI/application behavior in `dpanel`. Filemanager folder
creation and uploads should go through drust so ownership and permissions are
fixed as root while paths remain scoped inside `/home/{site_user}`.

## 12. Source layout

```text
dscript/
├── dpanel                         user CLI
├── archive.sh                     build dscript.zip for installer.sh
├── bootstrap/core.sh              implementation API and compatibility core
├── core/commands.sh               help, parsing, chain/individual routing, doctor
├── core/package-manager.sh        Debian/RPM package abstraction
├── repository/manifests/          module versions
├── repository/modules/            independent module scripts
├── repository/templates/          managed configuration templates
└── scripts/                        named maintenance scripts
```

Module `install.sh` files remain independently executable, while module
`remove.sh` and `update.sh` files are compatibility wrappers. The recommended
interface is always the `dpanel` command because it provides validation,
logging, help and dry-run behavior.
