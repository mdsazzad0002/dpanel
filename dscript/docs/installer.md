# Installer

`/var/www/installer.sh` downloads the live release archive, extracts it into
`/var/www/dscript`, assigns permissions, registers `/usr/local/bin/dpanel`,
and hands the request to `dscript/dpanel`.

The standalone installer does not install modules by itself. All real install
and update work is transferred to the dpanel CLI:

```text
installer.sh                  -> dscript/dpanel default-install
installer.sh apache nginx     -> dscript/dpanel chain install apache nginx
installer.sh update           -> dscript/dpanel chain update
installer.sh chain update     -> dscript/dpanel chain update
```

```bash
curl -fsSL https://installer.likesoftbd.com/installer.sh -o installer.sh
chmod 0755 installer.sh
sudo ./installer.sh
sudo ./installer.sh nginx php mariadb redis
sudo ./installer.sh update
```

The no-argument default install sequence is:

```text
apache -> nginx -> php -> mariadb -> supervisor -> rust/drust -> firewall -> fail2ban -> ssl -> postfix -> dovecot -> nodejs
```

Every mutating interactive menu action shows a confirmation screen first:

```text
Pending action: <name>
Will run:
  <command>
Configuration summary
...
Continue? Type yes to run:
```

Only the exact answer `yes` continues. Any other input cancels the action and
returns to the menu.

Source parameters are applied in this order: local directory, local ZIP, custom
ZIP URL, then the default live ZIP.

```bash
# Install directly from an existing checkout; no ZIP or network is used
sudo env DSCRIPT_SOURCE_DIR=/var/www/dscript ./installer.sh
sudo env DSCRIPT_SOURCE_DIR=/var/www/dscript ./installer.sh nginx php mariadb

# Install from an already downloaded ZIP
sudo env DSCRIPT_ARCHIVE_PATH=/tmp/dscript.zip ./installer.sh

# Download a ZIP from a custom endpoint
sudo env DSCRIPT_ARCHIVE_URL=https://example.com/dscript.zip ./installer.sh

# Change the extracted source destination and installed state root
sudo env DSCRIPT_DIR=/opt/serverpanel/dscript ./installer.sh
sudo env DPANEL_BASE_DIR=/opt/dpanel ./installer.sh
```

Archive must contain `dscript/dpanel`.


# NB if not found dpanel command
```bash
sudo /var/www/dscript/dpanel runtime refresh
hash -r
dpanel --version
```


<!-- ================================ Chain, update and runtime ================================== -->
# 

## Chain process

```bash
sudo dpanel chain install
sudo dpanel chain install apache,nginx,php,mariadb
sudo dpanel chain install apache nginx php mariadb
sudo dpanel chain update
dpanel chain verify
sudo dpanel chain repair
sudo dpanel --dry-run chain install
```

Environment controls: `PANEL_MODULES`, `SKIP_FIREWALL`, `SKIP_SSL`,
`SKIP_TEST`.

## Runtime

```bash
dpanel doctor
sudo dpanel doctor --fix
sudo /var/www/dscript/dpanel runtime refresh
/usr/local/bin/dpanel --version
/usr/local/bin/dpanel script list
```

Runtime files are installed under `/opt/dpanel/runtime`. Modules and manifests
are installed under `/opt/dpanel/repository`, so subsequent chain/module runs
use local assets instead of downloading each existing file again. The installer
automatically registers `/usr/local/bin/dpanel`;
use `dpanel runtime refresh` to repair that launcher.



<!-- =====================  All commands  ============================= -->

## Global options

```text
-h, --help             help
-V, --version          version
-n, --dry-run          preview mutation
-y, --yes              automatic confirmation
-v, --verbose          verbose diagnostics
```

## Primary commands

