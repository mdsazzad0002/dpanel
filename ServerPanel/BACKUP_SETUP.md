# Daily Backup Setup

This project now includes a built-in backup command:

```bash
php artisan serverpanel:backup
```

## What gets backed up

- Database
  - SQLite: copies the `.sqlite` file
  - MySQL/MariaDB: creates `.sql` dump using `mysqldump`
  - PostgreSQL: creates `.sql` dump using `pg_dump`
- Application data zip (`app-data.zip`)
  - `.env`
  - `storage/app` (except `storage/app/backups`)
  - `database/database.sqlite` (if exists)

Backup files are saved in:

`storage/app/backups/YYYYmmdd_HHMMSS/`

Old backups are auto-deleted based on `BACKUP_RETENTION_DAYS`.

## Environment variables

Set these values in `.env`:

```env
BACKUP_TIME=02:30
BACKUP_SCHEDULE_ENABLED=true
BACKUP_RETENTION_DAYS=7
MYSQLDUMP_PATH=mysqldump
PG_DUMP_PATH=pg_dump
BACKUP_REMOTE_UPLOAD_ENABLED=false
BACKUP_REMOTE_HOST=
BACKUP_REMOTE_PORT=22
BACKUP_REMOTE_USER=
BACKUP_REMOTE_PATH=
BACKUP_REMOTE_SSH_KEY_PATH=
BACKUP_REMOTE_STRICT_HOST_CHECKING=true
BACKUP_REMOTE_SSH_PATH=ssh
BACKUP_REMOTE_SCP_PATH=scp
```

Notes:
- If `mysqldump` or `pg_dump` is not in PATH, set full path.
- Windows WAMP example:
  - `MYSQLDUMP_PATH=C:\wamp64\bin\mysql\mysql8.0.31\bin\mysqldump.exe`
- If remote upload is enabled, `ssh` and `scp` must be available.

## Run backup manually

```bash
php artisan serverpanel:backup
```

Only database:

```bash
php artisan serverpanel:backup --only=db
```

Only files:

```bash
php artisan serverpanel:backup --only=files
```

## Use from panel UI

- Open: `/backups`
- Run on-demand backup:
  - `All (DB + Files)`
  - `Database Only`
  - `Files Only`
- Download single backup artifacts from each run row.
- Delete a single run folder from the same page.
- GUI control is available in **Backup Settings** block:
  - Enable/disable backup schedule
  - Set daily backup time
  - Set retention days
  - Enable/disable remote auto-upload
  - Configure remote host/user/path/port and SSH/SCP paths

## Daily automation

Laravel scheduler is already configured in `routes/console.php`:
- Runs daily at `BACKUP_TIME`.

You must run Laravel scheduler every minute from OS scheduler.

### Linux cron

```cron
* * * * * cd /path/to/ServerPanel && php artisan schedule:run >> /dev/null 2>&1
```

### Windows Task Scheduler

Create a task that runs every 1 minute:

Program:

`C:\wamp64\bin\php\php8.x.x\php.exe`

Arguments:

`artisan schedule:run`

Start in:

`D:\wamp64\www\ServerInstaller\ServerPanel`

Note:
- If schedule is disabled from GUI, `schedule:run` still executes but backup task is skipped.

## Verify

1. Run:
   - `php artisan serverpanel:backup`
2. Check folder:
   - `storage/app/backups`
3. Test scheduler:
   - `php artisan schedule:list`

## Configure another server

1. Copy project and `.env` to the new server.
2. Set correct PHP/DB backup binaries in `.env`:
   - `MYSQLDUMP_PATH`
   - `PG_DUMP_PATH`
3. Set retention and time:
   - `BACKUP_RETENTION_DAYS=7`
   - `BACKUP_TIME=02:30`
4. Ensure write permission:
   - `storage/app/backups`
5. Run once manually:
   - `php artisan serverpanel:backup --only=all`
6. Configure scheduler:
   - Linux cron or Windows Task Scheduler to run `php artisan schedule:run` every minute.
7. Open `/backups` and verify new run is listed and downloadable.

## Enable automatic upload to another server

1. In `.env`, set:
   - `BACKUP_REMOTE_UPLOAD_ENABLED=true`
   - `BACKUP_REMOTE_HOST=<target-host>`
   - `BACKUP_REMOTE_PORT=22`
   - `BACKUP_REMOTE_USER=<ssh-user>`
   - `BACKUP_REMOTE_PATH=/remote/backup/path`
   - Optional key:
     - `BACKUP_REMOTE_SSH_KEY_PATH=/path/to/private/key`
2. Keep host checking enabled for production:
   - `BACKUP_REMOTE_STRICT_HOST_CHECKING=true`
3. Test manually:
   - `php artisan serverpanel:backup --only=all`
4. Verify remote folder:
   - `<BACKUP_REMOTE_PATH>/YYYYmmdd_HHMMSS/`
5. Open `/backups` page:
   - It now shows schedule status and remote upload status.

If upload fails, the command returns failed status and prints SSH/SCP error output.

## Recommended GUI-first configuration

1. Open `/backups`.
2. In **Backup Settings**:
   - Turn on `Enable daily backup schedule`.
   - Set `Daily Time` and `Retention Days`.
   - Turn on `Enable auto-upload to remote server after backup` (if needed).
   - Fill `Remote Host`, `Remote User`, `Remote Path`, `Remote Port`.
3. Click `Save Backup Settings`.
4. Run `Run Backup Now` once to test both backup and upload.
