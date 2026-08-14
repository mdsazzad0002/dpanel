<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Services\Mail\MailboxImapService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncMailboxMetadataJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function __construct(public string $mailboxId, public string $folder = 'INBOX')
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->mailboxId.':'.$this->folder;
    }

    public function handle(MailboxImapService $imap): void
    {
        $mailbox = Mailbox::query()->find($this->mailboxId);
        if ($mailbox) {
            $imap->loadMailbox($mailbox, $this->folder);
        }
    }
}