```bash
dpanel
dpanel help
dpanel list
dpanel info
dpanel logs install
dpanel chain <install|update|verify|repair>
dpanel module list
dpanel module <name> <install|update|remove|reinstall|info>
dpanel script list
dpanel script help <name>
dpanel script run <name> [arguments]
dpanel doctor [--fix]
dpanel runtime refresh
```

## dpanel command map

```text
dpanel                                  Open interactive menu
dpanel help                             Show help text
dpanel default-install                  Install default stack with mail, ssl and node
dpanel chain install [module,...]        Full install handover
dpanel chain update                      System/runtime/module update handover
dpanel chain verify                      Read-only verification
dpanel chain repair                      Safe repair then verify
dpanel install [module,...]              Alias for chain install or module install
dpanel update [module]                   Alias for chain update or module update
dpanel module list                       Show available modules
dpanel module <name> install             Install one module
dpanel module <name> update              Update one module
dpanel module <name> remove              Remove one module
dpanel php versions                      Show PHP versions
dpanel php install [version|all]         Install PHP version(s)
dpanel script list                       Show maintenance scripts
dpanel script run <name> [args]          Run one maintenance script
dpanel doctor [--fix]                    Diagnose or repair local runtime
dpanel runtime refresh                   Rebuild the /usr/local/bin/dpanel launcher
```

## Interactive menu command hints

The bare command opens the guided menu:

```bash
dpanel
```

Each menu item prints the direct command beside the option so the same task can
be repeated without opening the menu next time.

```text
Default Install                         dpanel default-install
Default Update                          dpanel chain update
Apache/Nginx install                    dpanel module apache install && dpanel module nginx install
Apache/Nginx update                     dpanel module apache update && dpanel module nginx update
Apache/Nginx reinstall                  dpanel module apache reinstall && dpanel module nginx reinstall
Restore base web stack                  dpanel script run fix-web-stack
Restore panel web stack                 dpanel script run fix-panel-web-stack <domain> [--alias domain]
Restore root panel config               dpanel script run fix-dpanel-root <domain>
Install all PHP versions                dpanel php install all
Update all PHP versions                 dpanel php update all
Show PHP versions                       dpanel php versions
Set default PHP                         dpanel php default <version>
Repair one PHP version                  dpanel php reinstall <version>
Install MariaDB                         dpanel module mariadb install
Install Redis                           dpanel module redis install
Install Supervisor                      dpanel module supervisor install
Install firewall                        dpanel module firewall install
Install Fail2ban                        dpanel module fail2ban install
Install SSL/certbot                     dpanel module ssl install
Generate website SSL                    dpanel script run issue-ssl <domain> <root> 0 [--alias domain]
Generate website SSL with www           dpanel script run issue-ssl <domain> <root> 1 [--alias domain]
Install Rust/Drust service              bash /var/www/drust/deploy/install-service.sh
Restart Drust                           systemctl restart drust.service
Show Drust status                       systemctl status drust.service --no-pager
Show Drust logs                         journalctl -u drust.service -n 100 --no-pager
Install Postfix                         apt/dnf/yum install postfix
Install Dovecot                         apt/dnf/yum install dovecot-core dovecot-imapd dovecot-lmtpd dovecot-mysql
Install Node.js                         apt/dnf/yum install nodejs npm
Create system user                      dpanel filemanager user ensure <user> --home <path> --shell <shell>
Change root password                    passwd root
Disable SSH root login                  dpanel script run disable-root-login
```

## Compatibility aliases

```bash
dpanel install [module]
dpanel update [module]
dpanel remove <module>
dpanel php <action> [version|all]
dpanel site:create ...
dpanel filemanager ...
```

## Maintenance scripts

