<div align="center">

# dPanel

**A free, self-hosted web hosting control panel built on Laravel, Vue, and Rust.**

[![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)](https://laravel.com)
[![Vue](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)](https://vuejs.org)
[![Rust](https://img.shields.io/badge/Rust-2024-000000?logo=rust&logoColor=white)](https://www.rust-lang.org)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.2-777BB4?logo=php&logoColor=white)](https://www.php.net)
[![License](https://img.shields.io/badge/license-Free%20Use-blue)](LICENSE)

[Installation](docs/installation.md) ·
[Documentation](docs/README.md) ·
[Contributing](CONTRIBUTING.md) ·
[Security](SECURITY.md)

</div>

---

## Overview

dPanel manages websites, databases, email, DNS, SSL, and backups on a Linux
server. The web panel handles users and workflows, while small Rust services
carry out privileged host operations and serve public website traffic.

> **Free forever.** Every user gets the same software, the same features, and
> the same updates. There are no license fees, no feature locks, and no forced
> subscriptions. Paid help is available only if you ask for it.

## Features

| Area | Highlights |
| --- | --- |
| **Websites** | Per-site Linux accounts, per-site PHP-FPM pools, multiple PHP versions, one-click WordPress and Laravel installs, Git deployments, Node.js and Python apps |
| **Edge gateway** | Rust HTTP/TLS server with SNI certificates, static file serving, PHP-FPM dispatch, compression, and live config reloads |
| **SSL** | Automatic Let's Encrypt issuance, validation, and renewal |
| **Databases** | MySQL/MariaDB and PostgreSQL with per-database users, phpMyAdmin and pgAdmin, remote-access rules |
| **Email** | Postfix, Dovecot, and Roundcube with mailbox provisioning and delivery diagnostics |
| **DNS** | Authoritative DNS through PowerDNS with automatic zone reconciliation |
| **Files** | Account-scoped file manager with upload, zip/unzip, permissions repair, and trash |
| **Backups & migration** | Scheduled backups, portable restore packages, and imports from cPanel and CyberPanel |
| **Operations** | Cron jobs, FTP accounts, Redis, monitoring, security scans, and a server task runner |
| **Business** | Resellers, package plans, roles and permissions, and WHMCS integration |

## Architecture

```text
             Browser
                │
                ▼
   ┌──────────────────────────┐
   │   edge-gateway.service   │  public :80 / :443  (Rust)
   └──────────────────────────┘
        │ static · PHP-FPM · panel · phpMyAdmin
        ▼
   ┌──────────────────────────┐        ┌──────────────────────────┐
   │     dpanel (Laravel)     │ ─────▶ │      drust.service       │
   │  UI · auth · records ·   │ token  │  privileged localhost    │
   │  queues                  │        │  API on 127.0.0.1:9500   │
   └──────────────────────────┘        └──────────────────────────┘
                                                    │
                                    files · users · databases · SSL · PHP
```

| Component | Path | Responsibility |
| --- | --- | --- |
| [`dpanel`](dpanel) | `/var/www/dpanel` | Laravel + Vue panel: UI, authentication, authorization, records, and queues |
| [`drust`](drust) | `/var/www/drust` | Rust privileged API (`drust.service`) and public web gateway (`edge-gateway.service`) |
| [`dscript`](dscript) | `/var/www/dscript` | Shell toolkit for installing, updating, diagnosing, and repairing servers |

See [Architecture](docs/architecture.md) for the full request flow.

## Quick Start

Run on a fresh server as a user with `sudo` access:

```bash
curl -fsSL https://raw.githubusercontent.com/mdsazzad0002/dpanel/main/installer.sh -o installer.sh
chmod +x installer.sh
sudo ./installer.sh
```

Then check that the services are running:

```bash
sudo systemctl status drust.service edge-gateway.service
sudo dpanel doctor
```

Full steps, configuration, and permission setup are in the
[Installation Guide](docs/installation.md).

## Documentation

| Guide | Description |
| --- | --- |
| [Installation](docs/installation.md) | First install, configuration files, and website permissions |
| [Architecture](docs/architecture.md) | Components, request flow, PHP execution, and the edge gateway |
| [Operations](docs/operations.md) | Everyday commands, rebuilds, and troubleshooting |
| [dscript CLI](docs/dscript.md) | The `dpanel` command: chains, modules, scripts, and recovery |
| [drust Service](docs/drust-service.md) | Installing and running the privileged Rust service |
| [drust API](docs/drust-api.md) | Endpoint reference for the localhost execution API |
| [Backups](docs/backups.md) | Scheduled backups, remote upload, and restore |
| [Server Task Runner](docs/ssh-command-runner.md) | SSH connector, command safety rules, and task reports |
| [WHMCS Integration](dpanel/integrations/whmcs/README.md) | Connecting dPanel to WHMCS billing |

## Support Model

dPanel is one product with one release channel for everyone.

| | Self-service | Supported |
| --- | --- | --- |
| Software | Free | Free |
| Updates | Free | Free |
| Features | All | All |
| Help | Community | Paid expert assistance |

Paid support covers human work only, such as installation, migration,
troubleshooting, priority response, and managed operations. Donations are
always optional.

## Contributing

Bug reports, alpha-test feedback, and pull requests are welcome. The
[Contributing Guide](CONTRIBUTING.md) walks you through cloning the repository,
running the installer from your checkout, setting up permissions, and opening
a pull request.

## Security

Please **do not** open public issues for security problems. See the
[Security Policy](SECURITY.md) for how to report them privately.

## License

dPanel is free to use under the [dPanel Free Use License](LICENSE), a
source-available license. You may run it and sell hosting services with it, but
you may not redistribute, rebrand, or resell the software itself without
written permission.

<div align="center">
<sub>Copyright © 2026 mdsazzad0002</sub>
</div>
