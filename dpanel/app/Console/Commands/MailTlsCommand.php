<?php

namespace App\Console\Commands;

use App\Services\Mail\MailIps;
use App\Services\Mail\MailTlsCertificate;
use Illuminate\Console\Command;

class MailTlsCommand extends Command
{
    protected $signature = 'mail:tls';

    protected $description = 'Apply the per-IP mail transports, then issue or renew every mail IP hostname certificate for Postfix and Dovecot.';

    public function handle(MailIps $ips, MailTlsCertificate $tls): int
    {
        $sync = $ips->sync();
        $sync['ok'] || $this->warn($sync['message']);

        $result = $tls->ensure();
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
