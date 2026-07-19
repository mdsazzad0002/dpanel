# drust Runner Guide

`drust` is the root-owned execution API for ServerPanel. Laravel delegates filemanager and other privileged machine operations to this daemon; Laravel does not execute runtime shell scripts for these operations.

## One-command installation

Run the installer as root:

```bash
sudo /var/www/drust/deploy/install-service.sh
```

The installer is idempotent:

- It checks whether the root Cargo toolchain is available before installing anything.
- It installs Rust and build prerequisites only when the toolchain is missing or unusable.
- It creates `/etc/drust/drust.env` and generates an API token on first install only.
- It builds the release binary and installs the systemd unit.
- It enables and starts `drust.service`.

The root toolchain is isolated at:

```text
CARGO_HOME=/root/.cargo
RUSTUP_HOME=/root/.rustup
```

## Service files

- `deploy/drust.service` — root systemd unit
- `deploy/drust-start` — daemon wrapper
- `deploy/drust.env.example` — environment template
- `deploy/install-service.sh` — automatic installer

The daemon listens only on `127.0.0.1:9500` by default. Privileged filemanager operations require the service to run as `root`; access is protected by the bearer token in `/etc/drust/drust.env`.

## Laravel configuration

Use the same token in Laravel's `.env`:

```dotenv
SERVERPANEL_EXECUTION_API_URL=http://127.0.0.1:9500/api/v1/script/run
SERVERPANEL_FILEMANAGER_API_URL=http://127.0.0.1:9500/api/v1/filemanager
SERVERPANEL_EXECUTION_API_TOKEN=the_same_value_as_DRUST_API_TOKEN
```

After changing Laravel environment values:

```bash
cd /var/www/dpanel
php artisan config:clear
php artisan cache:clear
```

## Health and service checks

```bash
systemctl status drust.service
curl http://127.0.0.1:9500/health
```

Expected health response includes `status: ok` and `service: drust`.

View recent logs with:

```bash
journalctl -u drust.service -n 100 --no-pager
```

## File move API

Laravel delegates file and directory moves to the privileged daemon:

```text
POST /api/v1/filemanager/move
Authorization: Bearer <DRUST_API_TOKEN>
Content-Type: application/json

{
  "username": "account_user",
  "source": "/home/account_user/source.txt",
  "destination": "/home/account_user/folder/source.txt"
}
```

Both paths must remain inside `/home/{username}`. The daemon rejects traversal,
symlink escapes, overwrites, moving the account home, and moving a directory into
itself.

Deletion uses the same account scope:

```text
POST /api/v1/filemanager/delete
{"username":"account_user","path":"/home/account_user/source.txt"}
```

The account home itself cannot be deleted.

## Manual development run

For local development only, run from the repository root:

```bash
cd /var/www/drust
cargo run -- serve --port 9500 --token development-only-token
```

Do not use the development command for production. Use the systemd installer so the daemon has the required root permissions and automatic restart behavior.
