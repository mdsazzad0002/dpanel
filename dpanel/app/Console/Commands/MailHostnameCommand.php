<?php

namespace App\Console\Commands;

use App\Services\Mail\MailHostnameDetector;
use Illuminate\Console\Command;

class MailHostnameCommand extends Command
{
    protected $signature = 'mail:hostname {--apply : Set Postfix to the best hostname that resolves to this server}';

    protected $description = 'Show the mail hostname candidates and optionally apply the best one.';

    public function handle(MailHostnameDetector $detector): int
    {
        $this->line('Current Postfix hostname: '.($detector->current() ?: '(unset)'));
        $this->table(['Host', 'Source', 'Resolves to', 'Usable'], array_map(fn ($c) => [
            $c['host'],
            $c['source'],
            implode(', ', $c['addresses']) ?: '-',
            $c['usable'] ? 'yes' : 'no',
        ], $detector->candidates()));

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        $result = $detector->applyBest();
        $result['ok'] ? $this->info($result['message']) : $this->warn($result['message']);

        // A missing A record is a DNS task for the admin, not an update failure.
        return self::SUCCESS;
    }
}
