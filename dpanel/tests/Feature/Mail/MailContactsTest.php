<?php

namespace Tests\Feature\Mail;

use App\Http\Controllers\MailContactController;
use App\Models\Mailbox;
use App\Models\MailboxContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

class MailContactsTest extends TestCase
{
    use RefreshDatabase;

    private function mailbox(string $email): Mailbox
    {
        [$local, $domain] = explode('@', $email);

        return Mailbox::query()->forceCreate([
            'id' => (string) Str::uuid(), 'domain' => $domain, 'mailbox' => $local,
            'email' => $email, 'password' => 'x', 'status' => 'active',
        ]);
    }

    public function test_saved_contacts_are_listed_first_and_scoped_to_the_mailbox(): void
    {
        $mine = $this->mailbox('me@example.test');
        $other = $this->mailbox('other@example.test');
        $controller = new MailContactController;

        MailContactController::remember($mine, ['"Doe, Jane" <Jane@Corp.test>', 'bob@corp.test', 'not-an-address']);
        $controller->store(Request::create('/', 'POST', ['email' => 'Zed@Corp.test', 'name' => 'Zed']), 't', $mine->id);
        MailContactController::remember($other, ['secret@corp.test']);

        $contacts = $controller->index('t', $mine->id)->getData(true)['contacts'];

        $this->assertSame(['zed@corp.test', true], [$contacts[0]['email'], $contacts[0]['saved']]);
        $this->assertEqualsCanonicalizing(['zed@corp.test', 'jane@corp.test', 'bob@corp.test'], array_column($contacts, 'email'));
        $this->assertSame('Doe, Jane', collect($contacts)->firstWhere('email', 'jane@corp.test')['name']);
    }

    public function test_saving_a_remembered_address_keeps_one_row(): void
    {
        $mailbox = $this->mailbox('me@example.test');
        MailContactController::remember($mailbox, ['bob@corp.test']);

        (new MailContactController)->store(Request::create('/', 'POST', ['email' => 'BOB@corp.test', 'name' => 'Bob']), 't', $mailbox->id);
        // Sending again must not overwrite the name the user chose.
        MailContactController::remember($mailbox, ['Robert <bob@corp.test>']);

        $contact = MailboxContact::query()->sole();
        $this->assertSame(['Bob', true], [$contact->name, $contact->starred]);
    }

    public function test_a_contact_of_another_mailbox_cannot_be_deleted(): void
    {
        $mine = $this->mailbox('me@example.test');
        $other = $this->mailbox('other@example.test');
        MailContactController::remember($other, ['secret@corp.test']);
        $contact = MailboxContact::query()->sole();

        (new MailContactController)->destroy('t', $mine->id, (string) $contact->id);
        $this->assertTrue($contact->fresh() !== null);

        (new MailContactController)->destroy('t', $other->id, (string) $contact->id);
        $this->assertNull($contact->fresh());
    }
}
