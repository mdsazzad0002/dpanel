<?php

namespace App\Console\Commands;

use App\Models\CronJob;
use App\Models\Website;
use App\Services\Cron\CronSystemService;
use Illuminate\Console\Command;

/**
 * Registers the cron entry that drives Laravel's scheduler (SSL reconcile,
 * backups, mail and DNS checks) as a system job of the root panel website,
 * so it shows on that site's Cron Jobs page. An existing entry keeps its
 * schedule and enabled state; only the command and system cron are refreshed.
 */
class InstallSchedulerCronCommand extends Command
{
    protected $signature = 'serverpanel:install-scheduler-cron';
    protected $description = 'Install the panel scheduler (schedule:run) as a root panel cron job';

    public function handle(CronSystemService $cronSystem): int
    {
        $panelWebsite = Website::query()->find('1');
        if ($panelWebsite === null) {
            $this->error('Root panel website (id 1) was not found.');

            return self::FAILURE;
        }

        $job = CronJob::query()->firstOrNew(['id' => 'panel-scheduler']);
        $job->fill([
            'website_id' => '1',
            'domain' => (string) $panelWebsite->domain,
            'name' => 'Panel Scheduler',
            'command' => 'cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1',
            'description' => 'Runs dPanel scheduled tasks: SSL renew check, backups, mail and DNS checks. Disabling it stops all of them.',
        ]);
        if (! $job->exists) {
            $job->expression = '* * * * *';
            $job->status = 'active';
        }
        $job->save();

        try {
            $this->info($cronSystem->sync($job->fresh(), $panelWebsite));
        } catch (\Throwable $e) {
            $this->error('System cron was not installed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