```bash
sudo dpanel script run fix-dpanel-root panel.example.com
sudo dpanel script run fix-panel-web-stack dpanel.likesoftbd.com --alias dpanel.localhost
sudo dpanel script run fix-web-stack 8080 80
sudo dpanel script run sync-vhost sync example.com /home/example/public_html 8.3
sudo dpanel script run issue-ssl example.com /home/example/public_html 1
sudo dpanel script run create-demo-site /home/example/public_html example.com 8.3
dpanel script run php-detect-versions
dpanel script run php-detect-config --version 8.3
dpanel script run php-detect-extensions --version 8.3
sudo dpanel script run database-request create appdb appuser 'secret'
sudo dpanel script run reset-web-stack --yes
```



<!-- =======================  Database and cache ============================ -->

## Modules

```bash
sudo dpanel module mariadb install
sudo dpanel module mariadb update
sudo dpanel module redis install
sudo dpanel module redis update
sudo dpanel module supervisor install
sudo dpanel module queue install
systemctl status mariadb redis-server supervisor --no-pager
```

## Database helper

```bash
sudo dpanel script run database-request create appdb appuser 'secret'
sudo dpanel script run database-request update appdb appuser 'new-secret'
sudo dpanel script run database-request delete appdb appuser 'secret'
mysqladmin ping
```

Panel variables: `PANEL_DB_NAME`, `PANEL_DB_USER`, `PANEL_DB_PASSWORD`,
`PANEL_DB_HOST`, `PANEL_DB_PORT`, `PANEL_DB_CHARSET`, `PANEL_DB_COLLATION`.




<!-- ============== # Files and archive ===================== -->


## Layout

```text
/var/www/installer.sh
/var/www/dscript/dpanel
/var/www/dscript/archive.sh
/var/www/dscript/core/
/var/www/dscript/bootstrap/
/var/www/dscript/repository/
/var/www/dscript/scripts/
/opt/dpanel/runtime/
/opt/dpanel/repository/
/opt/dpanel/cache/
/opt/dpanel/logs/
```

## Build archive

```bash
cd /var/www/dscript
bash archive.sh /var/www/dscript.zip
unzip -t /var/www/dscript.zip
```

Publish as `https://installer.likesoftbd.com/dscript.zip`.

Archive root must contain `dscript/dpanel`, `dscript/core/`,
`dscript/bootstrap/`, `dscript/repository/` and `dscript/scripts/`.



<!-- ==================== Filemanager ================== -->

# Filemanager and users

```bash
dpanel filemanager exists /home/example
dpanel filemanager file-exists /home/example/.env
sudo dpanel filemanager create /home/example/public_html /home/example/logs
sudo dpanel filemanager remove /home/example/old
sudo dpanel filemanager user create example --home /home/example --shell /bin/bash
sudo dpanel filemanager user ensure example --site-directory public_html
```

Protected system paths, invalid usernames and unsafe traversal targets are
rejected by the module.




<!-- ======================= Module Installer ============================== -->
# Modules

List modules and versions:

```bash
dpanel module list
dpanel module <name> info
```

Available modules: apache, nginx, php, redis, mariadb, filemanager, ssl,
firewall, fail2ban, queue, supervisor, admin-user and ssh-root-login.

Postfix, Dovecot, Node.js and Rust/Drust are included in the default install
flow from the `dpanel` entrypoint even though they are not regular repository
modules yet.

Generic actions:

```bash
sudo dpanel module <module> install
sudo dpanel module <module> update
sudo dpanel module <module> reinstall
sudo dpanel module <module> remove
```

Examples:

```bash
sudo dpanel apache install
sudo dpanel nginx update
sudo dpanel mariadb reinstall
sudo dpanel redis remove
sudo dpanel firewall info
```

<!-- ======================= PHP ============================== -->

## PHP versions and configuration

```bash
dpanel php versions
sudo dpanel php install 8.3
sudo dpanel php install all
sudo dpanel php update 8.3
sudo dpanel php update all
sudo dpanel php reinstall 8.3
sudo dpanel php remove 8.3
sudo dpanel php default 8.3
sudo dpanel script run php-config-apply --version 8.3 --memory-limit 512M
dpanel script run php-detect-config --version 8.3
dpanel script run php-detect-extensions --version 8.3
dpanel script run php-detect-versions
systemctl status php8.3-fpm --no-pager
ls -l /run/php/php8.3-fpm.sock
php8.3 -v
```

