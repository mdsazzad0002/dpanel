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

// P5: new mailboxes, domains and package limits reach Rspamd within five
// minutes; an account that reached its limit is told once per hour or day.
Schedule::command('mail:send-limits')
    ->everyFiveMinutes()
    ->withoutOverlapping();
