# Security Policy

dPanel is a hosting control panel stack. Security reports are taken seriously because the project can manage websites, files, databases, SSL, and server-level operations.

## Supported Versions

Security fixes are provided for the latest code on the `main` branch.

| Version | Supported |
| --- | --- |
| `main` | Yes |
| Older snapshots/forks | No |

## Reporting A Vulnerability

Please do not open a public GitHub issue for security vulnerabilities.

Report privately to the project maintainer:

- GitHub: `mdsazzad0002`
- Repository: `https://github.com/mdsazzad0002/dpanel`

Include as much detail as possible:

- affected component: `dpanel`, `drust`, `dscript`, installer, or docs
- affected endpoint, command, file path, or UI page
- steps to reproduce
- expected result and actual result
- impact level
- logs or screenshots with secrets removed

## Secret Handling

Never share or commit:

- `.env` files
- API tokens
- database passwords
- SSH private keys
- SSL private keys
- service credentials
- logs containing credentials
- database dumps with user data

Important files such as `/etc/drust/drust.env` and `/var/www/dpanel/.env` must stay private on the server.

## Execution API Security

`drust` is the privileged localhost execution API. It should:

- listen on `127.0.0.1`
- run behind bearer-token authentication
- never be exposed directly to the public internet
- validate all file paths before touching the filesystem
- restrict filemanager operations to the intended account home
- avoid arbitrary shell execution from user-controlled input

If `drust` is exposed publicly, rotate the API token immediately and restrict network access.

## Deployment Hardening Checklist

Before using dPanel on a public server:

- keep `DRUST_API_TOKEN` and `SERVERPANEL_EXECUTION_API_TOKEN` synchronized and secret
- confirm `drust` is reachable only from localhost
- use HTTPS for public panel access
- run the panel with least-privilege web server users
- avoid `chmod 777`
- run `/var/www/dscript/scripts/fix-permissions.sh --all` after first install or project migration
- keep PHP, Laravel dependencies, Rust dependencies, nginx, Apache, and system packages updated
- disable unused services and public ports
- review logs without exposing secrets

## Permission Problems Are Not Always Security Fixes

If the file manager can open folders but cannot create, edit, upload, unzip, or delete files, use:

```bash
/var/www/dscript/scripts/fix-permissions.sh --all
```

Do not use this as a permanent workaround:

```bash
chmod -R 777 /home/example/public_html
```

The repair command keeps ownership safer by using the site user and `www-data` group with ACL inheritance.

## Disclosure Process

After a valid report is received:

1. The maintainer reviews and reproduces the issue.
2. A fix is prepared privately when needed.
3. The fix is released to `main`.
4. Public notes are added when disclosure is safe.

Thank you for helping keep dPanel safe.
