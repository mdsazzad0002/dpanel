# dPanel on Windows

Native Windows support is in progress. A Windows 10 or 11 PC can run the panel and
serve PHP websites for local development, built from this repository, on
`127.0.0.1`. Much of the panel is still untested there, and there is no installer.
For a public server, install on Linux with `installer.sh`. Status and remaining
work are in [plans/backlog.md](../plans/backlog.md).

## What works

| Part | State on Windows |
| --- | --- |
| `drust` build (`--no-default-features`, MSVC) | Works |
| drust API, `/health`, `/capabilities` | Works |
| Laravel migrations and seed (SQLite) | Works |
| Login, and the websites, databases, PHP, backups, DNS, email, monitoring and security pages | Open without errors |
| Panel reads `/capabilities` from drust with the shared token | Works |
| Creating a PHP website | Works |
| Opening that website on port 80, through the gateway and `php-cgi` | Works |
| Service supervisor, autostart, local SSL, automatic hosts entries | Not built yet (backlog F) |
| Features that call Linux commands | Not all ported (backlog C, D); they can fail |

The page checks only confirm that the pages open. Creating and opening a website
is the only action tested end to end. Expect others that call Linux commands to
fail.

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
   APP_URL=http://p.localhost
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

drust runs twice: the API on `127.0.0.1:9500`, and the edge gateway, which serves
the panel and every website on port 80 through a pool of `php-cgi` workers. PHP
must be on `PATH`, and nothing else may listen on ports 80 or 443, so stop
WampServer's Apache first.

From the repository root, in one PowerShell window:

```powershell
$token = ((Get-Content dpanel\.env | Where-Object { $_ -like 'SERVERPANEL_EXECUTION_API_TOKEN=*' } | Select-Object -Last 1) -split '=', 2)[1]
$env:DPANEL_PROFILE = 'local'
$env:DPANEL_PANEL_ROOT = "$PWD\dpanel"
$env:DRUST_API_TOKEN = $token
Start-Process .\drust\target\debug\drust.exe -ArgumentList 'serve', '--port', '9500' -WindowStyle Hidden
$env:DRUST_HTTP_BIND = '127.0.0.1:80'
$env:DRUST_HTTPS_BIND = '127.0.0.1:443'
$env:DRUST_PANEL_DOMAIN = 'p.localhost'
Start-Process .\drust\target\debug\drust.exe -ArgumentList 'edge-gateway' -WindowStyle Hidden
```

Open <http://p.localhost/login> and sign in with the seeded admin,
`test@example.com` and `password`. Change the password in your profile. Do not
expose this setup to a network. Nothing restarts these processes after a reboot
yet, so run this again. To stop them, end `drust.exe` and `php-cgi.exe` in Task
Manager.

## Websites

Create a website in the panel. Its files go to
`%USERPROFILE%\dPanel\sites\<owner>\public_html`.

A `.localhost` domain such as `demo.localhost` opens in the browser straight
away. Any other domain, such as `mysite.test`, needs a line like
`127.0.0.1 mysite.test` in `C:\Windows\System32\drivers\etc\hosts`, which takes
administrator rights.

## Troubleshooting

| Problem | Fix |
| --- | --- |
| Login returns to the login page | `SESSION_SECURE_COOKIE=false` is missing, or `.env` changed without `php artisan config:clear` |
| A page shows a Redis connection error | Set `CACHE_STORE` and `QUEUE_CONNECTION` to `database`, then run `php artisan config:clear` |
| `database.sqlite` cannot be deleted | A `php-cgi.exe` worker still has it open; stop the gateway and its workers first |
| The gateway does not start or a site gives no answer | Another program holds port 80 or 443; stop it, for example WampServer's Apache |
