# ServerPanel Core Module

This repository now includes a production-minded **ServerPanel Core** module for:

- SSH Connector (password + private key auth)
- Safe command queue runner for server tasks
- AI error resolver for task failures
- Command approval flow and blocked-command protection
- Full task/event history and TXT reports

## Stack

- Laravel 12
- Vue + Inertia
- Tailwind CSS
- MySQL/MariaDB
- Redis queue
- Supervisor worker
- `phpseclib/phpseclib` for SSH

## Security Controls

- Credentials are stored in encrypted casts (`encrypted_password`, `encrypted_private_key`, `encrypted_private_key_passphrase`).
- Secret fields are hidden from model serialization and never returned intentionally.
- Root login is only allowed when server mode is `setup` (and config allows it).
- Every command is classified as `safe`, `approval_required`, or `blocked`.
- Dangerous commands are blocked.
- Approval-required commands must be approved by admin.
- Every command emits timeline events and report output.

## Included Backend Components

### Tables

- `servers`
- `ssh_connection_tests`
- `command_jobs`
- `command_events`
- `ai_error_resolutions`
- `server_tasks`
- `server_task_steps`

### Models

- `Server`
- `SshConnectionTest`
- `CommandJob`
- `CommandEvent`
- `AiErrorResolution`
- `ServerTask`
- `ServerTaskStep`

### Services

- `SshClientService`
- `CommandSafetyService`
- `CommandRunnerService`
- `AiErrorResolverService`
- `ErrorSignatureService`
- `ReportService`
- `ServerInventoryService`

### Jobs

- `ExecuteSshCommandJob`
- `AnalyzeCommandErrorJob`
- `ScanServerInventoryJob`

### Controllers

- `ServerController`
- `CommandJobController`
- `ServerTaskController`

### Events

- `CommandCreated`
- `CommandClassified`
- `CommandApproved`
- `CommandStarted`
- `CommandFinished`
- `CommandFailed`
- `AiFixSuggested`

## UI Pages (Inertia Vue)

- `/servers`
- `/servers/create`
- `/servers/{server}`
- `/servers/{server}/commands`
- `/commands/{commandJob}`
- `/server-tasks`
- `/server-tasks/{task}`

## Setup

1. Install PHP dependencies:

```bash
composer install
```

2. Install frontend dependencies:

```bash
npm install
```

3. Configure `.env` queue settings:

```env
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
```

4. Run migrations and seeders:

```bash
php artisan migrate
php artisan db:seed
```

5. Build frontend assets:

```bash
npm run build
```

## Required Package

```bash
composer require phpseclib/phpseclib
```

## Supervisor Example

```ini
[program:serverpanel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path-to-project/artisan queue:work redis --queue=server-commands,default --sleep=2 --tries=1 --timeout=300
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path-to-project/storage/logs/serverpanel-worker.log
```

## Config

See `config/serverpanel.php` for:

- `ssh_timeout`
- `command_timeout`
- `allow_root_setup_mode`
- `auto_run_safe_commands`
- `auto_run_safe_fixes`
- `max_output_length`
- `blocked_commands`
- `approval_required_patterns`
- `safe_patterns`
- `report_base_path`

## Reports

Command reports are saved under:

`storage/app/serverpanel/reports/YYYY-MM-DD/server-{id}/command-{uuid}.txt`

## Seed Data

## Tests

Run:

```bash
php artisan test --filter=ServerPanelModuleTest
```

The feature test covers:

- encrypted password storage
- secret non-exposure
- safe/approval/blocked classification outcomes
- approval dispatch flow
- failed command AI analyzer dispatch
- report generation
- memory search
