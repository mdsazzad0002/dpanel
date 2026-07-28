# dPanel

![dPanel logo](docs/assets/dpanel_logo.png)

dPanel is a free-to-use, source-available ServerPanel hosting control panel
stack for managing websites, PHP runtimes, SSL, databases, file management,
mail, backups, monitoring, and server automation.

At a glance, the project is split into three responsibilities:

1. `dpanel` handles the web UI and user-facing panel workflows.
2. `drust` handles privileged localhost server actions.
3. `dscript` handles installation, bootstrap, repair, and server automation
   outside the main web app.

## Project Screenshots

Add final screenshots under `docs/assets/screenshots/` and keep the same names
below so this section becomes live automatically.

| Dashboard | Websites | File Manager |
| --- | --- | --- |
| ![Dashboard screenshot](docs/assets/screenshots/dashboard.png) | ![Websites screenshot](docs/assets/screenshots/websites.png) | ![File manager screenshot](docs/assets/screenshots/file-manager.png) |

| Databases | SSL Manager | Server Tasks |
| --- | --- | --- |
| ![Databases screenshot](docs/assets/screenshots/databases.png) | ![SSL manager screenshot](docs/assets/screenshots/ssl-manager.png) | ![Server tasks screenshot](docs/assets/screenshots/server-tasks.png) |

## What Is Included
```text
├── dPanel - Laravel 12 + Vue/Inertia control panel application
│   ├── Manage SSL
│   ├── Domain create
│   ├── Website management
│   ├── Database workflows
│   ├── File manager
│   ├── Server task history
│   ├── Service status and logs
│   ├── Backup and restore workflows
│   └── Queue and authorization workflows
├── drust - Rust localhost execution API for privileged server operations
│   ├── Protected server actions
│   ├── Vhost sync
│   ├── Permission repair
│   ├── Website config regeneration
│   ├── System-user operations
│   └── Execution bridge for unsafe host-level tasks
├── dscript - installer, bootstrap, update, and recovery scripts
│   ├── Installer bootstrap
│   ├── Chain install/update
│   ├── Recovery and repair
│   ├── Module and script runtime
│   ├── Web stack install/update
│   ├── SSL and vhost maintenance
│   ├── Database helper scripts
│   └── Public CLI entrypoint (`dpanel`)
├── phpMyAdmin - bundled phpMyAdmin integration assets
└── docs - screenshots, logos, and visual assets for the project
```



## Architecture Details

### 1. Panel Layer

`dpanel` is the user-facing application. It is responsible for:

- login and authorization
- dashboard and server status screens
- website and domain management
- SSL actions for websites
- database management views and task history
- file manager views and permission repair entrypoints
- server task logs and command history
- backup, restore, and operational workflows

### 2. Execution Layer

`drust` is the protected local execution API. It is responsible for:

- website vhost creation and sync
- permission repair on server-side paths
- protected host operations that should not be exposed directly to the browser
- system-user related operations
- web-stack regeneration when the panel or site config needs repair

### 3. Bootstrap Layer

`dscript` is the installer and recovery layer. It is responsible for:

- first install bootstrap
- chain install and update flows
- repair and recovery flows
- runtime launcher refresh
- module install/update/remove/reinstall
- shell-based maintenance commands

### 4. Supporting Assets

- `phpMyAdmin` provides bundled integration assets for database administration.
- `docs` provides operator guides, recovery notes, and command references.

## Feature Breakdown

### Website

The website area includes:

- domain creation
- website scaffold generation
- vhost sync
- vhost reload
- SSL certificate issuance
- SSL renewal support
- document root and permissions handling

Common website commands:

```text
dpanel site:create <domain> <username> [php-version] [ssl] [web-server] [root]
dpanel script run sync-vhost sync <domain> <root> <php-version>
dpanel script run issue-ssl <domain> <root> <include-www>
dpanel module nginx reload
dpanel module apache reload
```

### Database

The database area includes:

- database creation
- database user creation
- privilege management
- connection test workflows
- phpMyAdmin sign-on integration
- database credential handling for applications

Common database commands:

```text
dpanel script run database-request create <db> <user> <password> [host] [port]
dpanel script run database-request update <db> <user> <password> [host] [port]
dpanel script run database-request delete <db> <user> <password> [host] [port]
dpanel script run configure-phpmyadmin-signon
```

