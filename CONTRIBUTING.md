# Contributing to dPanel

Thank you for helping improve dPanel! Bug fixes, alpha-test feedback, and
feature ideas are all welcome. A short, rough note about what you tried and
where you got stuck is often just as useful as a polished report.

## Contents

- [Ground rules](#ground-rules)
- [Reporting issues](#reporting-issues)
- [Development setup](#development-setup)
- [Architecture boundaries](#architecture-boundaries)
- [Code style](#code-style)
- [Testing](#testing)
- [Pull requests](#pull-requests)
- [Documentation](#documentation)
- [License](#license)

## Ground rules

- Only submit code or content you have the right to contribute.
- Never include secrets, `.env` files, database dumps, private keys, or
  customer data.
- Never commit generated folders such as `vendor/`, `node_modules/`, or
  `drust/target/`.
- Never copy paid or proprietary code from another project.
- **Security vulnerabilities must be reported privately.** See the
  [Security Policy](SECURITY.md).

By contributing, you agree that the maintainer may use, modify, and distribute
your contribution as part of dPanel under the current or a future project
license (see section 5 of the [License](LICENSE)).

## Reporting issues

### Bugs

Please include:

- dPanel version or commit hash
- Operating system and PHP version
- The exact error message
- Steps to reproduce
- Relevant logs, with secrets removed

### Feature requests

Please describe:

- The workflow you want to support and the expected result
- Why it belongs in dPanel
- Which layers it touches: `dpanel`, `drust`, `dscript`, or all of them

### Alpha feedback

Tell us which build or branch you tried, what you attempted first, what felt
smooth, and what felt confusing or unfinished. Screenshots help a lot.

## Development setup

**Panel (Laravel + Vue)**

```bash
cd /var/www/dpanel
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

**drust (Rust)**

```bash
cd /var/www/drust
cargo build
cargo run -- serve --port 9500 --token development-only-token
```

**dscript (shell)**

```bash
cd /var/www/dscript
./dpanel doctor
sudo ./dpanel script list
```

## Architecture boundaries

Each component has one job. Keep it that way.

| Component | Owns |
| --- | --- |
| `dpanel` | UI, database records, authorization, queues, and user workflows |
| `drust` | Privileged local server operations and public web traffic |
| `dscript` | Install, bootstrap, and recovery scripts |

Do not run privileged shell commands directly from Laravel controllers. To add
a new host-level action:

1. Add the UI, model, job, or service code in `dpanel`.
2. Add a validated endpoint in `drust`.
3. Add a `dscript` wrapper only if it is useful as a maintenance command.
4. Document the new behavior.

See [Architecture](docs/architecture.md) for details.

## Code style

**Laravel**

- Keep controllers small; validate requests before calling services.
- Use policies or middleware for authorization.
- Use queued jobs for slow operations.
- Never expose secrets in props, JSON, logs, or reports.

**Vue / Inertia**

- Keep pages focused on one task and reuse shared components.
- Handle loading, success, empty, and error states.

**Rust**

- Run `cargo fmt` and validate every input.
- Keep path operations inside allowed directories.
- Return clear, operator-friendly errors.
- Never build shell commands from untrusted input.

**Shell**

- Start scripts with `set -euo pipefail`.
- Quote variables and validate arguments.
- Avoid broad, unsafe operations. `chmod 777` is never a fix.

## Testing

Run the checks for every area you changed.

```bash
# Panel
cd /var/www/dpanel
php artisan test
npm run build

# drust
cd /var/www/drust
cargo fmt --check
cargo test
cargo build
```

For gateway changes:

```bash
sudo systemctl status edge-gateway.service --no-pager
```

For permission-repair changes:

```bash
sudo /var/www/dscript/scripts/fix-permissions.sh --path /home/example/public_html
sudo -u www-data sh -c 'echo ok > /home/example/public_html/.permission-test && rm /home/example/public_html/.permission-test'
```

## Pull requests

Good commits are focused, describe the behavior they change, and avoid
unrelated formatting churn.

A good pull request includes:

- **What** problem it solves and what changed
- **How** it was tested
- **Screenshots** for UI changes
- **Risks** or migration notes, if any

## Documentation

Update the docs in the same pull request when you change install commands,
environment variables, API requests or responses, permission behavior,
security-sensitive behavior, or the developer workflow.

| Topic | File |
| --- | --- |
| Project overview | [`README.md`](README.md) |
| Install and configuration | [`docs/installation.md`](docs/installation.md) |
| Everyday commands | [`docs/operations.md`](docs/operations.md) |
| drust endpoints | [`docs/drust-api.md`](docs/drust-api.md) |
| `dpanel` CLI | [`docs/dscript.md`](docs/dscript.md) |
| Security guidance | [`SECURITY.md`](SECURITY.md) |

## License

dPanel is free to use under a custom source-available license. You may sell
hosting or server management services run on your own dPanel installation, but
you may not sell, rebrand, redistribute, or publish modified dPanel software
without written permission. See [LICENSE](LICENSE).
