<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class VerifySelfConnectionCommand extends Command
{
    protected $signature = 'selfconnection:verify {target : database|redis}';
    protected $description = 'Verify dpanel can connect using the current .env values (fresh process, no cached config)';

    public function handle(): int
    {
        $target = (string) $this->argument('target');

        try {
            if ($target === 'database') {
                DB::connection()->getPdo();
            } elseif ($target === 'redis') {
                Redis::connection()->ping();
                Redis::connection('cache')->ping();
            } else {
                $this->error('Unknown target: '.$target);
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