### File Manager

The file manager area includes:

- folder creation
- folder removal
- file existence checks
- directory existence checks
- system-user creation and validation
- home directory and shell assignment
- permission-sensitive path handling

Common file manager commands:

```text
dpanel filemanager create <path>...
dpanel filemanager remove <path>...
dpanel filemanager exists <path>...
dpanel filemanager file-exists <path>...
dpanel filemanager user create <username> [--home PATH] [--shell PATH]
dpanel filemanager user ensure <username> [options]
```

### Server and Runtime

The server/runtime area includes:

- nginx and apache install/update/reload
- PHP install/update/reinstall/default selection
- MariaDB install/update
- Redis and Supervisor support
- firewall and fail2ban setup
- SSL tooling installation
- runtime refresh for the public launcher

Common server commands:

```text
dpanel module nginx install
dpanel module nginx update
dpanel module nginx reload
dpanel module php install 8.3
dpanel module mariadb install
dpanel module supervisor install
dpanel module firewall install
dpanel module fail2ban install
dpanel module ssl install
dpanel runtime refresh
```

### Repair and Recovery

The repair area includes:

- `dpanel doctor`
- `dpanel chain repair`
- logs inspection
- runtime refresh
- permission repair
- web-stack reset support

Common repair commands:

```text
dpanel doctor
dpanel doctor --fix
dpanel chain verify
dpanel chain repair
dpanel logs install
dpanel logs update
dpanel script run fix-web-stack
dpanel script run fix-panel-web-stack <domain>
```

Common routes and commands:

```text
dpanel default-install
dpanel chain install
dpanel chain update
dpanel chain verify
dpanel chain repair
dpanel module nginx install
dpanel module nginx reload
dpanel module ssl install
dpanel script run sync-vhost sync <domain> <root> <php-version>
dpanel script run issue-ssl <domain> <root> <include-www>
dpanel site:create <domain> <username> [php-version] [ssl] [web-server] [root]
dpanel filemanager user ensure <username> --home <path> --shell <shell>
dpanel script run database-request create <db> <user> <password> [host] [port]
dpanel script run configure-phpmyadmin-signon
```

How requests move through the system:

```text
Browser
  -> dpanel UI request
  -> drust for protected server operations
  -> dscript for bootstrap / install / repair workflows
  -> Linux services and filesystem changes
```

Typical website flow:

```text
1. Create or sync vhost
2. Ensure webroot and file permissions
3. Install or reload nginx/apache
4. Issue SSL certificate
5. Reload web server after SSL issuance
6. Verify site and logs
```

Website route map:

```text
Create site
  -> dpanel site:create
  -> drust vhost sync
  -> nginx/apache config render
  -> file permissions and document root check
  -> SSL issue when enabled
  -> reload web server
```

Typical database flow:

```text
1. Create database and user
2. Grant privileges
3. Configure app credentials
4. Optionally configure phpMyAdmin signon
5. Test connection from the panel or app
```

Database route map:

```text
Database request
  -> dpanel script run database-request
  -> MariaDB/MySQL create/update/delete action
  -> optional phpMyAdmin sign-on configuration
  -> application credential update
```

### Operating Modes

The project is designed to support these modes:

1. Fresh server installation.
2. Existing server upgrade.
3. Module-by-module maintenance.
4. Website provisioning and SSL setup.
5. Database provisioning and credential support.
6. Emergency repair after partial failure.
7. Runtime refresh when the launcher becomes stale.

### Failure Behavior

- A normal chain step stops on the first real failure.
- Some repair/update steps may warn and continue when the remaining work is still useful.
- `ssl` install can succeed even if certbot package installation is incomplete, but issuance later still depends on certbot.
- `issue-ssl` fails if the webroot, domain, or certbot prerequisites are missing.
- `sync-vhost` must succeed before SSL issuance for a new site.

Canonical installed paths:

- Panel app: `/var/www/dpanel`
- Execution API: `/var/www/drust`
- Script repo: `/var/www/dscript`
- Docs and overview: `/var/www/README.md`

Common operator entrypoints:

- Public panel UI: browser -> `/var/www/dpanel`
- CLI installer: `sudo ./installer.sh`
- Direct CLI control: `dpanel ...`
- Repair and diagnosis: `dpanel doctor`, `dpanel chain repair`
- Main project guide: `/var/www/README.md`

