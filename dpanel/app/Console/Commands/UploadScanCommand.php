<?php

namespace App\Console\Commands;

use App\Services\Security\UploadScanner;
use Illuminate\Console\Command;

class UploadScanCommand extends Command
{
    protected $signature = 'serverpanel:upload-scan {state : on, off or status}';

    protected $description = 'Turn antivirus scanning of uploads on or off, e.g. when ClamAV breaks and blocks uploads';

    public function handle(UploadScanner $scanner): int
    {
        $state = strtolower((string) $this->argument('state'));

        if ($state === 'status') {
            $status = $scanner->status();
            $this->line('Upload scanning: '.($status['enabled'] ? 'on' : 'off'));
            $this->line('ClamAV daemon: '.(($status['daemon']['daemon_active'] ?? false) ? 'running' : 'stopped').(isset($status['daemon']['error']) ? ' ('.$status['daemon']['error'].')' : ''));

            return self::SUCCESS;
        }

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('State must be on, off or status.');

            return self::INVALID;
        }

        try {
            $warning = $scanner->setEnabled($state === 'on');
        } catch (\Throwable $e) {
            $this->error('Could not turn upload scanning on: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Upload scanning is '.$state.'.');
        if ($warning !== null) {
            $this->warn($warning);
        }

        return self::SUCCESS;
    }
}
