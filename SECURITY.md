# Security Policy

dPanel manages websites, files, databases, SSL certificates, and server-level
operations, so we take every security report seriously. Thank you for helping
keep dPanel and its users safe.

## Supported versions

Security fixes are released for the latest code on the `main` branch.

| Version | Supported |
| --- | :---: |
| `main` | ✅ |
| Older snapshots and forks | ❌ |

## Reporting a vulnerability

> **Please do not open a public GitHub issue for security vulnerabilities.**

Report privately through
[GitHub Security Advisories](https://github.com/mdsazzad0002/dpanel/security/advisories/new),
or contact the maintainer [@mdsazzad0002](https://github.com/mdsazzad0002)
directly.

Please include:

- The affected component: `dpanel`, `drust`, `dscript`, the installer, or docs
- The affected endpoint, command, file path, or UI page
- Steps to reproduce
- Expected and actual results
- The impact you believe it has
- Logs or screenshots, with secrets removed

Areas that deserve extra care include authentication and authorization, file
manager path validation, drust token handling, command execution, SSH keys,
database provisioning, SSL private keys, and permission repair.

## Disclosure process

1. The maintainer reviews and reproduces the report.
2. A fix is prepared privately when needed.
3. The fix is released to `main`.
4. Public notes are published once disclosure is safe.

## Hardening checklist

Before running dPanel on a public server:

- [ ] Keep `DRUST_API_TOKEN` and `SERVERPANEL_EXECUTION_API_TOKEN` identical and secret.
- [ ] Keep `/var/www/dpanel/.env` owned by `root:www-data` with mode `640`. It holds the drust token, which grants root-level control of the host.
- [ ] Keep websites on their own PHP-FPM pools (`DRUST_SITE_POOLS=1`, the default) so one site cannot read another site's files.
- [ ] Confirm drust is reachable only from `127.0.0.1`.
- [ ] Serve the panel over HTTPS only.
- [ ] Pass secrets to maintenance scripts through `DPANEL_ADMIN_PASSWORD`, `DPANEL_DB_PASSWORD`, or `DPANEL_USER_PASSWORD`, never as command-line arguments that other users can read from `/proc`.
- [ ] Run `sudo dpanel script run fix-permissions --all` after the first install or a project migration.
- [ ] Never use `chmod 777`.
- [ ] Keep PHP, Laravel and Rust dependencies, the edge gateway, and system packages up to date.
- [ ] Disable unused services and close unused ports.
- [ ] Review logs without exposing secrets.

## The drust API

`drust` is the privileged, root-owned execution API. It must:

- Listen on `127.0.0.1` only, behind bearer-token authentication
- Never be exposed to the public internet
- Validate every file path before touching the filesystem
- Keep file manager operations inside the account's home directory
- Never run shell commands built from user-controlled input

If drust has been exposed publicly, **rotate the API token immediately** and
restrict network access.

## Handling secrets

Never share or commit:

- `.env` files, including `/var/www/dpanel/.env` and `/etc/drust/drust.env`
- API tokens and service credentials
- Database passwords or dumps containing user data
- SSH or SSL private keys
- Logs that contain credentials

## Permission problems are usually not security bugs

If the file manager can open folders but cannot create, edit, upload, unzip, or
delete files, repair ownership instead of loosening permissions:

```bash
sudo dpanel script run fix-permissions --all
```

This keeps files owned by the site user with the `www-data` group and ACL
inheritance. Never use `chmod -R 777` as a workaround.
