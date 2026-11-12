<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Support\BackupSettings;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$backupSettings = app(BackupSettings::class)->read();
if ((bool) ($backupSettings['schedule_enabled'] ?? true)) {
    Schedule::command('serverpanel:backup')
        ->dailyAt((string) ($backupSettings['schedule_time'] ?? '02:30'))
        ->withoutOverlapping();
}

// Backlog W6: incremental restic backups of every website, then the retention.
$resticSettings = app(\App\Support\ResticSettings::class)->read();
if ($resticSettings['enabled'] && $resticSettings['repository'] !== '') {
    Schedule::job(new \App\Jobs\RunResticBackupsJob)
        ->dailyAt($resticSettings['schedule_time'])
        ->name('restic-backups')
        ->withoutOverlapping();
}

Schedule::command('serverpanel:ssl-reconcile')
    ->twiceDaily(3, 15)
    ->withoutOverlapping();

Schedule::command('mail:tls')
    ->dailyAt('03:30')
    ->withoutOverlapping();

Schedule::command('mail:outbound-check')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

Schedule::command('mail:mailbox-check')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('dns:reconcile-websites')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('websites:git-sync')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('accounts:purge-terminated')
    ->dailyAt('04:00')
    ->withoutOverlapping();

Schedule::command('accounts:disk-check')
    ->hourly()
    ->withoutOverlapping();

Schedule::command('accounts:bandwidth-check')
    ->everyFifteenMinutes()
    ->withoutOverlapping();

// P4: package CPU and memory limits reach the account's slice within five
// minutes, and each run samples the account's use for the dashboard.
Schedule::command('accounts:resource-check')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// P5: new mailboxes, domains and package limits reach Rspamd within five
// minutes; an account that reached its limit is told once per hour or day.
Schedule::command('mail:send-limits')
    ->everyFiveMinutes()
    ->withoutOverlapping();

// U6: a dPanel update that ends while nobody watches System → Updates still
// reaches the admins' notifications within a minute.
Schedule::command('panel:update-sync')
    ->everyMinute()
    ->withoutOverlapping();

// dPanel ⇄ dPanel live migration: remove packages of transfers abandoned a day ago.
Schedule::call(fn () => app(\App\Services\PanelLink\PanelPackageStore::class)->prune())
    ->hourly()
    ->name('panel-link:prune')
    ->withoutOverlapping();

// Expired migration imports, failed-restore folders and old exports: each had
// only a delayed queue job, which a Redis restart loses (PruneStorageCommand).
Schedule::command('storage:prune')
    ->hourly()
    ->withoutOverlapping();