## Final Destination

dPanel is being built as a practical self-hosted hosting control panel for
operators, developers, agencies, and small hosting providers who need one place
to manage websites, databases, SSL, files, backups, mail, and server repair
tasks without losing control of the underlying Linux server.

The intended production shape is:

```text
Browser
  -> dpanel: Laravel + Vue control panel
  -> drust: protected localhost execution API
  -> Linux services: nginx, Apache, PHP-FPM, MariaDB, Redis, certbot, filesystem

dscript remains available for installation, bootstrap, update, and recovery.
```

## Installer Guide

Run the installer from a fresh server with `curl`:

```bash
curl -fsSL https://installer.dengrweb.com/installer.sh -o installer.sh
chmod +x installer.sh
sudo ./installer.sh
```

If you are cloning the repository manually, the recommended lightweight
download is:

```bash
git clone --depth 1 https://github.com/mdsazzad0002/dpanel.git
```

For module-specific installs or updates:

```bash
sudo ./installer.sh nginx php mariadb
sudo ./installer.sh update
```

Full install, command, module, workflow, and recovery details are documented in
this README.

## Later Roadmap

- Improve first-time install reliability on fresh Ubuntu/Debian servers.
- Expand file manager permission diagnostics and repair coverage.
- Add clearer UI states for API failures, task errors, and repair suggestions.
- Harden website provisioning, vhost sync, and rollback flows.
- Improve SSL issuance and renewal visibility.
- Expand database user, privilege, DNS, and mail management helpers.
- Improve backup scheduling, restore workflows, monitoring, and alerting.
- Add stronger audit trails for privileged actions.
- Add more automated checks for Laravel, Rust, docs, and scripts.
- Add real architecture diagrams, release packaging notes, and screenshots.

See the full roadmap in [`ROADMAP.md`](ROADMAP.md).

## Quick Connections

- Developer guide: [`DEVELOPER.md`](DEVELOPER.md)
- Contribution guide: [`CONTRIBUTING.md`](CONTRIBUTING.md)
- Security policy: [`SECURITY.md`](SECURITY.md)
- License policy: [`LICENSE`](LICENSE)
- Changelog: [`CHANGELOG.md`](CHANGELOG.md)
- First install and permission repair: [`docs/FIRST_INSTALL_AND_PERMISSIONS.md`](docs/FIRST_INSTALL_AND_PERMISSIONS.md)
- Main project guide: [`README.md`](README.md)

## Developer Notes

Keep responsibilities separated:

- `dpanel` owns UI, database records, authorization, queues, and user workflows.
- `drust` owns privileged localhost server operations.
- `dscript` owns install, bootstrap, and recovery commands.

Laravel should not directly run unsafe privileged shell commands for production
server changes. Add host-level actions to `drust` and call them from `dpanel`
through a service or queued job.

## Contributor Notes

If you want to help shape dPanel, you are very welcome here.

Before contributing, read [`CONTRIBUTING.md`](CONTRIBUTING.md),
[`DEVELOPER.md`](DEVELOPER.md), [`SECURITY.md`](SECURITY.md), and
[`LICENSE`](LICENSE). Those pages explain the project boundaries, safe ways to
test changes, and the best way to share feedback.

Alpha testers are especially helpful. If you try an early build, please report
what you were trying to do, what worked, what felt confusing, and anything that
blocked you. Even small notes help us make the next release better.

Please do not submit secrets, `.env` files, private keys, database dumps,
customer data, generated dependency folders, or proprietary code you do not
have permission to contribute.

## Security

Please do not open public issues for security vulnerabilities. Follow
[`SECURITY.md`](SECURITY.md) and report privately with reproduction details.

Security-sensitive areas include:

- authentication and authorization
- file manager path validation
- `drust` API token handling
- command execution
- SSH keys and credentials
- database provisioning
- SSL private keys
- permissions and ownership repair

`drust` must stay localhost-only, bearer-token protected, and unavailable from
the public internet.

## License

dPanel is free to use under a custom source-available license. Public
modification, redistribution, rebranding, resale, or derivative works are not
allowed without written permission. You may sell hosting, website management,
server management, or related services operated through your own dPanel
installation, but you may not sell dPanel itself as software. See
[`LICENSE`](LICENSE).
