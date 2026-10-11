<?php

namespace Tests\Unit;

use App\Services\Mail\MailboxImapService;
use App\Services\Mail\MailImageProxy;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class MailboxDefaultFoldersTest extends TestCase
{
    private function missing(array $existing): array
    {
        $service = new MailboxImapService($this->createMock(MailImageProxy::class));

        return (new ReflectionMethod($service, 'missingDefaultFolders'))->invoke($service, $existing);
    }

    public function test_a_new_mailbox_gets_every_standard_folder(): void
    {
        $this->assertSame(['Sent', 'Drafts', 'Junk', 'Trash'], $this->missing(['INBOX']));
    }

    public function test_existing_folders_under_other_names_count(): void
    {
        $this->assertSame([], $this->missing(['INBOX', 'Sent Messages', 'Drafts', 'Spam', 'Deleted Items']));
        $this->assertSame(['Trash'], $this->missing(['INBOX', 'INBOX.Sent', 'INBOX.Drafts', 'INBOX.Junk']));
    }
}
