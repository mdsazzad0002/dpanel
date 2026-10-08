<?php

namespace App\Console\Commands;

use App\Services\Mail\MailOutboundGate;
use Illuminate\Console\Command;

class MailOutboundCheckCommand extends Command
{
    protected $signature = 'mail:outbound-check {--dry-run : Show the decision without changing Postfix}';

    protected $description = 'Check the server IPs\' reverse DNS and send over IPv4 only / hold outbound mail when Gmail would reject it.';

    public function handle(MailOutboundGate $gate): int
    {
        $state = $gate->check((bool) $this->option('dry-run'));
        $facts = $state['facts'];

        $this->table(['IP', 'PTR', 'Resolves back', 'DNS answered'], array_map(fn ($row) => [
            $row['ip'],
            $row['ptr'] ?: '-',
            $row['ok'] ? 'yes' : 'no',
            $row['lookup_failed'] ? 'no' : 'yes',
        ], array_values(array_filter([$facts['ipv4'], $facts['ipv6']]))));

        $this->line('IPv6 sending: '.$state['ipv6']);
        $this->line('Outbound mail: '.$state['outbound']);
        $this->line($state['reason']);
        if ($state['error']) {
            $this->error('Postfix was not updated: '.$state['error']);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
