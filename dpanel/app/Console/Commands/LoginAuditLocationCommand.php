<?php

namespace App\Console\Commands;

use App\Services\LoginAudit\LoginLocationSetting;
use Illuminate\Console\Command;

class LoginAuditLocationCommand extends Command
{
    protected $signature = 'login-audit:location {mode? : off, ask or required} {--scheme=both : https, http or both}';
    protected $description = 'Show or set whether the login form asks for the device location (use "off" if an admin is locked out)';

    public function handle(LoginLocationSetting $setting): int
    {
        $mode = $this->argument('mode');
        if ($mode === null) {
            foreach ($setting->all() as $scheme => $current) {
                $this->info(strtoupper($scheme).": {$current}");
            }

            return self::SUCCESS;
        }

        $option = (string) $this->option('scheme');
        $schemes = $option === 'both' ? LoginLocationSetting::SCHEMES : [$option];
        try {
            foreach ($schemes as $scheme) {
                $setting->set($scheme, (string) $mode);
                $this->info(strtoupper($scheme).": {$mode}");
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
