<?php

namespace App\Console\Commands;

use App\Services\Mail\MailTlsCertificate;
use Illuminate\Console\Command;

class MailTlsCommand extends Command
{
    protected $signature = 'mail:tls {host? : Mail hostname (defaults to the current Postfix hostname)}';

    protected $description = 'Issue or renew the mail hostname certificate and install it in Postfix and Dovecot.';

    public function handle(MailTlsCertificate $tls): int
    {
        $result = $tls->ensure((string) $this->argument('host'));
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
