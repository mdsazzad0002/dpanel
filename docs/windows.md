# dPanel on Windows

Native Windows support is in progress. The full hosting panel does not run on
Windows yet: for a working panel, install it on a Linux server with
`installer.sh`. Status and remaining work are in
[plans/backlog.md](../plans/backlog.md).

What does run on a Windows 10 or 11 PC today is a development setup: the Laravel
panel and the drust API, built from this repository, on `127.0.0.1`.

## What works

| Part | State on Windows |
| --- | --- |
| `drust` build (`--no-default-features`, MSVC) | Works |
| drust API, `/health`, `/capabilities` | Works |
| Laravel migrations and seed (SQLite) | Works |
| Login, and the websites, databases, PHP, backups, DNS, email, monitoring and security pages | Open without errors |
| Panel reads `/capabilities` from drust with the shared token | Works |
| Creating a website | Fails with `This command must run as root` (backlog C4, F1) |
| Website hosting: php-cgi pool, service supervisor, local SSL, hosts file | Not built yet (backlog F) |
| Laravel features that call `posix_*` or Linux commands | Not ported yet (backlog D); they can fail |
| Edge gateway on port 80 | Not tested |

The page checks only confirm that the pages open. Website creation is the only
action that changes the system that was tried, and it fails. Expect others that
call Linux commands to fail too.

## Requirements

- PHP 8.2 or newer on `PATH`, with `pdo_sqlite`
- Composer
- Node.js 22 or newer
- Rust (stable, `x86_64-pc-windows-msvc`) and Visual Studio Build Tools with the
  C++ workload

## Build and configure

Run these from the repository root in PowerShell.

1. Build drust. The first build takes about 6 minutes.

   ```powershell
   cd drust
   cargo build --no-default-features
   cd ..
   ```

2. Install the panel and build its frontend.

   ```powershell
   cd dpanel
   composer install
   npm ci
   npm run build
   Copy-Item .env.example .env
   ```

3. Create a token for the panel and drust to share, and keep it for the next
   steps.

   ```powershell
   [guid]::NewGuid().ToString('N')
   ```

4. Change these values in `dpanel\.env`. Add any that are missing. Redis is not
   needed with these settings.

   ```ini
   DPANEL_PROFILE=local
   APP_URL=http://127.0.0.1:8000
   SESSION_SECURE_COOKIE=false
   DB_CONNECTION=sqlite
   DB_DATABASE=database/database.sqlite
   QUEUE_CONNECTION=database
   CACHE_STORE=database
   SERVERPANEL_EXECUTION_API_TOKEN=<token>
   ```

5. Create the database.

   ```powershell
   New-Item -ItemType File database\database.sqlite
   php artisan key:generate
   php artisan migrate --seed
   ```

## Run

Use two PowerShell windows, from the repository root.

```powershell
# Window 1: drust API on 127.0.0.1:9500
$env:DPANEL_PROFILE = 'local'
$env:DRUST_API_TOKEN = '<token>'
.\drust\target\debug\drust.exe serve --port 9500
```

```powershell
# Window 2: the panel on 127.0.0.1:8000
cd dpanel
php artisan serve --host=127.0.0.1 --port=8000
```

Open <http://127.0.0.1:8000/login> and sign in with the seeded admin,
`test@example.com` and `password`. Change the password in your profile. Do not
expose this setup to a network.

For frontend work, run `npm run dev` in a third window.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| Login returns to the login page | `SESSION_SECURE_COOKIE=false` is missing, or `.env` changed without `php artisan config:clear` |
| A page shows a Redis connection error | Set `CACHE_STORE` and `QUEUE_CONNECTION` to `database`, then run `php artisan config:clear` |
| `database.sqlite` cannot be deleted | `php artisan serve` still has it open; stop the server first |
| `Call to undefined function posix_geteuid()` | That feature is not ported to Windows yet (backlog D1) |
