# Contributing To dPanel

Thank you for your interest in improving dPanel.

dPanel is a free-to-use ServerPanel hosting control panel stack made from:

- `dpanel` - Laravel + Vue control panel
- `drust` - Rust localhost execution API
- `dscript` - shell installer and repair tooling

Please read this guide before submitting issues, fixes, or feature ideas.

## Before You Start

Read these files first:

- `README.md` - project overview and install commands
- `DEVELOPER.md` - architecture and development workflow
- `SECURITY.md` - vulnerability reporting policy
- `LICENSE.md` - custom free-use license
- `docs/FIRST_INSTALL_AND_PERMISSIONS.md` - first install and permission repair guide

## Contribution Rules

By contributing, you agree that your contribution may be used, modified, and distributed by the dPanel maintainer as part of this project under the current license or a future project license.

Do not contribute code or content you do not have permission to submit.

Do not include:

- secrets
- `.env` files
- database dumps
- private keys
- customer data
- paid/proprietary code copied from another project
- generated dependency folders such as `vendor/`, `node_modules/`, or `drust/target/`

## Security Reports

Do not open public issues for security vulnerabilities.

Follow `SECURITY.md` and report privately with reproduction details.

Examples of security-sensitive areas:

- authentication and authorization
- file manager path validation
- `drust` API token handling
- command execution
- SSH keys and credentials
- database provisioning
- SSL private keys
- permissions and ownership repair

## Development Setup

Laravel app:

```bash
cd /var/www/dpanel
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

Rust API:

```bash
cd /var/www/drust
cargo fmt
cargo test
cargo build
```

Scripts:

```bash
cd /var/www/dscript
./dpanel doctor
sudo ./dpanel script list
```

## Architecture Boundaries

Keep responsibilities separate:

- `dpanel` owns UI, database records, authorization, queues, and user workflows.
- `drust` owns privileged local server operations.
- `dscript` owns install, bootstrap, and recovery scripts.

Do not add raw privileged shell execution directly inside Laravel controllers.

For a new host-level action:

1. Add UI/model/job/service code in `dpanel`.
2. Add a validated endpoint in `drust`.
3. Add a `dscript` wrapper only if it is useful as a maintenance command.
4. Document the new behavior.

## Code Style

Laravel:

- keep controllers small
- validate requests before service calls
- use policies/middleware for authorization
- use queued jobs for slow operations
- never expose secrets in props, JSON, logs, or reports

Vue/Inertia:

- keep pages task-focused
- use shared components for repeated UI
- include loading, success, empty, and error states

Rust:

- run `cargo fmt`
- validate all inputs
- keep path operations inside allowed directories
- return clear operator-facing errors
- avoid arbitrary shell built from untrusted input

Shell:

- use `set -euo pipefail`
- quote variables
- validate arguments
- avoid unsafe broad operations
- do not use `chmod 777` as a fix

## Testing Checklist

Run checks for the areas you changed.

Laravel:

```bash
cd /var/www/dpanel
php artisan test
npm run build
```

Rust:

```bash
cd /var/www/drust
cargo fmt
cargo test
cargo build
```

Server config changes:

```bash
sudo nginx -t
sudo apache2ctl configtest
```

Permission repair changes:

```bash
/var/www/dscript/scripts/fix-permissions.sh --path /home/example/public_html
sudo -u www-data sh -c 'echo ok > /home/example/public_html/.permission-test && rm /home/example/public_html/.permission-test'
```

## Documentation Updates

Update docs when changing:

- install commands
- required environment variables
- API request/response shapes
- permissions or ownership behavior
- security-sensitive behavior
- public website content
- developer workflow

Relevant docs:

- `README.md`
- `DEVELOPER.md`
- `SECURITY.md`
- `LICENSE.md`
- `docs/FIRST_INSTALL_AND_PERMISSIONS.md`
- `drust/docs.md`

## Commit And Pull Request Tips

Good commits:

- are focused
- describe the behavior changed
- avoid unrelated formatting churn
- do not include generated secrets or dependencies

Good pull requests include:

- what problem is solved
- what changed
- how it was tested
- screenshots for UI changes
- any risks or migration notes

## Issue Reports

For bugs, include:

- dPanel version or commit hash
- operating system
- PHP version
- web server: nginx, Apache, or both
- exact error message
- reproduction steps
- relevant logs with secrets removed

For feature requests, include:

- user workflow
- expected result
- why it belongs in dPanel
- whether it needs `dpanel`, `drust`, `dscript`, or all layers

## License Reminder

dPanel is free to use under a custom source-available license.

You may sell hosting or server management services operated through your own dPanel installation.

You may not sell, rebrand, redistribute, or publish modified dPanel software without written permission.