Versions come from `repository/modules/php/php.json`.

<!-- ======================= Web stack and vhosts ============================== -->

## Web stack

```bash
sudo dpanel module apache install
sudo dpanel module nginx install
sudo dpanel module apache update
sudo dpanel module nginx update
sudo dpanel script run fix-web-stack
sudo dpanel script run fix-panel-web-stack panel.example.com
sudo dpanel script run fix-dpanel-root panel.example.com
nginx -t
apache2ctl configtest
systemctl status nginx apache2 --no-pager
```

## Panel and website vhosts

```bash
sudo dpanel script run fix-dpanel-root panel.example.com
sudo dpanel script run fix-dpanel-root installer.likesoftbd.com \
  --alias installer.localhost --alias panel.localhost
sudo dpanel script run fix-panel-web-stack panel.example.com \
  --alias www.panel.example.com --alias panel.localhost \
  --app-dir /var/www/dpanel --conf-name dpanel.conf
sudo dpanel script run sync-vhost sync example.com /home/example/public_html 8.3
sudo dpanel script run sync-vhost remove example.com /home/example/public_html 8.3
sudo dpanel script run sync-vhost sync example.com /home/example/public_html 8.3 \
  --alias www.example.com --client-max-body-size 128m
curl -I http://panel.example.com
```

Full reset affects every vhost and backs up web configuration first:

```bash
sudo dpanel --dry-run script run reset-web-stack --yes
sudo dpanel script run reset-web-stack --yes
```

Do not run full reset when only one panel vhost needs repair.

<!-- ======================= Security, SSL and mail ============================== -->

## Security

```bash
sudo dpanel module firewall install
sudo dpanel module fail2ban install
sudo dpanel module ssl install
sudo dpanel module ssh-root-login install
sudo dpanel module admin-user install
sudo dpanel script run disable-root-login
sudo dpanel script run create-admin-user admin 'strong-password' admin@example.com
systemctl status drust fail2ban --no-pager
dpanel doctor
```

Drust-backed commands read `DRUST_API_TOKEN` from `/etc/drust/drust.env`
or `SERVERPANEL_EXECUTION_API_TOKEN` in the panel `.env`.

## SSL and mail

```bash
sudo dpanel module ssl install
sudo dpanel script run issue-ssl example.com /home/example/public_html 1
sudo dpanel script run issue-ssl example.com /home/example/public_html 0 --alias www.example.com
sudo apt-get install postfix dovecot-core dovecot-imapd dovecot-lmtpd dovecot-mysql
sudo systemctl restart postfix dovecot
```

Roundcube is not part of the default stack. Mail service installation focuses on
Postfix and Dovecot for the panel's own mail service integration.

<!-- ======================= Environment ============================== -->

## Environment variables

```text
PANEL_INSTALL_BASE_URL
PANEL_DSCRIPT_BASE_URL
DPANEL_BASE_URL
DPANEL_BASE_DIR=/opt/dpanel
DPANEL_RUNTIME_DIR=/opt/dpanel/runtime
DPANEL_CACHE_DIR=/opt/dpanel/cache
DPANEL_MODULE_DIR=/opt/dpanel/modules
DPANEL_LOG_DIR=/opt/dpanel/logs
PANEL_APP_DIR=/var/www/dpanel
PANEL_MODULES
SKIP_FIREWALL
SKIP_SSL
SKIP_TEST
PHP_VERSION
PANEL_DOMAIN
PANEL_PORT
DSCRIPT_REFRESH_REMOTE=true
DRUST_API_URL
DRUST_API_PORT
DRUST_API_TOKEN
DRUST_CONNECT_TIMEOUT
DRUST_REQUEST_TIMEOUT
```
