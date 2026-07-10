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
