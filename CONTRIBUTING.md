# Contributing to dPanel

Thank you for helping improve dPanel! Bug fixes, alpha-test feedback, and
feature ideas are all welcome. A short, rough note about what you tried and
where you got stuck is often just as useful as a polished report.

## Contents

- [TL;DR](#tldr)
- [Ground rules](#ground-rules)
- [Set up a development server](#set-up-a-development-server)
  - [1. Prepare a machine](#1-prepare-a-machine)
  - [2. Fork and clone](#2-fork-and-clone)
  - [3. Configure git and sign in to GitHub](#3-configure-git-and-sign-in-to-github)
  - [4. Run the installer from your checkout](#4-run-the-installer-from-your-checkout)
  - [5. Give yourself write access](#5-give-yourself-write-access)
  - [6. Verify the setup](#6-verify-the-setup)
- [Daily workflow](#daily-workflow)
- [Rebuild after a change](#rebuild-after-a-change)
- [Testing](#testing)
- [Pull requests](#pull-requests)
- [Architecture boundaries](#architecture-boundaries)
- [Code style](#code-style)
- [Documentation](#documentation)
- [Reporting issues](#reporting-issues)
- [Contributor troubleshooting](#contributor-troubleshooting)
- [License](#license)

## TL;DR

On a **fresh, disposable** Ubuntu/Debian VM (never a production server):

```bash
# 1. Clone your fork into /var/www (the repository mirrors that layout)
sudo git clone https://github.com/<your-username>/dpanel.git /var/www
cd /var/www
sudo git remote add upstream https://github.com/mdsazzad0002/dpanel.git

# 2. Make git happy with root-owned files and permission changes
git config --global --add safe.directory /var/www
sudo git config --global --add safe.directory /var/www
sudo git -C /var/www config core.fileMode false

# 3. Install everything from your checkout
sudo DSCRIPT_SOURCE_DIR=/var/www/dscript bash /var/www/installer.sh

# 4. Let your user edit the code (re-run after any permission repair)
sudo apt-get install -y acl
sudo setfacl -R -m u:$USER:rwX -m d:u:$USER:rwX /var/www/.git /var/www/dpanel /var/www/drust /var/www/dscript /var/www/docs

# 5. Sign in to GitHub so you can push (as your user, never with sudo)
sudo apt-get install -y gh
gh auth login          # GitHub.com → HTTPS → Login with a web browser
gh auth setup-git

# 6. Check it works
sudo dpanel doctor
```

Then create a branch, make your change, [rebuild](#rebuild-after-a-change),
[test](#testing), and open a [pull request](#pull-requests). Each step is
explained below.

## Ground rules

- Only submit code or content you have the right to contribute.
- Never include secrets, `.env` files, database dumps, private keys, or
  customer data.
- Never commit generated folders such as `vendor/`, `node_modules/`,
  `public/build/`, or `drust/target/`.
- Never copy paid or proprietary code from another project.
- **Security vulnerabilities must be reported privately.** See the
  [Security Policy](SECURITY.md).

By contributing, you agree that the maintainer may use, modify, and distribute
your contribution as part of dPanel under the current or a future project
license (see section 5 of the [License](LICENSE)).

## Set up a development server

dPanel manages a real server: it creates Linux users, PHP-FPM pools,
databases, and binds ports `80` and `443`. Develop on a machine you can throw
away.

### 1. Prepare a machine

| Requirement | Recommendation |
| --- | --- |
| Machine | A fresh Ubuntu or Debian virtual machine, local (Multipass, VirtualBox, UTM) or cloud |
| Resources | 2 vCPU, 4 GB RAM, 30 GB disk or more |
| Access | A normal user with `sudo` |
| Tools | `git`, `curl` (`sudo apt-get install -y git curl`) |

> [!WARNING]
> Never develop on a production server. The installer changes system
> packages, services, and file permissions.

### 2. Fork and clone

1. Click **Fork** on [github.com/mdsazzad0002/dpanel](https://github.com/mdsazzad0002/dpanel).
2. Clone your fork **into `/var/www`**. The repository root mirrors the server
   layout, so the checkout becomes the live install:

   ```text
   /var/www/
   ├── dpanel/        Laravel + Vue panel
   ├── drust/         Rust API and edge gateway
   ├── dscript/       Installer and recovery toolkit
   ├── docs/          Documentation
   └── installer.sh   Bootstrap installer
   ```

   If `/var/www` does not exist or is empty:

   ```bash
   sudo git clone https://github.com/<your-username>/dpanel.git /var/www
   ```

   If `/var/www` already has files (for example `/var/www/html`), clone in place:

   ```bash
   cd /var/www
   sudo git init
   sudo git remote add origin https://github.com/<your-username>/dpanel.git
   sudo git fetch origin main
   sudo git checkout -t origin/main
   ```

3. Add the main repository as `upstream` so you can stay in sync:

   ```bash
   cd /var/www
   sudo git remote add upstream https://github.com/mdsazzad0002/dpanel.git
   git remote -v
   ```

### 3. Configure git and sign in to GitHub

#### Git settings

The installer makes the code owned by `root:www-data` and changes file modes.
These settings keep git working smoothly:

```bash
# Trust the root-owned checkout, for your user and for root
git config --global --add safe.directory /var/www
sudo git config --global --add safe.directory /var/www

# Ignore chmod changes so permission repairs don't show every file as modified
sudo git -C /var/www config core.fileMode false

# Your identity for commits
git config --global user.name  "Your Name"
git config --global user.email "you@example.com"
```

Use the email address linked to your GitHub account (or your
`<id>+<username>@users.noreply.github.com` address) so commits show up on
your profile.

#### Sign in to GitHub

Cloning a public fork needs no login, but **pushing** does. GitHub no longer
accepts your account password for git, so pick one of the options below.

> [!IMPORTANT]
> Run `git commit`, `git push`, and the login commands as **your own user**,
> not with `sudo`. `sudo` uses root's credentials, which are not your GitHub
> login. Pushing needs write access to `.git`, which you get in
> [step 5](#5-give-yourself-write-access).

**Option A: GitHub CLI (easiest)**

```bash
sudo apt-get install -y gh
gh auth login
```

Answer the prompts with **GitHub.com → HTTPS → Yes (authenticate Git) →
Login with a web browser**. Open the URL it shows, enter the one-time code,
and approve. On a headless VM you can open the URL from any other device.

```bash
gh auth setup-git     # let git use the gh login for HTTPS pushes
gh auth status        # confirm you are signed in
```

**Option B: SSH key**

```bash
ssh-keygen -t ed25519 -C "you@example.com"   # press Enter to accept defaults
cat ~/.ssh/id_ed25519.pub                      # copy this line
```

Add the key under **GitHub → Settings → SSH and GPG keys → New SSH key**, then
test it and switch your fork's remote to SSH:

```bash
ssh -T git@github.com     # "Hi <username>! You've successfully authenticated"
git -C /var/www remote set-url origin git@github.com:<your-username>/dpanel.git
```

**Option C: HTTPS with a personal access token**

1. Create a token under **GitHub → Settings → Developer settings → Personal
   access tokens → Fine-grained tokens**. Limit it to your `dpanel` fork with
   **Contents: Read and write** permission.
2. Tell git to remember it:

   ```bash
   git config --global credential.helper store   # or 'cache' to keep it in memory only
   ```

3. On your first `git push`, enter your GitHub username and paste the token as
   the password.

> [!CAUTION]
> `credential.helper store` saves the token in plain text in
> `~/.git-credentials`. Use `cache` on shared machines, and never commit or
> share a token.

Check which remotes you will push to. `origin` must be **your fork**; you only
fetch from `upstream`:

```bash
git -C /var/www remote -v
```

### 4. Run the installer from your checkout

Point the installer at your local `dscript` so it installs **your** code
instead of downloading a release:

```bash
sudo DSCRIPT_SOURCE_DIR=/var/www/dscript bash /var/www/installer.sh
```

The installer detects that `dpanel/` and `drust/` are already in place,
registers the `dpanel` command, and runs the full install chain (PHP, MariaDB,
Redis, PostgreSQL, drust, edge gateway, mail, and more).

Useful variations:

```bash
# Preview what would run, without changing anything
sudo /var/www/dscript/dpanel --dry-run chain install

# Install only selected modules
sudo DSCRIPT_SOURCE_DIR=/var/www/dscript bash /var/www/installer.sh php mariadb redis

# Update an existing install from your checkout
sudo DSCRIPT_SOURCE_DIR=/var/www/dscript bash /var/www/installer.sh update
```

If a step fails, the chain stops. Read the first `[ERROR]`, run
`sudo dpanel doctor`, then retry only the failed module (for example
`sudo dpanel module redis install`). See the [dscript CLI guide](docs/dscript.md).

### 5. Give yourself write access

For safety, the installer keeps application code owned by `root:www-data` and
readable only by the web server. Instead of loosening that, grant **your user**
access with ACLs:

```bash
sudo apt-get install -y acl
cd /var/www
sudo setfacl -R -m u:$USER:rwX -m d:u:$USER:rwX .git dpanel drust dscript docs
sudo setfacl -m u:$USER:rw README.md CONTRIBUTING.md SECURITY.md LICENSE installer.sh .gitignore
```

> [!NOTE]
> The installer and `dpanel doctor --fix` re-apply the strict permissions.
> Run the two `setfacl` commands again after either of them.

Never use `chmod -R 777` on the panel code, and never commit permission
changes.

For Rust work, install a toolchain for your own user (the installer only sets
one up for `root`):

```bash
curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh
```

### 6. Verify the setup

```bash
sudo dpanel doctor
sudo systemctl status drust.service edge-gateway.service --no-pager
curl http://127.0.0.1:9500/health
git -C /var/www status          # should be clean
```

Open the panel at the domain you chose during install (or the server's IP)
and sign in with the admin account the installer created.

## Daily workflow

```bash
cd /var/www

# 1. Start from the latest upstream code
git fetch upstream
git switch main
git merge --ff-only upstream/main

# 2. Create a focused branch
git switch -c fix/filemanager-unzip-limit

# 3. Edit, rebuild, and test (see below)

# 4. Commit and push to your fork
git add -p
git commit -m "Limit unzip entries per request"
git push -u origin fix/filemanager-unzip-limit
```

Then open a pull request from your fork to `mdsazzad0002/dpanel:main`.

Branch name ideas: `fix/…`, `feat/…`, `docs/…`, `refactor/…`.

## Rebuild after a change

Your checkout **is** the live install, so rebuild only what you touched:

| Changed area | Command |
| --- | --- |
| Rust source (`drust/`) | `sudo /var/www/drust/deploy/install-service.sh` |
| Vue / CSS | `cd /var/www/dpanel && npm run build` (or `npm run dev` while working) |
| Laravel config or routes | `cd /var/www/dpanel && sudo -u www-data php artisan optimize:clear` |
| New migration | `cd /var/www/dpanel && sudo -u www-data php artisan migrate --force` |
| `composer.json` / `package.json` | `composer install` / `npm install` in `/var/www/dpanel` |
| Installer or runtime scripts | `sudo dpanel runtime refresh` |

More commands are in [Operations](docs/operations.md).

## Testing

Run the checks for every area you changed. Pull requests with failing checks
cannot be merged.

**Panel**

```bash
cd /var/www/dpanel
php artisan test
npm run build
```

**drust** (a separate target directory avoids clashing with the root-owned
production build)

```bash
cd /var/www/drust
cargo fmt --check
CARGO_TARGET_DIR="/tmp/drust-${USER}-target" cargo test
CARGO_TARGET_DIR="/tmp/drust-${USER}-target" cargo build --release
```

**dscript**

```bash
bash -n /var/www/dscript/dpanel
bash /var/www/dscript/tests/cli-smoke.sh
sudo dpanel --dry-run chain install
```

**Gateway and permission changes**

```bash
sudo systemctl status edge-gateway.service --no-pager
curl -H 'Host: example.com' http://127.0.0.1/

sudo /var/www/dscript/scripts/fix-permissions.sh --path /home/example/public_html
sudo -u www-data sh -c 'echo ok > /home/example/public_html/.permission-test && rm /home/example/public_html/.permission-test'
```

## Pull requests

Before opening a pull request, check that:

- [ ] The branch is up to date with `upstream/main`.
- [ ] The relevant tests and builds pass.
- [ ] `git status` shows no secrets, build output, or permission-only changes.
- [ ] Docs are updated if behavior changed (see [Documentation](#documentation)).

A good pull request includes:

- **What** problem it solves and what changed
- **How** it was tested
- **Screenshots** for UI changes
- **Risks** or migration notes, if any

Keep commits focused, describe the behavior they change, and avoid unrelated
formatting churn.

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

## Reporting issues

### Bugs

Please include:

- dPanel version or commit hash (`git -C /var/www rev-parse --short HEAD`)
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

## Contributor troubleshooting

| Problem | Fix |
| --- | --- |
| `fatal: detected dubious ownership in repository` | `git config --global --add safe.directory /var/www` (and the same with `sudo`) |
| Every file shows as modified after install | `sudo git -C /var/www config core.fileMode false` |
| `Permission denied` when editing or committing | Re-run the `setfacl` commands in [step 5](#5-give-yourself-write-access) |
| `destination path '/var/www' already exists` | Use the clone-in-place commands in [step 2](#2-fork-and-clone) |
| Panel actions fail with `Unauthorized` | Make `DRUST_API_TOKEN` in `/etc/drust/drust.env` match `SERVERPANEL_EXECUTION_API_TOKEN` in `/var/www/dpanel/.env`, then `sudo systemctl restart drust.service` |
| Laravel shows a 500 error | `sudo tail -n 50 /var/www/dpanel/storage/logs/laravel.log` |
| `Password authentication is not supported` | Sign in with `gh auth login`, an SSH key, or a token (see [step 3](#sign-in-to-github)) |
| `Permission denied (publickey)` | Add your SSH key to GitHub and check it with `ssh -T git@github.com` |
| `Permission to mdsazzad0002/dpanel.git denied` | You are pushing to `upstream`. Push to your fork: `git push -u origin <branch>` |
| Push asks for a password even after login | You ran git with `sudo`. Run it as your own user |
| `cargo: command not found` | Install Rust for your user with `rustup` (see [step 5](#5-give-yourself-write-access)) |
| Install chain stopped on an error | `sudo dpanel doctor`, then retry the failed module only |

## License

dPanel is free to use under a custom source-available license. You may sell
hosting or server management services run on your own dPanel installation, but
you may not sell, rebrand, redistribute, or publish modified dPanel software
without written permission. See [LICENSE](LICENSE).
