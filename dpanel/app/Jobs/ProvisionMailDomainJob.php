<?php

namespace App\Jobs;

use App\Models\MailDomain;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionMailDomainJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries = 2;

    public function __construct(
        public string $mailDomainId,
        public string $action = 'create',
    ) {
    }

    public function handle(SshClientService $sshClient): void
    {
        $domain = MailDomain::find($this->mailDomainId);
        if (! $domain) {
            return;
        }

        match ($this->action) {
            'create' => $this->provisionDomain($domain, $sshClient),
            'delete' => $this->deprovisionDomain($domain, $sshClient),
            default => null,
        };
    }

    private function provisionDomain(MailDomain $domain, SshClientService $sshClient): void
    {
        // Generate DKIM keys if enabled
        if ($domain->enable_dkim && empty($domain->dkim_private_key)) {
            $keys = $sshClient->generateDkimKeys($domain->domain, $domain->dkim_selector);
            $domain->update([
                'dkim_private_key' => $keys['private'],
                'dkim_public_key' => $keys['public'],
            ]);
        }

        // Configure mail service entries
        // Reload mail services
    }

    private function deprovisionDomain(MailDomain $domain, SshClientService $sshClient): void
    {
        // Remove mail service entries
        // Reload mail services
    }
}
