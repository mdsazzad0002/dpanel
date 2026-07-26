<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\ServerPanel\SshClientService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProvisionMailboxJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 2;

    public function __construct(
        public string $mailboxId,
        public string $action = 'create',
    ) {
    }

    public function handle(SshClientService $sshClient): void
    {
        $mailbox = Mailbox::find($this->mailboxId);
        if (! $mailbox) {
            return;
        }

        match ($this->action) {
            'create' => $this->createMailbox($mailbox, $sshClient),
            'delete' => $this->deleteMailbox($mailbox, $sshClient),
            default => null,
        };
    }

    private function createMailbox(Mailbox $mailbox, SshClientService $sshClient): void
    {
        // Create maildir structure
        // Set password hash
        // Reload mail services
    }

    private function deleteMailbox(Mailbox $mailbox, SshClientService $sshClient): void
    {
        // Remove maildir
        // Remove mailbox entries
        // Reload mail services
    }
}
