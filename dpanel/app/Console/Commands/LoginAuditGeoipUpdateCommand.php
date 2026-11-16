<?php

namespace App\Console\Commands;

use App\Services\LoginAudit\GeoIpDatabase;
use App\Services\LoginAudit\LoginAuditRecorder;
use Illuminate\Console\Command;

class LoginAuditGeoipUpdateCommand extends Command
{
    protected $signature = 'login-audit:geoip-update {--force : Download even when the file is less than a month old}';
    protected $description = 'Download the DB-IP City Lite database used to place login IPs on the map';

    public function handle(GeoIpDatabase $database, LoginAuditRecorder $recorder): int
    {
        if (! $this->option('force') && ! $database->isStale()) {
            $this->info('The DB-IP database is up to date.');

            return self::SUCCESS;
        }

        try {
            $month = $database->update();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("DB-IP database {$month} installed. Rows located: ".$recorder->locatePending(50000));

        return self::SUCCESS;
    }
}
